import type { Programacion } from "@/features/agenda/contracts";

// Real wire shape: optional null fields are omitted by API Platform.
export function scheduled(id = 1, tenantId = 1, changes: Partial<Programacion> = {}) {
  return {
    "@id": `/api/tareas-programadas/${id}`, "@type": "TareaProgramada", id,
    tarea: `/api/tareas/${id}`, establecimiento: `/api/establecimientos/${tenantId}`,
    fechaProgramada: "2026-09-23T10:00:00+02:00", estado: "pendiente", ...changes,
  };
}

export function task(id = 1, tenantId = 1) {
  return { "@id": `/api/tareas/${id}`, id, nombre: `Control ${id}`, establecimiento: `/api/establecimientos/${tenantId}`, planControl: `/api/planes-control/${tenantId}`, frecuencia: "diaria", horaPrevista: "10:00:00" };
}

export function plan(id = 1, tenantId = 1) {
  return { "@id": `/api/planes-control/${id}`, id, nombre: `Plan de temperaturas ${tenantId}`, tipo: "temperaturas", establecimiento: `/api/establecimientos/${tenantId}` };
}

export function person(id = 5) { return { "@id": `/api/usuarios/${id}`, id, nombre: `Persona ${id}`, apellidos: "Prueba" }; }

export function collection(items: unknown[], total = items.length, next: string | null = null) {
  return { "@type": "Collection", member: items, totalItems: total, ...(next ? { view: { next } } : {}) };
}

export function localInstant(dayOffset: number, hours = 10) {
  const value = new Date(); value.setDate(value.getDate() + dayOffset); value.setHours(hours, 0, 0, 0);
  return value.toISOString().replace(".000Z", "Z");
}
