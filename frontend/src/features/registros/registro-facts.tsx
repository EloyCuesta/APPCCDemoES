import { DateTime } from "@/components/ui/date-time";
import type { RegistroHistorico } from "./read-contracts";

export function formatDato(value: unknown): string {
  return typeof value === "boolean" ? value ? "Sí" : "No" : value === null ? "Sin dato" : typeof value === "string" ? value : JSON.stringify(value);
}
export function resumenRegistro(registro: RegistroHistorico): string {
  if (registro.valorNumerico !== null) return `Valor: ${registro.valorNumerico}`;
  if (registro.datos === null || !Object.keys(registro.datos).length) return "Sin datos adicionales";
  return Object.entries(registro.datos).slice(0, 3).map(([key, value]) => `${key}: ${formatDato(value)}`).join(" · ");
}

/** Datos históricos compartidos por el detalle de Registros y el origen de Incidencias. */
export function RegistroFacts({ registro, tarea, autor, confirmadoPor }: { registro: RegistroHistorico; tarea: string; autor: string; confirmadoPor: string | null }) {
  return <>
    <h3>Registro #{registro.id} · {tarea}</h3>
    <dl className="incidencia-facts">
      <div><dt>Resultado</dt><dd>{registro.conforme ? "Conforme" : "No conforme"}</dd></div>
      <div><dt>Fecha del registro</dt><dd><DateTime value={registro.fechaHora} /></dd></div>
      <div><dt>Registrado por</dt><dd>{autor}</dd></div>
      {registro.valorNumerico !== null && <div><dt>Valor numérico</dt><dd>{registro.valorNumerico}</dd></div>}
      <div><dt>Ejecución</dt><dd>#{registro.tareaProgramada.split("/").at(-1)}</dd></div>
      <div><dt>Confirmación</dt><dd>{registro.confirmadoAt ? <><DateTime value={registro.confirmadoAt} />{confirmadoPor && ` · ${confirmadoPor}`}</> : "Sin confirmación registrada"}</dd></div>
    </dl>
    {registro.datos !== null && <div><h3>Datos registrados</h3><dl className="incidencia-facts">{Object.entries(registro.datos).map(([key, value]) => <div key={key}><dt>{key}</dt><dd>{formatDato(value)}</dd></div>)}</dl></div>}
    <h3>Observaciones del registro</h3><p className="incidencia-text">{registro.observaciones ?? "Sin observaciones"}</p>
  </>;
}
