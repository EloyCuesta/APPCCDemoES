"use client";

import { useCallback, useState } from "react";
import { useApi } from "@/hooks/use-api";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";
import { formatDate, formatTime } from "@/features/agenda/dates";
import { estados, gravedades } from "./contracts";
import {
  emptyFilters,
  incidenciasQuery,
  type IncidenciasFiltersValue,
} from "./query";
import { loadIncidencias } from "./service";
import { useConsulta } from "@/hooks/use-consulta";
import { IncidenciasFilters } from "./incidencias-filters";
import { IncidenciaDetail } from "./incidencia-detail";
import { Pagination } from "@/components/ui/pagination";

export function IncidenciasScreen() {
  const { establecimientoActual } = useEstablecimiento();
  if (!establecimientoActual) return null;
  return (
    <IncidenciasWorkspace
      key={establecimientoActual.id}
      tenantId={establecimientoActual.id}
      tenantName={establecimientoActual.nombre}
    />
  );
}

function IncidenciasWorkspace({
  tenantId,
  tenantName,
}: {
  tenantId: number;
  tenantName: string;
}) {
  const api = useApi();
  const { refresh } = useEstablecimiento();
  const [filters, setFilters] = useState(emptyFilters);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<number | null>(null);
  const [timeZone] = useState(
    () => Intl.DateTimeFormat().resolvedOptions().timeZone,
  );
  const query = incidenciasQuery(filters, page);
  const load = useCallback(
    (signal: AbortSignal) => loadIncidencias(api, query, tenantId, signal),
    [api, query, tenantId],
  );
  const { state, retry } = useConsulta(load);
  function apply(value: IncidenciasFiltersValue) {
    setFilters(value);
    setPage(1);
    retry();
  }
  return (
    <div className="incidencias-screen">
      {selected !== null ? (
        <IncidenciaDetail
          key={selected}
          id={selected}
          tenantId={tenantId}
          onChanged={retry}
          onBack={() => {
            setSelected(null);
            retry();
          }}
        />
      ) : (
        <>
          <div className="page-heading">
            <div>
              <p className="eyebrow">SEGUIMIENTO APPCC</p>
              <h1>Incidencias APPCC</h1>
              <p className="muted">Incidencias de {tenantName}.</p>
            </div>
            <button
              type="button"
              className="button button-secondary"
              disabled={state.status === "loading"}
              onClick={retry}
            >
              Actualizar incidencias
            </button>
          </div>
          <p className="agenda-timezone">
            Fechas y horas en <strong>{timeZone}</strong> · Zona del dispositivo
          </p>
        </>
      )}
      {/* Conserva el borrador y los filtros al regresar del detalle; el cambio de tenant desmonta todo. */}
      <div hidden={selected !== null}>
        <IncidenciasFilters onApply={apply} />
        <section
          aria-label="Resultados de incidencias"
          aria-busy={state.status === "loading"}
        >
          {state.status === "loading" && (
            <LoadingState message="Cargando incidencias…" />
          )}
          {state.status === "error" && (
            <>
              <ErrorNotice
                error={state.error}
                onRetry={state.error.retryable ? retry : undefined}
              />
              {[403, 404].includes(state.error.status) && (
                <button
                  type="button"
                  className="button button-secondary"
                  onClick={() => void refresh()}
                >
                  Actualizar mis accesos
                </button>
              )}
            </>
          )}
          {state.status === "ready" && (
            <>
              {state.data.items.length === 0 ? (
                <div className="card agenda-empty">
                  <h2>No hay incidencias en esta consulta</h2>
                  <p className="muted">
                    Prueba con otros filtros o actualiza la consulta.
                  </p>
                </div>
              ) : (
                <ul className="incidencias-list">
                  {state.data.items.map((item) => (
                    <li
                      key={item.id}
                      className="card incidencia-row"
                      data-incidencia-id={item.id}
                    >
                      <div>
                        <p className="eyebrow">INCIDENCIA #{item.id}</p>
                        <h2>{item.titulo}</h2>
                        <p className="incidencia-description">
                          {item.descripcion}
                        </p>
                        <time dateTime={item.fechaApertura}>
                          {formatDate(item.fechaApertura)} ·{" "}
                          {formatTime(item.fechaApertura)}
                        </time>
                      </div>
                      <div className="incidencia-row-actions">
                        <span
                          className={`incidencia-state incidencia-state-${item.estado}`}
                        >
                          {estados[item.estado]}
                        </span>
                        <span>Gravedad: {gravedades[item.gravedad]}</span>
                        <button
                          type="button"
                          className="button button-secondary"
                          onClick={() => setSelected(item.id)}
                          aria-label={`Abrir incidencia #${item.id}`}
                        >
                          Abrir incidencia
                        </button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
              <Pagination
                label="incidencias"
                data={state.data}
                page={page}
                onPage={setPage}
              />
            </>
          )}
        </section>
      </div>
    </div>
  );
}
