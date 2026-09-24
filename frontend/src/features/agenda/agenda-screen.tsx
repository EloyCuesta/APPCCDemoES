"use client";

import { useEffect, useState } from "react";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";
import { AgendaFilters } from "./agenda-filters";
import { AgendaList } from "./agenda-list";
import { agendaQuery, emptyFilters, PAGE_SIZE, type AgendaFiltersValue } from "./query";
import { localDay } from "./dates";
import { useAgenda } from "./use-agenda";
import { RegistroScreen } from "@/features/registros/registro-screen";
import { puedeRegistrar } from "@/features/registros/contracts";

export function AgendaScreen() {
  const { establecimientoActual, refresh } = useEstablecimiento();
  if (!establecimientoActual) return null;
  return <AgendaWorkspace key={establecimientoActual.id} tenantId={establecimientoActual.id} tenantName={establecimientoActual.nombre} refreshAccess={() => void refresh()} />;
}

function AgendaWorkspace({ tenantId, tenantName, refreshAccess }: { tenantId: number; tenantName: string; refreshAccess: () => void }) {
  const { membresiaActual } = useEstablecimiento();
  const [registroId, setRegistroId] = useState<number | null>(null);
  const [success, setSuccess] = useState(false);
  const [filters, setFilters] = useState<AgendaFiltersValue>(emptyFilters);
  const [page, setPage] = useState(1);
  const [today, setToday] = useState(() => localDay(new Date()));
  const [timeZone] = useState(() => Intl.DateTimeFormat().resolvedOptions().timeZone);
  const { state, retry } = useAgenda(agendaQuery(filters, page), tenantId);
  useEffect(() => {
    const timer = setInterval(() => setToday(localDay(new Date())), 60_000);
    return () => clearInterval(timer);
  }, []);
  function apply(next: AgendaFiltersValue) { setFilters(next); setPage(1); retry(); }
  const total = state.status === "ready" ? state.data.total : null;
  const hasNext = state.status === "ready" && (state.data.next !== null || (total !== null && page * PAGE_SIZE < total));

  if (registroId !== null && puedeRegistrar(membresiaActual?.rol)) return <RegistroScreen key={registroId} id={registroId} tenantId={tenantId}
    onBack={() => { setRegistroId(null); retry(); }}
    onSuccess={() => { setRegistroId(null); setSuccess(true); retry(); }} />;

  return <div className="agenda-screen">
    {success && <p className="card registro-success" role="status">Control registrado y confirmado correctamente.</p>}
    <div className="page-heading"><div><p className="eyebrow">CONTROL DIARIO</p><h1>Agenda APPCC</h1><p className="muted">Controles pendientes y vencidos de {tenantName}.</p></div><button className="button button-secondary" type="button" onClick={retry} disabled={state.status === "loading"}>Actualizar agenda</button></div>
    <p className="agenda-timezone">Fechas y horas en <strong>{timeZone}</strong> · Zona del dispositivo</p>
    <AgendaFilters tenantId={tenantId} today={today} onApply={apply} />
    <section className="agenda-results" aria-label="Resultados de agenda" aria-busy={state.status === "loading"}>
      {state.status === "loading" && <LoadingState message="Cargando los controles de la agenda…" />}
      {state.status === "error" && <><ErrorNotice error={state.error} onRetry={state.error.retryable ? retry : undefined} />{[403, 404].includes(state.error.status) && <button type="button" className="button button-secondary" onClick={refreshAccess}>Actualizar mis accesos</button>}</>}
      {state.status === "ready" && <><AgendaList items={state.data.items} today={today} onRegister={puedeRegistrar(membresiaActual?.rol) ? (id) => { setSuccess(false); setRegistroId(id); } : undefined} /><nav className="agenda-pagination" aria-label="Paginación de agenda"><button type="button" className="button button-secondary" disabled={page === 1} onClick={() => setPage((current) => current - 1)}>Anterior</button><p role="status">Página {page}{total !== null && ` · ${total} controles en la consulta`}</p><button type="button" className="button button-secondary" disabled={!hasNext} onClick={() => setPage((current) => current + 1)}>Siguiente</button></nav></>}
    </section>
  </div>;
}
