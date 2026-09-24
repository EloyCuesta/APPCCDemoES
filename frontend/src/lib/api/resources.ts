import { ApiError, isRecord } from "./errors";
import { isInstant } from "@/features/agenda/dates";

type Resource = "incidencias" | "registros" | "establecimientos" | "usuarios" | "tareas" | "tareas-programadas" | "evidencias";
export function invalidResource(): never { throw new ApiError(502, "La API ha devuelto datos no válidos. Vuelve a consultar."); }
export function resourceId(value: unknown, resource: Resource): number {
  const match = typeof value === "string" && new RegExp(`^/api/${resource}/([1-9][0-9]*)$`).exec(value);
  const id = match ? Number(match[1]) : 0;
  if (!Number.isSafeInteger(id) || id <= 0) return invalidResource();
  return id;
}
export function resourceRelation(value: unknown, resource: Resource): string {
  return `/api/${resource}/${resourceId(value, resource)}`;
}
export function resourceIdentity(value: unknown, resource: string): Record<string, unknown> & { id: number } {
  if (!isRecord(value) || typeof value.id !== "number" || !Number.isSafeInteger(value.id) || value.id <= 0) return invalidResource();
  if (value["@id"] !== undefined && value["@id"] !== `/api/${resource}/${value.id}`) return invalidResource();
  return { ...value, id: value.id };
}
export function resourceText(value: unknown): string { return typeof value === "string" ? value : invalidResource(); }
export function optionalText(value: unknown): string | null { return value == null ? null : resourceText(value); }
export function resourceInstant(value: unknown): string { return isInstant(value) ? value : invalidResource(); }
