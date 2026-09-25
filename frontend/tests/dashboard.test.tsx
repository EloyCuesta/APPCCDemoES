import { beforeEach, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { SessionProvider } from "@/providers/session-provider";
import { EstablecimientoGate } from "@/features/establecimientos/establecimiento-gate";
import { Indicadores } from "@/features/dashboard/indicadores";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { useAuth } from "@/hooks/use-auth";
import { browserSessionStorage as storage } from "@/lib/auth/storage";
import { context, deferred, json } from "./fixtures";
import { collection, scheduled } from "./agenda-fixtures";
import { dayBoundary, localDay } from "@/features/agenda/dates";

const fetchMock = vi.fn<typeof fetch>();
let status: number;
beforeEach(() => {
  status = 200;
  vi.stubGlobal("fetch", fetchMock);
  vi.stubEnv("NEXT_PUBLIC_API_URL", "http://api.example.test");
  storage.setToken("dashboard-session");
  storage.setEstablecimiento(1);
  fetchMock.mockReset();
  fetchMock.mockImplementation(async (input, init) => {
    const url = new URL(String(input));
    if (url.pathname === "/api/me") return json(context([1, 2]));
    const tenant = Number(new Headers(init?.headers).get("X-Establecimiento-Id"));
    const counts: Record<string, number> = { pendiente: 2, vencida: 3, abierta: 4, en_proceso: 5 };
    return json(collection([], tenant * (counts[url.searchParams.get("estado") ?? ""] ?? 6)), status);
  });
});
function Content() {
  const { establecimientoActual, seleccionarEstablecimiento } = useEstablecimiento();
  const { isAuthenticated } = useAuth();
  return isAuthenticated ? <><button onClick={() => seleccionarEstablecimiento(2)}>Cambiar</button><EstablecimientoGate>{establecimientoActual && <Indicadores tenantId={establecimientoActual.id} />}</EstablecimientoGate></> : <p>Sin sesión privada</p>;
}
function setup() { render(<SessionProvider><Content /></SessionProvider>); }

it("cuenta controles, registros e incidencias no resueltas con filtros de día y tenant", async () => {
  setup();
  expect(await screen.findByText("9")).toBeInTheDocument();
  for (const [label, count] of [["Controles pendientes hoy", "2"], ["Controles vencidos", "3"], ["Registros realizados hoy", "6"], ["Incidencias sin resolver", "9"]]) expect(within(screen.getByRole("region", { name: label })).getByText(count)).toBeInTheDocument();
  const requests = fetchMock.mock.calls.filter(([input]) => new URL(String(input)).pathname !== "/api/me");
  expect(requests).toHaveLength(5);
  for (const [input, init] of requests) {
    const url = new URL(String(input));
    expect(url.searchParams.get("itemsPerPage")).toBe("1");
    expect(new Headers(init?.headers).get("X-Establecimiento-Id")).toBe("1");
    if (url.pathname === "/api/registros") {
      expect(url.searchParams.get("fechaHora[after]")).toBe(dayBoundary(localDay(new Date()), false));
      expect(url.searchParams.get("fechaHora[before]")).toBe(dayBoundary(localDay(new Date()), true));
    }
  }
  expect(screen.getByRole("link", { name: "Consultar registros" })).toHaveAttribute("href", "/registros");
});

it.each([403, 404, 500, 503])("%s oculta cifras y permite recuperarse", async (code) => {
  status = code; setup();
  expect(await screen.findByRole("alert")).toBeInTheDocument();
  expect(screen.queryByText("9")).not.toBeInTheDocument();
  status = 200;
  fireEvent.click(screen.getByRole("button", { name: "Actualizar resumen" }));
  expect(await screen.findByText("9")).toBeInTheDocument();
});

it("401 elimina la sesión y todos los indicadores", async () => {
  status = 401; setup();
  await waitFor(() => expect(storage.getToken()).toBeNull());
  expect(screen.queryByRole("region", { name: "Resumen operativo" })).not.toBeInTheDocument();
});

it.each([200, 401])("respuesta tardía %s tras cambio no contamina cifras ni sesión", async (code) => {
  const pending = deferred<Response>();
  fetchMock.mockResolvedValueOnce(json(context([1, 2]))).mockReturnValueOnce(pending.promise);
  setup();
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(6));
  const oldSignal = fetchMock.mock.calls[1][1]?.signal;
  fireEvent.click(screen.getByRole("button", { name: "Cambiar" }));
  expect(await screen.findByText("18")).toBeInTheDocument();
  await act(async () => pending.resolve(json(collection([], 999), code)));
  expect(oldSignal?.aborted).toBe(true);
  expect(screen.queryByText("999")).not.toBeInTheDocument();
  expect(storage.getToken()).toBe("dashboard-session");
});

it("rechaza colecciones de otro establecimiento y totales ausentes", async () => {
  fetchMock.mockResolvedValueOnce(json(context())).mockResolvedValueOnce(json(collection([scheduled(1, 2)], 1)));
  setup();
  expect(await screen.findByRole("alert")).toBeInTheDocument();
  fetchMock.mockImplementation(async () => json({ member: [] }));
  fireEvent.click(screen.getByRole("button", { name: "Actualizar resumen" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("total válido");
});
