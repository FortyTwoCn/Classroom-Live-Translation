import type { Unit } from "./api";
// A truncated snapshot cannot imply deletion of cached historical pages.
export function mergeUnits(cached: Unit[], incoming: Unit[]): Unit[] {
  const byId = new Map(cached.map((unit) => [String(unit.id), unit]));
  for (const unit of incoming) {
    const key = String(unit.id);
    if (unit.deleted_at) byId.delete(key);
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
      timedUnit(unit) &&
      unit.end_sample !== null &&
      unit.end_sample > unit.start_sample! &&
      sample >= unit.start_sample! &&
      sample < unit.end_sample,
  );
}
