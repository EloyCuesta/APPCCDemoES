import { beforeEach, describe, expect, it, vi } from "vitest";
import { createSessionStore } from "@/lib/auth/session-store";
import { browserSessionStorage as storage } from "@/lib/auth/storage";
import { endpoints } from "@/lib/api/endpoints";
import { context, deferred, json } from "./fixtures";

const fetchMock = vi.fn<typeof fetch>();
const input = { email: " ana@example.test ", password: "test-only-password" };
const createStore = () =>
  createSessionStore("http://api.example.test", storage);

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

describe("sesión real sobre el contrato HTTP", () => {
  it("login correcto obtiene identidad y selecciona un único establecimiento", async () => {
    fetchMock
      .mockResolvedValueOnce(json({ token: "jwt-test" }))
      .mockResolvedValueOnce(json(context()));
    const store = createStore();
    await store.login(input);
    expect(store.getSnapshot()).toMatchObject({
      status: "authenticated",
      session: { user: { nombre: "Ana" }, establecimientoActual: { id: 1 } },
    });
    expect(storage.getToken()).toBe("jwt-test");
    const [url, options] = fetchMock.mock.calls[0];
    expect(url).toBe("http://api.example.test/api/login_check");
    expect(JSON.parse(options?.body as string)).toEqual({
      email: "ana@example.test",
      password: input.password,
    });
    expect(new Headers(options?.headers).has("Authorization")).toBe(false);
    const meHeaders = new Headers(fetchMock.mock.calls[1][1]?.headers);
    expect(meHeaders.get("Authorization")).toBe("Bearer jwt-test");
    expect(meHeaders.get("Accept")).toBe("application/json");
    expect(meHeaders.has("X-Establecimiento-Id")).toBe(false);
    expect(JSON.stringify(store.getSnapshot())).not.toContain("jwt-test");
  });

  it("login incorrecto presenta error seguro sin consultar contexto", async () => {
    fetchMock.mockResolvedValue(
      json({ message: "Invalid credentials.", trace: "private" }, 401),
    );
    const store = createStore();
    await store.login(input);
    expect(store.getSnapshot().status).toBe("anonymous");
    expect(store.getSnapshot().error?.message).toContain(
      "Email o contraseña incorrectos",
    );
    expect(storage.getToken()).toBeNull();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("restaura desde token consultando al backend y valida la selección guardada", async () => {
    storage.setToken("restored");
    storage.setEstablecimiento(2);
    fetchMock.mockResolvedValue(json(context([1, 2])));
    const store = createStore();
    await store.restore();
    expect(store.getSnapshot().session?.establecimientoActual?.id).toBe(2);
    expect(fetchMock.mock.calls[0][0]).toContain("/api/me");
  });

  it("descarta un establecimiento revocado y obliga a seleccionar entre los actuales", async () => {
    storage.setToken("restored");
    storage.setEstablecimiento(99);
    fetchMock.mockResolvedValue(json(context([1, 2])));
    const store = createStore();
    await store.restore();
    expect(store.getSnapshot().session?.establecimientoActual).toBeNull();
    expect(storage.getEstablecimiento()).toBeNull();
  });

  it("permite seleccionar varios y las siguientes peticiones usan el nuevo tenant", async () => {
    storage.setToken("restored");
    fetchMock
      .mockResolvedValueOnce(json(context([1, 2])))
      .mockImplementation(async () => json({ id: 2 }));
    const store = createStore();
    await store.restore();
    expect(store.getSnapshot().session?.establecimientoActual).toBeNull();
    store.seleccionarEstablecimiento(1);
    await store.api.request(endpoints.establecimiento(1));
    store.seleccionarEstablecimiento(2);
    await store.api.request(endpoints.establecimiento(2));
    expect(
      new Headers(fetchMock.mock.calls[1][1]?.headers).get(
        "X-Establecimiento-Id",
      ),
    ).toBe("1");
    const headers = new Headers(fetchMock.mock.calls[2][1]?.headers);
    expect(headers.get("X-Establecimiento-Id")).toBe("2");
    expect(headers.get("Accept")).toBe("application/ld+json");
    expect(storage.getEstablecimiento()).toBe(2);
    expect(() => store.seleccionarEstablecimiento(99)).toThrow(
      "No tienes permisos",
    );
  });

  it("sin membresías conserva la identidad, pero bloquea consultas operativas", async () => {
    storage.setToken("restored");
    fetchMock.mockResolvedValue(json(context([])));
    const store = createStore();
    await store.restore();
    expect(store.getSnapshot().status).toBe("authenticated");
    expect(store.getSnapshot().session?.establecimientoActual).toBeNull();
    await expect(
      store.api.request(endpoints.establecimiento(1)),
    ).rejects.toMatchObject({ status: 400 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("logout elimina token, usuario, selección y datos derivados", async () => {
    storage.setToken("restored");
    fetchMock.mockResolvedValue(json(context()));
    const store = createStore();
    await store.restore();
    store.logout();
    expect(storage.getToken()).toBeNull();
    expect(storage.getEstablecimiento()).toBeNull();
    expect(store.getSnapshot()).toMatchObject({
      status: "anonymous",
      session: null,
      error: null,
    });
  });

  it.each(["restore", "tenant"])(
    "401 en %s limpia toda la sesión",
    async (mode) => {
      storage.setToken("restored");
      storage.setEstablecimiento(1);
      const store = createStore();
      if (mode === "tenant") {
        fetchMock.mockResolvedValueOnce(json(context()));
        await store.restore();
      }
      fetchMock.mockResolvedValue(json({ message: "expired" }, 401));
      if (mode === "restore") await store.restore();
      else
        await expect(
          store.api.request(endpoints.establecimiento(1)),
        ).rejects.toMatchObject({ status: 401 });
      expect(store.getSnapshot()).toMatchObject({
        status: "anonymous",
        session: null,
        error: { status: 401 },
      });
      expect(storage.getToken()).toBeNull();
      expect(storage.getEstablecimiento()).toBeNull();
    },
  );

  it("403 conserva la sesión y devuelve un error de permisos", async () => {
    storage.setToken("restored");
    fetchMock
      .mockResolvedValueOnce(json(context()))
      .mockResolvedValue(json({ detail: "private trace" }, 403));
    const store = createStore();
    await store.restore();
    await expect(
      store.api.request(endpoints.establecimiento(1)),
    ).rejects.toMatchObject({
      status: 403,
      message: expect.stringContaining("No tienes permisos"),
    });
    expect(store.getSnapshot().status).toBe("authenticated");
  });

  it("un fallo de red permite reintentar restauración sin perder el token", async () => {
    storage.setToken("restored");
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
    const store = createStore();
    await store.restore();
    expect(store.getSnapshot()).toMatchObject({
      status: "error",
      session: null,
    });
    expect(storage.getToken()).toBe("restored");
    fetchMock.mockResolvedValueOnce(json(context()));
    await store.restore();
    expect(store.getSnapshot().status).toBe("authenticated");
  });

  it("logout durante login impide que una respuesta tardía restaure la sesión", async () => {
    const pending = deferred<Response>();
    fetchMock.mockReturnValueOnce(pending.promise);
    const store = createStore();
    const login = store.login(input);
    store.logout();
    pending.resolve(json({ token: "too-late" }));
    await login;
    expect(store.getSnapshot().status).toBe("anonymous");
    expect(storage.getToken()).toBeNull();
  });

  it("ignora un 401 tardío del establecimiento anterior al cambiar de contexto", async () => {
    storage.setToken("restored");
    fetchMock.mockResolvedValueOnce(json(context([1, 2])));
    const store = createStore();
    await store.restore();
    store.seleccionarEstablecimiento(1);
    const pending = deferred<Response>();
    fetchMock.mockReturnValueOnce(pending.promise);
    const request = store.api.request(endpoints.establecimiento(1));
    const assertion = expect(request).rejects.toMatchObject({
      name: "AbortError",
    });
    store.seleccionarEstablecimiento(2);
    pending.resolve(json({}, 401));
    await assertion;
    expect(store.getSnapshot().session?.establecimientoActual?.id).toBe(2);
    expect(storage.getToken()).toBe("restored");
  });

  it("rechaza contratos de sesión incompletos y no muestra contenido privado", async () => {
    storage.setToken("restored");
    fetchMock.mockResolvedValue(json({ id: 5, membresias: [] }));
    const store = createStore();
    await store.restore();
    expect(store.getSnapshot()).toMatchObject({
      status: "error",
      session: null,
      error: { status: 502 },
    });
  });
});
