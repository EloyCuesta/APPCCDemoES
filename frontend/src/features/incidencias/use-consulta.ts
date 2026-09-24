"use client";

import { useEffect, useState } from "react";
import { ApiError, asApiError, isAborted } from "@/lib/api/errors";

type State<T> =
  | { status: "loading" }
  | { status: "ready"; data: T }
  | { status: "error"; error: ApiError };

/** El loader memoizado incluye tenant, filtros y páginas; oculta datos anteriores antes del efecto. */
export function useConsulta<T>(load: (signal: AbortSignal) => Promise<T>) {
  const [attempt, setAttempt] = useState(0);
  const [result, setResult] = useState<{
    load: typeof load;
    attempt: number;
    state: State<T>;
  } | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    load(controller.signal)
      .then((data) => {
        if (!controller.signal.aborted)
          setResult({ load, attempt, state: { status: "ready", data } });
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted && !isAborted(error)) {
          setResult({
            load,
            attempt,
            state: { status: "error", error: asApiError(error) },
          });
          controller.abort();
        }
      });
    return () => controller.abort();
  }, [load, attempt]);
  const state: State<T> =
    result?.load === load && result.attempt === attempt
      ? result.state
      : { status: "loading" };
  return { state, retry: () => setAttempt((value) => value + 1) };
}
