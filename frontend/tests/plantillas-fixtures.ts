import { task } from "./agenda-fixtures";

export function plantilla(id = 1, actividad = "restaurante", activa = true) {
  return {
    "@id": `/api/plantillas-appcc/${id}`, id, nombre: `Plantilla ${actividad}`,
    descripcion: "Controles iniciales para adaptar al establecimiento.", tipoActividad: actividad, activa,
    configuracion: {
      puntosControl: [{ clave: "frio", nombre: "Cámara frigorífica" }],
      planes: [{ nombre: "Plan de temperaturas", tareas: [{
        nombre: "Temperatura de conservación", frecuencia: "diaria", requiereLimites: true,
        instrucciones: "Identificar el equipo y medir.", configuracion: { tipoRespuesta: "numero" },
      }, { nombre: "Limpieza de cocina", frecuencia: "diaria", configuracion: { tipoRespuesta: "boolean" } }] }],
    },
  };
}

export function recibo(tenantId = 1, yaAplicada = false) {
  return {
    aplicacionId: 9, plantillaId: 1, establecimientoId: tenantId, yaAplicada,
    aplicadaAt: "2026-09-25T10:00:00+00:00",
    creados: yaAplicada ? { planes: 0, puntos: 0, tareas: 2 } : { planes: 1, puntos: 1, tareas: 2 },
    resultadoInicial: {
      planes: [{ id: 21, iri: "/api/planes-control/21", nombre: "Plan de temperaturas" }],
      puntos: [{ id: 31, iri: "/api/puntos-control/31", nombre: "Cámara frigorífica" }],
      tareas: [{ id: 41, iri: "/api/tareas/41", nombre: "Temperatura de conservación", activa: false,
        configuracionPendiente: true, planControl: "/api/planes-control/21", puntoControl: "/api/puntos-control/31" },
      { id: 42, iri: "/api/tareas/42", nombre: "Limpieza de cocina", activa: true,
        configuracionPendiente: false, planControl: "/api/planes-control/21", puntoControl: "/api/puntos-control/31" }],
    },
  };
}

export function control(tenantId = 1, activa = false) {
  return { ...task(41, tenantId), nombre: "Temperatura de conservación", activa, requiereLimites: true,
    configuracion: { tipoRespuesta: "numero" }, limiteMinimo: activa ? "0.000" : null,
    limiteMaximo: activa ? "5.000" : null, unidad: "°C", instrucciones: "Identificar el equipo y medir." };
}
