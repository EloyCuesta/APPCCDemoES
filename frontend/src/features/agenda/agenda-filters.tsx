"use client";

import { useState, type FormEvent } from "react";
import { ReferenceSelect } from "./reference-select";
import { emptyFilters, filtersError, type AgendaFiltersValue } from "./query";

export function AgendaFilters({ tenantId, today, onApply }: {
  tenantId: number; today: string; onApply: (filters: AgendaFiltersValue) => void;
}) {
  const [draft, setDraft] = useState<AgendaFiltersValue>(emptyFilters);
  const [error, setError] = useState<string | null>(null);

  function apply(value: AgendaFiltersValue) {
    const invalid = filtersError(value); setError(invalid);
    if (!invalid) onApply(value);
  }
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); apply(draft); }
  function preset(value: AgendaFiltersValue) { setDraft(value); apply(value); }

  return <form className="card agenda-filters" onSubmit={submit} aria-label="Filtros de agenda">
    <div className="section-heading"><h2>Filtrar controles</h2><button className="button button-quiet" type="button" onClick={() => preset(emptyFilters)}>Limpiar filtros</button></div>
    <div className="agenda-filter-grid">
      <div className="agenda-filter-field"><label htmlFor="agenda-desde">Desde</label><input id="agenda-desde" type="date" value={draft.desde} onChange={(event) => setDraft({ ...draft, desde: event.target.value })} /></div>
      <div className="agenda-filter-field"><label htmlFor="agenda-hasta">Hasta</label><input id="agenda-hasta" type="date" value={draft.hasta} onChange={(event) => setDraft({ ...draft, hasta: event.target.value })} /></div>
      <div className="agenda-filter-field"><label htmlFor="agenda-estado">Estado</label><select id="agenda-estado" value={draft.estado} onChange={(event) => {
        const estado = event.target.value;
        if (estado === "" || estado === "pendiente" || estado === "vencida") setDraft({ ...draft, estado });
      }}><option value="">Pendientes y vencidas</option><option value="pendiente">Pendientes</option><option value="vencida">Vencidas</option></select></div>
      <ReferenceSelect kind="asignadoA" tenantId={tenantId} value={draft.asignadoA} onChange={(asignadoA) => setDraft({ ...draft, asignadoA })} />
      <ReferenceSelect kind="tarea" tenantId={tenantId} value={draft.tarea} onChange={(tarea) => setDraft({ ...draft, tarea })} />
    </div>
    {error && <p className="error-notice" role="alert">{error}</p>}
    <div className="agenda-filter-actions"><button className="button button-primary" type="submit">Aplicar filtros</button><button className="button button-secondary" type="button" onClick={() => preset({ ...draft, desde: today, hasta: today })}>Ver hoy</button><p className="muted">Los filtros se aplican a la fecha programada.</p></div>
  </form>;
}
