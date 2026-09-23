"use client";

import { useEffect, useState } from "react";
import { useApi } from "@/hooks/use-api";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { endpoints } from "@/lib/api/endpoints";
import { ApiError, asApiError, isAborted, isRecord } from "@/lib/api/errors";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";

type Connection =
  | { status: "loading" }
  | { status: "connected"; checkedAt: string }
  | { status: "error"; error: ApiError };

export function ConnectionStatus({
  establecimientoId,
}: {
  establecimientoId: number;
}) {
  const api = useApi();
  const { refresh } = useEstablecimiento();
  const [attempt, setAttempt] = useState(0);
  const [connection, setConnection] = useState<Connection>({
    status: "loading",
  });
  useEffect(() => {
    const controller = new AbortController();
    api
      .request(endpoints.establecimiento(establecimientoId), {
        signal: controller.signal,
      })
      .then((data) => {
        if (
          !isRecord(data) ||
          data.id !== establecimientoId ||
          typeof data.nombre !== "string"
        )
          throw new ApiError(502);
        if (!controller.signal.aborted)
          setConnection({
            status: "connected",
            checkedAt: new Date().toLocaleTimeString("es-ES", {
              hour: "2-digit",
              minute: "2-digit",
            }),
          });
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted && !isAborted(error))
          setConnection({ status: "error", error: asApiError(error) });
      });
    return () => controller.abort();
  }, [api, establecimientoId, attempt]);

  function retry() {
    setConnection({ status: "loading" });
    setAttempt((value) => value + 1);
  }
  return (
    <section
      className="card connection-card"
      aria-labelledby="connection-title"
    >
      <div className="section-heading">
        <h2 id="connection-title">Conexión con la API</h2>
        <span className="eyebrow">ESTADO</span>
      </div>
      {connection.status === "loading" && (
        <LoadingState message="Comprobando el acceso al establecimiento…" />
      )}
      {connection.status === "connected" && (
        <>
          <div className="connection-success" role="status">
            <span className="status-dot" />
            Conexión verificada
          </div>
          <p className="muted">
            Acceso al establecimiento confirmado a las {connection.checkedAt}.
          </p>
          <button className="button button-secondary" onClick={retry}>
            Comprobar de nuevo
          </button>
        </>
      )}
      {connection.status === "error" && (
        <>
          <ErrorNotice
            error={connection.error}
            onRetry={connection.error.retryable ? retry : undefined}
          />
          {[403, 404].includes(connection.error.status) && (
            <button
              className="button button-secondary"
              onClick={() => void refresh()}
            >
              Actualizar mis accesos
            </button>
          )}
        </>
      )}
    </section>
  );
}
