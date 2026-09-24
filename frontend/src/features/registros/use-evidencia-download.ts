"use client";

import { useEffect, useRef, useState } from "react";
import { useApi } from "@/hooks/use-api";
import { ApiError, asApiError, isAborted } from "@/lib/api/errors";
import type { EvidenciaHistorica } from "./evidencias-contracts";
import { downloadEvidencia } from "./download";

export function useEvidenciaDownload() {
  const api = useApi();
  const controller = useRef<AbortController | null>(null);
  const locked = useRef(false);
  const urls = useRef(new Map<string, ReturnType<typeof setTimeout>>());
  const [busy, setBusy] = useState<number | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState("");
  useEffect(() => {
    const current = new AbortController();
    controller.current = current;
    const pendingUrls = urls.current;
    return () => {
      current.abort();
      for (const [url, timer] of pendingUrls) { clearTimeout(timer); URL.revokeObjectURL(url); }
      pendingUrls.clear();
    };
  }, []);
  async function download(evidencia: EvidenciaHistorica) {
    if (locked.current || !controller.current || controller.current.signal.aborted) return;
    locked.current = true;
    setBusy(evidencia.id); setError(null); setNotice("");
    const signal = controller.current.signal;
    let objectUrl: string | null = null;
    try {
      const { blob, filename } = await downloadEvidencia(api, evidencia, signal);
      signal.throwIfAborted();
      objectUrl = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = objectUrl; anchor.download = filename; anchor.hidden = true;
      try { document.body.append(anchor); anchor.click(); } finally { anchor.remove(); }
      const url = objectUrl;
      urls.current.set(url, setTimeout(() => { URL.revokeObjectURL(url); urls.current.delete(url); }, 1000));
      setNotice(`Descarga iniciada: ${filename}`);
    } catch (cause) {
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      if (!signal.aborted && !isAborted(cause)) setError(asApiError(cause));
    } finally {
      locked.current = false;
      if (!signal.aborted) setBusy(null);
    }
  }
  return { download, busy, error, notice };
}
