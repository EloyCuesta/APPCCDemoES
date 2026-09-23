export interface Endpoint {
    path: string;
    scope: "public" | "session" | "tenant";
    accept: "application/json" | "application/ld+json";
}

// Paths verified against Symfony routes and API Platform resource metadata.
export const endpoints = {
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
    | ((query: URLSearchParams) => Endpoint)
>;
