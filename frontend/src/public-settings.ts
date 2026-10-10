import { ref } from "vue";
import { api } from "./api";
import { locale, setLocale, type Locale } from "./i18n";
export const publicSettings = ref({
  default_locale: "zh-CN",
  time_zone: "Asia/Shanghai",
  retention_days: 0,
  audio_download: false,
  max_lesson_seconds: 5400,
});
export async function loadPublicSettings() {
  const result = await api.request<Partial<typeof publicSettings.value>>(
    "/api/public-settings",
  );
  publicSettings.value = { ...publicSettings.value, ...result };
  if (
    !localStorage.getItem("classroom.locale") &&
    ["zh-CN", "en", "uk"].includes(result.default_locale || "")
  )
    setLocale(result.default_locale as Locale);
}
export function displayDate(value?: string | null) {
  if (!value) return "—";
  try {
    return new Intl.DateTimeFormat(locale.value, {
      timeZone: publicSettings.value.time_zone,
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(normalizeUtcDate(value)));
  } catch {
    return "—";
  }
}
export function normalizeUtcDate(value: string) {
  const normalized = value.replace(" ", "T");
  if (/[+-]\d{2}$/.test(normalized)) return `${normalized}:00`;
  if (/^\d{4}-\d{2}-\d{2}T[\d:.]+$/.test(normalized)) return `${normalized}Z`;
  return normalized;
}
export function clockSeconds(value: number) {
  const s = Math.max(0, Math.floor(value));
  return `${String(Math.floor(s / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}`;
}
