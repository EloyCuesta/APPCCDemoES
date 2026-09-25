"use client";

import { useCallback } from "react";
import Link from "next/link";
import { useApi } from "@/hooks/use-api";
import { useConsulta } from "@/hooks/use-consulta";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";
import { loadIndicadores } from "./service";

export function Indicadores({ tenantId }: { tenantId: number }) {
  const api = useApi();
  const { refresh } = useEstablecimiento();
  const load = useCallback((signal: AbortSignal) => loadIndicadores(api, tenantId, signal), [api, tenantId]);
  const { state, retry } = useConsulta(load);
  return <section aria-label="Resumen operativo" aria-busy={state.status === "loading"}>
    <div className="section-heading indicators-heading">
      <h2>Resumen operativo</h2>
      <button type="button" className="button button-secondary" onClick={retry} disabled={state.status === "loading"}>Actualizar resumen</button>
    </div>
    <p className="muted">Hoy según la zona horaria de este dispositivo. Las incidencias incluyen abiertas y en proceso.</p>
    {state.status === "loading" && <LoadingState message="Cargando resumen operativo…" />}
    {state.status === "error" && <><ErrorNotice error={state.error} onRetry={state.error.retryable ? retry : undefined} />{[403, 404].includes(state.error.status) && <button className="button button-secondary" onClick={() => void refresh()}>Actualizar accesos del resumen</button>}</>}
    {state.status === "ready" && <div className="kpi-grid">
      {[
        { label: "Controles pendientes hoy", value: state.data.pendientes, href: "/agenda" },
        { label: "Controles vencidos", value: state.data.vencidos, href: "/agenda" },
        { label: "Registros realizados hoy", value: state.data.registros, href: "/registros" },
        { label: "Incidencias sin resolver", value: state.data.incidencias, href: "/incidencias" },
      ].map((item) => <section className="card kpi-card" key={item.label} aria-label={item.label}>
        <h3>{item.label}</h3><p className="kpi-value">{item.value}</p><Link href={item.href}>Consultar {item.href.slice(1)}</Link>
      </section>)}
    </div>}
  </section>;
}
