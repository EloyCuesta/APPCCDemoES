import { beforeEach, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SessionProvider } from "@/providers/session-provider";
import { LoginScreen } from "@/features/auth/login-screen";
import { AuthGuard } from "@/features/auth/auth-guard";
import { AppShell } from "@/components/layout/app-shell";
import { EstablecimientoGate } from "@/features/establecimientos/establecimiento-gate";
import { DashboardScreen } from "@/features/dashboard/dashboard-screen";
import { browserSessionStorage as storage } from "@/lib/auth/storage";
import { context, deferred, json } from "./fixtures";

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));
const router = { replace };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/dashboard" }));
const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", (input: RequestInfo | URL, init?: RequestInit) => {
    if (["/api/tareas-programadas/agenda", "/api/registros", "/api/incidencias"].includes(new URL(String(input)).pathname)) return Promise.resolve(json({ member: [], totalItems: 0 }));
    return fetchMock(input, init);
  });
  vi.stubEnv("NEXT_PUBLIC_API_URL", "http://api.example.test");
});

function PrivateApp() {
  return (
    <SessionProvider>
      <AuthGuard>
        <AppShell>
          <EstablecimientoGate>
            <DashboardScreen />
          </EstablecimientoGate>
        </AppShell>
      </AuthGuard>
    </SessionProvider>
  );
}

it("permite login con teclado, muestra carga y redirige a dashboard", async () => {
  fetchMock
    .mockResolvedValueOnce(json({ token: "test-token" }))
    .mockResolvedValueOnce(json(context()));
  const user = userEvent.setup();
  render(
    <SessionProvider>
      <LoginScreen />
    </SessionProvider>,
  );
  await user.type(await screen.findByLabelText("Email"), "ana@example.test");
  await user.type(screen.getByLabelText("Contraseña"), "test-password{Enter}");
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/dashboard"));
  expect(storage.getToken()).toBe("test-token");
});

it("muestra login incorrecto en un aviso accesible", async () => {
  fetchMock.mockResolvedValue(json({}, 401));
  const user = userEvent.setup();
  render(
    <SessionProvider>
      <LoginScreen />
    </SessionProvider>,
  );
  await user.type(await screen.findByLabelText("Email"), "ana@example.test");
  await user.type(screen.getByLabelText("Contraseña"), "wrong-password");
  await user.click(screen.getByRole("button", { name: /Entrar a mi espacio/ }));
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "Email o contraseña incorrectos",
  );
  expect(screen.getByLabelText("Contraseña")).toHaveValue("");
  expect(replace).not.toHaveBeenCalled();
});

it("sin sesión redirige al login sin montar contenido privado", async () => {
  render(<PrivateApp />);
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  expect(
    screen.queryByRole("heading", { name: "Dashboard" }),
  ).not.toBeInTheDocument();
});

it("restauración no expone contenido privado mientras /me está pendiente", async () => {
  storage.setToken("restored");
  const pending = deferred<Response>();
  fetchMock.mockReturnValueOnce(pending.promise);
  render(<PrivateApp />);
  expect(screen.getByRole("status")).toHaveTextContent("Restaurando");
  expect(screen.queryByText("Tu sesión")).not.toBeInTheDocument();
  fetchMock.mockResolvedValue(json({ id: 1, nombre: "Establecimiento 1" }));
  pending.resolve(json(context()));
  expect(
    await screen.findByRole("heading", { name: "Dashboard" }),
  ).toBeInTheDocument();
  expect(await screen.findByText("Conexión verificada")).toBeInTheDocument();
});

it("selecciona establecimiento, desmonta datos anteriores y cierra sesión", async () => {
  storage.setToken("restored");
  fetchMock
    .mockResolvedValueOnce(json(context([1, 2])))
    .mockImplementation(async (url) => {
      const id = String(url).endsWith("/2") ? 2 : 1;
      return json({ id, nombre: `Establecimiento ${id}` });
    });
  const user = userEvent.setup();
  render(<PrivateApp />);
  expect(
    await screen.findByRole("heading", { name: "¿Dónde vas a trabajar hoy?" }),
  ).toBeInTheDocument();
  const selector = screen.getAllByRole("combobox")[0];
  await user.selectOptions(selector, "1");
  expect(
    await screen.findByRole("heading", { name: "Establecimiento 1" }),
  ).toBeInTheDocument();
  expect(await screen.findByText("Conexión verificada")).toBeInTheDocument();
  const pending = deferred<Response>();
  fetchMock.mockReturnValueOnce(pending.promise);
  await user.selectOptions(screen.getByRole("combobox"), "2");
  expect(
    screen.queryByRole("heading", { name: "Establecimiento 1" }),
  ).not.toBeInTheDocument();
  expect(screen.queryByText("Conexión verificada")).not.toBeInTheDocument();
  expect(screen.getByText("Comprobando el acceso al establecimiento…")).toBeInTheDocument();
  pending.resolve(json({ id: 2, nombre: "Establecimiento 2" }));
  expect(await screen.findByText("Conexión verificada")).toBeInTheDocument();
  await user.click(screen.getByRole("button", { name: "Cerrar sesión" }));
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  expect(storage.getToken()).toBeNull();
  expect(storage.getEstablecimiento()).toBeNull();
  expect(
    screen.queryByRole("heading", { name: "Dashboard" }),
  ).not.toBeInTheDocument();
});

it("403 operativo muestra permisos y permite actualizar accesos", async () => {
  storage.setToken("restored");
  fetchMock
    .mockResolvedValueOnce(json(context()))
    .mockResolvedValueOnce(json({}, 403));
  render(<PrivateApp />);
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "No tienes permisos",
  );
  expect(
    screen.getByRole("button", { name: "Actualizar mis accesos" }),
  ).toBeInTheDocument();
});

it("401 operativo desmonta el dashboard y redirige al login", async () => {
  storage.setToken("restored");
  fetchMock
    .mockResolvedValueOnce(json(context()))
    .mockResolvedValueOnce(json({}, 401));
  render(<PrivateApp />);
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  expect(
    screen.queryByRole("heading", { name: "Dashboard" }),
  ).not.toBeInTheDocument();
  expect(storage.getToken()).toBeNull();
});

it("sin membresías presenta un estado útil y permite cerrar sesión", async () => {
  storage.setToken("restored");
  fetchMock.mockResolvedValue(json(context([])));
  render(<PrivateApp />);
  expect(
    await screen.findByRole("heading", {
      name: "Sin establecimientos disponibles",
    }),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Cerrar sesión" }));
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
});
