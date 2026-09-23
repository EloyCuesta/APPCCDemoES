import { ApiError, isRecord } from "@/lib/api/errors";
import type {
  ContextoSesion,
  Establecimiento,
  LoginResponse,
  Membresia,
} from "@/types/session";

const validId = (value: unknown): value is number =>
  typeof value === "number" && Number.isSafeInteger(value) && value > 0;
const text = (value: unknown): value is string => typeof value === "string";

function establecimiento(value: unknown): value is Establecimiento {
  return (
    isRecord(value) &&
    validId(value.id) &&
    text(value.iri) &&
    text(value.nombre) &&
    text(value.tipoActividad) &&
    isRecord(value.entidadFiscal) &&
    validId(value.entidadFiscal.id) &&
    text(value.entidadFiscal.nombre)
  );
}

function membresia(value: unknown): value is Membresia {
  return (
    isRecord(value) &&
    validId(value.id) &&
    text(value.iri) &&
    ["admin", "responsable", "trabajador", "auditor"].includes(
      String(value.rol),
    ) &&
    establecimiento(value.establecimiento)
  );
}

export function parseContexto(data: unknown): ContextoSesion {
  if (
    !isRecord(data) ||
    !validId(data.id) ||
    !text(data.nombre) ||
    !text(data.apellidos) ||
    !text(data.email) ||
    !Array.isArray(data.membresias) ||
    !data.membresias.every(membresia) ||
    !(
      data.establecimientoPredeterminadoId === null ||
      validId(data.establecimientoPredeterminadoId)
    )
  ) {
    throw new ApiError(
      502,
      "No se ha podido interpretar el contexto de la sesión.",
    );
  }
  return {
    id: data.id,
    nombre: data.nombre,
    apellidos: data.apellidos,
    email: data.email,
    membresias: data.membresias,
    establecimientoPredeterminadoId: data.establecimientoPredeterminadoId,
  };
}

export function parseLogin(data: unknown): LoginResponse {
  if (!isRecord(data) || typeof data.token !== "string" || !data.token.trim())
    throw new ApiError(502);
  return { token: data.token };
}
