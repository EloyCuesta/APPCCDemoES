import type { AgendaItem } from "./contracts";
import { localDay } from "./dates";

export type AgendaGroupKey = "vencidas" | "anteriores" | "hoy" | "proximas";
export interface AgendaGroup { key: AgendaGroupKey; title: string; description: string; items: AgendaItem[] }

export function groupAgenda(items: AgendaItem[], today: string): AgendaGroup[] {
  const groups: Record<AgendaGroupKey, AgendaGroup> = {
    vencidas: { key: "vencidas", title: "Vencidas", description: "Controles marcados como vencidos por la API.", items: [] },
    anteriores: { key: "anteriores", title: "Pendientes de días anteriores", description: "Conservan el estado pendiente indicado por la API.", items: [] },
    hoy: { key: "hoy", title: "Hoy", description: "Controles pendientes programados para hoy.", items: [] },
    proximas: { key: "proximas", title: "Próximas", description: "Controles pendientes de los próximos días.", items: [] },
  };
  for (const item of items) {
    const day = localDay(new Date(item.programacion.fechaProgramada));
    const key = item.programacion.estado === "vencida" ? "vencidas" : day < today ? "anteriores" : day === today ? "hoy" : "proximas";
    groups[key].items.push(item);
  }
  return Object.values(groups);
}
