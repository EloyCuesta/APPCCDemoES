import type { ApiClient } from "@/lib/api/client";
import { readCollection, type ApiCollection } from "@/lib/api/collections";
import { endpoints } from "@/lib/api/endpoints";
import { ApiError } from "@/lib/api/errors";
import { checkId, checkTenant, iriId, parsePlan, parseProgramacion, parseResponsable, parseTarea, type AgendaItem, type PlanAgenda, type ResponsableAgenda, type TareaAgenda } from "./contracts";

function memo<T>(cache: Map<number, Promise<T>>, id: number, load: () => Promise<T>): Promise<T> {
  const found = cache.get(id);
  if (found) return found;
  const pending = load(); cache.set(id, pending); return pending;
}

/** Caches exist only for this request. No operational data outlives a query/tenant. */
export async function loadAgenda(api: ApiClient, query: string, tenantId: number, signal: AbortSignal): Promise<ApiCollection<AgendaItem>> {
  signal.throwIfAborted();
  const collection = readCollection(await api.request(endpoints.agenda(new URLSearchParams(query)), { signal }), (value) => {
    const item = checkTenant(parseProgramacion(value), tenantId);
    if (item.estado !== "pendiente" && item.estado !== "vencida") throw new ApiError(502, "La respuesta no corresponde a la agenda de controles abiertos.");
    return item;
  });
  const tasks = new Map<number, Promise<TareaAgenda>>();
  const plans = new Map<number, Promise<PlanAgenda>>();
  const people = new Map<number, Promise<ResponsableAgenda | null>>();
  const items: AgendaItem[] = [];
  // Four rows at a time, one request per unique task/plan/person in the page.
  for (let offset = 0; offset < collection.items.length; offset += 4) {
    signal.throwIfAborted();
    const batch = await Promise.all(collection.items.slice(offset, offset + 4).map(async (programacion) => {
      const taskId = iriId(programacion.tarea, "tareas");
      const tarea = await memo(tasks, taskId, async () => checkTenant(checkId(parseTarea(await api.request(endpoints.tarea(taskId), { signal })), taskId), tenantId));
      signal.throwIfAborted();
      const planId = iriId(tarea.planControl, "planes-control");
      const planPromise = memo(plans, planId, async () => checkTenant(checkId(parsePlan(await api.request(endpoints.planControl(planId), { signal })), planId), tenantId));
      const personId = programacion.asignadoA === null ? null : iriId(programacion.asignadoA, "usuarios");
      const personPromise = personId === null ? Promise.resolve(null) : memo(people, personId, async () => {
        try { return checkId(parseResponsable(await api.request(endpoints.usuario(personId), { signal })), personId); }
        catch (error) {
          // Revoked membership can leave a legitimate historical assignment unreadable.
          if (error instanceof ApiError && error.status === 404) return null;
          throw error;
        }
      });
      const [plan, responsable] = await Promise.all([planPromise, personPromise]);
      return { programacion, tarea, plan, responsable };
    }));
    items.push(...batch);
  }
  signal.throwIfAborted();
  return { ...collection, items };
}

export type ReferenceKind = "tarea" | "asignadoA";
export interface ReferenceOption { iri: string; label: string }

export async function loadOptions(api: ApiClient, kind: ReferenceKind, page: number, tenantId: number, signal: AbortSignal): Promise<ApiCollection<ReferenceOption>> {
  if (kind === "tarea") {
    return readCollection(await api.request(endpoints.tareas(page), { signal }), (value) => {
      const tarea = checkTenant(parseTarea(value), tenantId);
      return { iri: `/api/tareas/${tarea.id}`, label: tarea.nombre };
    });
  }
  return readCollection(await api.request(endpoints.usuarios(page), { signal }), (value) => {
    const person = parseResponsable(value);
    return { iri: `/api/usuarios/${person.id}`, label: `${person.nombre} ${person.apellidos}`.trim() };
  });
}
