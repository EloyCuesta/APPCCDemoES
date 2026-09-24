import type { ApiClient } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { endpoints } from "@/lib/api/endpoints";
import type { EvidenciaHistorica } from "./evidencias-contracts";

function safeFilename(value: string): string {
  const basename = value.split(/[\\/]/).at(-1) ?? "";
  return [...basename].filter((c) => c.charCodeAt(0) >= 32 && c.charCodeAt(0) !== 127 && !'<>:"|?*'.includes(c) && !/[\u202a-\u202e\u2066-\u2069]/.test(c)).join("").trim().replace(/[. ]+$/, "").slice(0, 255);
}
export function downloadFilename(disposition: string | null, knownName: string, id: number): string {
  let name = "";
  if (disposition) {
    const encoded = /(?:^|;)\s*filename\*\s*=\s*UTF-8'[^']*'([^;]+)/i.exec(disposition);
    if (encoded) { try { name = decodeURIComponent(encoded[1].trim()); } catch { /* Usa metadato conocido si el encabezado es inválido. */ } }
    if (!name) {
      const plain = /(?:^|;)\s*filename\s*=\s*(?:"((?:[^"\\]|\\.)*)"|([^;]+))/i.exec(disposition);
      if (plain) name = (plain[1] ?? plain[2]).replace(/\\(.)/g, "$1").trim();
    }
  }
  return safeFilename(name) || safeFilename(knownName) || `evidencia-${id}`;
}

export async function downloadEvidencia(api: ApiClient, evidencia: EvidenciaHistorica, signal: AbortSignal) {
  try {
    const response = await api.download(endpoints.descargarEvidencia(evidencia.id), { signal });
    signal.throwIfAborted();
    if (response.blob.size !== evidencia.tamanoBytes || response.blob.type.split(";")[0].toLowerCase() !== evidencia.mimeType) {
      throw new ApiError(502, "El archivo recibido no coincide con la evidencia. Vuelve a consultar el registro.");
    }
    return { blob: response.blob, filename: downloadFilename(response.contentDisposition, evidencia.nombreOriginal, evidencia.id) };
  } catch (error) {
    if (error instanceof ApiError && error.status === 503) throw new ApiError(503, "El archivo de esta evidencia no está disponible en este momento. El registro histórico se conserva. Vuelve a intentarlo más tarde.");
    throw error;
  }
}
