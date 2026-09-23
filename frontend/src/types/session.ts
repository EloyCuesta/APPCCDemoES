export type RolEstablecimiento =
  "admin" | "responsable" | "trabajador" | "auditor";

export interface Usuario {
  id: number;
  nombre: string;
  apellidos: string;
  email: string;
}

export interface Establecimiento {
  id: number;
  iri: string;
  nombre: string;
  tipoActividad: string;
  entidadFiscal: { id: number; nombre: string };
}

export interface Membresia {
  id: number;
  iri: string;
  rol: RolEstablecimiento;
  establecimiento: Establecimiento;
}

/** Respuesta JSON simple de GET /api/me, independiente de JSON-LD. */
export interface ContextoSesion extends Usuario {
  membresias: Membresia[];
  establecimientoPredeterminadoId: number | null;
}

export interface AuthSession {
  user: Usuario;
  membresias: Membresia[];
  establecimientoActual: Establecimiento | null;
}

export interface LoginInput {
  email: string;
  password: string;
}
export interface LoginResponse {
  token: string;
}
