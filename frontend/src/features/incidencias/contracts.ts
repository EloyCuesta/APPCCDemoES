import { ApiError, isRecord } from "@/lib/api/errors";
import { isInstant } from "@/features/agenda/dates";
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

type Resource =
  | "incidencias"
  | "registros"
  | "establecimientos"
  | "usuarios"
  | "tareas"
  | "tareas-programadas";
function invalid(): never {
  throw new ApiError(
    502,
    "La API ha devuelto datos de incidencias no válidos. Vuelve a consultar.",
  );
}
export function resourceId(value: unknown, resource: Resource): number {
  const match =
    typeof value === "string" &&
    new RegExp(`^/api/${resource}/([1-9][0-9]*)$`).exec(value);
  const id = match ? Number(match[1]) : 0;
  if (!Number.isSafeInteger(id) || id <= 0) return invalid();
  return id;
}
function relation(value: unknown, resource: Resource): string {
  return `/api/${resource}/${resourceId(value, resource)}`;
}
function identity(
  value: unknown,
  resource: string,
): Record<string, unknown> & { id: number } {
  if (
    !isRecord(value) ||
    typeof value.id !== "number" ||
    !Number.isSafeInteger(value.id) ||
    value.id <= 0
  )
    return invalid();
  if (
    value["@id"] !== undefined &&
    value["@id"] !== `/api/${resource}/${value.id}`
  )
    return invalid();
  return { ...value, id: value.id };
}
function text(value: unknown): string {
  return typeof value === "string" ? value : invalid();
}
function optionalText(value: unknown): string | null {
  return value == null ? null : text(value);
}
function instant(value: unknown): string {
  return isInstant(value) ? value : invalid();
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
export function parseRegistroOrigen(value: unknown) {
  const data = identity(value, "registros");
  if (
    typeof data.conforme !== "boolean" ||
    (data.datos != null && !isRecord(data.datos) && !Array.isArray(data.datos))
  )
    return invalid();
  return {
    id: data.id,
    establecimiento: relation(data.establecimiento, "establecimientos"),
    tarea: relation(data.tarea, "tareas"),
    tareaProgramada: relation(data.tareaProgramada, "tareas-programadas"),
    usuario: relation(data.usuario, "usuarios"),
    fechaHora: instant(data.fechaHora),
    conforme: data.conforme,
    valorNumerico: optionalText(data.valorNumerico),
    datos: data.datos ?? null,
    observaciones: optionalText(data.observaciones),
    confirmadoAt: data.confirmadoAt == null ? null : instant(data.confirmadoAt),
    confirmadoPor:
      data.confirmadoPor == null
        ? null
        : relation(data.confirmadoPor, "usuarios"),
  };
}

export function checkParent<T extends { incidencia: string }>(
  item: T,
  id: number,
): T {
  if (item.incidencia !== `/api/incidencias/${id}`) return invalid();
  return item;
}
