export interface Endpoint {
    path: string;
    scope: "public" | "session" | "tenant";
    accept: "application/json" | "application/ld+json" | "application/octet-stream";
}

// Paths verified against Symfony routes and API Platform resource metadata.
export const endpoints = {
    historicoRegistros: (query: URLSearchParams): Endpoint => ({
        path: `/api/registros?${query}`, scope: "tenant", accept: "application/ld+json",
    }),
    evidenciasRegistro: (id: number, page: number): Endpoint => ({
        path: `/api/registros/${id}/evidencias?page=${page}&itemsPerPage=20`, scope: "tenant", accept: "application/ld+json",
    }),
    descargarEvidencia: (id: number): Endpoint => ({
        path: `/api/evidencias/${id}/descargar`, scope: "tenant", accept: "application/octet-stream",
    }),
    incidencias: (query: URLSearchParams): Endpoint => ({
        path: `/api/incidencias?${query}`, scope: "tenant", accept: "application/ld+json",
    }),
    incidencia: (id: number): Endpoint => ({
        path: `/api/incidencias/${id}`, scope: "tenant", accept: "application/ld+json",
    }),
    accionesIncidencia: (id: number, page: number): Endpoint => ({
        path: `/api/incidencias/${id}/acciones?page=${page}&itemsPerPage=20`, scope: "tenant", accept: "application/ld+json",
    }),
    historialIncidencia: (id: number, page: number): Endpoint => ({
        path: `/api/incidencias/${id}/historial?page=${page}&itemsPerPage=20&order%5BcreatedAt%5D=asc`, scope: "tenant", accept: "application/ld+json",
    }),
    accionesCorrectivas: { path: "/api/acciones-correctivas", scope: "tenant", accept: "application/ld+json" },
    registro: (id: number): Endpoint => ({
        path: `/api/registros/${id}`, scope: "tenant", accept: "application/ld+json",
    }),
    programacion: (id: number): Endpoint => ({
        path: `/api/tareas-programadas/${id}`, scope: "tenant", accept: "application/ld+json",
    }),
    configuracionesEstablecimiento: { path: "/api/configuraciones-establecimiento", scope: "tenant", accept: "application/ld+json" },
    registros: { path: "/api/registros", scope: "tenant", accept: "application/ld+json" },
    subirEvidencia: { path: "/api/evidencias/subidas", scope: "tenant", accept: "application/json" },
    login: {
        path: "/api/login_check",
        scope: "public",
        accept: "application/json",
    },
    me: { path: "/api/me", scope: "session", accept: "application/json" },
    establecimiento: (id: number): Endpoint => ({
        path: `/api/establecimientos/${id}`,
        scope: "tenant",
        accept: "application/ld+json",
    }),
    agenda: (query: URLSearchParams): Endpoint => ({
        path: `/api/tareas-programadas/agenda?${query}`,
        scope: "tenant",
        accept: "application/ld+json",
    }),
    tarea: (id: number): Endpoint => ({
        path: `/api/tareas/${id}`,
        scope: "tenant",
        accept: "application/ld+json",
    }),
    planControl: (id: number): Endpoint => ({
        path: `/api/planes-control/${id}`,
        scope: "tenant",
        accept: "application/ld+json",
    }),
    usuario: (id: number): Endpoint => ({
        path: `/api/usuarios/${id}`,
        scope: "tenant",
        accept: "application/ld+json",
    }),
    tareas: (page: number): Endpoint => ({
        path: `/api/tareas?page=${page}&itemsPerPage=100`,
        scope: "tenant",
        accept: "application/ld+json",
    }),
    usuarios: (page: number): Endpoint => ({
        path: `/api/usuarios?page=${page}`,
        scope: "tenant",
        accept: "application/ld+json",
    }),
} satisfies Record<
    string,
    | Endpoint
    | ((id: number) => Endpoint)
    | ((id: number, page: number) => Endpoint)
    | ((query: URLSearchParams) => Endpoint)
>;
