import { beforeEach, expect, it, vi } from "vitest";
import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import { SessionProvider } from "@/providers/session-provider";
import { IncidenciasScreen } from "@/features/incidencias/incidencias-screen";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { useAuth } from "@/hooks/use-auth";
import { browserSessionStorage as storage } from "@/lib/auth/storage";
import type { RolEstablecimiento } from "@/types/session";
import type { EstadoIncidencia } from "@/features/incidencias/contracts";
import { context, deferred, json } from "./fixtures";
import { collection, person, task } from "./agenda-fixtures";
import {
  accion,
  historial,
  incidencia,
  registro,
} from "./incidencias-fixtures";

const fetchMock = vi.fn<typeof fetch>();
let rol: RolEstablecimiento;
let ids: number[];
let estado: EstadoIncidencia;
let actions: ReturnType<typeof accion>[];
let history: ReturnType<typeof historial>[];
let overrides: Map<string, (url: URL, init?: RequestInit) => Promise<Response>>;

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  vi.stubEnv("NEXT_PUBLIC_API_URL", "http://api.example.test");
  fetchMock.mockReset();
  storage.setToken("session-token");
  storage.setEstablecimiento(1);
  rol = "responsable";
  ids = [1];
  estado = "abierta";
  actions = [];
  history = [historial()];
  overrides = new Map();
  fetchMock.mockImplementation(async (input, init) => {
    const url = new URL(String(input)),
      path = url.pathname;
    const method = init?.method ?? "GET";
    const override = overrides.get(`${method} ${path}`);
    if (override) return override(url, init);
    if (path === "/api/me") {
      const me = context(ids);
      me.membresias.forEach((m) => (m.rol = rol));
      return json(me);
    }
    const tenant = Number(
      new Headers(init?.headers).get("X-Establecimiento-Id"),
    );
    if (method === "PATCH" && path === "/api/incidencias/11") {
      const previous = estado;
      estado = JSON.parse(String(init?.body)).estado;
      history.push(historial(history.length + 1, previous, estado));
      return json(incidencia(11, tenant, estado));
    }
    if (method === "POST" && path === "/api/acciones-correctivas") {
      const input = JSON.parse(String(init?.body));
      const item = { ...accion(actions.length + 1), ...input };
      actions.push(item);
      return json(item, 201);
    }
    if (path === "/api/incidencias")
      return json(collection([incidencia(11, tenant, estado)]));
    if (path === "/api/incidencias/11")
      return json(incidencia(11, tenant, estado));
    if (path === "/api/incidencias/11/acciones")
      return json(collection(actions));
    if (path === "/api/incidencias/11/historial")
      return json(collection(history));
    if (path === "/api/registros/8") return json(registro(tenant));
    if (path === "/api/tareas/1") return json(task(1, tenant));
    if (path === "/api/usuarios/5") return json(person());
    throw new Error(`Unexpected: ${method} ${path}`);
  });
});

function SessionControls() {
  const { seleccionarEstablecimiento } = useEstablecimiento();
  const { error, status } = useAuth();
  return (
    <>
      <button onClick={() => seleccionarEstablecimiento(2)}>
        Cambiar establecimiento
      </button>
      <p>{status}</p>
      {error && <p role="alert">{error.message}</p>}
    </>
  );
}
function mount() {
  return render(
    <SessionProvider>
      <SessionControls />
      <IncidenciasScreen />
    </SessionProvider>,
  );
}
async function open() {
  mount();
  fireEvent.click(
    await screen.findByRole("button", { name: "Abrir incidencia #11" }),
  );
  await screen.findByRole("region", { name: "Registro APPCC de origen" });
}
function detail() {
  return within(screen.getByRole("region", { name: "Detalle de incidencia" }));
}
function calls(path: string, method = "GET") {
  return fetchMock.mock.calls.filter(
    ([url, init]) =>
      new URL(String(url)).pathname === path &&
      (init?.method ?? "GET") === method,
  );
}
function fillAction() {
  fireEvent.change(screen.getByLabelText("Descripción de la acción"), {
    target: { value: " Ajustar equipo " },
  });
  fireEvent.change(screen.getByLabelText("Resultado de la acción (opcional)"), {
    target: { value: " Temperatura estable " },
  });
}
function saveAction() {
  fireEvent.click(
    screen.getByRole("button", { name: "Guardar acción correctiva" }),
  );
}
async function loaded() {
  await waitFor(() =>
    expect(
      screen.getByRole("region", { name: "Detalle de incidencia" }),
    ).toHaveAttribute("aria-busy", "false"),
  );
}

it.each(["admin", "responsable", "trabajador", "auditor"] as const)(
  "%s: consulta y permisos exactos en detalle",
  async (role) => {
    rol = role;
    await open();
    expect(detail().getByText("Observación original inmutable.")).toBeVisible();
    expect(detail().getByText("9.000")).toBeVisible();
    expect(detail().getByText("L-42")).toBeVisible();
    expect(detail().getByText("Creación → Abierta")).toBeVisible();
    expect(detail().getAllByText(/Persona 5 Prueba/).length).toBeGreaterThan(0);
    for (const name of ["Resolver incidencia", "Poner en proceso"]) {
      expect(detail().queryByRole("button", { name }) !== null).toBe(
        ["admin", "responsable"].includes(role),
      );
    }
    expect(
      detail().queryByRole("button", { name: "Guardar acción correctiva" }) !==
        null,
    ).toBe(role !== "auditor");
    if (role === "auditor")
      expect(
        detail().getByText("Acceso de auditor: solo lectura."),
      ).toBeVisible();
    expect(calls("/api/incidencias/11", "PATCH")).toHaveLength(0);
    expect(calls("/api/acciones-correctivas", "POST")).toHaveLength(0);
  },
);

it("filtros combinados, fechas con zona, paginación y reinicio de página", async () => {
  overrides.set("GET /api/incidencias", async () =>
    json(collection([incidencia()], 41, "/api/incidencias?page=2")),
  );
  mount();
  await screen.findByRole("button", { name: "Abrir incidencia #11" });
  fireEvent.click(screen.getByRole("button", { name: "Siguiente" }));
  await screen.findByText("Página 2 · 41 resultados");
  const set = (label: string, value: string) =>
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
  set("Estado", "en_proceso");
  set("Gravedad", "alta");
  set("Registro de origen (número)", "8");
  set("Apertura desde", "2026-09-01");
  set("Apertura hasta", "2026-09-24");
  set("Orden de apertura", "asc");
  fireEvent.click(screen.getByRole("button", { name: "Aplicar filtros" }));
  await screen.findByText("Página 1 · 41 resultados");
  const params = new URL(String(calls("/api/incidencias").at(-1)![0]))
    .searchParams;
  expect(Object.fromEntries(params)).toMatchObject({
    page: "1",
    itemsPerPage: "20",
    estado: "en_proceso",
    gravedad: "alta",
    registro: "/api/registros/8",
    "order[fechaApertura]": "asc",
  });
  for (const key of ["fechaApertura[after]", "fechaApertura[before]"])
    expect(params.get(key)).toMatch(/T\d{2}:\d{2}:\d{2}Z$/);
  fireEvent.click(screen.getByRole("button", { name: "Limpiar filtros" }));
  await waitFor(() =>
    expect(
      new URL(String(calls("/api/incidencias").at(-1)![0])).searchParams.has(
        "registro",
      ),
    ).toBe(false),
  );
});

it("filtros inválidos no consultan y una consulta vacía ofrece recuperación", async () => {
  overrides.set("GET /api/incidencias", async () => json(collection([])));
  mount();
  await screen.findByText("No hay incidencias en esta consulta");
  fireEvent.change(screen.getByLabelText("Registro de origen (número)"), {
    target: { value: "-4" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Aplicar filtros" }));
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "número de registro válido",
  );
  expect(calls("/api/incidencias")).toHaveLength(1);
});

it("pagina acciones e historial por separado sin truncar los históricos", async () => {
  overrides.set("GET /api/incidencias/11/acciones", async (url) =>
    json(
      collection([accion(url.searchParams.get("page") === "2" ? 21 : 1)], 21),
    ),
  );
  overrides.set("GET /api/incidencias/11/historial", async (url) =>
    json(
      collection(
        [
          historial(
            url.searchParams.get("page") === "2" ? 21 : 1,
            "abierta",
            "en_proceso",
          ),
        ],
        21,
      ),
    ),
  );
  await open();
  fireEvent.click(
    within(
      screen.getByRole("navigation", {
        name: "Paginación de acciones correctivas",
      }),
    ).getByRole("button", { name: "Siguiente" }),
  );
  await screen.findByText("Acción 21");
  expect(
    new URL(
      String(calls("/api/incidencias/11/historial").at(-1)![0]),
    ).searchParams.get("page"),
  ).toBe("1");
  fireEvent.click(
    within(
      screen.getByRole("navigation", { name: "Paginación de historial" }),
    ).getByRole("button", { name: "Siguiente" }),
  );
  await loaded();
  expect(
    new URL(
      String(calls("/api/incidencias/11/historial").at(-1)![0]),
    ).searchParams.get("page"),
  ).toBe("2");
  expect(
    new URL(
      String(calls("/api/incidencias/11/acciones").at(-1)![0]),
    ).searchParams.get("page"),
  ).toBe("2");
});

it("incidencia manual sin registro y autor histórico no accesible", async () => {
  overrides.set("GET /api/incidencias/11", async () =>
    json({ ...incidencia(), registro: null }),
  );
  overrides.set("GET /api/usuarios/5", async () => json({}, 404));
  await open();
  expect(
    detail().getByText("Esta incidencia no tiene un registro APPCC asociado."),
  ).toBeVisible();
  expect(detail().getByText(/Usuario #5 \(sin acceso actual\)/)).toBeVisible();
  expect(calls("/api/registros/8")).toHaveLength(0);
});

it("acción: POST mínimo, JWT y tenant, sin duplicar envío y relectura completa", async () => {
  rol = "trabajador";
  const pending = deferred<Response>();
  overrides.set("POST /api/acciones-correctivas", () => pending.promise);
  await open();
  fillAction();
  saveAction();
  saveAction();
  expect(calls("/api/acciones-correctivas", "POST")).toHaveLength(1);
  expect(
    screen.getByRole("button", { name: "Guardar acción correctiva" }),
  ).toBeDisabled();
  const init = calls("/api/acciones-correctivas", "POST")[0][1]!;
  expect(JSON.parse(String(init.body))).toEqual({
    incidencia: "/api/incidencias/11",
    descripcion: "Ajustar equipo",
    resultado: "Temperatura estable",
  });
  const headers = new Headers(init.headers);
  expect(headers.get("Authorization")).toBe("Bearer session-token");
  expect(headers.get("X-Establecimiento-Id")).toBe("1");
  actions.push({ ...accion(), descripcion: "Acción confirmada por servidor" });
  await act(async () =>
    pending.resolve(
      json({ id: 99, descripcion: "Respuesta de POST no usada" }, 201),
    ),
  );
  await screen.findByText("Acción confirmada por servidor");
  expect(
    detail().queryByText("Respuesta de POST no usada"),
  ).not.toBeInTheDocument();
  for (const path of [
    "/api/incidencias",
    "/api/incidencias/11",
    "/api/registros/8",
    "/api/incidencias/11/acciones",
    "/api/incidencias/11/historial",
  ])
    expect(calls(path).length).toBeGreaterThanOrEqual(2);
});

it.each(["en_proceso", "resuelta"] as const)(
  "abierta permite pasar a %s y refleja el historial del servidor",
  async (destino) => {
    await open();
    fireEvent.click(
      screen.getByRole("button", {
        name:
          destino === "en_proceso" ? "Poner en proceso" : "Resolver incidencia",
      }),
    );
    await screen.findByText(
      `Abierta → ${destino === "en_proceso" ? "En proceso" : "Resuelta"}`,
    );
    const init = calls("/api/incidencias/11", "PATCH")[0][1]!;
    expect(JSON.parse(String(init.body))).toEqual({ estado: destino });
    expect(new Headers(init.headers).get("Content-Type")).toBe(
      "application/merge-patch+json",
    );
    expect(
      detail().queryByRole("button", { name: "Poner en proceso" }),
    ).not.toBeInTheDocument();
    if (destino === "resuelta") {
      expect(
        detail().queryByRole("button", { name: "Resolver incidencia" }),
      ).not.toBeInTheDocument();
      expect(
        detail().queryByRole("button", { name: "Guardar acción correctiva" }),
      ).not.toBeInTheDocument();
    }
  },
);

it("en proceso solo ofrece resolver y resuelta permanece sin escrituras", async () => {
  estado = "en_proceso";
  await open();
  expect(
    detail().queryByRole("button", { name: "Poner en proceso" }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Resolver incidencia" }));
  await screen.findByText("En proceso → Resuelta");
  expect(
    detail().queryByRole("button", {
      name: /Resolver|Reabrir|Guardar acción|Poner en proceso/,
    }),
  ).not.toBeInTheDocument();
});

it("422 del cierre queda en Symfony, muestra mensaje/violations y permite añadir acción", async () => {
  overrides.set("PATCH /api/incidencias/11", async () =>
    json(
      {
        detail: "Se necesita una acción correctiva para cerrar.",
        violations: [{ propertyPath: "estado", message: "Añade una acción." }],
      },
      422,
    ),
  );
  await open();
  fireEvent.click(screen.getByRole("button", { name: "Resolver incidencia" }));
  await loaded();
  expect(detail().getByRole("alert")).toHaveTextContent(
    "Se necesita una acción correctiva para cerrar.",
  );
  expect(detail().getByRole("alert")).toHaveTextContent(
    "estado: Añade una acción.",
  );
  expect(
    screen.getByRole("button", { name: "Guardar acción correctiva" }),
  ).toBeEnabled();
  expect(detail().getByText("Abierta", { exact: true })).toBeVisible();
});

it("conserva el borrador de acción tras validación 422 y consulta posterior", async () => {
  overrides.set("POST /api/acciones-correctivas", async () =>
    json({ detail: "Descripción no válida." }, 422),
  );
  await open();
  fillAction();
  saveAction();
  await loaded();
  expect(screen.getByLabelText("Descripción de la acción")).toHaveValue(
    " Ajustar equipo ",
  );
  expect(
    screen.getByLabelText("Resultado de la acción (opcional)"),
  ).toHaveValue(" Temperatura estable ");
});

it.each([403, 404, 409, 422, 0])(
  "error %s al consultar muestra recuperación explícita",
  async (status) => {
    overrides.set("GET /api/incidencias", async () => {
      if (!status) throw new TypeError("offline");
      return json({}, status);
    });
    mount();
    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent(
      {
        403: "permisos",
        404: "No se ha encontrado",
        409: "conflicto",
        422: "validar",
        0: "conectar",
      }[status]!,
    );
    if ([403, 404].includes(status))
      expect(
        screen.getByRole("button", { name: "Actualizar mis accesos" }),
      ).toBeVisible();
    else if (status !== 422)
      expect(
        screen.getByRole("button", { name: "Volver a intentar" }),
      ).toBeVisible();
    expect(
      screen.queryByRole("button", { name: "Abrir incidencia #11" }),
    ).not.toBeInTheDocument();
  },
);

it.each(["GET", "PATCH", "POST"])(
  "401 en %s invalida JWT y retira el contenido privado",
  async (method) => {
    if (method === "GET") {
      overrides.set("GET /api/incidencias", async () => json({}, 401));
      mount();
    } else {
      overrides.set(
        `${method} ${method === "POST" ? "/api/acciones-correctivas" : "/api/incidencias/11"}`,
        async () => json({}, 401),
      );
      await open();
      if (method === "POST") {
        fillAction();
        saveAction();
      } else
        fireEvent.click(
          screen.getByRole("button", { name: "Poner en proceso" }),
        );
    }
    await waitFor(() => expect(storage.getToken()).toBeNull());
    expect(screen.getByRole("alert")).toHaveTextContent("sesión ha caducado");
    expect(
      screen.queryByRole("region", { name: "Detalle de incidencia" }),
    ).not.toBeInTheDocument();
  },
);

it.each([403, 404, 409, 422, 0, 500])(
  "escritura fallida %s: consulta sin reenvío automático y trata incertidumbre",
  async (status) => {
    overrides.set("POST /api/acciones-correctivas", async () => {
      if (!status) throw new TypeError("offline");
      return json({}, status);
    });
    await open();
    fillAction();
    saveAction();
    await loaded();
    expect(detail().getByRole("alert")).toBeVisible();
    expect(calls("/api/incidencias/11")).toHaveLength(2);
    expect(calls("/api/acciones-correctivas", "POST")).toHaveLength(1);
    if ([0, 409, 500].includes(status)) {
      expect(
        screen.getByRole("button", { name: "Guardar acción correctiva" }),
      ).toBeDisabled();
      expect(screen.getByText(/La operación pudo completarse/)).toBeVisible();
      fireEvent.click(
        screen.getByRole("button", {
          name: "Volver a consultar la incidencia",
        }),
      );
      await loaded();
      expect(
        screen.getByRole("button", { name: "Guardar acción correctiva" }),
      ).toBeEnabled();
    }
  },
);

it("no permite otra escritura si falla la relectura posterior al guardado", async () => {
  await open();
  overrides.set("GET /api/incidencias/11", async () => json({}, 503));
  fillAction();
  saveAction();
  await loaded();
  expect(
    detail().queryByRole("button", { name: "Guardar acción correctiva" }),
  ).not.toBeInTheDocument();
  expect(
    detail().queryByText(/Acción correctiva guardada/),
  ).not.toBeInTheDocument();
  expect(detail().getByRole("alert")).toBeVisible();
});

it.each(["incidencia", "registro", "accion"])(
  "rechaza datos de otro tenant/padre en %s",
  async (kind) => {
    const path =
      kind === "incidencia"
        ? "/api/incidencias/11"
        : kind === "registro"
          ? "/api/registros/8"
          : "/api/incidencias/11/acciones";
    overrides.set(`GET ${path}`, async () =>
      json(
        kind === "incidencia"
          ? incidencia(11, 2)
          : kind === "registro"
            ? registro(2)
            : collection([{ ...accion(), incidencia: "/api/incidencias/999" }]),
      ),
    );
    mount();
    fireEvent.click(
      await screen.findByRole("button", { name: "Abrir incidencia #11" }),
    );
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(
      detail().queryByRole("region", { name: "Registro APPCC de origen" }),
    ).not.toBeInTheDocument();
  },
);

it("cambio de tenant cancela listado y descarta incluso un 401 tardío", async () => {
  ids = [1, 2];
  const pending = deferred<Response>();
  let signal: AbortSignal | null = null;
  overrides.set("GET /api/incidencias", async (_url, init) => {
    if (new Headers(init?.headers).get("X-Establecimiento-Id") === "1") {
      signal = init!.signal!;
      return pending.promise;
    }
    return json(collection([incidencia(22, 2)]));
  });
  mount();
  await waitFor(() => expect(signal).not.toBeNull());
  fireEvent.click(
    screen.getByRole("button", { name: "Cambiar establecimiento" }),
  );
  await screen.findByRole("button", { name: "Abrir incidencia #22" });
  expect(signal!.aborted).toBe(true);
  await act(async () => pending.resolve(json({}, 401)));
  expect(storage.getToken()).toBe("session-token");
  expect(
    screen.queryByRole("button", { name: "Abrir incidencia #11" }),
  ).not.toBeInTheDocument();
});

it.each(["detalle", "escritura"])(
  "cambiar tenant durante %s aborta, descarta formulario, selección y filtros",
  async (kind) => {
    ids = [1, 2];
    const pending = deferred<Response>();
    let signal: AbortSignal | null = null;
    if (kind === "detalle")
      overrides.set("GET /api/registros/8", async (_url, init) => {
        signal = init!.signal!;
        return pending.promise;
      });
    else
      overrides.set("POST /api/acciones-correctivas", async (_url, init) => {
        signal = init!.signal!;
        return pending.promise;
      });
    mount();
    await screen.findByRole("button", { name: "Abrir incidencia #11" });
    fireEvent.change(screen.getByLabelText("Gravedad"), {
      target: { value: "alta" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Aplicar filtros" }));
    fireEvent.click(
      await screen.findByRole("button", { name: "Abrir incidencia #11" }),
    );
    if (kind === "escritura") {
      await screen.findByRole("region", { name: "Registro APPCC de origen" });
      fillAction();
      saveAction();
    }
    await waitFor(() => expect(signal).not.toBeNull());
    fireEvent.click(
      screen.getByRole("button", { name: "Cambiar establecimiento" }),
    );
    await screen.findByText("No conformidad 11 del local 2");
    expect(signal!.aborted).toBe(true);
    await act(async () =>
      pending.resolve(json(kind === "detalle" ? registro(1) : accion(), 200)),
    );
    expect(
      screen.queryByRole("region", { name: "Detalle de incidencia" }),
    ).not.toBeInTheDocument();
    expect(screen.getByLabelText("Gravedad")).toHaveValue("");
    expect(
      screen.queryByText(/Acción correctiva guardada/),
    ).not.toBeInTheDocument();
    const call = calls("/api/incidencias").at(-1)!;
    expect(new Headers(call[1]?.headers).get("X-Establecimiento-Id")).toBe("2");
    expect(new URL(String(call[0])).searchParams.has("gravedad")).toBe(false);
  },
);
