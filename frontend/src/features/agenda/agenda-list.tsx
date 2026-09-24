import type { AgendaItem as Item } from "./contracts";
import { AgendaItem } from "./agenda-item";
import { groupAgenda } from "./grouping";

export function AgendaList({ items, today, onRegister }: { items: Item[]; today: string; onRegister?: (id: number) => void }) {
  if (items.length === 0) return <section className="card agenda-empty" role="status"><span className="section-symbol" aria-hidden="true">✓</span><h2>No hay controles en esta consulta</h2><p className="muted">Prueba otro rango de fechas o limpia los filtros para consultar el resto de la agenda.</p></section>;
  const groups = groupAgenda(items, today);
  return <>
    <div className="agenda-summary" aria-label="Resumen de la página">{groups.map((group) => <div key={group.key} className={`agenda-summary-item agenda-summary-${group.key}`}><span>{group.title}</span><strong>{group.items.length}</strong></div>)}</div>
    <p className="agenda-page-note muted">Los grupos y sus contadores corresponden a esta página.</p>
    {groups.filter((group) => group.items.length > 0).map((group) => <section className={`agenda-group agenda-group-${group.key}`} aria-labelledby={`group-${group.key}`} key={group.key}>
      <div className="agenda-group-heading"><h2 id={`group-${group.key}`}>{group.title} <span>{group.items.length}</span></h2><p className="muted">{group.description}</p></div>
      <div className="agenda-columns" aria-hidden="true"><span>Fecha · Hora prevista</span><span>Tarea / control APPCC</span><span>Responsable</span><span>Estado</span></div>
      <ul className="agenda-items">{group.items.map((item) => <AgendaItem item={item} key={item.programacion.id} onRegister={onRegister} />)}</ul>
    </section>)}
  </>;
}
