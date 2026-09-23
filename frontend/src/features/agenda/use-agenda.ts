"use client";

import { useEffect, useState } from "react";
import { useApi } from "@/hooks/use-api";
import { ApiError, asApiError, isAborted } from "@/lib/api/errors";
import type { ApiCollection } from "@/lib/api/collections";
import type { AgendaItem } from "./contracts";
import { loadAgenda } from "./service";

type AgendaState = { status: "loading" } | { status: "ready"; data: ApiCollection<AgendaItem> } | { status: "error"; error: ApiError };
const loading: AgendaState = { status: "loading" };

export function useAgenda(query: string, tenantId: number) {
  const api = useApi();
  const [attempt, setAttempt] = useState(0);
  const key = `${tenantId}:${attempt}:${query}`;
  const [result, setResult] = useState<{ key: string; state: AgendaState }>({ key: "", state: loading });
  useEffect(() => {
    const controller = new AbortController();
    loadAgenda(api, query, tenantId, controller.signal).then((data) => {
      if (!controller.signal.aborted) setResult({ key, state: { status: "ready", data } });
    }).catch((error: unknown) => {
      if (!controller.signal.aborted && !isAborted(error)) {
        setResult({ key, state: { status: "error", error: asApiError(error) } });
        controller.abort();
      }
    });
    return () => controller.abort();
  }, [api, query, tenantId, key]);
  // Hide old rows synchronously, before the effect for the new query starts.
  return { state: result.key === key ? result.state : loading, retry: () => setAttempt((value) => value + 1) };
}
