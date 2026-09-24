import { invalidResource, resourceIdentity, resourceRelation, resourceInstant, resourceText } from "@/lib/api/resources";

export const tiposEvidencia = { foto: "Fotografía", documento: "Documento", firma: "Firma histórica" };
export function parseEvidencia(value: unknown, registroId: number) {
  const data = resourceIdentity(value, "evidencias");
  const registro = resourceRelation(data.registro, "registros");
  const nombreOriginal = resourceText(data.nombreOriginal);
  const mimeType = resourceText(data.mimeType).toLowerCase();
  if (registro !== `/api/registros/${registroId}` || data.incidencia != null ||
    typeof data.tipo !== "string" || !Object.hasOwn(tiposEvidencia, data.tipo) ||
    typeof data.tamanoBytes !== "number" || !Number.isSafeInteger(data.tamanoBytes) || data.tamanoBytes <= 0 ||
    !nombreOriginal.trim() || nombreOriginal.length > 255 || /[\\/]/.test(nombreOriginal) || [...nombreOriginal].some((c) => c.charCodeAt(0) < 32) ||
    !/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/.test(mimeType) ||
    data.downloadUrl !== `/api/evidencias/${data.id}/descargar`) return invalidResource();
  // Solo metadatos de presentación. No propaga claves, tokens, rutas ni hashes.
  return { id: data.id, registro, nombreOriginal, mimeType, tamanoBytes: data.tamanoBytes,
    tipo: data.tipo as keyof typeof tiposEvidencia, createdAt: resourceInstant(data.createdAt),
    subidaPor: resourceRelation(data.subidaPor, "usuarios") };
}
export type EvidenciaHistorica = ReturnType<typeof parseEvidencia>;

export function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;
  const unit = bytes < 1024 * 1024 ? "KiB" : "MiB";
  const amount = bytes / (unit === "KiB" ? 1024 : 1024 * 1024);
  return `${new Intl.NumberFormat("es-ES", { maximumFractionDigits: 1 }).format(amount)} ${unit}`;
}
