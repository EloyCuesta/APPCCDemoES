import type { EstadoIncidencia } from "@/features/incidencias/contracts";

export function incidencia(
  id = 11,
  tenantId = 1,
  estado: EstadoIncidencia = "abierta",
) {
  return {
    "@id": `/api/incidencias/${id}`,
    id,
    establecimiento: `/api/establecimientos/${tenantId}`,
    registro: "/api/registros/8",
    titulo: `No conformidad ${id} del local ${tenantId}`,
    descripcion: "Temperatura fuera de rango.",
    gravedad: "media",
    estado,
    fechaApertura: "2026-09-24T10:00:00+02:00",
    ...(estado === "resuelta"
      ? { fechaCierre: "2026-09-24T11:30:00+02:00" }
      : {}),
  };
}
export function registro(tenantId = 1) {
  return {
    "@id": "/api/registros/8",
    id: 8,
    establecimiento: `/api/establecimientos/${tenantId}`,
    tarea: "/api/tareas/1",
    tareaProgramada: "/api/tareas-programadas/6",
    usuario: "/api/usuarios/5",
    conforme: false,
    valorNumerico: "9.000",
    datos: { resultado: false, lote: "L-42" },
    observaciones: "Observación original inmutable.",
    fechaHora: "2026-09-24T09:59:00+02:00",
    confirmadoPor: "/api/usuarios/5",
    confirmadoAt: "2026-09-24T10:00:00+02:00",
  };
}
export function accion(id = 1) {
  return {
    "@id": `/api/acciones-correctivas/${id}`,
    id,
    incidencia: "/api/incidencias/11",
    usuario: "/api/usuarios/5",
    descripcion: `Acción ${id}`,
    resultado: "Temperatura corregida",
    fechaHora: "2026-09-24T11:00:00+02:00",
  };
}
export function historial(
  id = 1,
  estadoAnterior: EstadoIncidencia | null = null,
  estadoNuevo: EstadoIncidencia = "abierta",
) {
  return {
    "@id": `/api/historiales-incidencia/${id}`,
    id,
    incidencia: "/api/incidencias/11",
    estadoAnterior,
    estadoNuevo,
    cambiadoPor: "/api/usuarios/5",
    createdAt: "2026-09-24T10:00:00+02:00",
  };
}
