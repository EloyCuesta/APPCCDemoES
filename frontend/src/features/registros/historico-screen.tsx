"use client";

import { useCallback, useState } from "react";
import { useApi } from "@/hooks/use-api";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { useConsulta } from "@/hooks/use-consulta";
import { DateTime } from "@/components/ui/date-time";
import { LoadingState } from "@/components/ui/feedback";
import { Pagination } from "@/components/ui/pagination";
import { loadHistorico } from "./historico-service";
import { emptyFilters, historicoQuery, type HistoricoFiltersValue } from "./historico-query";
import { HistoricoFilters } from "./historico-filters";
import { HistoricoError } from "./historico-feedback";
import { HistoricoDetail } from "./historico-detail";
import { resumenRegistro } from "./registro-facts";

export function HistoricoScreen() {
  const { establecimientoActual } = useEstablecimiento();
  return establecimientoActual ? <HistoricoWorkspace key={establecimientoActual.id} tenantId={establecimientoActual.id} tenantName={establecimientoActual.nombre} /> : null;
}
function HistoricoWorkspace({ tenantId, tenantName }: { tenantId: number; tenantName: string }) {
  const api = useApi();
  const [filters, setFilters] = useState(emptyFilters);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<number | null>(null);
  const [timeZone] = useState(() => Intl.DateTimeFormat().resolvedOptions().timeZone);
  const query = historicoQuery(filters, page);
  const load = useCallback((signal: AbortSignal) => loadHistorico(api, query, tenantId, signal), [api, query, tenantId]);
  const { state, retry } = useConsulta(load);
  function apply(next: HistoricoFiltersValue) { setFilters(next); setPage(1); retry(); }
  return <div className="historico-screen">
    {selected !== null ? <HistoricoDetail key={selected} id={selected} tenantId={tenantId} onBack={() => { setSelected(null); retry(); }} /> : <>
      <div className="page-heading"><div><p className="eyebrow">TRAZABILIDAD APPCC</p><h1>Registros APPCC</h1><p className="muted">Histórico de controles de {tenantName}.</p></div><button type="button" className="button button-secondary" onClick={retry} disabled={state.status === "loading"}>Actualizar registros</button></div>
      <p className="agenda-timezone">Fechas y horas en <strong>{timeZone}</strong> · Zona del dispositivo</p>
    </>}
    <div hidden={selected !== null}>
      <HistoricoFilters tenantId={tenantId} onApply={apply} />
      <section aria-label="Resultados de registros" aria-busy={state.status === "loading"}>
        {state.status === "loading" && <LoadingState message="Cargando registros…" />}
        {state.status === "error" && <HistoricoError error={state.error} retry={retry} />}
        {state.status === "ready" && <>
          {state.data.items.length === 0 ? <div className="card agenda-empty"><h2>No hay registros en esta consulta</h2><p className="muted">Prueba con otros filtros o actualiza la consulta.</p></div> : <ul className="historico-list">{state.data.items.map(({ registro, tarea, usuario }) => <li className="card historico-row" key={registro.id} data-registro-id={registro.id}>
            <div><p className="eyebrow">REGISTRO #{registro.id}</p><h2>{tarea}</h2><p className="muted"><DateTime value={registro.fechaHora} /> · {usuario}</p><p className="historico-summary">{resumenRegistro(registro)}</p></div>
            <div className="historico-row-actions"><span className={`historico-result ${registro.conforme ? "historico-conforme" : "historico-no-conforme"}`}>{registro.conforme ? "Conforme" : "No conforme"}</span><span className="muted">Evidencias en el detalle</span><button type="button" className="button button-secondary" aria-label={`Ver registro #${registro.id}`} onClick={() => setSelected(registro.id)}>Ver registro</button></div>
          </li>)}</ul>}
          <Pagination label="registros" data={state.data} page={page} onPage={setPage} />
        </>}
      </section>
    </div>
  </div>;
}
