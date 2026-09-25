import { beforeEach, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { SessionProvider } from "@/providers/session-provider";
import { PlantillasScreen } from "@/features/plantillas/plantillas-screen";
import { parsePlantilla, parseResultado } from "@/features/plantillas/contracts";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { useAuth } from "@/hooks/use-auth";
import { browserSessionStorage as storage } from "@/lib/auth/storage";
import type { RolEstablecimiento } from "@/types/session";
import { context, deferred, json } from "./fixtures";
import { collection } from "./agenda-fixtures";
import { control, plantilla, recibo } from "./plantillas-fixtures";

const fetchMock = vi.fn<typeof fetch>();
let role: RolEstablecimiento;
let active: boolean;
let overrides: Map<string, (url: URL, init?: RequestInit) => Promise<Response>>;
beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  vi.stubEnv("NEXT_PUBLIC_API_URL", "http://api.example.test");
  fetchMock.mockReset();
  storage.setToken("session-token");
  storage.setEstablecimiento(1);
  role = "responsable";
  active = false;
  overrides = new Map();
  fetchMock.mockImplementation(async (input, init) => {
    const url = new URL(String(input)), method = init?.method ?? "GET";
    const override = overrides.get(`${method} ${url.pathname}`);
    if (override) return override(url, init);
    if (url.pathname === "/api/me") {
      const data = context([1, 2]);
      data.membresias.forEach((item) => { item.rol = role; });
      return json(data);
    }
    const tenantId = Number(new Headers(init?.headers).get("X-Establecimiento-Id"));
    if (url.pathname === "/api/plantillas-appcc") return json(collection([plantilla(), plantilla(2, "obrador"), plantilla(3, "catering")]));
    if (url.pathname === "/api/plantillas-appcc/1/aplicar") return json(recibo(tenantId));
    if (url.pathname === "/api/tareas/41") {
      if (method === "PATCH") active = true;
      return json(control(tenantId, active));
    }
    throw new Error(`Unexpected: ${method} ${url.pathname}`);
  });
});

function SessionControls() {
  const { seleccionarEstablecimiento } = useEstablecimiento();
  const { error } = useAuth();
  return <><button onClick={() => seleccionarEstablecimiento(2)}>Cambiar establecimiento</button>{error && <p role="alert">{error.message}</p>}</>;
}
function mount() {
  return render(<SessionProvider><SessionControls /><PlantillasScreen /></SessionProvider>);
}
function calls(path: string, method = "GET") {
  return fetchMock.mock.calls.filter(([url, init]) => new URL(String(url)).pathname === path && (init?.method ?? "GET") === method);
}
const applyPath = "/api/plantillas-appcc/1/aplicar";
async function confirm() {
  mount();
  fireEvent.click(await screen.findByRole("button", { name: "Aplicar Plantilla restaurante" }));
  fireEvent.click(screen.getByRole("checkbox", { name: /He revisado/ }));
  fireEvent.click(screen.getByRole("button", { name: "Confirmar aplicación", exact: true }));
}
async function configure() {
  await confirm();
  fireEvent.click(await screen.findByRole("button", { name: "Configurar control #41" }));
  await screen.findByLabelText("Límite mínimo");
}
function limits(min = "0", max = "5") {
  fireEvent.change(screen.getByLabelText("Límite mínimo"), { target: { value: min } });
  fireEvent.change(screen.getByLabelText("Límite máximo"), { target: { value: max } });
  fireEvent.click(screen.getByRole("checkbox", { name: /Confirmo que los límites/ }));
}

it("carga tres plantillas, preview completo, actividad compatible y confirmación sin escritura accidental", async () => {
  mount();
  await screen.findByRole("heading", { name: "Plantilla restaurante" });
  expect(screen.getByRole("heading", { name: "Plantilla obrador" })).toBeVisible();
  expect(screen.getByRole("heading", { name: "Plantilla catering" })).toBeVisible();
  expect(screen.getByRole("button", { name: "Aplicar Plantilla obrador" })).toBeDisabled();
  fireEvent.click(screen.getByText("Ver contenido de Plantilla restaurante"));
  const card = screen.getByRole("heading", { name: "Plantilla restaurante" }).closest("li")!;
  expect(within(card).getByText("Temperatura de conservación")).toBeVisible();
  expect(within(card).getByText("Limpieza de cocina")).toBeVisible();
  expect(within(card).getByText(/Requiere configurar límites/)).toBeVisible();
  fireEvent.click(screen.getByRole("button", { name: "Aplicar Plantilla restaurante" }));
  expect(screen.getByRole("button", { name: "Confirmar aplicación", exact: true })).toBeDisabled();
  expect(screen.getByRole("heading", { name: "Aplicar en Establecimiento 1" })).toHaveFocus();
  fireEvent.click(screen.getByRole("button", { name: "Cancelar" }));
  expect(calls(applyPath, "POST")).toHaveLength(0);
  const headers = new Headers(calls("/api/plantillas-appcc")[0][1]?.headers);
  expect(headers.get("Authorization")).toBe("Bearer session-token");
  expect(headers.has("X-Establecimiento-Id")).toBe(false);
});

it.each(["admin", "responsable", "trabajador", "auditor"] as const)("%s consulta con permisos exactos", async (value) => {
  role = value;
  mount();
  await screen.findByRole("heading", { name: "Plantilla restaurante" });
  expect(screen.queryByRole("button", { name: "Aplicar Plantilla restaurante" }) !== null).toBe(["admin", "responsable"].includes(value));
});

it("plantilla inactiva no puede aplicarse", async () => {
  overrides.set("GET /api/plantillas-appcc", async () => json(collection([plantilla(1, "restaurante", false)])));
  mount();
  expect(await screen.findByRole("button", { name: "Aplicar Plantilla restaurante" })).toBeDisabled();
  expect(screen.getByText("Plantilla inactiva.")).toBeVisible();
});

it("catálogo vacío y paginación por total/view.next", async () => {
  overrides.set("GET /api/plantillas-appcc", async (url) => json(url.searchParams.get("page") === "2" ? collection([]) : collection([plantilla()], 21, "/api/plantillas-appcc?page=2")));
  mount();
  await screen.findByRole("heading", { name: "Plantilla restaurante" });
  fireEvent.click(screen.getByRole("button", { name: "Siguiente" }));
  await screen.findByText("No hay plantillas disponibles");
  expect(screen.getByRole("button", { name: "Siguiente" })).toBeDisabled();
  fireEvent.click(screen.getByRole("button", { name: "Anterior" }));
  await screen.findByText("Página 1 · 21 resultados");
});

it("POST vacío autenticado, bloqueo de doble clic y recibo con controles creados", async () => {
  const pending = deferred<Response>();
  overrides.set(`POST ${applyPath}`, () => pending.promise);
  await confirm();
  fireEvent.click(screen.getByRole("button", { name: "Aplicando…" }));
  expect(calls(applyPath, "POST")).toHaveLength(1);
  const init = calls(applyPath, "POST")[0][1]!;
  expect(JSON.parse(String(init.body))).toEqual({});
  expect(new Headers(init.headers).get("X-Establecimiento-Id")).toBe("1");
  expect(new Headers(init.headers).get("Authorization")).toBe("Bearer session-token");
  expect(init.credentials).toBe("omit");
  expect(init.redirect).toBe("error");
  await act(async () => pending.resolve(json(recibo())));
  const receipt = await screen.findByRole("region", { name: "Resultado de aplicación" });
  expect(receipt).toHaveTextContent("1 planes, 1 puntos y 2 tareas");
  expect(receipt).toHaveTextContent("Control #41");
  expect(screen.queryByRole("button", { name: "Aplicar Plantilla restaurante" })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Actualizar plantillas" }));
  await screen.findByRole("heading", { name: "Plantilla restaurante" });
  expect(screen.queryByRole("button", { name: "Aplicar Plantilla restaurante" })).not.toBeInTheDocument();
});

it("yaAplicada muestra la aplicación original y ningún duplicado", async () => {
  overrides.set(`POST ${applyPath}`, async () => json({ ...recibo(1, true), creados: { planes: 0, puntos: 0, tareas: 0 } }));
  await confirm();
  await screen.findByRole("heading", { name: "La plantilla ya estaba aplicada" });
  expect(screen.getByText(/Se conserva la aplicación anterior/)).toBeVisible();
});

it.each([403, 404, 409, 422, 500, 503, 0])("error %s consultando catálogo con recuperación segura", async (status) => {
  overrides.set("GET /api/plantillas-appcc", async () => {
    if (!status) throw new TypeError("offline");
    return json({}, status);
  });
  mount();
  expect(await screen.findByRole("alert")).toBeVisible();
  expect(screen.queryByRole("heading", { name: "Plantilla restaurante" })).not.toBeInTheDocument();
  if ([403, 404].includes(status)) expect(screen.getByRole("button", { name: "Actualizar mis accesos" })).toBeVisible();
  if ([0, 409, 500, 503].includes(status)) expect(screen.getByRole("button", { name: "Volver a intentar" })).toBeVisible();
});

it.each([403, 404, 409, 422, 500, 0])("error %s aplicando sin reenvío automático", async (status) => {
  overrides.set(`POST ${applyPath}`, async () => {
    if (!status) throw new TypeError("offline");
    return json({ detail: "Configuración no compatible.", violations: [{ propertyPath: "plantilla", message: "Revise la actividad." }] }, status);
  });
  await confirm();
  await screen.findByRole("alert");
  expect(calls(applyPath, "POST")).toHaveLength(1);
  if (status === 422) expect(screen.getByRole("alert")).toHaveTextContent("plantilla: Revise la actividad.");
  if ([0, 409, 500].includes(status)) {
    overrides.delete(`POST ${applyPath}`);
    fireEvent.click(screen.getByRole("button", { name: "Comprobar aplicación" }));
    await screen.findByRole("heading", { name: "Plantilla aplicada correctamente" });
    expect(calls(applyPath, "POST")).toHaveLength(2);
  }
});

it.each(["GET", "POST", "PATCH"])("401 en %s retira sesión y datos privados", async (method) => {
  const path = method === "GET" ? "/api/plantillas-appcc" : method === "POST" ? applyPath : "/api/tareas/41";
  overrides.set(`${method} ${path}`, async () => json({}, 401));
  if (method === "GET") mount();
  else if (method === "POST") await confirm();
  else { await configure(); limits(); fireEvent.click(screen.getByRole("button", { name: "Guardar límites y activar" })); }
  await waitFor(() => expect(storage.getToken()).toBeNull());
  expect(screen.queryByRole("region", { name: "Resultado de aplicación" })).not.toBeInTheDocument();
  expect(screen.queryByRole("heading", { name: "Plantillas APPCC" })).not.toBeInTheDocument();
});

it.each(["catalogo", "aplicar", "configurar"])("cambiar tenant cancela %s y descarta 401 tardío", async (stage) => {
  const pending = deferred<Response>();
  let signal: AbortSignal | null = null;
  const path = stage === "catalogo" ? "/api/plantillas-appcc" : stage === "aplicar" ? applyPath : "/api/tareas/41";
  const method = stage === "aplicar" ? "POST" : stage === "configurar" ? "PATCH" : "GET";
  let first = true;
  overrides.set(`${method} ${path}`, async (_url, init) => {
    if (first) { first = false; signal = init!.signal!; return pending.promise; }
    return json(collection([plantilla(2, "restaurante")]));
  });
  if (stage === "catalogo") mount();
  else if (stage === "aplicar") await confirm();
  else { await configure(); limits(); fireEvent.click(screen.getByRole("button", { name: "Guardar límites y activar" })); }
  await waitFor(() => expect(signal).not.toBeNull());
  fireEvent.click(screen.getByRole("button", { name: "Cambiar establecimiento" }));
  await screen.findByText("Prepara los controles de Establecimiento 2.");
  expect(signal!.aborted).toBe(true);
  await act(async () => pending.resolve(json({}, 401)));
  expect(storage.getToken()).toBe("session-token");
  expect(screen.queryByRole("region", { name: "Resultado de aplicación" })).not.toBeInTheDocument();
  expect(screen.queryByRole("region", { name: "Confirmar aplicación" })).not.toBeInTheDocument();
});

it("rechaza recibo de otro tenant sin mostrar éxito", async () => {
  overrides.set(`POST ${applyPath}`, async () => json(recibo(2)));
  await confirm();
  await screen.findByRole("alert");
  expect(screen.queryByRole("region", { name: "Resultado de aplicación" })).not.toBeInTheDocument();
});

it("valida límites, guarda PATCH mínimo una sola vez y consulta el estado actual", async () => {
  const pending = deferred<Response>();
  overrides.set("PATCH /api/tareas/41", () => pending.promise);
  await configure();
  limits("10", "5");
  fireEvent.click(screen.getByRole("button", { name: "Guardar límites y activar" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("mínimo no puede superar");
  expect(calls("/api/tareas/41", "PATCH")).toHaveLength(0);
  fireEvent.change(screen.getByLabelText("Límite mínimo"), { target: { value: "0" } });
  fireEvent.click(screen.getByRole("button", { name: "Guardar límites y activar" }));
  fireEvent.click(screen.getByRole("button", { name: "Guardar límites y activar" }));
  expect(calls("/api/tareas/41", "PATCH")).toHaveLength(1);
  const init = calls("/api/tareas/41", "PATCH")[0][1]!;
  expect(JSON.parse(String(init.body))).toEqual({ limiteMinimo: "0", limiteMaximo: "5", unidad: "°C", instrucciones: "Identificar el equipo y medir.", activa: true });
  expect(new Headers(init.headers).get("Content-Type")).toBe("application/merge-patch+json");
  active = true;
  await act(async () => pending.resolve(json(control(1, true))));
  await screen.findByText("El control está configurado y activo.");
  expect(calls("/api/tareas/41")).toHaveLength(2);
});

it("control del recibo ya activo no permite sobrescribirlo y otro tenant es rechazado", async () => {
  active = true;
  await configureActive();
  expect(screen.queryByLabelText("Límite mínimo")).not.toBeInTheDocument();
  overrides.set("GET /api/tareas/41", async () => json(control(2)));
  fireEvent.click(screen.getByRole("button", { name: "Consultar estado actual" }));
  await screen.findByRole("alert");
  expect(screen.queryByLabelText("Límite mínimo")).not.toBeInTheDocument();
});

async function configureActive() {
  await confirm();
  fireEvent.click(await screen.findByRole("button", { name: "Configurar control #41" }));
  await screen.findByText("El control está configurado y activo.");
}

it.each([409, 422, 500])("configuración error %s conserva datos y exige lectura tras incertidumbre", async (status) => {
  overrides.set("PATCH /api/tareas/41", async () => json({ detail: "Límites no válidos." }, status));
  await configure(); limits();
  fireEvent.click(screen.getByRole("button", { name: "Guardar límites y activar" }));
  await screen.findByRole("alert");
  expect(screen.getByLabelText("Límite máximo")).toHaveValue("5");
  expect(calls("/api/tareas/41", "PATCH")).toHaveLength(1);
  if (status !== 422) {
    expect(screen.getByRole("button", { name: "Guardar límites y activar" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "Consultar estado actual" }));
    await screen.findByLabelText("Límite mínimo");
    expect(calls("/api/tareas/41")).toHaveLength(2);
  }
});

it("contratos rechazan identidades, relaciones, tipos y contadores adulterados", () => {
  expect(() => parsePlantilla({ ...plantilla(), "@id": "/api/plantillas-appcc/99" })).toThrow();
  expect(() => parsePlantilla({ ...plantilla(), activa: "true" })).toThrow();
  expect(() => parseResultado(recibo(), 2, 1)).toThrow();
  expect(() => parseResultado({ ...recibo(), creados: { planes: 1, puntos: 1, tareas: 90 } }, 1, 1)).toThrow();
  const corrupt = recibo();
  corrupt.resultadoInicial.tareas[0].planControl = "/api/planes-control/999";
  expect(() => parseResultado(corrupt, 1, 1)).toThrow();
});
