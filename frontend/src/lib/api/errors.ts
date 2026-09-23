export interface ValidationViolation {
  field: string;
  message: string;
}

const messages: Record<number, string> = {
  0: "No se pudo conectar con la API. Comprueba la conexión y vuelve a intentarlo.",
  400: "La solicitud no es válida. Revisa los datos introducidos.",
  401: "Tu sesión ha caducado o ya no es válida. Vuelve a iniciar sesión.",
  403: "No tienes permisos para acceder a este recurso o establecimiento.",
  404: "No se ha encontrado el recurso solicitado.",
  409: "Los datos han cambiado o existe un conflicto. Actualiza e inténtalo de nuevo.",
  422: "No se pudieron validar los datos. Revisa los campos indicados.",
  429: "Se han realizado demasiados intentos. Espera un momento y vuelve a intentarlo.",
};

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message = messages[status] ??
      "El servicio no está disponible. Inténtalo de nuevo más tarde.",
    public readonly violations: ValidationViolation[] = [],
  ) {
    super(message);
    this.name = "ApiError";
  }

  get retryable() {
    return (
      this.status === 0 ||
      this.status === 409 ||
      this.status === 429 ||
      this.status >= 500
    );
  }
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

// Only validation/domain responses can supply UI text; never display traces or 5xx bodies.
function domainMessage(value: unknown): string | undefined {
  if (typeof value !== "string" || !value.trim() || value.length > 500) return;
  if (
    /SQLSTATE|stack\s?trace|exception|vendor[\\/]|[A-Z]:\\|\/var\/|<[^>]+>/i.test(
      value,
    )
  )
    return;
  return value.trim();
}

export function responseError(
  status: number,
  body: unknown,
  login = false,
): ApiError {
  if (status === 401 && login)
    return new ApiError(
      401,
      "Email o contraseña incorrectos, o cuenta no disponible.",
    );
  if (status !== 422 || !isRecord(body)) return new ApiError(status);
  const violations = Array.isArray(body.violations)
    ? body.violations.flatMap((item) => {
        if (!isRecord(item)) return [];
        const message = domainMessage(item.message);
        return message
          ? [{ field: domainMessage(item.propertyPath) ?? "", message }]
          : [];
      })
    : [];
  return new ApiError(
    status,
    domainMessage(body.detail ?? body["hydra:description"]),
    violations,
  );
}

export function asApiError(error: unknown): ApiError {
  return error instanceof ApiError ? error : new ApiError(0);
}

export function isAborted(error: unknown): boolean {
  return error instanceof Error && error.name === "AbortError";
}
