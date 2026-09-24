import { dayBoundary, isCalendarDay } from "@/features/agenda/dates";
import type { EstadoIncidencia, GravedadIncidencia } from "./contracts";

export const PAGE_SIZE = 20;
export interface IncidenciasFiltersValue {
  estado: EstadoIncidencia | "";
  gravedad: GravedadIncidencia | "";
  registro: string;
  desde: string;
  hasta: string;
  orden: "asc" | "desc";
}
export const emptyFilters: IncidenciasFiltersValue = {
  estado: "",
  gravedad: "",
  registro: "",
  desde: "",
  hasta: "",
  orden: "desc",
};
export function filtersError(filters: IncidenciasFiltersValue): string | null {
  if (
    (filters.desde && !isCalendarDay(filters.desde)) ||
    (filters.hasta && !isCalendarDay(filters.hasta))
  )
    return "Introduce fechas válidas.";
  if (filters.desde && filters.hasta && filters.desde > filters.hasta)
    return "La fecha de inicio no puede ser posterior a la fecha de fin.";
  if (
    filters.registro &&
    (!/^[1-9][0-9]*$/.test(filters.registro) ||
      !Number.isSafeInteger(Number(filters.registro)))
  )
    return "Introduce un número de registro válido.";
  return null;
}
export function incidenciasQuery(
  filters: IncidenciasFiltersValue,
  page: number,
) {
  const query = new URLSearchParams({
    page: String(page),
    itemsPerPage: String(PAGE_SIZE),
    "order[fechaApertura]": filters.orden,
  });
  if (filters.estado) query.set("estado", filters.estado);
  if (filters.gravedad) query.set("gravedad", filters.gravedad);
  if (filters.registro)
    query.set("registro", `/api/registros/${filters.registro}`);
  if (filters.desde)
    query.set("fechaApertura[after]", dayBoundary(filters.desde, false));
  if (filters.hasta)
    query.set("fechaApertura[before]", dayBoundary(filters.hasta, true));
  return query.toString();
}
