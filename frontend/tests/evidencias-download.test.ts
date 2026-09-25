import { beforeEach, expect, it, vi } from "vitest";
import { ApiClient } from "@/lib/api/client";
import { endpoints } from "@/lib/api/endpoints";
import {
  downloadEvidencia,
  downloadFilename,
} from "@/features/registros/download";
import { parseEvidencia } from "@/features/registros/evidencias-contracts";
import { deferred, json } from "./fixtures";
import { evidence } from "./historico-fixtures";

const fetchMock = vi.fn<typeof fetch>();
const unauthorized = vi.fn();
let tenant: number;
let token: string | null;
function client() {
  return new ApiClient("https://api.example.test", {
    getToken: () => token,
    getEstablecimientoId: () => tenant,
    onUnauthorized: unauthorized,
  });
}
function binary(
  body = "foto",
  type = "image/png",
  disposition = 'attachment; filename="foto.png"',
) {
  return new Response(body, {
    headers: { "Content-Type": type, "Content-Disposition": disposition },
  });
}
beforeEach(() => {
  fetchMock.mockReset();
  unauthorized.mockReset();
  vi.stubGlobal("fetch", fetchMock);
  tenant = 1;
  token = "jwt-test";
});

it("descarga mediante la ruta autenticada, JWT, tenant y opciones seguras", async () => {
  fetchMock.mockResolvedValue(binary());
  const result = await client().download(endpoints.descargarEvidencia(1));
  expect(fetchMock).toHaveBeenCalledTimes(1);
  const [url, init] = fetchMock.mock.calls[0];
  expect(url).toBe("https://api.example.test/api/evidencias/1/descargar");
  expect(init).toMatchObject({
    method: "GET",
    credentials: "omit",
    redirect: "error",
    cache: "no-store",
  });
  expect(Object.fromEntries(new Headers(init?.headers))).toEqual({
    accept: "application/octet-stream",
    authorization: "Bearer jwt-test",
    "x-establecimiento-id": "1",
  });
  expect(result.blob.size).toBe(4);
  expect(result.contentDisposition).toBe('attachment; filename="foto.png"');
});

it("sin JWT no envía ninguna petición", async () => {
  token = null;
  await expect(
    client().download(endpoints.descargarEvidencia(1)),
  ).rejects.toMatchObject({ status: 401 });
  expect(unauthorized).toHaveBeenCalledOnce();
  expect(fetchMock).not.toHaveBeenCalled();
});

it.each([401, 403, 404, 503])(
  "HTTP %s no descarga el cuerpo de error ni revela detalles",
  async (status) => {
    fetchMock.mockResolvedValue(
      json(
        { detail: "storageKey=/srv/private/token-secret sha256=hash" },
        status,
      ),
    );
    await expect(
      client().download(endpoints.descargarEvidencia(1)),
    ).rejects.toMatchObject({
      status,
      message: expect.not.stringMatching(/storageKey|srv|token-secret|sha256/),
    });
    expect(unauthorized).toHaveBeenCalledTimes(status === 401 ? 1 : 0);
  },
);

it("el timeout cancela la descarga sin reintentar", async () => {
  vi.useFakeTimers();
  try {
    fetchMock.mockImplementation(
      (_url, init) =>
        new Promise((_resolve, reject) => {
          init?.signal?.addEventListener(
            "abort",
            () => reject(new DOMException("Aborted", "AbortError")),
            { once: true },
          );
        }),
    );
    const assertion = expect(
      client().download(endpoints.descargarEvidencia(1)),
    ).rejects.toMatchObject({
      status: 0,
      message: expect.stringContaining("tardando demasiado"),
    });
    await vi.advanceTimersByTimeAsync(15_000);
    await assertion;
    expect(fetchMock).toHaveBeenCalledOnce();
    expect(fetchMock.mock.calls[0][1]?.signal?.aborted).toBe(true);
  } finally {
    vi.useRealTimers();
  }
});

it("abortar descarta incluso una respuesta que ignora la cancelación", async () => {
  const pending = deferred<Response>();
  fetchMock.mockReturnValue(pending.promise);
  const controller = new AbortController();
  const assertion = expect(
    client().download(endpoints.descargarEvidencia(1), {
      signal: controller.signal,
    }),
  ).rejects.toMatchObject({ name: "AbortError" });
  controller.abort();
  pending.resolve(binary());
  await assertion;
  expect(unauthorized).not.toHaveBeenCalled();
});

it("una señal ya cancelada no inicia otra descarga ni invalida la sesión", async () => {
  const controller = new AbortController();
  controller.abort();
  await expect(client().download(endpoints.descargarEvidencia(1), { signal: controller.signal })).rejects.toMatchObject({ name: "AbortError" });
  expect(fetchMock).not.toHaveBeenCalled();
  expect(unauthorized).not.toHaveBeenCalled();
});

it.each([200, 401])(
  "cambiar establecimiento descarta respuesta tardía %s y conserva la sesión",
  async (status) => {
    const pending = deferred<Response>();
    fetchMock.mockReturnValueOnce(pending.promise).mockResolvedValue(binary());
    const api = client();
    const assertion = expect(
      api.download(endpoints.descargarEvidencia(1)),
    ).rejects.toMatchObject({ name: "AbortError" });
    tenant = 2;
    api.cancelPending();
    await api.download(endpoints.descargarEvidencia(2));
    pending.resolve(status === 200 ? binary() : json({}, status));
    await assertion;
    expect(
      new Headers(fetchMock.mock.calls[1][1]?.headers).get(
        "X-Establecimiento-Id",
      ),
    ).toBe("2");
    expect(unauthorized).not.toHaveBeenCalled();
  },
);

it("cambiar establecimiento durante la lectura del blob descarta el archivo", async () => {
  const pending = deferred<Blob>();
  const response = binary();
  vi.spyOn(response, "blob").mockReturnValue(pending.promise);
  fetchMock.mockResolvedValue(response);
  const api = client();
  const assertion = expect(
    api.download(endpoints.descargarEvidencia(1)),
  ).rejects.toMatchObject({ name: "AbortError" });
  await vi.waitFor(() => expect(response.blob).toHaveBeenCalled());
  api.cancelPending();
  tenant = 2;
  pending.resolve(new Blob(["foto"], { type: "image/png" }));
  await assertion;
});

it("rechaza archivos vacíos", async () => {
  fetchMock.mockResolvedValue(binary(""));
  await expect(
    client().download(endpoints.descargarEvidencia(1)),
  ).rejects.toMatchObject({
    status: 502,
    message: expect.stringContaining("vacío"),
  });
});

it.each([
  ["wrong", "image/png"],
  ["foto", "text/html"],
])("rechaza tamaño o MIME inesperado (%s, %s)", async (body, mime) => {
  fetchMock.mockResolvedValue(binary(body, mime));
  await expect(
    downloadEvidencia(
      client(),
      parseEvidencia(evidence(), 8),
      new AbortController().signal,
    ),
  ).rejects.toMatchObject({ status: 502 });
});

it("503 explica que el registro histórico se conserva", async () => {
  fetchMock.mockResolvedValue(json({}, 503));
  await expect(
    downloadEvidencia(
      client(),
      parseEvidencia(evidence(), 8),
      new AbortController().signal,
    ),
  ).rejects.toMatchObject({
    status: 503,
    message: expect.stringContaining("histórico se conserva"),
  });
});

it.each([
  [null, "original.pdf", "original.pdf"],
  ['attachment; filename="control.pdf"', "original.pdf", "control.pdf"],
  [
    "attachment; filename=old.pdf; filename*=UTF-8''revisi%C3%B3n%20t%C3%A9rmica.pdf",
    "original.pdf",
    "revisión térmica.pdf",
  ],
  ["attachment; filename*=UTF-8''%broken", "original.pdf", "original.pdf"],
  ['attachment; filename="../../control.pdf"', "original.pdf", "control.pdf"],
  [
    "attachment; filename*=UTF-8''C%3A%5Cprivate%5Ccontrol.pdf",
    "original.pdf",
    "control.pdf",
  ],
  ['attachment; filename="CON.pdf"', "original.pdf", "original.pdf"],
  ['attachment; filename="lpt1.png"', "original.pdf", "original.pdf"],
  [
    'attachment; filename="control\u202epdf.exe"',
    "original.pdf",
    "controlpdf.exe",
  ],
  [null, "\u0000.. ", "evidencia-1"],
])("nombre seguro para Content-Disposition %s", (header, known, expected) => {
  expect(downloadFilename(header, known, 1)).toBe(expected);
});

it("los metadatos públicos eliminan rutas, claves, tokens y hashes internos", () => {
  const parsed = parseEvidencia(
    {
      ...evidence(),
      storageKey: "secret",
      ruta: "/srv/private",
      token: "secret",
      sha256: "hash",
    },
    8,
  );
  expect(parsed).not.toHaveProperty("storageKey");
  expect(parsed).not.toHaveProperty("ruta");
  expect(parsed).not.toHaveProperty("token");
  expect(parsed).not.toHaveProperty("sha256");
});

it.each([
  { registro: "/api/registros/99" },
  { incidencia: "/api/incidencias/1" },
  { downloadUrl: "https://outside.test/file" },
  { nombreOriginal: "../private.png" },
  { subidaPor: "/api/usuarios/../5" },
  { tamanoBytes: 0 },
  { mimeType: "text/html\r\nInjected" },
])("rechaza metadatos o relaciones inválidas %o", (changes) => {
  expect(() => parseEvidencia({ ...evidence(), ...changes }, 8)).toThrow();
});
