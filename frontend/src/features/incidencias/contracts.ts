import { invalidResource as invalid, resourceIdentity as identity, resourceRelation as relation, resourceText as text, resourceInstant as instant, optionalText } from "@/lib/api/resources";
export { resourceId } from "@/lib/api/resources";
export { parseRegistroOrigen } from "@/features/registros/read-contracts";
import type { RolEstablecimiento } from "@/types/session";

export const estados = {
  abierta: "Abierta",
  en_proceso: "En proceso",
  resuelta: "Resuelta",
};
export const gravedades = {
  baja: "Baja",
  media: "Media",
  alta: "Alta",
  critica: "Crítica",
};
export type EstadoIncidencia = keyof typeof estados;
export type GravedadIncidencia = keyof typeof gravedades;

// Visibilidad de controles según TenantAuthorization; Symfony autoriza cada escritura.
export function puedeCambiarEstado(rol: RolEstablecimiento | undefined) {
  return rol === "admin" || rol === "responsable";
}
export function puedeAnadirAccion(rol: RolEstablecimiento | undefined) {
  return puedeCambiarEstado(rol) || rol === "trabajador";
}

function state(value: unknown): EstadoIncidencia {
  return typeof value === "string" && Object.hasOwn(estados, value)
    ? (value as EstadoIncidencia)
    : invalid();
}

export function parseIncidencia(value: unknown) {
  const data = identity(value, "incidencias");
  if (
    typeof data.gravedad !== "string" ||
    !Object.hasOwn(gravedades, data.gravedad)
  )
    return invalid();
  return {
    id: data.id,
    establecimiento: relation(data.establecimiento, "establecimientos"),
    registro:
      data.registro == null ? null : relation(data.registro, "registros"),
    titulo: text(data.titulo),
    descripcion: text(data.descripcion),
    gravedad: data.gravedad as GravedadIncidencia,
    estado: state(data.estado),
    fechaApertura: instant(data.fechaApertura),
    fechaCierre: data.fechaCierre == null ? null : instant(data.fechaCierre),
  };
}
export type Incidencia = ReturnType<typeof parseIncidencia>;

export function parseAccion(value: unknown) {
  const data = identity(value, "acciones-correctivas");
  return {
    id: data.id,
    incidencia: relation(data.incidencia, "incidencias"),
    usuario: relation(data.usuario, "usuarios"),
    descripcion: text(data.descripcion),
    resultado: optionalText(data.resultado),
    fechaHora: instant(data.fechaHora),
  };
}
export function parseHistorial(value: unknown) {
  const data = identity(value, "historiales-incidencia");
  return {
    id: data.id,
    incidencia: relation(data.incidencia, "incidencias"),
    estadoAnterior:
      data.estadoAnterior == null ? null : state(data.estadoAnterior),
    estadoNuevo: state(data.estadoNuevo),
    cambiadoPor:
      data.cambiadoPor == null ? null : relation(data.cambiadoPor, "usuarios"),
    comentario: optionalText(data.comentario),
    createdAt: instant(data.createdAt),
  };
}
export function checkParent<T extends { incidencia: string }>(
  item: T,
  id: number,
): T {
  if (item.incidencia !== `/api/incidencias/${id}`) return invalid();
  return item;
}
