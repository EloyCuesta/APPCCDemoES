"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";
import { useApi } from "@/hooks/use-api";
import { useConsulta } from "@/hooks/use-consulta";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { ApiError, asApiError, isAborted } from "@/lib/api/errors";
import { decimal } from "@/features/registros/contracts";
import type { ControlConfigurable } from "./contracts";
import { activarControl, loadControl, type LimitesControl } from "./service";
import styles from "./plantillas.module.css";

export function ConfigurarControl({ id, tenantId }: { id: number; tenantId: number }) {
  const [open, setOpen] = useState(false);
  return <div>
    <button type="button" className="button button-secondary" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
      {open ? "Ocultar configuración" : "Configurar control"} #{id}
    </button>
    {open && <ControlEditor id={id} tenantId={tenantId} />}
  </div>;
}

function ControlEditor({ id, tenantId }: { id: number; tenantId: number }) {
  const api = useApi();
  const { membresiaActual, refresh } = useEstablecimiento();
  const canWrite = ["admin", "responsable"].includes(membresiaActual?.rol ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [uncertain, setUncertain] = useState(false);
  const controller = useRef<AbortController | null>(null);
  const locked = useRef(false);
  const load = useCallback((signal: AbortSignal) => loadControl(api, id, tenantId, signal), [api, id, tenantId]);
  const { state, retry } = useConsulta(load);
  useEffect(() => {
    const current = new AbortController();
    controller.current = current;
    return () => current.abort();
  }, []);

  async function save(input: LimitesControl) {
    const signal = controller.current?.signal;
    if (locked.current || !signal || signal.aborted || !canWrite || uncertain
      || state.status !== "ready" || state.data.activa) return;
    locked.current = true;
    setBusy(true);
    setError(null);
    try {
      await activarControl(api, id, input, signal);
      signal.throwIfAborted();
      retry();
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

  return <section className={styles.preview} aria-label={`Configuración del control #${id}`} aria-busy={busy || state.status === "loading"}>
    {error && <ErrorNotice error={error} />}
    {uncertain && <p role="status">La activación pudo completarse. Consulta el estado actual antes de guardar de nuevo.</p>}
    {state.status === "loading" && <LoadingState message="Consultando configuración actual…" />}
    {state.status === "error" && <ErrorNotice error={state.error} />}
    {([error?.status, state.status === "error" ? state.error.status : null].includes(403)) && <button type="button" className="button button-secondary" onClick={() => void refresh()}>Actualizar mis accesos</button>}
    <button type="button" className="button button-secondary" disabled={busy || state.status === "loading"}
      onClick={() => { setError(null); setUncertain(false); retry(); }}>Consultar estado actual</button>
    {state.status === "ready" && (state.data.activa
      ? <p role="status">El control está configurado y activo.</p>
      : canWrite && <LimitesForm control={state.data} disabled={busy || uncertain} onSave={save} />)}
  </section>;
}

function LimitesForm({ control, disabled, onSave }: {
  control: ControlConfigurable; disabled: boolean; onSave: (input: LimitesControl) => Promise<void>;
}) {
  const [minimo, setMinimo] = useState(control.limiteMinimo ?? "");
  const [maximo, setMaximo] = useState(control.limiteMaximo ?? "");
  const [unidad, setUnidad] = useState(control.unidad);
  const [instrucciones, setInstrucciones] = useState(control.instrucciones);
  const [confirmed, setConfirmed] = useState(false);
  const [error, setError] = useState("");
  const prefix = `control-${control.id}`;
  return <form onSubmit={(event) => {
    event.preventDefault();
    if (disabled || !confirmed) return;
    const min = minimo.trim(), max = maximo.trim();
    if ((!min && !max) || (min && !decimal.test(min)) || (max && !decimal.test(max))) {
      setError("Indica al menos un límite decimal válido, con punto y hasta tres decimales."); return;
    }
    if (min && max && Number(min) > Number(max)) {
      setError("El límite mínimo no puede superar el máximo."); return;
    }
    if (!unidad.trim() || !instrucciones.trim()) {
      setError("Indica la unidad y las instrucciones del control."); return;
    }
    setError("");
    void onSave({ limiteMinimo: min || null, limiteMaximo: max || null, unidad: unidad.trim(), instrucciones: instrucciones.trim() });
  }}>
    <fieldset disabled={disabled} className={styles.fields}>
      <legend>Configurar y activar {control.nombre}</legend>
      <p>Usa los límites aprobados para los productos, equipos y procesos del establecimiento. Puedes indicar un único límite.</p>
      {error && <p role="alert" className="error-notice">{error}</p>}
      <label htmlFor={`${prefix}-min`}>Límite mínimo</label>
      <input id={`${prefix}-min`} inputMode="decimal" value={minimo} onChange={(event) => setMinimo(event.target.value)} />
      <label htmlFor={`${prefix}-max`}>Límite máximo</label>
      <input id={`${prefix}-max`} inputMode="decimal" value={maximo} onChange={(event) => setMaximo(event.target.value)} />
      <label htmlFor={`${prefix}-unit`}>Unidad</label>
      <input id={`${prefix}-unit`} required maxLength={30} value={unidad} onChange={(event) => setUnidad(event.target.value)} />
      <label htmlFor={`${prefix}-instructions`}>Instrucciones del control</label>
      <textarea id={`${prefix}-instructions`} required rows={3} value={instrucciones} onChange={(event) => setInstrucciones(event.target.value)} />
      <label className={styles.check}><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />Confirmo que los límites e instrucciones están revisados para este establecimiento.</label>
      <button type="submit" className="button button-primary" disabled={disabled || !confirmed}>Guardar límites y activar</button>
    </fieldset>
  </form>;
}
