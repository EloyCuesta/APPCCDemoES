import { ApiError, isRecord } from "@/lib/api/errors";
import { isInstant } from "./dates";

export const estados = { pendiente: "Pendiente", vencida: "Vencida", completada: "Completada", omitida: "Omitida" };
export type EstadoProgramacion = keyof typeof estados;
export const frecuencias = { diaria: "Diaria", semanal: "Semanal", mensual: "Mensual", por_turno: "Por turno", por_recepcion: "Por recepción", bajo_demanda: "Bajo demanda" };
export type FrecuenciaTarea = keyof typeof frecuencias;
export const tiposControl = {
  temperaturas: "Temperaturas", limpieza: "Limpieza", plagas: "Plagas", recepcion: "Recepción", trazabilidad: "Trazabilidad",
  alergenos: "Alérgenos", agua: "Agua", residuos: "Residuos", mantenimiento: "Mantenimiento", aceite_fritura: "Aceite de fritura", otro: "Otro",
};
export type TipoControl = keyof typeof tiposControl;

/** Deliberate subset of the entity's read contract, independent of its audit fields. */
export interface Programacion {
  id: number;
  tarea: string;
  establecimiento: string;
  asignadoA: string | null;
  fechaProgramada: string;
  fechaLimite: string | null;
  estado: EstadoProgramacion;
}
export interface TareaAgenda {
  id: number; nombre: string; establecimiento: string; planControl: string;
  frecuencia: FrecuenciaTarea; horaPrevista: string | null;
}
export interface PlanAgenda { id: number; nombre: string; tipo: TipoControl; establecimiento: string }
export interface ResponsableAgenda { id: number; nombre: string; apellidos: string }
export interface AgendaItem {
  programacion: Programacion;
  tarea: TareaAgenda;
  plan: PlanAgenda;
  responsable: ResponsableAgenda | null;
}

function invalid(): never { throw new ApiError(502, "La API ha devuelto datos de agenda no válidos. Vuelve a intentarlo."); }
function validId(value: unknown): value is number { return typeof value === "number" && Number.isSafeInteger(value) && value > 0; }
function hasKey<T extends object>(object: T, key: unknown): key is keyof T { return typeof key === "string" && Object.hasOwn(object, key); }
function nonEmpty(value: unknown): value is string { return typeof value === "string" && value.trim().length > 0; }

export function iriId(value: unknown, resource: "tareas" | "establecimientos" | "usuarios" | "planes-control"): number {
  if (typeof value !== "string") return invalid();
  const match = new RegExp(`^/api/${resource}/([1-9][0-9]*)$`).exec(value);
  const id = match ? Number(match[1]) : 0;
  if (!validId(id)) return invalid();
  return id;
}

function relation(value: unknown, resource: Parameters<typeof iriId>[1]): string {
  const id = iriId(value, resource);
  return `/api/${resource}/${id}`;
}

function identity(data: unknown, resource: string): Record<string, unknown> & { id: number } {
  if (!isRecord(data) || !validId(data.id)) return invalid();
  if (data["@id"] !== undefined && data["@id"] !== `/api/${resource}/${data.id}`) return invalid();
  return { ...data, id: data.id };
}

export function parseProgramacion(value: unknown): Programacion {
  const data = identity(value, "tareas-programadas");
  // API Platform's SerializerContextBuilder defaults to skip_null_values=true.
  const fechaLimite = data.fechaLimite ?? null;
  if (!isInstant(data.fechaProgramada) || !(fechaLimite === null || isInstant(fechaLimite)) || !hasKey(estados, data.estado)) return invalid();
  return {
    id: data.id, tarea: relation(data.tarea, "tareas"), establecimiento: relation(data.establecimiento, "establecimientos"),
    asignadoA: data.asignadoA == null ? null : relation(data.asignadoA, "usuarios"),
    fechaProgramada: data.fechaProgramada, fechaLimite, estado: data.estado,
  };
}

export function parseTarea(value: unknown): TareaAgenda {
  const data = identity(value, "tareas");
  const horaPrevista = data.horaPrevista ?? null;
  if (!nonEmpty(data.nombre) || !hasKey(frecuencias, data.frecuencia)
    || !(horaPrevista === null || (typeof horaPrevista === "string" && /^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/.test(horaPrevista)))) return invalid();
  return { id: data.id, nombre: data.nombre, frecuencia: data.frecuencia, horaPrevista,
    establecimiento: relation(data.establecimiento, "establecimientos"), planControl: relation(data.planControl, "planes-control") };
}

export function parsePlan(value: unknown): PlanAgenda {
  const data = identity(value, "planes-control");
  if (!nonEmpty(data.nombre) || !hasKey(tiposControl, data.tipo)) return invalid();
  return { id: data.id, nombre: data.nombre, tipo: data.tipo, establecimiento: relation(data.establecimiento, "establecimientos") };
}

export function parseResponsable(value: unknown): ResponsableAgenda {
  const data = identity(value, "usuarios");
  if (!nonEmpty(data.nombre) || typeof data.apellidos !== "string") return invalid();
  return { id: data.id, nombre: data.nombre, apellidos: data.apellidos };
}

export function checkTenant<T extends { establecimiento: string }>(value: T, tenantId: number): T {
  if (value.establecimiento !== `/api/establecimientos/${tenantId}`) return invalid();
  return value;
}

export function checkId<T extends { id: number }>(value: T, id: number): T {
  if (value.id !== id) return invalid();
  return value;
}
