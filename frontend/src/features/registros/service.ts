import type { ApiClient } from "@/lib/api/client";
import { endpoints } from "@/lib/api/endpoints";
import { ApiError, isRecord } from "@/lib/api/errors";
import { readCollection } from "@/lib/api/collections";
import {
  checkId,
  checkTenant,
  iriId,
  parseProgramacion,
} from "@/features/agenda/contracts";
import { parseTareaRegistro, type EvidenciaSubida } from "./contracts";

export async function loadControl(
  api: ApiClient,
  id: number,
  tenantId: number,
  signal: AbortSignal,
) {
  const programacion = checkTenant(
    checkId(
      parseProgramacion(
        await api.request(endpoints.programacion(id), { signal }),
      ),
      id,
    ),
    tenantId,
  );
  if (!["pendiente", "vencida"].includes(programacion.estado))
    throw new ApiError(409);
  const tareaId = iriId(programacion.tarea, "tareas");
  const [rawTask, rawConfig] = await Promise.all([
    api.request(endpoints.tarea(tareaId), { signal }),
    api.request(endpoints.configuracionesEstablecimiento, { signal }),
  ]);
  const tarea = checkTenant(
    checkId(parseTareaRegistro(rawTask), tareaId),
    tenantId,
  );
  // Establecimiento.configuracion no es legible por API. La colección existente
  // aplica TenantExtension y la BD impone una configuración por establecimiento.
  const configs = readCollection(rawConfig, (config) => {
    if (
      !isRecord(config) ||
      !Number.isSafeInteger(config.id) ||
      Number(config.id) <= 0 ||
      config.establecimiento !== `/api/establecimientos/${tenantId}` ||
      typeof config.requiereObservacionNoConforme !== "boolean"
    )
      throw new ApiError(502);
    return { requiereObservacion: config.requiereObservacionNoConforme };
  });
  if (configs.items.length !== 1 || configs.next !== null)
    throw new ApiError(
      502,
      "No se ha podido obtener la configuración del establecimiento.",
    );
  signal.throwIfAborted();
  return {
    programacion,
    tarea,
    requiereObservacion: configs.items[0].requiereObservacion,
  };
}

export async function subirEvidencia(
  api: ApiClient,
  archivo: File,
  tipo: EvidenciaSubida["tipo"],
  signal: AbortSignal,
): Promise<EvidenciaSubida> {
  const body = new FormData();
  body.append("archivo", archivo);
  body.append("tipo", tipo);
  const data = await api.request(endpoints.subirEvidencia, {
    method: "POST",
    body,
    signal,
  });
  if (
    !isRecord(data) ||
    typeof data.token !== "string" ||
    !/^[a-f0-9]{64}$/.test(data.token) ||
    typeof data.nombreOriginal !== "string" ||
    typeof data.expiresAt !== "string" ||
    !Number.isFinite(Date.parse(data.expiresAt))
  )
    throw new ApiError(502);
  return {
    token: data.token,
    tipo,
    nombreOriginal: data.nombreOriginal,
    expiresAt: data.expiresAt,
  };
}

export type Control = Awaited<ReturnType<typeof loadControl>>;
