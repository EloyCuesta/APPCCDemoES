/** Calendar helpers only; expiry transitions remain exclusively in Symfony. */
export function isCalendarDay(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value && date.getUTCFullYear() > 0;
}

export function isInstant(value: unknown): value is string {
  return typeof value === "string"
    && /^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/.test(value)
    && isCalendarDay(value.slice(0, 10)) && Number.isFinite(Date.parse(value));
}

export function localDay(date: Date): string {
  return `${String(date.getFullYear()).padStart(4, "0")}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

export function dayBoundary(day: string, end: boolean): string {
  if (!isCalendarDay(day)) throw new Error("Invalid calendar day");
  const date = new Date(`${day}T${end ? "23:59:59" : "00:00:00"}`);
  // Build each boundary separately: daylight-saving days are not always 24 hours.
  return date.toISOString().replace(".000Z", "Z");
}

export function formatDate(value: string): string {
  return new Intl.DateTimeFormat("es-ES", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(value));
}

export function formatTime(value: string): string {
  return new Intl.DateTimeFormat("es-ES", { hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(new Date(value));
}
