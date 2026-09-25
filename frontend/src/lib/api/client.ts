import { ApiError, responseError } from "./errors";
import type { Endpoint } from "./endpoints";

interface ApiContext {
  getToken(): string | null;
  getEstablecimientoId(): number | null;
  onUnauthorized(): void;
}

interface RequestOptions {
  method?: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
  body?: unknown;
  signal?: AbortSignal;
}

export interface BinaryResponse {
  blob: Blob;
  contentDisposition: string | null;
}

export class ApiClient {
  private pending = new AbortController();

  constructor(
    private readonly baseUrl: string,
    private readonly context: ApiContext,
  ) {}

  cancelPending() {
    this.pending.abort();
    this.pending = new AbortController();
  }

  async request<T = unknown>(
    endpoint: Endpoint,
    options: RequestOptions = {},
  ): Promise<T> {
    return await this.send(endpoint, options, false) as T;
  }

  async download(endpoint: Endpoint, options: Pick<RequestOptions, "signal"> = {}): Promise<BinaryResponse> {
    return await this.send(endpoint, options, true) as BinaryResponse;
  }

  private async send(endpoint: Endpoint, options: RequestOptions, binary: boolean): Promise<unknown> {
    // Una continuación de un contexto anterior no debe iniciar otra petición.
    options.signal?.throwIfAborted();
    let base: URL;
    try {
      base = new URL(this.baseUrl);
      if (
        !/^https?:$/.test(base.protocol) ||
        base.username ||
        base.password ||
        base.search ||
        base.hash
      )
        throw new Error();
    } catch {
      throw new ApiError(
        0,
        "La conexión con la API no está configurada. Contacta con la persona administradora.",
      );
    }
    if (
      !endpoint.path.startsWith("/api/") ||
      endpoint.path.includes("..") ||
      endpoint.path.includes("\\")
    ) {
      throw new ApiError(400);
    }
    const headers = new Headers({ Accept: endpoint.accept });
    if (endpoint.scope !== "public") {
      const token = this.context.getToken();
      if (!token) {
        this.context.onUnauthorized();
        throw new ApiError(401);
      }
      headers.set("Authorization", `Bearer ${token}`);
    }
    if (endpoint.scope === "tenant") {
      const id = this.context.getEstablecimientoId();
      if (!id)
        throw new ApiError(
          400,
          "Selecciona un establecimiento para continuar.",
        );
      headers.set("X-Establecimiento-Id", String(id));
    }
    const multipart = options.body instanceof FormData;
    if (options.body !== undefined && !multipart) {
      headers.set(
        "Content-Type",
        options.method === "PATCH"
          ? "application/merge-patch+json"
          : endpoint.accept,
      );
    }
    const timeout = new AbortController();
    const timer = setTimeout(() => timeout.abort(), 15_000);
    const cancelled = AbortSignal.any([
      this.pending.signal,
      ...(options.signal ? [options.signal] : []),
    ]);
    const signal = AbortSignal.any([cancelled, timeout.signal]);
    try {
      const response = await fetch(
        `${base.href.replace(/\/$/, "")}${endpoint.path}`,
        {
          method: options.method ?? "GET",
          headers,
          body:
            options.body === undefined
              ? undefined
              : multipart ? options.body as FormData : JSON.stringify(options.body),
          cache: "no-store",
          credentials: "omit",
          redirect: "error",
          signal,
        },
      );
      let data: unknown = null;
      // Los errores siempre usan la misma semántica JSON. Nunca se descargan como archivos.
      if (!binary || !response.ok) {
        try {
          data = await response.json();
        } catch {
          data = null;
        }
      }
      // Also protects against transports that complete after being aborted.
      cancelled.throwIfAborted();
      if (timeout.signal.aborted)
        throw new ApiError(
          0,
          "La API está tardando demasiado. Vuelve a intentarlo.",
        );
      if (!response.ok) {
        const error = responseError(
          response.status,
          data,
          endpoint.scope === "public",
        );
        if (response.status === 401 && endpoint.scope !== "public")
          this.context.onUnauthorized();
        throw error;
      }
      if (binary) {
        const blob = await response.blob();
        cancelled.throwIfAborted();
        if (timeout.signal.aborted) throw new ApiError(0, "La API está tardando demasiado. Vuelve a intentarlo.");
        if (blob.size === 0) throw new ApiError(502, "La API ha devuelto un archivo vacío.");
        return { blob, contentDisposition: response.headers.get("Content-Disposition") } satisfies BinaryResponse;
      }
      if (data === null && response.status !== 204)
        throw new ApiError(502, "La API ha devuelto una respuesta no válida.");
      return data;
    } catch (error) {
      if (error instanceof ApiError) throw error;
      cancelled.throwIfAborted();
      throw new ApiError(
        0,
        timeout.signal.aborted
          ? "La API está tardando demasiado. Vuelve a intentarlo."
          : undefined,
      );
    } finally {
      clearTimeout(timer);
    }
  }
}
