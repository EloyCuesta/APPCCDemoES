import { isRecord } from "@/lib/api/errors";
import { checkId, checkTenant, parseTarea as parseTareaAgenda } from "@/features/agenda/contracts";
import { decimal } from "@/features/registros/contracts";
import {
  invalidResource,
  optionalText,
  resourceIdentity,
  resourceInstant,
  resourceText,
} from "@/lib/api/resources";

export interface TareaPlantilla {
  nombre: string;
  frecuencia: string;
  instrucciones: string | null;
  requiereLimites: boolean;
}

export interface Plantilla {
  id: number;
  nombre: string;
  descripcion: string | null;
  tipoActividad: string;
  activa: boolean;
  planes: { nombre: string; tareas: TareaPlantilla[] }[];
  puntos: string[];
}

function boolean(value: unknown): boolean {
  return typeof value === "boolean" ? value : invalidResource();
}

function list(value: unknown): unknown[] {
  return Array.isArray(value) ? value : invalidResource();
}

function parseTarea(value: unknown): TareaPlantilla {
  if (!isRecord(value)) return invalidResource();
  return {
    nombre: resourceText(value.nombre),
    frecuencia: resourceText(value.frecuencia),
    instrucciones: optionalText(value.instrucciones),
    requiereLimites: value.requiereLimites === undefined
      ? isRecord(value.configuracion) && value.configuracion.tipoRespuesta === "numero"
      : boolean(value.requiereLimites),
  };
}

export function parsePlantilla(value: unknown): Plantilla {
  const data = resourceIdentity(value, "plantillas-appcc");
  if (!isRecord(data.configuracion)) return invalidResource();
  return {
    id: data.id,
    nombre: resourceText(data.nombre),
    descripcion: optionalText(data.descripcion),
    tipoActividad: resourceText(data.tipoActividad),
    activa: boolean(data.activa),
    planes: list(data.configuracion.planes).map((plan) => {
      if (!isRecord(plan)) return invalidResource();
      return {
        nombre: resourceText(plan.nombre),
        tareas: list(plan.tareas).map(parseTarea),
      };
    }),
    puntos: list(data.configuracion.puntosControl ?? []).map((punto) => {
      if (!isRecord(punto)) return invalidResource();
      return resourceText(punto.nombre);
    }),
  };
}

export interface ResultadoAplicacion {
  aplicacionId: number;
  plantillaId: number;
  establecimientoId: number;
  yaAplicada: boolean;
  aplicadaAt: string;
  creados: { planes: number; puntos: number; tareas: number };
  tareas: { id: number; nombre: string; activa: boolean; configuracionPendiente: boolean }[];
}

function positiveId(value: unknown): number {
  return typeof value === "number" && Number.isSafeInteger(value) && value > 0
    ? value : invalidResource();
}

function count(value: unknown): number {
  return typeof value === "number" && Number.isSafeInteger(value) && value >= 0
    ? value : invalidResource();
}

function receiptResource(value: unknown, resource: string): Record<string, unknown> & { id: number; nombre: string } {
  if (!isRecord(value)) return invalidResource();
  const id = positiveId(value.id);
  if (value.iri !== `/api/${resource}/${id}`) return invalidResource();
  return { ...value, id, nombre: resourceText(value.nombre) };
}

export function parseResultado(value: unknown, plantillaId: number, tenantId: number): ResultadoAplicacion {
  if (!isRecord(value) || !isRecord(value.creados) || !isRecord(value.resultadoInicial)) {
    return invalidResource();
  }
  if (value.plantillaId !== plantillaId || value.establecimientoId !== tenantId) return invalidResource();
  const planes = list(value.resultadoInicial.planes).map((item) => receiptResource(item, "planes-control"));
  const puntos = list(value.resultadoInicial.puntos).map((item) => receiptResource(item, "puntos-control"));
  const tareas = list(value.resultadoInicial.tareas).map((item) => {
    const tarea = receiptResource(item, "tareas");
    if (!planes.some((plan) => tarea.planControl === `/api/planes-control/${plan.id}`)) return invalidResource();
    if (tarea.puntoControl != null && !puntos.some((punto) => tarea.puntoControl === `/api/puntos-control/${punto.id}`)) return invalidResource();
    return {
      id: tarea.id,
      nombre: tarea.nombre,
      activa: boolean(tarea.activa),
      configuracionPendiente: boolean(tarea.configuracionPendiente),
    };
  });
  const yaAplicada = boolean(value.yaAplicada);
  const creados = {
    planes: count(value.creados.planes),
    puntos: count(value.creados.puntos),
    tareas: count(value.creados.tareas),
  };
  if (creados.planes !== (yaAplicada ? 0 : planes.length)
    || creados.puntos !== (yaAplicada ? 0 : puntos.length)
    || creados.tareas !== (yaAplicada ? 0 : tareas.length)) return invalidResource();
  return {
    aplicacionId: positiveId(value.aplicacionId), plantillaId, establecimientoId: tenantId,
    yaAplicada, aplicadaAt: resourceInstant(value.aplicadaAt), creados, tareas,
  };
}

export const frecuencias: Record<string, string> = {
  diaria: "Diaria", semanal: "Semanal", mensual: "Mensual", bajo_demanda: "Bajo demanda",
};

export function compatible(plantilla: Plantilla, actividad: string) {
  return plantilla.tipoActividad === "otro" || plantilla.tipoActividad === actividad;
}

export interface ControlConfigurable {
  id: number;
  nombre: string;
  activa: boolean;
  limiteMinimo: string | null;
  limiteMaximo: string | null;
  unidad: string;
  instrucciones: string;
}

export function parseControl(value: unknown, id: number, tenantId: number): ControlConfigurable {
  const tarea = checkTenant(checkId(parseTareaAgenda(value), id), tenantId);
  if (!isRecord(value) || value.requiereLimites !== true
    || !isRecord(value.configuracion) || value.configuracion.tipoRespuesta !== "numero") return invalidResource();
  const minimo = optionalText(value.limiteMinimo), maximo = optionalText(value.limiteMaximo);
  if ([minimo, maximo].some((limit) => limit !== null && !decimal.test(limit))) return invalidResource();
  return {
    id: tarea.id, nombre: tarea.nombre, activa: boolean(value.activa),
    limiteMinimo: minimo, limiteMaximo: maximo,
    unidad: optionalText(value.unidad) ?? "", instrucciones: optionalText(value.instrucciones) ?? "",
  };
}
