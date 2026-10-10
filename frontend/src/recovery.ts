import type { Unit } from "./api";
export function hasSubtitleText(text: string): boolean {
  return /[^\s\u0085\u00a0\u1680\u180e\u2000-\u200f\u2028-\u202f\u205f\u2060-\u206f\u3000\ufeff]/u.test(
    text,
  );
}
// A truncated snapshot cannot imply deletion of cached historical pages.
export function mergeUnits(cached: Unit[], incoming: Unit[]): Unit[] {
  const byId = new Map(
    cached
      .filter((unit) => !unit.deleted_at && hasSubtitleText(unit.text))
      .map((unit) => [String(unit.id), unit]),
  );
  for (const unit of incoming) {
    const key = String(unit.id);
    if (unit.deleted_at || !hasSubtitleText(unit.text)) byId.delete(key);
    else byId.set(key, unit);
  }
  return [...byId.values()].sort((a, b) => Number(a.id) - Number(b.id));
}
export function timedUnit(unit: Unit): boolean {
  return (
    unit.start_sample !== null &&
    Number.isFinite(unit.start_sample) &&
    unit.start_sample >= 0 &&
    ["server_segment", "exact"].includes(unit.timing_precision || "")
  );
}
export function activeUnits(units: Unit[], seconds: number): Unit[] {
  const sample = Math.floor(seconds * 16000);
  return units.filter(
    (unit) =>
      !unit.deleted_at &&
      hasSubtitleText(unit.text) &&
      timedUnit(unit) &&
      unit.end_sample !== null &&
      unit.end_sample > unit.start_sample! &&
      sample >= unit.start_sample! &&
      sample < unit.end_sample,
  );
}
