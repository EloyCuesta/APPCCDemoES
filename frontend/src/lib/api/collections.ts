import { ApiError, isRecord } from "./errors";

export interface ApiCollection<T> {
  items: T[];
  total: number | null;
  next: string | null;
}

/** API Platform 4 uses member/totalItems; older Hydra prefixes are also supported. */
export function readCollection<T>(
  data: unknown,
  parseItem: (item: unknown) => T,
): ApiCollection<T> {
  if (!isRecord(data)) throw new ApiError(502);
  const members = data.member ?? data["hydra:member"];
  if (!Array.isArray(members)) throw new ApiError(502);
  const total = data.totalItems ?? data["hydra:totalItems"];
  const view = data.view ?? data["hydra:view"];
  const next = isRecord(view) ? (view.next ?? view["hydra:next"]) : null;
  return {
    items: members.map(parseItem),
    total: typeof total === "number" && total >= 0 ? total : null,
    next: typeof next === "string" ? next : null,
  };
}
