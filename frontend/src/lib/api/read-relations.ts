import type { ApiClient } from "./client";
import { endpoints } from "./endpoints";
import { ApiError } from "./errors";
import { resourceId } from "./resources";
import { checkId, parseResponsable } from "@/features/agenda/contracts";

export async function loadUsuarioLabel(api: ApiClient, iri: string, signal: AbortSignal): Promise<string> {
  const id = resourceId(iri, "usuarios");
  try {
    const person = checkId(parseResponsable(await api.request(endpoints.usuario(id), { signal })), id);
    return `${person.nombre} ${person.apellidos}`.trim();
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 404) throw error;
    return `Usuario #${id} (sin acceso actual)`;
  }
}
