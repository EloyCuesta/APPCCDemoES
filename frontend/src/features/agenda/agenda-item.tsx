import { estados, frecuencias, tiposControl, type AgendaItem as Item } from "./contracts";
import { formatDate, formatTime } from "./dates";

export function AgendaItem({ item, onRegister }: { item: Item; onRegister?: (id: number) => void }) {
  const { programacion, tarea, plan, responsable } = item;
  return <li className="agenda-item" data-programacion-id={programacion.id}>
    <div className="agenda-when"><time dateTime={programacion.fechaProgramada}><strong>{formatTime(programacion.fechaProgramada)}</strong><span>{formatDate(programacion.fechaProgramada)}</span></time></div>
    <div className="agenda-task"><p className="agenda-control-type">{tiposControl[plan.tipo]}</p><h3>{tarea.nombre}</h3><p className="muted">{plan.nombre}</p><span className="agenda-frequency">{frecuencias[tarea.frecuencia]}</span></div>
    <div className="agenda-person"><span className="agenda-cell-label">Responsable</span><p>{responsable ? `${responsable.nombre} ${responsable.apellidos}`.trim() : programacion.asignadoA === null ? "Sin asignar" : "Responsable no disponible"}</p></div>
    <div className="agenda-item-status"><span className={`agenda-state agenda-state-${programacion.estado}`}>{estados[programacion.estado]}</span>{programacion.fechaLimite && <p className="agenda-deadline">Límite: <time dateTime={programacion.fechaLimite}>{formatDate(programacion.fechaLimite)}, {formatTime(programacion.fechaLimite)}</time></p>}{onRegister && ["pendiente", "vencida"].includes(programacion.estado) && <button type="button" className="button button-secondary agenda-register" onClick={() => onRegister(programacion.id)}>Registrar control</button>}</div>
  </li>;
}
