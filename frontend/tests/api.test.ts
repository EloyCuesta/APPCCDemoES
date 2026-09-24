import { beforeEach, expect, it, vi } from "vitest";
import { ApiClient } from "@/lib/api/client";
import { endpoints } from "@/lib/api/endpoints";
import { ApiError, responseError } from "@/lib/api/errors";
import { readCollection } from "@/lib/api/collections";
import { json } from "./fixtures";

const fetchMock = vi.fn<typeof fetch>();
const unauthorized = vi.fn();
const client = () =>
  new ApiClient("https://api.example.test", {
    getToken: () => "jwt-test",
    getEstablecimientoId: () => 7,
    onUnauthorized: unauthorized,
  });
beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

it.each([400, 401, 403, 404, 409, 422, 500, 502, 503])(
  "normaliza HTTP %i sin filtrar detalles internos",
  async (status) => {
    fetchMock.mockResolvedValue(
      json({ detail: "SQLSTATE private stack trace", trace: "secret" }, status),
    );
    await expect(
      client().request(endpoints.establecimiento(7)),
    ).rejects.toMatchObject({
      status,
      message: expect.not.stringContaining("SQLSTATE"),
    });
    expect(unauthorized).toHaveBeenCalledTimes(status === 401 ? 1 : 0);
  },
);

it("maneja errores de red", async () => {
  fetchMock.mockRejectedValue(new TypeError("Failed to fetch"));
  await expect(client().request(endpoints.me)).rejects.toMatchObject({
    status: 0,
  });
});

it("timeout de una escritura aborta a los 15 segundos sin reintentar", async () => {
  vi.useFakeTimers();
  try {
    fetchMock.mockImplementation((_url, init) => new Promise((_resolve, reject) => {
      init?.signal?.addEventListener("abort", () => reject(new DOMException("Aborted", "AbortError")), { once: true });
    }));
    const assertion = expect(client().request(endpoints.registros, { method: "POST", body: { confirmarRegistro: true } })).rejects.toMatchObject({
      status: 0, message: "La API está tardando demasiado. Vuelve a intentarlo.",
    });
    await vi.advanceTimersByTimeAsync(15_000);
    await assertion;
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][1]?.signal?.aborted).toBe(true);
  } finally { vi.useRealTimers(); }
});

it("expone validaciones de dominio 422", () => {
  const error = responseError(422, {
    detail: "El registro ya está confirmado.",
    violations: [
      { propertyPath: "nombre", message: "El nombre es obligatorio." },
    ],
  });
  expect(error.message).toBe("El registro ya está confirmado.");
  expect(error.violations).toEqual([
    { field: "nombre", message: "El nombre es obligatorio." },
  ]);
  expect(responseError(409, {}).retryable).toBe(true);
});

it("evita enviar credenciales a rutas absolutas o redirecciones", async () => {
  await expect(
    client().request({
      ...endpoints.me,
      path: "https://outside.example/api/me",
    }),
  ).rejects.toMatchObject({ status: 400 });
  expect(fetchMock).not.toHaveBeenCalled();
  fetchMock.mockResolvedValue(json({}));
  await client().request(endpoints.me);
  expect(fetchMock.mock.calls[0][1]).toMatchObject({
    redirect: "error",
    credentials: "omit",
    cache: "no-store",
  });
});

it("rechaza respuestas exitosas que no contienen JSON", async () => {
  fetchMock.mockResolvedValue(
    new Response("<html>error</html>", { status: 200 }),
  );
  await expect(client().request(endpoints.me)).rejects.toMatchObject({
    status: 502,
  });
});

it.each([false, true])(
  "adapta colecciones JSON-LD (prefijo Hydra: %s)",
  (hydra) => {
    const prefix = hydra ? "hydra:" : "";
    const result = readCollection(
      {
        [`${prefix}member`]: [1, 2],
        [`${prefix}totalItems`]: 12,
        [`${prefix}view`]: { [`${prefix}next`]: "/api/tareas?page=2" },
      },
      (value) => Number(value),
    );
    expect(result).toEqual({
      items: [1, 2],
      total: 12,
      next: "/api/tareas?page=2",
    });
  },
);

it("una colección sin total no inventa una cifra", () => {
  expect(readCollection({ member: [] }, (value) => value).total).toBeNull();
  expect(() => readCollection({}, (value) => value)).toThrow(ApiError);
});
