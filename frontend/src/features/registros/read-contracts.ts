import { isRecord } from "@/lib/api/errors";
import { invalidResource, resourceIdentity, resourceRelation, resourceInstant, optionalText } from "@/lib/api/resources";

/** Histórico inmutable, compartido por Registros e Incidencias. No calcula conformidad. */
export function parseRegistroOrigen(value: unknown) {
  const data = resourceIdentity(value, "registros");
  if (typeof data.conforme !== "boolean" || (data.datos != null && !isRecord(data.datos) && !Array.isArray(data.datos))) return invalidResource();
  const valorNumerico = optionalText(data.valorNumerico);
  if (valorNumerico !== null && !/^-?\d{1,9}(?:\.\d{1,3})?$/.test(valorNumerico)) return invalidResource();
  return {
    id: data.id, establecimiento: resourceRelation(data.establecimiento, "establecimientos"),
    tarea: resourceRelation(data.tarea, "tareas"), tareaProgramada: resourceRelation(data.tareaProgramada, "tareas-programadas"),
    usuario: resourceRelation(data.usuario, "usuarios"), fechaHora: resourceInstant(data.fechaHora), conforme: data.conforme,
    valorNumerico, datos: data.datos ?? null, observaciones: optionalText(data.observaciones),
    confirmadoAt: data.confirmadoAt == null ? null : resourceInstant(data.confirmadoAt),
    confirmadoPor: data.confirmadoPor == null ? null : resourceRelation(data.confirmadoPor, "usuarios"),
  };
}
export type RegistroHistorico = ReturnType<typeof parseRegistroOrigen>;
