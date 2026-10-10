<script setup lang="ts">
import { onMounted, ref } from "vue";
import { api, errorCode } from "../api";
import { t } from "../i18n";
import { displayDate } from "../public-settings";
interface Diagnostics {
  failed_queues: Array<{ queue: string; count: number }>;
  uploads: Array<{ storage_state: string; count: number }>;
  archive_failures: number;
  issues: number;
  recent: Array<{
    id: number;
    lesson_id: number | null;
    action: string;
    created_at: string;
  }>;
}
const result = ref<Diagnostics | null>(null),
  error = ref(""),
  busy = ref(false);
async function load() {
  busy.value = true;
  error.value = "";
  try {
    result.value = await api.request<Diagnostics>("/api/admin/diagnostics");
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <section>
    <div class="section-header">
      <h2>{{ t("diagnostics") }}</h2>
      <button @click="load" :disabled="busy">{{ t("refresh") }}</button>
    </div>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <template v-if="result"
      ><dl class="usage-grid">
        <div>
          <dt>{{ t("archiveFailures") }}</dt>
          <dd>{{ result.archive_failures }}</dd>
        </div>
        <div>
          <dt>{{ t("unresolvedIssues") }}</dt>
          <dd>{{ result.issues }}</dd>
        </div>
      </dl>
      <h3>{{ t("failedQueues") }}</h3>
      <p v-for="row in result.failed_queues" :key="row.queue">
        {{ row.queue }}: {{ row.count }}
      </p>
      <h3>{{ t("uploads") }}</h3>
      <p v-for="row in result.uploads" :key="row.storage_state">
        {{ t(`upload_${row.storage_state}`) }}: {{ row.count }}
      </p>
      <h3>{{ t("recentAudit") }}</h3>
      <div class="table-wrap">
        <table>
          <tbody>
            <tr v-for="row in result.recent" :key="row.id">
              <td>{{ displayDate(row.created_at) }}</td>
              <td>{{ t(`audit_${row.action}`) }}</td>
              <td>{{ row.lesson_id ?? "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div></template
    >
  </section>
</template>
