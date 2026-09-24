"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useApi } from "@/hooks/use-api";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";
import { ApiError, asApiError, isAborted } from "@/lib/api/errors";
import {
  estados,
  gravedades,
  puedeAnadirAccion,
  puedeCambiarEstado,
} from "./contracts";
import {
  loadDetalle,
  writeIncidencia,
  type EscrituraIncidencia,
} from "./service";
import { useConsulta } from "@/hooks/use-consulta";
import { Pagination } from "@/components/ui/pagination";
import { IncidenciaContent } from "./incidencia-content";
import { DateTime } from "@/components/ui/date-time";

export function IncidenciaDetail({
  id,
  tenantId,
  onBack,
  onChanged,
}: {
  id: number;
  tenantId: number;
  onBack: () => void;
  onChanged: () => void;
}) {
  const api = useApi();
  const { membresiaActual, refresh } = useEstablecimiento();
  const [accionesPage, setAccionesPage] = useState(1);
  const [historialPage, setHistorialPage] = useState(1);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState("");
  const [incierto, setIncierto] = useState(false);
  const [descripcion, setDescripcion] = useState("");
  const [resultado, setResultado] = useState("");
  const controller = useRef<AbortController | null>(null);
  const locked = useRef(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const load = useCallback(
    (signal: AbortSignal) =>
      loadDetalle(api, id, tenantId, accionesPage, historialPage, signal),
    [api, id, tenantId, accionesPage, historialPage],
  );
  const { state, retry } = useConsulta(load);
  useEffect(() => {
    const current = new AbortController();
    controller.current = current;
    heading.current?.focus();
    return () => current.abort();
  }, []);
  function reload() {
    setIncierto(false);
    setError(null);
    setNotice("");
    retry();
  }
  async function write(input: EscrituraIncidencia) {
    if (
      locked.current ||
      incierto ||
      state.status !== "ready" ||
      !controller.current ||
      controller.current.signal.aborted
    )
      return;
    const allowed =
      input.tipo === "estado"
        ? puedeCambiarEstado(membresiaActual?.rol)
        : puedeAnadirAccion(membresiaActual?.rol);
    if (!allowed) {
      setError(new ApiError(403));
      return;
    }
    locked.current = true;
    setBusy(true);
    setError(null);
    setNotice("");
    const signal = controller.current.signal;
    try {
      await writeIncidencia(api, id, input, signal);
      signal.throwIfAborted();
      if (input.tipo === "accion") {
        setDescripcion("");
        setResultado("");
      }
      setNotice(
        input.tipo === "estado"
          ? "Cambio de estado guardado. Datos consultados de nuevo en el servidor."
          : "Acción correctiva guardada. Datos consultados de nuevo en el servidor.",
      );
    } catch (cause) {
      if (!signal.aborted && !isAborted(cause)) {
        const parsed = asApiError(cause);
        setError(parsed);
        setIncierto([0, 409].includes(parsed.status) || parsed.status >= 500);
      }
    } finally {
      locked.current = false;
      if (!signal.aborted) {
        setBusy(false);
        setAccionesPage(1);
        setHistorialPage(1);
        // No se usa la respuesta de escritura como estado local. También se consulta tras un error.
        retry();
        onChanged();
      }
    }
  }
  const disabled = busy || incierto;
  const data = state.status === "ready" ? state.data : null;
  return (
    <section
      className="incidencia-detail"
      aria-label="Detalle de incidencia"
      aria-busy={busy || state.status === "loading"}
    >
      <div className="incidencia-toolbar">
        <button
          type="button"
          className="button button-secondary"
          onClick={onBack}
        >
          Volver a incidencias
        </button>
        <button
          type="button"
          className="button button-secondary"
          disabled={busy || state.status === "loading"}
          onClick={reload}
        >
          Volver a consultar la incidencia
        </button>
      </div>
      <h1 ref={heading} tabIndex={-1}>
        Incidencia #{id}
      </h1>
      {error && <ErrorNotice error={error} />}
      {incierto && (
        <p className="error-notice" role="status">
          La operación pudo completarse o la incidencia pudo cambiar. Comprueba
          el detalle y vuelve a consultar la incidencia antes de enviar otra
          operación.
        </p>
      )}
      {(error && [403, 404].includes(error.status)) ||
      (state.status === "error" && [403, 404].includes(state.error.status)) ? (
        <button
          type="button"
          className="button button-secondary"
          onClick={() => void refresh()}
        >
          Actualizar mis accesos
        </button>
      ) : null}
      {state.status === "loading" && (
        <LoadingState message="Consultando incidencia, registro, acciones e historial…" />
      )}
      {state.status === "error" && (
        <ErrorNotice
          error={state.error}
          onRetry={state.error.retryable ? retry : undefined}
        />
      )}
      {data && (
        <>
          {notice && (
            <p className="card registro-success" role="status">
              {notice}
            </p>
          )}
          <div className="card incidencia-summary">
            <h2>{data.incidencia.titulo}</h2>
            <p>
              <span
                className={`incidencia-state incidencia-state-${data.incidencia.estado}`}
              >
                {estados[data.incidencia.estado]}
              </span>{" "}
              · Gravedad: {gravedades[data.incidencia.gravedad]}
            </p>
            <h3>Descripción de la incidencia</h3>
            <p className="incidencia-text">{data.incidencia.descripcion}</p>
            {membresiaActual?.rol === "auditor" && (
              <p className="muted">Acceso de auditor: solo lectura.</p>
            )}
            {data.incidencia.estado !== "resuelta" &&
              puedeCambiarEstado(membresiaActual?.rol) && (
                <div className="incidencia-toolbar">
                  {data.incidencia.estado === "abierta" && (
                    <button
                      type="button"
                      className="button button-secondary"
                      disabled={disabled}
                      onClick={() =>
                        void write({ tipo: "estado", estado: "en_proceso" })
                      }
                    >
                      Poner en proceso
                    </button>
                  )}
                  <button
                    type="button"
                    className="button button-primary"
                    disabled={disabled}
                    onClick={() =>
                      void write({ tipo: "estado", estado: "resuelta" })
                    }
                  >
                    Resolver incidencia
                  </button>
                </div>
              )}
          </div>
          <IncidenciaContent data={data} />
          <section
            className="card incidencia-section"
            aria-label="Acciones correctivas"
          >
            <h2>Acciones correctivas</h2>
            {data.acciones.items.length === 0 ? (
              <p className="muted">
                No hay acciones correctivas en esta página.
              </p>
            ) : (
              <ol className="incidencia-history">
                {data.acciones.items.map((a) => (
                  <li key={a.id}>
                    <p className="incidencia-text">
                      <strong>{a.descripcion}</strong>
                    </p>
                    <p className="incidencia-text">
                      Resultado: {a.resultado ?? "Sin resultado indicado"}
                    </p>
                    <p className="muted">
                      {data.usuarios[a.usuario]} ·{" "}
                      <DateTime value={a.fechaHora} />
                    </p>
                  </li>
                ))}
              </ol>
            )}
            <Pagination
              label="acciones correctivas"
              data={data.acciones}
              page={accionesPage}
              onPage={setAccionesPage}
              disabled={busy}
            />
            {data.incidencia.estado !== "resuelta" &&
              puedeAnadirAccion(membresiaActual?.rol) && (
                <AccionForm
                  disabled={disabled}
                  onSubmit={write}
                  descripcion={descripcion}
                  resultado={resultado}
                  setDescripcion={setDescripcion}
                  setResultado={setResultado}
                />
              )}
          </section>
          <section
            className="card incidencia-section"
            aria-label="Historial de estados"
          >
            <h2>Historial de estados</h2>
            {data.historial.items.length === 0 ? (
              <p className="muted">No hay cambios de estado en esta página.</p>
            ) : (
              <ol className="incidencia-history">
                {data.historial.items.map((h) => (
                  <li key={h.id}>
                    <p>
                      <strong>
                        {h.estadoAnterior
                          ? estados[h.estadoAnterior]
                          : "Creación"}{" "}
                        → {estados[h.estadoNuevo]}
                      </strong>
                    </p>
                    <p className="muted">
                      {h.cambiadoPor
                        ? data.usuarios[h.cambiadoPor]
                        : "Sin autor registrado"}{" "}
                      · <DateTime value={h.createdAt} />
                    </p>
                    {h.comentario && (
                      <p className="incidencia-text">{h.comentario}</p>
                    )}
                  </li>
                ))}
              </ol>
            )}
            <Pagination
              label="historial"
              data={data.historial}
              page={historialPage}
              onPage={setHistorialPage}
              disabled={busy}
            />
          </section>
        </>
      )}
      {busy && <LoadingState message="Guardando y consultando el servidor…" />}
    </section>
  );
}

function AccionForm({
  disabled,
  onSubmit,
  descripcion,
  resultado,
  setDescripcion,
  setResultado,
}: {
  disabled: boolean;
  onSubmit: (input: EscrituraIncidencia) => Promise<void>;
  descripcion: string;
  resultado: string;
  setDescripcion: (value: string) => void;
  setResultado: (value: string) => void;
}) {
  return (
    <form
      className="incidencia-action-form"
      onSubmit={(e) => {
        e.preventDefault();
        void onSubmit({ tipo: "accion", descripcion, resultado });
      }}
    >
      <fieldset disabled={disabled}>
        <legend>Añadir acción correctiva</legend>
        <label htmlFor="accion-descripcion">Descripción de la acción</label>
        <textarea
          id="accion-descripcion"
          required
          rows={3}
          value={descripcion}
          onChange={(e) => setDescripcion(e.target.value)}
        />
        <label htmlFor="accion-resultado">
          Resultado de la acción (opcional)
        </label>
        <textarea
          id="accion-resultado"
          rows={3}
          value={resultado}
          onChange={(e) => setResultado(e.target.value)}
        />
        <button type="submit" className="button button-primary">
          Guardar acción correctiva
        </button>
      </fieldset>
    </form>
  );
}
