"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useApi } from "@/hooks/use-api";
import { useConsulta } from "@/hooks/use-consulta";
import { LoadingState } from "@/components/ui/feedback";
import { DateTime } from "@/components/ui/date-time";
import { Pagination } from "@/components/ui/pagination";
import { loadRegistroHistorico, type DetalleRegistro } from "./historico-service";
import { RegistroFacts } from "./registro-facts";
import { HistoricoError } from "./historico-feedback";
import { formatBytes, tiposEvidencia } from "./evidencias-contracts";
import { useEvidenciaDownload } from "./use-evidencia-download";

export function HistoricoDetail({ id, tenantId, onBack }: { id: number; tenantId: number; onBack: () => void }) {
  const api = useApi();
  const [page, setPage] = useState(1);
  const heading = useRef<HTMLHeadingElement>(null);
  const load = useCallback((signal: AbortSignal) => loadRegistroHistorico(api, id, tenantId, page, signal), [api, id, tenantId, page]);
  const { state, retry } = useConsulta(load);
  useEffect(() => { heading.current?.focus(); }, []);
  return <section className="historico-detail" aria-label="Detalle del registro" aria-busy={state.status === "loading"}>
    <div className="incidencia-toolbar"><button type="button" className="button button-secondary" onClick={onBack}>Volver a registros</button><button type="button" className="button button-secondary" onClick={retry} disabled={state.status === "loading"}>Actualizar registro</button></div>
    <h1 ref={heading} tabIndex={-1}>Registro #{id}</h1><p className="muted">Consulta del control realizado y sus evidencias.</p>
    {state.status === "loading" && <LoadingState message="Cargando registro y evidencias…" />}
    {state.status === "error" && <HistoricoError error={state.error} retry={retry} />}
    {state.status === "ready" && <>
      <section className="card incidencia-section" aria-label="Datos del registro"><RegistroFacts registro={state.data.registro} tarea={state.data.tarea} autor={state.data.usuario} confirmadoPor={state.data.confirmadoPor} /></section>
      <Evidencias data={state.data} page={page} onPage={setPage} />
    </>}
  </section>;
}
function Evidencias({ data, page, onPage }: { data: DetalleRegistro; page: number; onPage: (page: number) => void }) {
  const { download, busy, error, notice } = useEvidenciaDownload();
  return <section className="card incidencia-section" aria-label="Evidencias del registro" aria-busy={busy !== null}>
    <h2>Evidencias{data.evidencias.total !== null && ` (${data.evidencias.total})`}</h2>
    {error && <HistoricoError error={error} />}
    {notice && <p role="status">{notice}</p>}
    {busy !== null && <LoadingState message="Descargando evidencia…" />}
    {data.evidencias.items.length === 0 ? <p className="muted">No hay evidencias en esta página.</p> : <ul className="historico-evidencias">{data.evidencias.items.map((e) => <li key={e.id} data-evidencia-id={e.id}>
      <div><h3>{e.nombreOriginal}</h3><p>{tiposEvidencia[e.tipo]} · {e.mimeType} · {formatBytes(e.tamanoBytes)}</p><p className="muted">Subida por {data.autores[e.subidaPor]} · <DateTime value={e.createdAt} /></p></div>
      <button type="button" className="button button-secondary" disabled={busy !== null} aria-label={`Descargar ${e.nombreOriginal} (#${e.id})`} onClick={() => void download(e)}>Descargar</button>
    </li>)}</ul>}
    <Pagination label="evidencias" data={data.evidencias} page={page} onPage={onPage} />
  </section>;
}
