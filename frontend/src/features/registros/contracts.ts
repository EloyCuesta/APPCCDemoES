import { ApiError, isRecord } from "@/lib/api/errors";
import { parseTarea, type TareaAgenda } from "@/features/agenda/contracts";
import type { RolEstablecimiento } from "@/types/session";

export function puedeRegistrar(rol: RolEstablecimiento | undefined) {
  return rol === "admin" || rol === "responsable" || rol === "trabajador";
}

export const decimal = /^-?\d{1,9}(?:\.\d{1,3})?$/;
export interface TareaRegistro extends TareaAgenda {
  instrucciones: string | null;
  unidad: string | null;
  limiteMinimo: string | null;
  limiteMaximo: string | null;
  respuesta: "numero" | "boolean" | "campos";
  campos: string[];
}
export interface EvidenciaSubida {
  token: string;
  tipo: "foto" | "documento";
  nombreOriginal: string;
  expiresAt: string;
}

export function parseTareaRegistro(value: unknown): TareaRegistro {
  const tarea = parseTarea(value);
  if (!isRecord(value)) throw new ApiError(502);
  const config = value.configuracion ?? {};
  if (!isRecord(config))
    throw new ApiError(
      422,
      "La configuración de este control no permite registrar resultados.",
    );
  const text = (field: string) => {
    const result = value[field] ?? null;
    if (result !== null && typeof result !== "string") throw new ApiError(502);
    return result as string | null;
  };
  const limiteMinimo = text("limiteMinimo"),
    limiteMaximo = text("limiteMaximo");
  for (const limit of [limiteMinimo, limiteMaximo])
    if (limit !== null && !decimal.test(limit)) throw new ApiError(502);
  const numeric =
    config.tipoRespuesta === "numero" ||
    limiteMinimo !== null ||
    limiteMaximo !== null;
  if (
    (config.tipoRespuesta != null &&
      !["numero", "boolean"].includes(String(config.tipoRespuesta))) ||
    (numeric && config.tipoRespuesta === "boolean")
  )
    throw new ApiError(
      422,
      "El tipo de respuesta de este control no está soportado.",
    );
  const campos = config.campos;
  const respuesta = numeric
    ? "numero"
    : config.tipoRespuesta === "boolean"
      ? "boolean"
      : "campos";
  if (
    respuesta === "campos" &&
    (!Array.isArray(campos) ||
      !campos.length ||
      !campos.every((c) => typeof c === "string" && c.trim()))
  ) {
    throw new ApiError(
      422,
      "El control necesita una respuesta numérica, booleana o campos configurados. Contacta con la persona responsable.",
    );
  }
  if (value.activa === false || value.configuracionPendiente === true)
    throw new ApiError(
      422,
      "El control está inactivo o pendiente de configuración.",
    );
  return {
    ...tarea,
    instrucciones: text("instrucciones"),
    unidad: text("unidad"),
    limiteMinimo,
    limiteMaximo,
    respuesta,
    campos: respuesta === "campos" ? [...new Set(campos as string[])] : [],
  };
}

/** Previsión para la interfaz. El POST deja el cálculo definitivo a Symfony. */
export function previsionConformidad(
  tarea: TareaRegistro,
  valor: string,
  resultado: string,
): boolean | null {
  if (tarea.respuesta !== "numero")
    return resultado === "" ? null : resultado === "true";
  if (!decimal.test(valor)) return null;
  const numero = Number(valor);
  return (
    (tarea.limiteMinimo === null || numero >= Number(tarea.limiteMinimo)) &&
    (tarea.limiteMaximo === null || numero <= Number(tarea.limiteMaximo))
  );
}
