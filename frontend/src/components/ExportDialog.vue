<script setup lang="ts">
import { onUnmounted, ref } from "vue";
import { api, errorCode, type Lesson } from "../api";
import { t } from "../i18n";
import Modal from "./Modal.vue";
const props = defineProps<{ lesson: Lesson; hasContent: boolean }>();
const emit = defineEmits<{ close: [] }>();
interface ExportJob {
  id: string;
  status: string;
  format: string;
  warnings: Array<string | { code: string; count?: number }>;
  counts: Record<string, number>;
  expires_at: string | null;
  failure_code?: string;
}
const format = ref("txt"),
  layout = ref("bilingual"),
  textVersion = ref("current"),
  job = ref<ExportJob | null>(null),
  busy = ref(false),
  error = ref("");
let timer: number | undefined;
let mounted = true;
async function poll() {
  if (!job.value || !mounted) return;
  try {
    const response = await api.request<{ export: ExportJob }>(
      `/api/exports/${job.value.id}`,
    );
    job.value = response.export;
    if (["queued", "processing"].includes(job.value.status))
      timer = window.setTimeout(poll, 1200);
  } catch (e) {
    error.value = errorCode(e);
  }
}
async function create() {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    const response = await api.request<{ export: ExportJob }>(
      `/api/lessons/${props.lesson.id}/exports`,
      {
        format: format.value,
        layout: layout.value,
        text_version: textVersion.value,
        idempotency_key: crypto.randomUUID(),
      },
    );
    job.value = response.export;
    void poll();
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
async function retry() {
  if (!job.value) return;
  busy.value = true;
  try {
    const response = await api.request<{ export: ExportJob }>(
      `/api/exports/${job.value.id}/retry`,
      {},
    );
    job.value = response.export;
    void poll();
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
onUnmounted(() => {
  mounted = false;
  window.clearTimeout(timer);
});
</script>
<template>
  <Modal :title="t('export')" @close="emit('close')"
    ><p class="eyebrow">{{ lesson.course_name }}</p>
    <h3>{{ lesson.name }}</h3>
    <p class="muted">{{ t("exportHelp") }}</p>
    <form @submit.prevent="create">
      <label
        >{{ t("exportFormat")
        }}<select v-model="format" :aria-label="t('exportFormat')" :disabled="busy || !!job">
          <option value="txt">TXT (.txt)</option>
          <option value="md">Markdown (.md)</option>
          <option value="srt">SRT (.srt)</option>
          <option value="vtt">WebVTT (.vtt)</option>
        </select></label
      ><label
        >{{ t("displayLayout")
        }}<select v-model="layout" :aria-label="t('displayLayout')" :disabled="busy || !!job">
          <option value="bilingual">{{ t("bilingual") }}</option>
          <option value="source">{{ t("original") }}</option>
          <option value="translation">{{ t("translation") }}</option>
        </select></label
      ><label
        >{{ t("textVersion")
        }}<select v-model="textVersion" :aria-label="t('textVersion')" :disabled="busy || !!job">
          <option value="current">{{ t("currentText") }}</option>
          <option value="original">{{ t("originalModel") }}</option>
        </select></label
      >
      <p v-if="!hasContent">{{ t("noExportable") }}</p>
      <p v-if="error" class="alert" role="alert">{{ t(`error_${error}`) }}</p>
      <div v-if="job" aria-live="polite">
        <h3>{{ t(`export_${job.status}`) }}</h3>
        <p v-if="job.expires_at">
          {{ t("expiresAt") }}: {{ new Date(job.expires_at).toLocaleString() }}
        </p>
        <template v-if="Object.keys(job.counts || {}).length"
          ><h4>{{ t("exportCounts") }}</h4>
          <dl class="counts">
            <template v-for="(value, key) in job.counts" :key="key"
              ><dt>{{ t(String(key)) }}</dt>
              <dd>{{ value }}</dd></template
            >
          </dl></template
        ><template v-if="job.warnings?.length"
          ><h4>{{ t("exportWarnings") }}</h4>
          <ul>
            <li v-for="(warning, index) in job.warnings" :key="index">
              {{
                t(
                  `warning_${typeof warning === "string" ? warning : warning.code}`,
                )
              }}<span v-if="typeof warning !== 'string' && warning.count">
                ({{ warning.count }})</span
              >
            </li>
          </ul></template
        >
        <p v-if="job.failure_code" class="alert">
          {{ t(`error_${job.failure_code}`) }}
        </p>
      </div>
      <div class="dialog-actions">
        <button type="button" @click="emit('close')">
          {{ t(job ? "close" : "cancel") }}</button
        ><button
          v-if="!job"
          class="primary"
          type="submit"
          :disabled="busy || !hasContent"
        >
          {{ t(busy ? "loading" : "exportConfirm") }}</button
        ><a
          v-if="job?.status === 'ready'"
          class="button primary"
          :href="`/api/exports/${job.id}/file`"
          >{{ t("download") }}</a
        ><button
          v-if="job?.status === 'failed'"
          type="button"
          @click="retry"
          :disabled="busy"
        >
          {{ t("retry") }}</button
        ><button
          v-if="job?.status === 'expired'"
          type="button"
          @click="job = null"
        >
          {{ t("exportConfirm") }}
        </button>
      </div>
    </form></Modal
  >
</template>
