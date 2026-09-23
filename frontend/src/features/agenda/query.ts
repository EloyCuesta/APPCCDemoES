import type { EstadoProgramacion } from "./contracts";
import { dayBoundary, isCalendarDay } from "./dates";

export interface AgendaFiltersValue {
  desde: string; hasta: string;
  estado: Extract<EstadoProgramacion, "pendiente" | "vencida"> | "";
  asignadoA: string; tarea: string;
}
export const emptyFilters: AgendaFiltersValue = { desde: "", hasta: "", estado: "", asignadoA: "", tarea: "" };
export const PAGE_SIZE = 20;

export function filtersError(filters: AgendaFiltersValue): string | null {
  if ((filters.desde && !isCalendarDay(filters.desde)) || (filters.hasta && !isCalendarDay(filters.hasta))) return "Introduce fechas válidas.";
  if (filters.desde && filters.hasta && filters.desde > filters.hasta) return "La fecha de inicio no puede ser posterior a la fecha de fin.";
  return null;
}

export function agendaQuery(filters: AgendaFiltersValue, page: number): string {
  const query = new URLSearchParams({ page: String(page), itemsPerPage: String(PAGE_SIZE), "order[fechaProgramada]": "asc" });
  if (filters.desde) query.set("fechaProgramada[after]", dayBoundary(filters.desde, false));
  if (filters.hasta) query.set("fechaProgramada[before]", dayBoundary(filters.hasta, true));
  if (filters.estado) query.set("estado", filters.estado);
  if (filters.asignadoA) query.set("asignadoA", filters.asignadoA);
  if (filters.tarea) query.set("tarea", filters.tarea);
  return query.toString();
}
