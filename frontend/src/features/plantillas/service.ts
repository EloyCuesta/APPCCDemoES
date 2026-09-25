import type { ApiClient } from "@/lib/api/client";
import type { Endpoint } from "@/lib/api/endpoints";
import { readCollection } from "@/lib/api/collections";
import { parseControl, parsePlantilla, parseResultado } from "./contracts";
import { endpoints } from "@/lib/api/endpoints";

// El catálogo es global autenticado; la aplicación siempre lleva el contexto de establecimiento.
const plantillaEndpoints = {
  catalogo: (page: number): Endpoint => ({
    path: `/api/plantillas-appcc?page=${page}&itemsPerPage=20`,
    scope: "session", accept: "application/ld+json",
  }),
  aplicar: (id: number): Endpoint => ({
    path: `/api/plantillas-appcc/${id}/aplicar`,
    scope: "tenant", accept: "application/ld+json",
  }),
};

export async function loadPlantillas(api: ApiClient, page: number, signal: AbortSignal) {
  const raw = await api.request(plantillaEndpoints.catalogo(page), { signal });
  signal.throwIfAborted();
  return readCollection(raw, parsePlantilla);
}

export async function aplicarPlantilla(api: ApiClient, id: number, tenantId: number, signal: AbortSignal) {
  const raw = await api.request(plantillaEndpoints.aplicar(id), {
    method: "POST", body: {}, signal,
  });
  signal.throwIfAborted();
  return parseResultado(raw, id, tenantId);
}

export async function loadControl(api: ApiClient, id: number, tenantId: number, signal: AbortSignal) {
  const raw = await api.request(endpoints.tarea(id), { signal });
  signal.throwIfAborted();
  return parseControl(raw, id, tenantId);
}

export interface LimitesControl {
  limiteMinimo: string | null;
  limiteMaximo: string | null;
  unidad: string;
  instrucciones: string;
}

export async function activarControl(api: ApiClient, id: number, input: LimitesControl, signal: AbortSignal) {
  await api.request(endpoints.tarea(id), {
    method: "PATCH", signal,
    body: { ...input, activa: true },
  });
  signal.throwIfAborted();
}
