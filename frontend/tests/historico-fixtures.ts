import { registro } from "./incidencias-fixtures";

export function historicalRecord(id = 8, tenantId = 1, conforme = false) {
  return { ...registro(tenantId), "@id": `/api/registros/${id}`, id, conforme };
}

export function evidence(
  id = 1,
  tipo: "foto" | "documento" | "firma" = "foto",
  registroId = 8,
) {
  const mimeType = tipo === "documento" ? "application/pdf" : "image/png";
  return {
    "@id": `/api/evidencias/${id}`,
    id,
    registro: `/api/registros/${registroId}`,
    tipo,
    nombreOriginal: `${tipo}-${id}.${tipo === "documento" ? "pdf" : "png"}`,
    mimeType,
    tamanoBytes: 4,
    createdAt: "2026-09-24T10:01:00+02:00",
    subidaPor: "/api/usuarios/5",
    downloadUrl: `/api/evidencias/${id}/descargar`,
  };
}
