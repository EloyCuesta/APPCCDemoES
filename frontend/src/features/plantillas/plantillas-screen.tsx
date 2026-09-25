"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";
import { Pagination } from "@/components/ui/pagination";
import { DateTime } from "@/components/ui/date-time";
import { useApi } from "@/hooks/use-api";
import { useConsulta } from "@/hooks/use-consulta";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { ApiError, asApiError, isAborted } from "@/lib/api/errors";
import type { Establecimiento } from "@/types/session";
import { compatible, frecuencias, type Plantilla, type ResultadoAplicacion } from "./contracts";
import { aplicarPlantilla, loadPlantillas } from "./service";
import { ConfigurarControl } from "./configurar-control";
import styles from "./plantillas.module.css";

export function PlantillasScreen() {
  const { establecimientoActual } = useEstablecimiento();
  if (!establecimientoActual) return null;
  return <PlantillasWorkspace key={establecimientoActual.id} local={establecimientoActual} />;
}

function PlantillasWorkspace({ local }: { local: Establecimiento }) {
  const api = useApi();
  const { membresiaActual, refresh } = useEstablecimiento();
  const canApply = ["admin", "responsable"].includes(membresiaActual?.rol ?? "");
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [uncertain, setUncertain] = useState(false);
  const [receipts, setReceipts] = useState<Record<number, ResultadoAplicacion>>({});
  const controller = useRef<AbortController | null>(null);
  const locked = useRef(false);
  const load = useCallback((signal: AbortSignal) => loadPlantillas(api, page, signal), [api, page]);
  const { state, retry } = useConsulta(load);
  useEffect(() => {
    const current = new AbortController();
    controller.current = current;
    return () => current.abort();
  }, []);

  function choose(id: number | null) {
    if (locked.current) return;
    setSelected(id);
    setError(null);
    setUncertain(false);
  }

  async function apply(plantilla: Plantilla) {
    const signal = controller.current?.signal;
    if (locked.current || !signal || signal.aborted || !canApply
      || state.status !== "ready" || !plantilla.activa
      || !compatible(plantilla, local.tipoActividad) || receipts[plantilla.id]) return;
    locked.current = true;
    setBusy(true);
    setError(null);
    try {
      const receipt = await aplicarPlantilla(api, plantilla.id, local.id, signal);
      signal.throwIfAborted();
      setReceipts((current) => ({ ...current, [plantilla.id]: receipt }));
      setSelected(null);
      setUncertain(false);
    } catch (cause) {
      if (!signal.aborted && !isAborted(cause)) {
        const parsed = asApiError(cause);
        setError(parsed);
        setUncertain([0, 409].includes(parsed.status) || parsed.status >= 500);
      }
    } finally {
      locked.current = false;
      if (!signal.aborted) setBusy(false);
    }
  }

  return (
    <div className={styles.screen}>
      <div className="page-heading">
        <div>
          <p className="eyebrow">CONFIGURACIÓN INICIAL</p>
          <h1>Plantillas APPCC</h1>
          <p className="muted">Prepara los controles de {local.nombre}.</p>
        </div>
        <button type="button" className="button button-secondary"
          disabled={busy || state.status === "loading"}
          onClick={() => { choose(null); retry(); }}>
          Actualizar plantillas
        </button>
      </div>
      <p>Consulta los planes y tareas antes de aplicar una plantilla. Los controles numéricos necesitan límites propios antes de activarse.</p>
      {!canApply && <p className="card">Puedes consultar las plantillas. Solo una persona administradora o responsable puede aplicarlas.</p>}
      {Object.values(receipts).map((receipt) => <ApplicationReceipt key={receipt.aplicacionId} receipt={receipt} tenantId={local.id} tenantName={local.nombre} canConfigure={canApply} />)}
      <section aria-label="Catálogo de plantillas" aria-busy={state.status === "loading" || busy}>
        {state.status === "loading" && <LoadingState message="Cargando plantillas…" />}
        {state.status === "error" && <>
          <ErrorNotice error={state.error} onRetry={state.error.retryable ? retry : undefined} />
          {[403, 404].includes(state.error.status) && <button type="button" className="button button-secondary" onClick={() => void refresh()}>Actualizar mis accesos</button>}
        </>}
        {state.status === "ready" && <>
          {state.data.items.length === 0 ? <div className="card"><h2>No hay plantillas disponibles</h2><p className="muted">Actualiza la consulta cuando estén disponibles las plantillas del catálogo.</p></div> : <ul className={styles.list}>
            {state.data.items.map((plantilla) => {
              const isCompatible = compatible(plantilla, local.tipoActividad);
              const receipt = receipts[plantilla.id];
              const taskCount = plantilla.planes.reduce((sum, plan) => sum + plan.tareas.length, 0);
              return <li key={plantilla.id} className={`card ${styles.card}`}>
                <h2>{plantilla.nombre}</h2>
                {plantilla.descripcion && <p className={styles.text}>{plantilla.descripcion}</p>}
                <p>{plantilla.planes.length} planes · {plantilla.puntos.length} puntos · {taskCount} tareas</p>
                <details>
                  <summary className={styles.summary}>Ver contenido de {plantilla.nombre}</summary>
                  <div className={styles.preview}>
                    {plantilla.planes.map((plan, index) => <div key={index}>
                      <h3>{plan.nombre}</h3>
                      <ul>{plan.tareas.map((tarea, i) => <li key={i}>
                        <strong>{tarea.nombre}</strong><p className="muted">{frecuencias[tarea.frecuencia] ?? tarea.frecuencia}{tarea.requiereLimites ? " · Requiere configurar límites" : ""}</p>
                        {tarea.instrucciones && <p className={styles.text}>{tarea.instrucciones}</p>}
                      </li>)}</ul>
                    </div>)}
                    {plantilla.puntos.length > 0 && <div><h3>Puntos de control</h3><ul>{plantilla.puntos.map((punto, index) => <li key={index}>{punto}</li>)}</ul></div>}
                  </div>
                </details>
                {!plantilla.activa && <p className="muted">Plantilla inactiva.</p>}
                {!isCompatible && <p className="muted">Esta plantilla corresponde a la actividad {plantilla.tipoActividad}. La actividad de {local.nombre} es {local.tipoActividad}.</p>}
                {receipt ? <p role="status">Aplicación confirmada en {local.nombre}.</p> : canApply && <button type="button" className="button button-primary"
                  disabled={busy || !plantilla.activa || !isCompatible}
                  onClick={() => choose(plantilla.id)} aria-label={`Aplicar ${plantilla.nombre}`}>
                  Aplicar plantilla
                </button>}
                {selected === plantilla.id && <Confirmation key={plantilla.id} plantilla={plantilla} tenantName={local.nombre} busy={busy}
                  error={error} uncertain={uncertain} onCancel={() => choose(null)} onApply={() => void apply(plantilla)} onRefresh={() => void refresh()} />}
              </li>;
            })}
          </ul>}
          <Pagination label="plantillas" data={state.data} page={page} disabled={busy} onPage={(value) => { choose(null); setPage(value); }} />
        </>}
      </section>
    </div>
  );
}

function Confirmation({ plantilla, tenantName, busy, error, uncertain, onCancel, onApply, onRefresh }: {
  plantilla: Plantilla; tenantName: string; busy: boolean; error: ApiError | null; uncertain: boolean;
  onCancel: () => void; onApply: () => void; onRefresh: () => void;
}) {
  const [confirmed, setConfirmed] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus(); }, []);
  return <section className={styles.confirmation} aria-label="Confirmar aplicación">
    <h3 ref={heading} tabIndex={-1}>Aplicar en {tenantName}</h3>
    <p>Se crearán los planes, puntos y tareas de {plantilla.nombre}. Una aplicación anterior de esta misma plantilla se conserva sin duplicar tareas.</p>
    {error && <ErrorNotice error={error} />}
    {error && [403, 404].includes(error.status) && <button type="button" className="button button-secondary" onClick={onRefresh}>Actualizar mis accesos</button>}
    {uncertain && <p role="status">No se ha podido confirmar el resultado. Comprueba la aplicación para recuperar el recibo; el servidor evita duplicados de esta plantilla.</p>}
    <label className={styles.check}><input type="checkbox" checked={confirmed} disabled={busy} onChange={(event) => setConfirmed(event.target.checked)} />He revisado el contenido y confirmo el establecimiento {tenantName}.</label>
    <div className={styles.actions}>
      <button type="button" className="button button-primary" disabled={busy || !confirmed} onClick={onApply}>{busy ? "Aplicando…" : uncertain ? "Comprobar aplicación" : "Confirmar aplicación"}</button>
      <button type="button" className="button button-secondary" disabled={busy} onClick={onCancel}>Cancelar</button>
    </div>
  </section>;
}

function ApplicationReceipt({ receipt, tenantId, tenantName, canConfigure }: {
  receipt: ResultadoAplicacion; tenantId: number; tenantName: string; canConfigure: boolean;
}) {
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus(); }, []);
  return <section className={styles.receipt} aria-label="Resultado de aplicación">
    <h2 ref={heading} tabIndex={-1}>{receipt.yaAplicada ? "La plantilla ya estaba aplicada" : "Plantilla aplicada correctamente"}</h2>
    <p role="status">{receipt.yaAplicada ? "Se conserva la aplicación anterior, sin crear duplicados." : `Se han creado ${receipt.creados.planes} planes, ${receipt.creados.puntos} puntos y ${receipt.creados.tareas} tareas en ${tenantName}.`}</p>
    <p className="muted">Aplicación original: <DateTime value={receipt.aplicadaAt} />. El recibo conserva el resultado inicial.</p>
    <ul>{receipt.tareas.map((tarea) => <li key={tarea.id}>
      <strong>{tarea.nombre}</strong> · Control #{tarea.id}
      {tarea.configuracionPendiente && <p className="muted">Creado pendiente de límites.</p>}
      {tarea.configuracionPendiente && canConfigure && <ConfigurarControl id={tarea.id} tenantId={tenantId} />}
    </li>)}</ul>
    <p>Revisa equipos, instrucciones y frecuencias antes de usar los controles. Las tareas activas se incorporan a la Agenda mediante la programación del establecimiento.</p>
    <div className={styles.actions}><Link className="button button-primary" href="/agenda">Ir a Agenda</Link></div>
  </section>;
}
