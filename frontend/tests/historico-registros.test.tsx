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
import { HistoricoScreen } from "@/features/registros/historico-screen";
import {
  filtersError,
  emptyFilters,
} from "@/features/registros/historico-query";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { useAuth } from "@/hooks/use-auth";
import { browserSessionStorage as storage } from "@/lib/auth/storage";
import { context, deferred, json } from "./fixtures";
import { collection, person, task } from "./agenda-fixtures";
import { evidence, historicalRecord } from "./historico-fixtures";

const fetchMock = vi.fn<typeof fetch>();
let ids: number[];
let overrides: Map<string, (url: URL, init?: RequestInit) => Promise<Response>>;

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  vi.stubEnv("NEXT_PUBLIC_API_URL", "http://api.example.test");
  fetchMock.mockReset();
  storage.setToken("session-token");
  storage.setEstablecimiento(1);
  ids = [1];
  overrides = new Map();
  fetchMock.mockImplementation(async (input, init) => {
    const url = new URL(String(input)),
      path = url.pathname;
    const override = overrides.get(path);
    if (override) return override(url, init);
    if (path === "/api/me") return json(context(ids));
    const tenant = Number(
      new Headers(init?.headers).get("X-Establecimiento-Id"),
    );
    if (path === "/api/registros")
      return json(
        collection([
          historicalRecord(8, tenant),
          historicalRecord(9, tenant, true),
        ]),
      );
    if (path === "/api/tareas")
      return json(collection([task(1, tenant), task(2, tenant)]));
    if (path === "/api/usuarios")
      return json(collection([person(), person(6)]));
    if (path === "/api/tareas/1") return json(task(1, tenant));
    if (path === "/api/usuarios/5") return json(person());
    if (path === "/api/usuarios/6") return json(person(6));
    if (path === "/api/registros/8") return json(historicalRecord(8, tenant));
    if (path === "/api/registros/8/evidencias")
      return json(
        collection([
          evidence(),
          evidence(2, "documento"),
          evidence(3, "firma"),
        ]),
      );
    if (path === "/api/evidencias/1/descargar")
      return new Response("foto", {
        headers: {
          "Content-Type": "image/png",
          "Content-Disposition": "attachment; filename*=UTF-8''c%C3%A1mara.png",
        },
      });
    throw new Error(`Unexpected ${path}`);
  });
});

function SessionControls() {
  const { seleccionarEstablecimiento } = useEstablecimiento();
  const { error } = useAuth();
  return (
    <>
      <button onClick={() => seleccionarEstablecimiento(2)}>
        Cambiar establecimiento
      </button>
      {error && <p role="alert">{error.message}</p>}
    </>
  );
}
function mount() {
  return render(
    <SessionProvider>
      <SessionControls />
      <HistoricoScreen />
    </SessionProvider>,
  );
}
async function open() {
  mount();
  fireEvent.click(
    await screen.findByRole("button", { name: "Ver registro #8" }),
  );
  await screen.findByRole("region", { name: "Datos del registro" });
}
function calls(path: string) {
  return fetchMock.mock.calls.filter(
    ([url]) => new URL(String(url)).pathname === path,
  );
}
function params() {
  return new URL(String(calls("/api/registros").at(-1)![0])).searchParams;
}
function set(label: string, value: string) {
  fireEvent.change(screen.getByLabelText(label), { target: { value } });
}
function apply() {
  fireEvent.click(screen.getByRole("button", { name: "Aplicar filtros" }));
}

it("carga registros conformes y no conformes con autor, tarea y cabeceras tenant", async () => {
  mount();
  await screen.findByRole("button", { name: "Ver registro #8" });
  expect(screen.getByText("Conforme", { exact: true })).toBeVisible();
  expect(screen.getByText("No conforme", { exact: true })).toBeVisible();
  expect(screen.getAllByRole("heading", { name: "Control 1" })).toHaveLength(2);
  expect(
    within(
      screen.getByRole("region", { name: "Resultados de registros" }),
    ).getAllByText(/Persona 5 Prueba/),
  ).toHaveLength(2);
  expect(calls("/api/tareas/1")).toHaveLength(1);
  expect(calls("/api/usuarios/5")).toHaveLength(1);
  for (const [url, init] of fetchMock.mock.calls) {
    if (new URL(String(url)).pathname === "/api/me") continue;
    expect(new Headers(init?.headers).get("X-Establecimiento-Id")).toBe("1");
    expect(new Headers(init?.headers).get("Authorization")).toBe(
      "Bearer session-token",
    );
  }
});

it("consulta vacía conserva filtros y ofrece actualización", async () => {
  overrides.set("/api/registros", async () => json(collection([])));
  mount();
  expect(
    await screen.findByText("No hay registros en esta consulta"),
  ).toBeVisible();
  expect(
    screen.getByRole("button", { name: "Actualizar registros" }),
  ).toBeEnabled();
  expect(screen.getByRole("button", { name: "Siguiente" })).toBeDisabled();
});

it.each([403, 404, 500, 503, 0])(
  "listado: error %s se muestra sin datos privados ni detalles internos",
  async (status) => {
    overrides.set("/api/registros", async () => {
      if (!status) throw new TypeError("offline");
      return json({ detail: "SQLSTATE private path token" }, status);
    });
    mount();
    expect(await screen.findByRole("alert")).not.toHaveTextContent(
      /SQLSTATE|private path|token/,
    );
    expect(
      screen.queryByRole("button", { name: "Ver registro #8" }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole("button", {
        name: [403, 404].includes(status)
          ? "Actualizar mis accesos"
          : "Volver a intentar",
      }),
    ).toBeVisible();
  },
);

it.each(["listado", "detalle", "descarga"])(
  "401 válido en %s retira el contenido privado",
  async (stage) => {
    if (stage === "listado") {
      overrides.set("/api/registros", async () => json({}, 401));
      mount();
    } else if (stage === "detalle") {
      overrides.set("/api/registros/8", async () => json({}, 401));
      mount();
      fireEvent.click(
        await screen.findByRole("button", { name: "Ver registro #8" }),
      );
    } else {
      overrides.set("/api/evidencias/1/descargar", async () => json({}, 401));
      await open();
      fireEvent.click(
        screen.getByRole("button", { name: "Descargar foto-1.png (#1)" }),
      );
    }
    await waitFor(() => expect(storage.getToken()).toBeNull());
    expect(screen.getByRole("alert")).toHaveTextContent("sesión ha caducado");
    expect(
      screen.queryByRole("region", { name: "Datos del registro" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Ver registro #8" }),
    ).not.toBeInTheDocument();
  },
);

it.each([
  ["Tarea / control", "/api/tareas/2", "tarea"],
  ["Usuario", "/api/usuarios/6", "usuario"],
  ["Conformidad", "true", "conforme"],
  ["Conformidad", "false", "conforme"],
  ["Orden por fecha", "asc", "order[fechaHora]"],
])("filtro %s=%s llega al backend", async (label, value, key) => {
  mount();
  await screen.findByRole("button", { name: "Ver registro #8" });
  set(label, value);
  apply();
  await waitFor(() => expect(params().get(key)).toBe(value));
  await screen.findByRole("button", { name: "Ver registro #8" });
});

it("combina filtros, fechas y orden; cambiar filtros reinicia página y limpiar restaura valores", async () => {
  overrides.set("/api/registros", async () =>
    json(collection([historicalRecord()], 41)),
  );
  mount();
  await screen.findByRole("button", { name: "Ver registro #8" });
  fireEvent.click(screen.getByRole("button", { name: "Siguiente" }));
  await screen.findByText("Página 2 · 41 resultados");
  set("Tarea / control", "/api/tareas/2");
  set("Usuario", "/api/usuarios/6");
  set("Conformidad", "false");
  set("Fecha desde", "2026-09-01");
  set("Fecha hasta", "2026-09-25");
  set("Orden por fecha", "asc");
  apply();
  await screen.findByText("Página 1 · 41 resultados");
  expect(Object.fromEntries(params())).toMatchObject({
    page: "1",
    itemsPerPage: "20",
    tarea: "/api/tareas/2",
    usuario: "/api/usuarios/6",
    conforme: "false",
    "order[fechaHora]": "asc",
  });
  expect(params().get("fechaHora[after]")).toBe(
    new Date("2026-09-01T00:00:00").toISOString().replace(".000Z", "Z"),
  );
  expect(params().get("fechaHora[before]")).toBe(
    new Date("2026-09-25T23:59:59").toISOString().replace(".000Z", "Z"),
  );
  fireEvent.click(screen.getByRole("button", { name: "Limpiar filtros" }));
  await waitFor(() =>
    expect(Object.fromEntries(params())).toEqual({
      page: "1",
      itemsPerPage: "20",
      "order[fechaHora]": "desc",
    }),
  );
  expect(screen.getByLabelText("Tarea / control")).toHaveValue("");
  expect(screen.getByLabelText("Fecha desde")).toHaveValue("");
  await screen.findByRole("button", { name: "Ver registro #8" });
});

it("rango de fechas inválido muestra validación sin consultar", async () => {
  mount();
  await screen.findByRole("button", { name: "Ver registro #8" });
  set("Fecha desde", "2026-09-25");
  set("Fecha hasta", "2026-09-01");
  apply();
  expect(screen.getByRole("alert")).toHaveTextContent(
    "inicio no puede ser posterior",
  );
  expect(calls("/api/registros")).toHaveLength(1);
  expect(filtersError({ ...emptyFilters, desde: "2026-02-30" })).toBe(
    "Introduce fechas válidas.",
  );
  expect(filtersError({ ...emptyFilters, hasta: "not-a-date" })).toBe(
    "Introduce fechas válidas.",
  );
});

it.each(["totalItems", "view.next"])(
  "paginación anterior/siguiente basada en %s",
  async (source) => {
    overrides.set("/api/registros", async (url) =>
      json(
        source === "totalItems"
          ? collection([historicalRecord()], 21)
          : {
              member: [historicalRecord()],
              ...(url.searchParams.get("page") === "1"
                ? { view: { next: "/api/registros?page=2" } }
                : {}),
            },
      ),
    );
    mount();
    await screen.findByRole("button", { name: "Ver registro #8" });
    expect(screen.getByRole("button", { name: "Anterior" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "Siguiente" }));
    await screen.findByText(
      source === "totalItems" ? "Página 2 · 21 resultados" : "Página 2",
    );
    expect(screen.getByRole("button", { name: "Siguiente" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "Anterior" }));
    await screen.findByText(
      source === "totalItems" ? "Página 1 · 21 resultados" : "Página 1",
    );
    expect(params().get("page")).toBe("1");
  },
);

it("detalle muestra tarea, autor, fecha, conformidad, valor, estructura, observaciones y confirmación", async () => {
  overrides.set("/api/registros/8", async () =>
    json({ ...historicalRecord(), confirmadoPor: "/api/usuarios/6" }),
  );
  await open();
  const details = within(
    screen.getByRole("region", { name: "Datos del registro" }),
  );
  expect(
    details.getByRole("heading", { name: "Registro #8 · Control 1" }),
  ).toBeVisible();
  for (const text of [
    "No conforme",
    "9.000",
    "L-42",
    "No",
    "Observación original inmutable.",
    "Persona 5 Prueba",
  ])
    expect(details.getByText(text, { exact: true })).toBeVisible();
  expect(details.getByText(/Persona 6 Prueba/)).toBeVisible();
  expect(screen.getByRole("heading", { name: "Registro #8" })).toHaveFocus();
  const times = screen
    .getByRole("region", { name: "Datos del registro" })
    .querySelectorAll("time");
  expect([...times].map((time) => time.dateTime)).toEqual([
    "2026-09-24T09:59:00+02:00",
    "2026-09-24T10:00:00+02:00",
  ]);
});

it("tarea y autores históricos inaccesibles conservan registro y evidencias", async () => {
  overrides.set("/api/tareas/1", async () => json({}, 404));
  overrides.set("/api/usuarios/5", async () => json({}, 404));
  await open();
  expect(
    screen.getByRole("heading", {
      name: "Registro #8 · Control #1 (sin acceso actual)",
    }),
  ).toBeVisible();
  expect(
    within(
      screen.getByRole("region", { name: "Datos del registro" }),
    ).getByText("Usuario #5 (sin acceso actual)", { exact: true }),
  ).toBeVisible();
  expect(
    within(
      screen.getByRole("region", { name: "Evidencias del registro" }),
    ).getAllByText(/Subida por Usuario #5 \(sin acceso actual\)/),
  ).toHaveLength(3);
});

it("evidencias incluyen fotografía, documento y firma histórica con metadatos y autor", async () => {
  await open();
  const section = within(
    screen.getByRole("region", { name: "Evidencias del registro" }),
  );
  for (const [name, label, mime] of [
    ["foto-1.png", "Fotografía", "image/png"],
    ["documento-2.pdf", "Documento", "application/pdf"],
    ["firma-3.png", "Firma histórica", "image/png"],
  ]) {
    expect(section.getByRole("heading", { name })).toBeVisible();
    expect(section.getByText(`${label} · ${mime} · 4 B`)).toBeVisible();
  }
  expect(section.getAllByText(/Subida por Persona 5 Prueba/)).toHaveLength(3);
  expect(
    section.getByRole("heading", { name: "Evidencias (3)" }),
  ).toBeVisible();
});

it("pagina evidencias sin perder el registro", async () => {
  overrides.set("/api/registros/8/evidencias", async (url) =>
    json(
      collection([evidence(url.searchParams.get("page") === "2" ? 21 : 1)], 21),
    ),
  );
  await open();
  fireEvent.click(
    within(
      screen.getByRole("navigation", { name: "Paginación de evidencias" }),
    ).getByRole("button", { name: "Siguiente" }),
  );
  await screen.findByRole("heading", { name: "foto-21.png" });
  expect(
    screen.getByRole("region", { name: "Datos del registro" }),
  ).toBeVisible();
  expect(
    new URL(
      String(calls("/api/registros/8/evidencias").at(-1)![0]),
    ).searchParams.get("page"),
  ).toBe("2");
});

it("detalle histórico sin datos opcionales ni evidencias sigue siendo consultable", async () => {
  overrides.set("/api/registros/8", async () =>
    json({
      ...historicalRecord(),
      valorNumerico: null,
      datos: null,
      observaciones: null,
      confirmadoPor: null,
      confirmadoAt: null,
    }),
  );
  overrides.set("/api/registros/8/evidencias", async () =>
    json(collection([])),
  );
  await open();
  expect(screen.getByText("Sin confirmación registrada")).toBeVisible();
  expect(screen.getByText("Sin observaciones")).toBeVisible();
  expect(screen.getByText("No hay evidencias en esta página.")).toBeVisible();
});

it.each([403, 404, 503, 0])(
  "descarga fallida %s conserva el detalle y permite volver a intentarlo",
  async (status) => {
    overrides.set("/api/evidencias/1/descargar", async () => {
      if (!status) throw new TypeError("offline");
      return json({}, status);
    });
    await open();
    fireEvent.click(
      screen.getByRole("button", { name: "Descargar foto-1.png (#1)" }),
    );
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(
      screen.getByRole("region", { name: "Datos del registro" }),
    ).toBeVisible();
    expect(
      screen.getByRole("button", { name: "Descargar foto-1.png (#1)" }),
    ).toBeEnabled();
    expect(calls("/api/evidencias/1/descargar")).toHaveLength(1);
  },
);

it.each(["listado", "detalle", "tarea", "evidencia"])(
  "rechaza datos de otro tenant o relación equivocada en %s",
  async (stage) => {
    const path =
      stage === "listado"
        ? "/api/registros"
        : stage === "detalle"
          ? "/api/registros/8"
          : stage === "tarea"
            ? "/api/tareas/1"
            : "/api/registros/8/evidencias";
    overrides.set(path, async () =>
      json(
        stage === "listado"
          ? collection([historicalRecord(8, 2)])
          : stage === "detalle"
            ? historicalRecord(8, 2)
            : stage === "tarea"
              ? task(1, 2)
              : collection([evidence(1, "foto", 999)]),
      ),
    );
    mount();
    if (stage === "detalle" || stage === "evidencia")
      fireEvent.click(
        await screen.findByRole("button", { name: "Ver registro #8" }),
      );
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(
      screen.queryByRole("region", { name: "Datos del registro" }),
    ).not.toBeInTheDocument();
  },
);

it.each([200, 401])(
  "cambio de establecimiento aborta listado y descarta HTTP tardío %s",
  async (status) => {
    ids = [1, 2];
    const pending = deferred<Response>();
    let signal: AbortSignal | null = null;
    overrides.set("/api/registros", async (_url, init) => {
      if (new Headers(init?.headers).get("X-Establecimiento-Id") === "1") {
        signal = init!.signal!;
        return pending.promise;
      }
      return json(collection([historicalRecord(22, 2)]));
    });
    mount();
    await waitFor(() => expect(signal).not.toBeNull());
    fireEvent.click(
      screen.getByRole("button", { name: "Cambiar establecimiento" }),
    );
    await screen.findByRole("button", { name: "Ver registro #22" });
    expect(signal!.aborted).toBe(true);
    await act(async () =>
      pending.resolve(
        status === 200 ? json(collection([historicalRecord()])) : json({}, 401),
      ),
    );
    expect(storage.getToken()).toBe("session-token");
    expect(
      screen.queryByRole("button", { name: "Ver registro #8" }),
    ).not.toBeInTheDocument();
  },
);

it.each(["detalle", "evidencias", "descarga"])(
  "cambio de establecimiento durante %s aborta y limpia selección/filtros",
  async (stage) => {
    ids = [1, 2];
    const pending = deferred<Response>();
    let signal: AbortSignal | null = null;
    const path =
      stage === "detalle"
        ? "/api/registros/8"
        : stage === "evidencias"
          ? "/api/registros/8/evidencias"
          : "/api/evidencias/1/descargar";
    overrides.set(path, async (_url, init) => {
      signal = init!.signal!;
      return pending.promise;
    });
    mount();
    await screen.findByRole("button", { name: "Ver registro #8" });
    set("Conformidad", "false");
    apply();
    fireEvent.click(
      await screen.findByRole("button", { name: "Ver registro #8" }),
    );
    if (stage === "descarga")
      fireEvent.click(
        await screen.findByRole("button", {
          name: "Descargar foto-1.png (#1)",
        }),
      );
    await waitFor(() => expect(signal).not.toBeNull());
    fireEvent.click(
      screen.getByRole("button", { name: "Cambiar establecimiento" }),
    );
    await screen.findByRole("button", { name: "Ver registro #8" });
    expect(signal!.aborted).toBe(true);
    await act(async () => pending.resolve(json({}, 401)));
    expect(storage.getToken()).toBe("session-token");
    expect(
      screen.queryByRole("region", { name: "Detalle del registro" }),
    ).not.toBeInTheDocument();
    expect(screen.getByLabelText("Conformidad")).toHaveValue("");
    expect(params().has("conforme")).toBe(false);
  },
);

it("descarga impide doble clic y revoca URL al salir del detalle", async () => {
  const pending = deferred<Response>();
  overrides.set("/api/evidencias/1/descargar", () => pending.promise);
  const create = vi.fn(() => "blob:download-test"),
    revoke = vi.fn();
  Object.defineProperty(URL, "createObjectURL", {
    configurable: true,
    writable: true,
    value: create,
  });
  Object.defineProperty(URL, "revokeObjectURL", {
    configurable: true,
    writable: true,
    value: revoke,
  });
  let downloadedFilename = "";
  const click = vi
    .spyOn(HTMLAnchorElement.prototype, "click")
    .mockImplementation(function (this: HTMLAnchorElement) {
      downloadedFilename = this.download;
    });
  await open();
  const button = screen.getByRole("button", {
    name: "Descargar foto-1.png (#1)",
  });
  fireEvent.click(button);
  fireEvent.click(button);
  expect(calls("/api/evidencias/1/descargar")).toHaveLength(1);
  expect(button).toBeDisabled();
  await act(async () =>
    pending.resolve(
      new Response("foto", {
        headers: {
          "Content-Type": "image/png",
          "Content-Disposition": "attachment; filename*=UTF-8''c%C3%A1mara.png",
        },
      }),
    ),
  );
  await screen.findByText("Descarga iniciada: cámara.png");
  expect(create).toHaveBeenCalledOnce();
  expect(click).toHaveBeenCalledOnce();
  expect(downloadedFilename).toBe("cámara.png");
  fireEvent.click(screen.getByRole("button", { name: "Volver a registros" }));
  await screen.findByRole("button", { name: "Ver registro #8" });
  expect(revoke).toHaveBeenCalledWith("blob:download-test");
});
