import type { ContextoSesion, Membresia } from "@/types/session";

// Fixtures only for isolated tests; never imported by application code.
export function membership(id: number): Membresia {
  return {
    id,
    iri: `/api/usuarios-establecimientos/${id}`,
    rol: "responsable",
    establecimiento: {
      id,
      iri: `/api/establecimientos/${id}`,
      nombre: `Establecimiento ${id}`,
      tipoActividad: "restaurante",
      entidadFiscal: { id, nombre: `Empresa ${id}` },
    },
  };
}

export function context(ids = [1]): ContextoSesion {
  return {
    id: 5,
    nombre: "Ana",
    apellidos: "García",
    email: "ana@example.test",
    membresias: ids.map(membership),
    establecimientoPredeterminadoId: ids.length === 1 ? ids[0] : null,
  };
}

export function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

export function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => {
    resolve = done;
  });
  return { promise, resolve };
}
