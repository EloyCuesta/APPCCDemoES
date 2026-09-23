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
} satisfies Record<string, Endpoint | ((id: number) => Endpoint)>;
