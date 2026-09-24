import type { ApiClient } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { endpoints } from "@/lib/api/endpoints";
import { readCollection } from "@/lib/api/collections";
import { resourceId } from "@/lib/api/resources";
import { loadUsuarioLabel } from "@/lib/api/read-relations";
import { checkId, checkTenant, parseTarea } from "@/features/agenda/contracts";
import { parseRegistroOrigen } from "./read-contracts";
import { parseEvidencia } from "./evidencias-contracts";

/** Cachés de promesas limitadas a una consulta y tenant; nunca persistentes. */
function references(api: ApiClient, tenantId: number, signal: AbortSignal) {
  const users = new Map<string, Promise<string>>(), tasks = new Map<string, Promise<string>>();
  return {
    usuario(iri: string) {
      if (!users.has(iri)) users.set(iri, loadUsuarioLabel(api, iri, signal));
      return users.get(iri)!;
    },
    tarea(iri: string) {
      if (!tasks.has(iri)) tasks.set(iri, (async () => {
        const id = resourceId(iri, "tareas");
        try { return checkTenant(checkId(parseTarea(await api.request(endpoints.tarea(id), { signal })), id), tenantId).nombre; }
        catch (error) { if (!(error instanceof ApiError) || error.status !== 404) throw error; return `Control #${id} (sin acceso actual)`; }
      })());
      return tasks.get(iri)!;
    },
  };
}

export async function loadHistorico(api: ApiClient, query: string, tenantId: number, signal: AbortSignal) {
  const collection = readCollection(await api.request(endpoints.historicoRegistros(new URLSearchParams(query)), { signal }), (raw) => checkTenant(parseRegistroOrigen(raw), tenantId));
  const refs = references(api, tenantId, signal);
  const items: Array<{ registro: ReturnType<typeof parseRegistroOrigen>; tarea: string; usuario: string }> = [];
  // Dos filas por lote, hasta cuatro lecturas simultáneas, deduplicadas por IRI.
  for (let i = 0; i < collection.items.length; i += 2) {
    signal.throwIfAborted();
    items.push(...await Promise.all(collection.items.slice(i, i + 2).map(async (registro) => {
      const [tarea, usuario] = await Promise.all([refs.tarea(registro.tarea), refs.usuario(registro.usuario)]);
      return { registro, tarea, usuario };
    })));
  }
  signal.throwIfAborted();
  return { ...collection, items };
}

export async function loadRegistroHistorico(api: ApiClient, id: number, tenantId: number, page: number, signal: AbortSignal) {
  const registro = checkTenant(checkId(parseRegistroOrigen(await api.request(endpoints.registro(id), { signal })), id), tenantId);
  signal.throwIfAborted();
  const refs = references(api, tenantId, signal);
  const [rawEvidencias, tarea, usuario, confirmadoPor] = await Promise.all([
    api.request(endpoints.evidenciasRegistro(id, page), { signal }), refs.tarea(registro.tarea), refs.usuario(registro.usuario),
    registro.confirmadoPor === null ? null : refs.usuario(registro.confirmadoPor),
  ]);
  const evidencias = readCollection(rawEvidencias, (raw) => parseEvidencia(raw, id));
  const autores: Record<string, string> = {};
  const iris = [...new Set(evidencias.items.map((e) => e.subidaPor))];
  for (let i = 0; i < iris.length; i += 4) {
    signal.throwIfAborted();
    await Promise.all(iris.slice(i, i + 4).map(async (iri) => { autores[iri] = await refs.usuario(iri); }));
  }
  signal.throwIfAborted();
  return { registro, tarea, usuario, confirmadoPor, evidencias, autores };
}
export type DetalleRegistro = Awaited<ReturnType<typeof loadRegistroHistorico>>;
