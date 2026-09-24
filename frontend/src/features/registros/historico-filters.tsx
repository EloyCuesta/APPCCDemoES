"use client";

import { useState, type FormEvent } from "react";
import { ReferenceSelect } from "@/features/agenda/reference-select";
import { emptyFilters, filtersError, type HistoricoFiltersValue } from "./historico-query";

export function HistoricoFilters({ tenantId, onApply }: { tenantId: number; onApply: (filters: HistoricoFiltersValue) => void }) {
  const [value, setValue] = useState(emptyFilters);
  const [error, setError] = useState<string | null>(null);
  function submit(event: FormEvent) { event.preventDefault(); const error = filtersError(value); setError(error); if (!error) onApply(value); }
  return <form className="card agenda-filters" aria-label="Filtros de registros" onSubmit={submit}>
    <div className="section-heading"><h2>Filtrar registros</h2></div>
    <div className="historico-filter-grid">
      <ReferenceSelect kind="tarea" tenantId={tenantId} value={value.tarea} onChange={(tarea) => setValue({ ...value, tarea })} />
      <ReferenceSelect kind="asignadoA" userLabel="Usuario" tenantId={tenantId} value={value.usuario} onChange={(usuario) => setValue({ ...value, usuario })} />
      <div className="agenda-filter-field"><label htmlFor="hist-conforme">Conformidad</label><select id="hist-conforme" value={value.conforme} onChange={(e) => setValue({ ...value, conforme: e.target.value as HistoricoFiltersValue["conforme"] })}><option value="">Todos los resultados</option><option value="true">Conformes</option><option value="false">No conformes</option></select></div>
      <div className="agenda-filter-field"><label htmlFor="hist-desde">Fecha desde</label><input id="hist-desde" type="date" value={value.desde} onChange={(e) => setValue({ ...value, desde: e.target.value })} /></div>
      <div className="agenda-filter-field"><label htmlFor="hist-hasta">Fecha hasta</label><input id="hist-hasta" type="date" value={value.hasta} onChange={(e) => setValue({ ...value, hasta: e.target.value })} /></div>
      <div className="agenda-filter-field"><label htmlFor="hist-orden">Orden por fecha</label><select id="hist-orden" value={value.orden} onChange={(e) => setValue({ ...value, orden: e.target.value as HistoricoFiltersValue["orden"] })}><option value="desc">Más recientes primero</option><option value="asc">Más antiguos primero</option></select></div>
    </div>
    {error && <p role="alert">{error}</p>}
    <div className="agenda-filter-actions"><button type="submit" className="button button-primary">Aplicar filtros</button><button type="button" className="button button-secondary" onClick={() => { setValue(emptyFilters); setError(null); onApply(emptyFilters); }}>Limpiar filtros</button></div>
  </form>;
}
