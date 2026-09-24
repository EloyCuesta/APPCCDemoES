import { beforeEach, expect, it, vi } from "vitest";
import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { SessionProvider } from "@/providers/session-provider";
import { AgendaScreen } from "@/features/agenda/agenda-screen";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { browserSessionStorage as storage } from "@/lib/auth/storage";
import type { RolEstablecimiento } from "@/types/session";
import { context, deferred, json } from "./fixtures";
import { collection, plan, scheduled, task } from "./agenda-fixtures";

const fetchMock = vi.fn<typeof fetch>();
const token = "a".repeat(64);
let tipo: "numero" | "boolean" | "campos";
let rol: RolEstablecimiento;
let registered: boolean;
let write: () => Promise<Response>;
let upload: () => Promise<Response>;
let ids: number[];

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  vi.stubEnv("NEXT_PUBLIC_API_URL", "http://api.example.test");
  fetchMock.mockReset();
  tipo = "numero";
  rol = "trabajador";
  registered = false;
  ids = [1];
  storage.setToken("session-token");
  write = async () => {
    registered = true;
    return json(
      { id: 10, tareaProgramada: "/api/tareas-programadas/1", conforme: true },
      201,
    );
  };
  upload = async () =>
    json(
      { token, nombreOriginal: "foto.png", expiresAt: "2099-01-01T00:00:00Z" },
      201,
    );
  fetchMock.mockImplementation(async (url, init) => {
    const path = new URL(String(url)).pathname;
    if (path === "/api/me") {
      const me = context(ids);
      me.membresias.forEach((m) => (m.rol = rol));
      return json(me);
    }
    const tenant = Number(
      new Headers(init?.headers).get("X-Establecimiento-Id"),
    );
    if (path === "/api/registros") return write();
    if (path === "/api/evidencias/subidas") return upload();
    if (path === "/api/tareas-programadas/agenda")
      return json(collection(registered ? [] : [scheduled(1, tenant)]));
    if (path === "/api/tareas-programadas/1") return json(scheduled(1, tenant));
    if (path === "/api/tareas/1")
      return json({
        ...task(1, tenant),
        instrucciones: "Compruebe la cámara.",
        unidad: tipo === "numero" ? "°C" : null,
        ...(tipo === "numero"
          ? { limiteMinimo: "0.000", limiteMaximo: "5.000" }
          : {}),
        configuracion:
          tipo === "campos"
            ? {
                campos: [
                  "proveedor",
                  "producto",
                  "lote",
                  "condicionesRecepcion",
                ],
              }
            : { tipoRespuesta: tipo },
      });
    if (path === `/api/planes-control/${tenant}`)
      return json(plan(tenant, tenant));
    if (path === `/api/establecimientos/${tenant}`) return json({ id: tenant });
    if (path === "/api/configuraciones-establecimiento")
      return json(
        collection([
          {
            id: tenant,
            establecimiento: `/api/establecimientos/${tenant}`,
            requiereObservacionNoConforme: true,
          },
        ]),
      );
    throw new Error(`Unexpected path: ${path}`);
  });
});

function SwitchTenant() {
  const { seleccionarEstablecimiento } = useEstablecimiento();
  return (
    <button onClick={() => seleccionarEstablecimiento(2)}>
      Cambiar establecimiento
    </button>
  );
}
function mount() {
  return render(
    <SessionProvider>
      <SwitchTenant />
      <AgendaScreen />
    </SessionProvider>,
  );
}
async function open() {
  mount();
  fireEvent.click(
    await screen.findByRole("button", { name: "Registrar control" }),
  );
  await screen.findByRole("heading", { name: "Control 1" });
}
function confirm() {
  fireEvent.click(screen.getByRole("checkbox", { name: /Confirmo que/ }));
}
function submit() {
  fireEvent.click(
    screen.getByRole("button", { name: "Confirmar y registrar control" }),
  );
}
function posts(path = "/api/registros") {
  return fetchMock.mock.calls.filter(
    ([url, init]) => String(url).endsWith(path) && init?.method === "POST",
  );
}
function body() {
  return JSON.parse(String(posts()[0][1]?.body));
}
function numeric(value = "3.5") {
  fireEvent.change(screen.getByLabelText("Valor numérico (°C)"), {
    target: { value },
  });
}
function observations() {
  fireEvent.change(screen.getByLabelText(/Observaciones/), {
    target: { value: "Lectura comprobada." },
  });
}

it.each(["admin", "responsable", "trabajador"] as const)(
  "%s puede registrar desde Agenda",
  async (role) => {
    rol = role;
    mount();
    expect(
      await screen.findByRole("button", { name: "Registrar control" }),
    ).toBeEnabled();
  },
);

it("AUDITOR consulta sin acciones operativas", async () => {
  rol = "auditor";
  mount();
  await screen.findByRole("heading", { name: "Control 1" });
  expect(
    screen.queryByRole("button", { name: "Registrar control" }),
  ).not.toBeInTheDocument();
  expect(posts()).toHaveLength(0);
});

it("numérico conforme sin fotografía: 201, confirmarRegistro y regreso con nueva consulta", async () => {
  await open();
  expect(screen.getByText("Compruebe la cámara.")).toBeVisible();
  expect(screen.getByText(/Mínimo: 0.000 · Máximo: 5.000/)).toBeVisible();
  numeric("3,500");
  observations();
  confirm();
  submit();
  await screen.findByText(/Control registrado y confirmado correctamente/);
  await screen.findByText("No hay controles en esta consulta");
  expect(body()).toEqual({
    tareaProgramada: "/api/tareas-programadas/1",
    valorNumerico: "3.500",
    observaciones: "Lectura comprobada.",
    evidencias: [],
    confirmarRegistro: true,
  });
  const headers = new Headers(posts()[0][1]?.headers);
  expect(headers.get("Authorization")).toBe("Bearer session-token");
  expect(headers.get("X-Establecimiento-Id")).toBe("1");
  expect(posts()).toHaveLength(1);
  expect(
    screen.queryByRole("button", { name: "Registrar control" }),
  ).not.toBeInTheDocument();
});

it("no envía sin confirmación ni con decimal fuera del contrato", async () => {
  await open();
  numeric();
  submit();
  expect(posts()).toHaveLength(0);
  numeric("1.1234");
  confirm();
  submit();
  expect(await screen.findByRole("alert")).toHaveTextContent("3 decimales");
  expect(posts()).toHaveLength(0);
});

it("booleano envía datos.resultado como boolean y deja la conformidad a Symfony", async () => {
  tipo = "boolean";
  await open();
  fireEvent.change(screen.getByLabelText("Resultado", { exact: true }), {
    target: { value: "true" },
  });
  confirm();
  submit();
  await screen.findByText(/Control registrado y confirmado correctamente/);
  expect(body().datos).toEqual({ resultado: true });
  expect(body()).not.toHaveProperty("conforme");
});

it("campos reales de recepción obligatorios y conformidad manual explícita", async () => {
  tipo = "campos";
  await open();
  confirm();
  submit();
  expect(posts()).toHaveLength(0);
  for (const campo of [
    "proveedor",
    "producto",
    "lote",
    "condicionesRecepcion",
  ]) {
    expect(screen.getByLabelText(campo)).toBeRequired();
    fireEvent.change(screen.getByLabelText(campo), {
      target: { value: `Valor ${campo}` },
    });
  }
  fireEvent.change(screen.getByLabelText("Conformidad (evaluación manual)"), {
    target: { value: "true" },
  });
  confirm();
  submit();
  await screen.findByText(/Control registrado y confirmado correctamente/);
  expect(body().conforme).toBe(true);
  expect(body().datos.lote).toBe("Valor lote");
});

it("no conforme solicita fotografía, sube multipart y usa exclusivamente token y tipo", async () => {
  await open();
  numeric("9");
  observations();
  confirm();
  submit();
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "fotografía válida",
  );
  expect(
    screen.getByText(/Previsión: no conforme. Se requiere una fotografía/),
  ).toBeVisible();
  expect(posts()).toHaveLength(0);
  fireEvent.change(screen.getByLabelText("Subir fotografía"), {
    target: { files: [new File(["png"], "foto.png", { type: "image/png" })] },
  });
  await screen.findByText("foto.png · Fotografía");
  const init = posts("/api/evidencias/subidas")[0][1]!;
  expect(init.body).toBeInstanceOf(FormData);
  expect(new Headers(init.headers).has("Content-Type")).toBe(false);
  expect((init.body as FormData).get("tipo")).toBe("foto");
  expect((init.body as FormData).get("archivo")).toBeInstanceOf(File);
  expect(JSON.stringify(sessionStorage)).not.toContain(token);
  expect(JSON.stringify(localStorage)).not.toContain(token);
  confirm();
  submit();
  await screen.findByText(/Control registrado y confirmado correctamente/);
  expect(body().evidencias).toEqual([{ token, tipo: "foto" }]);
  expect(body()).not.toHaveProperty("conforme");
});

it("un documento PDF no sustituye la fotografía de una no conformidad", async () => {
  tipo = "boolean";
  await open();
  fireEvent.change(screen.getByLabelText("Resultado", { exact: true }), {
    target: { value: "false" },
  });
  observations();
  fireEvent.change(screen.getByLabelText("Subir documento PDF"), {
    target: {
      files: [new File(["pdf"], "parte.pdf", { type: "application/pdf" })],
    },
  });
  await screen.findByText("foto.png · Documento");
  confirm();
  submit();
  expect(await screen.findByRole("alert")).toHaveTextContent("fotografía");
  expect(posts()).toHaveLength(0);
});

it.each([403, 404, 409, 422, 500])(
  "muestra error HTTP %i sin reintentar la escritura",
  async (status) => {
    write = async () =>
      json(
        {
          detail:
            "El registro no conforme requiere al menos una fotografía válida.",
        },
        status,
      );
    await open();
    numeric();
    confirm();
    submit();
    const alert = await screen.findByRole("alert");
    const expected: Record<number, string> = {
      403: "No tienes permisos",
      404: "No se ha encontrado",
      409: "registrado por otra sesión",
      422: "fotografía válida",
      500: "servicio no está disponible",
    };
    expect(alert).toHaveTextContent(expected[status]);
    expect(posts()).toHaveLength(1);
    expect(
      screen.getByRole("button", { name: "Volver a consultar la Agenda" }),
    ).toBeEnabled();
    if (status === 409 || status === 500)
      expect(
        screen.getByRole("button", { name: "Confirmar y registrar control" }),
      ).toBeDisabled();
  },
);

it("red deja resultado incierto y obliga a consultar antes de otra escritura", async () => {
  write = async () => {
    throw new TypeError("Failed to fetch");
  };
  await open();
  numeric();
  confirm();
  submit();
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "No se pudo conectar",
  );
  expect(posts()).toHaveLength(1);
  expect(
    screen.getByRole("button", { name: "Confirmar y registrar control" }),
  ).toBeDisabled();
});

it("401 descarta formulario y sesión", async () => {
  write = async () => json({}, 401);
  await open();
  numeric();
  confirm();
  submit();
  await waitFor(() => expect(storage.getToken()).toBeNull());
  expect(
    screen.queryByLabelText("Valor numérico (°C)"),
  ).not.toBeInTheDocument();
});

it.each(["subida", "registro"])(
  "cambiar tenant cancela %s e ignora respuestas tardías",
  async (operation) => {
    ids = [1, 2];
    storage.setEstablecimiento(1);
    const pending = deferred<Response>();
    if (operation === "subida") upload = () => pending.promise;
    else write = () => pending.promise;
    await open();
    if (operation === "subida")
      fireEvent.change(screen.getByLabelText("Subir fotografía"), {
        target: {
          files: [new File(["png"], "foto.png", { type: "image/png" })],
        },
      });
    else {
      numeric();
      confirm();
      submit();
    }
    const path =
      operation === "subida" ? "/api/evidencias/subidas" : "/api/registros";
    await waitFor(() => expect(posts(path)).toHaveLength(1));
    const signal = posts(path)[0][1]!.signal!;
    fireEvent.click(
      screen.getByRole("button", { name: "Cambiar establecimiento" }),
    );
    expect(signal.aborted).toBe(true);
    await act(async () =>
      pending.resolve(
        json(
          operation === "subida"
            ? {
                token,
                nombreOriginal: "foto.png",
                expiresAt: "2099-01-01T00:00:00Z",
              }
            : { id: 10, tareaProgramada: "/api/tareas-programadas/1" },
          201,
        ),
      ),
    );
    await screen.findByText(
      "Controles pendientes y vencidos de Establecimiento 2.",
    );
    expect(
      screen.queryByText(/Control registrado y confirmado/),
    ).not.toBeInTheDocument();
    fireEvent.click(
      await screen.findByRole("button", { name: "Registrar control" }),
    );
    await screen.findByLabelText("Valor numérico (°C)");
    expect(screen.queryByText("foto.png · Fotografía")).not.toBeInTheDocument();
  },
);

it("salir del formulario descarta los tokens y no completa la ejecución", async () => {
  await open();
  fireEvent.change(screen.getByLabelText("Subir fotografía"), {
    target: { files: [new File(["png"], "foto.png", { type: "image/png" })] },
  });
  await screen.findByText("foto.png · Fotografía");
  fireEvent.click(
    screen.getByRole("button", { name: "Volver a consultar la Agenda" }),
  );
  fireEvent.click(
    await screen.findByRole("button", { name: "Registrar control" }),
  );
  await screen.findByLabelText("Valor numérico (°C)");
  expect(screen.queryByText("foto.png · Fotografía")).not.toBeInTheDocument();
  expect(posts()).toHaveLength(0);
});
