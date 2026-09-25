import type { ApiClient } from "@/lib/api/client";
import { endpoints } from "@/lib/api/endpoints";
import { ApiError } from "@/lib/api/errors";
import { loadUsuarioLabel } from "@/lib/api/read-relations";
import { readCollection } from "@/lib/api/collections";
import {
  checkId,
  checkTenant,
  parseTarea,
} from "@/features/agenda/contracts";
import {
  checkParent,
  parseAccion,
  parseHistorial,
  parseIncidencia,
  parseRegistroOrigen,
  resourceId,
  type EstadoIncidencia,
} from "./contracts";

async function loadTareaLabel(api: ApiClient, id: number, tenantId: number, signal: AbortSignal) {
  try {
    return checkTenant(checkId(parseTarea(await api.request(endpoints.tarea(id), { signal })), id), tenantId).nombre;
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 404) throw error;
    return `Control #${id} (sin acceso actual)`;
  }
}

export async function loadIncidencias(
  api: ApiClient,
  query: string,
  tenantId: number,
  signal: AbortSignal,
) {
  const result = readCollection(
    await api.request(endpoints.incidencias(new URLSearchParams(query)), {
      signal,
    }),
    (raw) => checkTenant(parseIncidencia(raw), tenantId),
  );
  signal.throwIfAborted();
  return result;
}

export async function loadDetalle(
  api: ApiClient,
  id: number,
  tenantId: number,
  accionesPage: number,
  historialPage: number,
  signal: AbortSignal,
) {
  const incidencia = checkTenant(
    checkId(
      parseIncidencia(await api.request(endpoints.incidencia(id), { signal })),
      id,
    ),
    tenantId,
  );
  signal.throwIfAborted();
  const [accionesRaw, historialRaw, registroRaw] = await Promise.all([
    api.request(endpoints.accionesIncidencia(id, accionesPage), { signal }),
    api.request(endpoints.historialIncidencia(id, historialPage), { signal }),
    incidencia.registro === null
      ? null
      : api.request(
          endpoints.registro(resourceId(incidencia.registro, "registros")),
          { signal },
        ),
  ]);
  const acciones = readCollection(accionesRaw, (raw) =>
    checkParent(parseAccion(raw), id),
  );
  const historial = readCollection(historialRaw, (raw) =>
    checkParent(parseHistorial(raw), id),
  );
  const registro =
    incidencia.registro === null
      ? null
      : checkTenant(
          checkId(
            parseRegistroOrigen(registroRaw),
            resourceId(incidencia.registro, "registros"),
          ),
          tenantId,
        );
  signal.throwIfAborted();
  const tareaId = registro ? resourceId(registro.tarea, "tareas") : null;
  const tarea =
    tareaId === null
      ? null
      : await loadTareaLabel(api, tareaId, tenantId, signal);
  const iris = new Set(
    [
      ...acciones.items.map((a) => a.usuario),
      ...historial.items.map((h) => h.cambiadoPor),
      registro?.usuario,
      registro?.confirmadoPor,
    ].filter((iri): iri is string => typeof iri === "string"),
  );
  const usuarios: Record<string, string> = {};
  // Los autores con membresías revocadas pueden dejar de ser consultables: se conserva su identificador histórico.
  for (const iri of iris) {
    signal.throwIfAborted();
    usuarios[iri] = await loadUsuarioLabel(api, iri, signal);
  }
  signal.throwIfAborted();
  return { incidencia, registro, tarea, acciones, historial, usuarios };
}
export type DetalleIncidencia = Awaited<ReturnType<typeof loadDetalle>>;

export type EscrituraIncidencia =
  | { tipo: "estado"; estado: Exclude<EstadoIncidencia, "abierta"> }
  | { tipo: "accion"; descripcion: string; resultado: string };
export async function writeIncidencia(
  api: ApiClient,
  id: number,
  input: EscrituraIncidencia,
  signal: AbortSignal,
) {
  // Solo campos del contrato. Identidad, fechas, transiciones y condición de cierre las valida Symfony.
  if (input.tipo === "estado") {
    await api.request(endpoints.incidencia(id), {
      method: "PATCH",
      signal,
      body: { estado: input.estado },
    });
  } else {
    await api.request(endpoints.accionesCorrectivas, {
      method: "POST",
      signal,
      body: {
        incidencia: `/api/incidencias/${id}`,
        descripcion: input.descripcion.trim(),
        resultado: input.resultado.trim() || null,
      },
    });
  }
  signal.throwIfAborted();
}
