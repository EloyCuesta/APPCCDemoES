import type { ApiClient } from "@/lib/api/client";
import { readCollection } from "@/lib/api/collections";
import { endpoints, type Endpoint } from "@/lib/api/endpoints";
import { ApiError } from "@/lib/api/errors";
import { checkTenant, parseProgramacion } from "@/features/agenda/contracts";
import { dayBoundary, localDay } from "@/features/agenda/dates";
import { parseRegistroOrigen } from "@/features/registros/read-contracts";
import { parseIncidencia } from "@/features/incidencias/contracts";

/** Los COUNT de las colecciones ya aplican permisos, tenant y filtros en SQL. */
export async function loadIndicadores(api: ApiClient, tenantId: number, signal: AbortSignal, now = new Date()) {
  const day = localDay(now);
  const since = dayBoundary(day, false);
  const until = dayBoundary(day, true);
  const query = (filters: Record<string, string>) => new URLSearchParams({ page: "1", itemsPerPage: "1", ...filters });
  async function count<T>(endpoint: Endpoint, parse: (value: unknown) => T) {
    const result = readCollection(await api.request(endpoint, { signal }), parse);
    signal.throwIfAborted();
    if (result.total === null || !Number.isSafeInteger(result.total)) throw new ApiError(502, "La API no ha devuelto un total válido.");
    return result.total;
  }
  const [pendientes, vencidos, registros, abiertas, enProceso] = await Promise.all([
    count(endpoints.agenda(query({ estado: "pendiente", "fechaProgramada[after]": since, "fechaProgramada[before]": until })), (raw) => checkTenant(parseProgramacion(raw), tenantId)),
    count(endpoints.agenda(query({ estado: "vencida" })), (raw) => checkTenant(parseProgramacion(raw), tenantId)),
    count(endpoints.historicoRegistros(query({ "fechaHora[after]": since, "fechaHora[before]": until })), (raw) => checkTenant(parseRegistroOrigen(raw), tenantId)),
    count(endpoints.incidencias(query({ estado: "abierta" })), (raw) => checkTenant(parseIncidencia(raw), tenantId)),
    count(endpoints.incidencias(query({ estado: "en_proceso" })), (raw) => checkTenant(parseIncidencia(raw), tenantId)),
  ]);
  signal.throwIfAborted();
  return { pendientes, vencidos, registros, incidencias: abiertas + enProceso };
}
