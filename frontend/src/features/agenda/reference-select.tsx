"use client";

import { useEffect, useId, useState } from "react";
import { useApi } from "@/hooks/use-api";
import { ErrorNotice } from "@/components/ui/feedback";
import { ApiError, asApiError, isAborted } from "@/lib/api/errors";
import { loadOptions, type ReferenceKind, type ReferenceOption } from "./service";

interface OptionsState { key: string; options: ReferenceOption[]; hasMore: boolean; error: ApiError | null }

export function ReferenceSelect({ kind, tenantId, value, onChange }: {
  kind: ReferenceKind; tenantId: number; value: string; onChange: (value: string) => void;
}) {
  const api = useApi();
  const id = useId();
  const [page, setPage] = useState(1);
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState<OptionsState>({ key: "", options: [], hasMore: false, error: null });
  const key = `${tenantId}:${kind}:${page}:${attempt}`;
  const loading = state.key !== key;
  const label = kind === "tarea" ? "Tarea / control" : "Responsable";
  const plural = kind === "tarea" ? "tareas" : "responsables";
  useEffect(() => {
    const controller = new AbortController();
    loadOptions(api, kind, page, tenantId, controller.signal).then((data) => {
      if (controller.signal.aborted) return;
      setState((previous) => {
        const options = [...new Map([...(page === 1 ? [] : previous.options), ...data.items].map((option) => [option.iri, option])).values()];
        return { key, options, hasMore: data.next !== null || (data.total !== null && options.length < data.total), error: null };
      });
    }).catch((error: unknown) => {
      if (!controller.signal.aborted && !isAborted(error)) setState((previous) => ({ ...previous, key, error: asApiError(error) }));
    });
    return () => controller.abort();
  }, [api, kind, page, tenantId, key]);

  return <div className="agenda-filter-field">
    <label htmlFor={id}>{label}</label>
    <select id={id} value={value} onChange={(event) => onChange(event.target.value)} disabled={loading && state.options.length === 0}>
      <option value="">{kind === "tarea" ? "Todas las tareas" : "Todos los responsables"}</option>
      {state.options.map((option) => <option value={option.iri} key={option.iri}>{option.label}</option>)}
    </select>
    {loading && <span className="muted filter-help" role="status">Cargando {plural}…</span>}
    {!loading && state.error && <ErrorNotice error={state.error} onRetry={() => setAttempt((count) => count + 1)} />}
    {!loading && !state.error && state.hasMore && <button type="button" className="filter-more" onClick={() => setPage((current) => current + 1)}>Cargar más {plural}</button>}
  </div>;
}
