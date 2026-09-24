import { dayBoundary, isCalendarDay } from "@/features/agenda/dates";

export interface HistoricoFiltersValue {
  tarea: string; usuario: string; conforme: "" | "true" | "false"; desde: string; hasta: string; orden: "asc" | "desc";
}
export const emptyFilters: HistoricoFiltersValue = { tarea: "", usuario: "", conforme: "", desde: "", hasta: "", orden: "desc" };
export function filtersError(filters: HistoricoFiltersValue): string | null {
  if ((filters.desde && !isCalendarDay(filters.desde)) || (filters.hasta && !isCalendarDay(filters.hasta))) return "Introduce fechas válidas.";
  if (filters.desde && filters.hasta && filters.desde > filters.hasta) return "La fecha de inicio no puede ser posterior a la fecha de fin.";
  return null;
}
export function historicoQuery(filters: HistoricoFiltersValue, page: number) {
  const query = new URLSearchParams({ page: String(page), itemsPerPage: "20", "order[fechaHora]": filters.orden });
  if (filters.tarea) query.set("tarea", filters.tarea);
  if (filters.usuario) query.set("usuario", filters.usuario);
  if (filters.conforme) query.set("conforme", filters.conforme);
  if (filters.desde) query.set("fechaHora[after]", dayBoundary(filters.desde, false));
  if (filters.hasta) query.set("fechaHora[before]", dayBoundary(filters.hasta, true));
  return query.toString();
}
