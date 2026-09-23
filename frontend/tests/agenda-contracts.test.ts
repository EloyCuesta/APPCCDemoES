import { describe, expect, it } from "vitest";
import { parseProgramacion, parseTarea, parsePlan, parseResponsable } from "@/features/agenda/contracts";
import { agendaQuery, emptyFilters, filtersError } from "@/features/agenda/query";
import { dayBoundary, isInstant, localDay } from "@/features/agenda/dates";
import { groupAgenda } from "@/features/agenda/grouping";
import { plan, task, person, scheduled, localInstant } from "./agenda-fixtures";

describe("contratos reales y calendario de agenda", () => {
  it("admite JSON-LD y campos nulos omitidos por API Platform", () => {
    expect(parseProgramacion(scheduled())).toMatchObject({ id: 1, asignadoA: null, fechaLimite: null });
    expect(parseProgramacion(scheduled(1, 1, { asignadoA: null, fechaLimite: null }))).toMatchObject({ asignadoA: null, fechaLimite: null });
    expect(parseTarea({ ...task(), horaPrevista: undefined }).horaPrevista).toBeNull();
  });

  it("admite el objeto JSON sin metadatos JSON-LD", () => {
    expect(parseProgramacion({ ...scheduled(), "@id": undefined, "@type": undefined }).id).toBe(1);
  });

  it.each([
    { estado: "bloqueada" }, { id: "1" }, { tarea: "https://otro.example/api/tareas/1" },
    { establecimiento: "/api/establecimientos/0" }, { asignadoA: { id: 5 } }, { tarea: undefined },
    { "@id": "/api/tareas-programadas/2" }, { fechaProgramada: "2026-02-30T10:00:00Z" },
    { fechaProgramada: "2026-09-23T10:00:00" }, { fechaLimite: 0 },
  ])("rechaza datos inválidos sin casts: %j", (change) => {
    expect(() => parseProgramacion({ ...scheduled(), ...change })).toThrow("datos de agenda no válidos");
  });

  it("valida frecuencia, hora HH:mm:ss y tipo de control", () => {
    expect(() => parseTarea({ ...task(), frecuencia: "anual" })).toThrow();
    expect(() => parseTarea({ ...task(), horaPrevista: "25:00:00" })).toThrow();
    expect(() => parsePlan({ ...plan(), tipo: "inventado" })).toThrow();
    expect(parseResponsable(person()).nombre).toBe("Persona 5");
  });

  it("compone solo filtros soportados, IRI completas y orden estable", () => {
    const query = new URLSearchParams(agendaQuery({ desde: "2026-09-23", hasta: "2026-09-23", estado: "vencida", asignadoA: "/api/usuarios/5", tarea: "/api/tareas/9" }, 3));
    expect(query.get("fechaProgramada[after]")).toBe(dayBoundary("2026-09-23", false));
    expect(query.get("fechaProgramada[before]")).toBe(dayBoundary("2026-09-23", true));
    expect(query.get("asignadoA")).toBe("/api/usuarios/5"); expect(query.get("tarea")).toBe("/api/tareas/9");
    expect(query.get("estado")).toBe("vencida"); expect(query.get("page")).toBe("3");
    expect(query.get("itemsPerPage")).toBe("20"); expect(query.get("order[fechaProgramada]")).toBe("asc");
    expect(new URLSearchParams(agendaQuery(emptyFilters, 1)).size).toBe(3);
  });

  it("valida rangos y rechaza fechas imposibles", () => {
    expect(filtersError({ ...emptyFilters, desde: "2026-09-24", hasta: "2026-09-23" })).toContain("posterior");
    expect(filtersError({ ...emptyFilters, desde: "2026-02-30" })).toContain("válidas");
    expect(isInstant("2026-09-23T12:00:00+14:59")).toBe(false);
  });

  it("los extremos del día conservan el horario local, incluso al cambiar el offset", () => {
    for (const day of ["2026-03-29", "2026-10-25"]) {
      const start = new Date(dayBoundary(day, false)); const end = new Date(dayBoundary(day, true));
      expect(localDay(start)).toBe(day); expect(start.getHours()).toBe(0);
      expect(localDay(end)).toBe(day); expect(end.getHours()).toBe(23); expect(end.getSeconds()).toBe(59);
    }
  });

  it("agrupa vencidas, hoy y próximas sin convertir pendientes antiguas en vencidas", () => {
    const items = [
      scheduled(1, 1, { estado: "vencida", fechaProgramada: localInstant(-1) }),
      scheduled(2, 1, { fechaProgramada: localInstant(0) }),
      scheduled(3, 1, { fechaProgramada: localInstant(2) }),
      scheduled(4, 1, { fechaProgramada: localInstant(-2) }),
    ].map((value) => ({ programacion: parseProgramacion(value), tarea: parseTarea(task(value.id)), plan: parsePlan(plan()), responsable: null }));
    const grouped = groupAgenda(items, localDay(new Date()));
    expect(grouped.map((group) => [group.key, group.items.map((item) => item.programacion.id)])).toEqual([
      ["vencidas", [1]], ["anteriores", [4]], ["hoy", [2]], ["proximas", [3]],
    ]);
    expect(items[3].programacion.estado).toBe("pendiente");
  });
});
