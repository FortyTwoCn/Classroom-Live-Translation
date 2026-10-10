<script setup lang="ts">
import { onMounted, ref } from "vue";
import { api, errorCode } from "../api";
import { t } from "../i18n";
import { clockSeconds, displayDate } from "../public-settings";
const props = defineProps<{ lessonId?: number }>();
interface Usage {
  audio: {
    samples: number;
    seconds: number;
    daily_seconds: number;
    reset_at: string;
  };
  model: {
    session_count: number;
    failed_sessions: number;
    call_seconds_estimate: number;
    recovery_count?: number;
    rotation_count?: number;
    interrupted_sessions?: number;
    resume_sessions?: number;
    rotation_sessions?: number;
    official_records: number;
    unknown_usage_records: number;
    tokens: {
      total: number | null;
      input: number | null;
      output: number | null;
      input_details: {
        audio: number | null;
        text: number | null;
        image: number | null;
      };
      output_details: { audio: number | null; text: number | null };
    };
  };
  costs: {
    no_pricing_configured: boolean;
    complete: boolean;
    totals: Array<{
      currency: string;
      priced_subtotal: string;
      estimated_total: string | null;
      complete: boolean;
    }>;
    unpriced: Array<{
      region: string;
      item: string;
      tokens: number | null;
      reason: string;
    }>;
  };
}
const usage = ref<Usage | null>(null),
  error = ref(""),
  busy = ref(false);
async function load() {
  busy.value = true;
  error.value = "";
  try {
    usage.value = await api.request<Usage>(
      props.lessonId
        ? `/api/lessons/${props.lessonId}/usage`
        : "/api/admin/usage",
    );
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
onMounted(load);
const tokenText = (value: number | null | undefined) =>
  value ?? t("unknownValue");
</script>
<template>
  <section class="usage-panel">
    <div class="section-header">
      <h2>{{ t("usage") }}</h2>
      <button @click="load" :disabled="busy">{{ t("refresh") }}</button>
    </div>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <template v-if="usage"
      ><dl class="usage-grid">
        <div>
          <dt>{{ t("recordedAudio") }}</dt>
          <dd>{{ clockSeconds(usage.audio.seconds) }}</dd>
        </div>
        <div>
          <dt>{{ t("dailyAudio") }}</dt>
          <dd>{{ clockSeconds(usage.audio.daily_seconds) }}</dd>
        </div>
        <div>
          <dt>{{ t("modelSessions") }}</dt>
          <dd>{{ usage.model.session_count }}</dd>
        </div>
        <div>
          <dt>{{ t("officialUsage") }}</dt>
          <dd>{{ usage.model.official_records }}</dd>
        </div>
        <div>
          <dt>{{ t("unknownUsage") }}</dt>
          <dd>{{ usage.model.unknown_usage_records }}</dd>
        </div>
        <div>
          <dt>{{ t("tokenUsage") }}</dt>
          <dd>{{ tokenText(usage.model.tokens.total) }}</dd>
        </div>
      </dl>
      <p>
        {{ t("inputTokens") }}: {{ tokenText(usage.model.tokens.input) }} ·
        {{ t("outputTokens") }}: {{ tokenText(usage.model.tokens.output) }}
      </p>
      <p>
        {{ t("failedSessions") }}: {{ usage.model.failed_sessions }} ·
        {{ t("callDurationEstimate") }}:
        {{ clockSeconds(usage.model.call_seconds_estimate) }}
      </p>
      <p v-if="usage.model.resume_sessions !== undefined">
        {{ t("recoveryCount") }}: {{ usage.model.resume_sessions }}
      </p>
      <p v-if="usage.model.rotation_sessions !== undefined">
        {{ t("rotationCount") }}: {{ usage.model.rotation_sessions }}
      </p>
      <details>
        <summary>{{ t("tokenUsage") }}</summary>
        <p v-for="item in ['audio', 'text', 'image'] as const" :key="item">
          {{ t(`price_${item}_input`) }}:
          {{ tokenText(usage.model.tokens.input_details[item]) }}
        </p>
        <p v-for="item in ['audio', 'text'] as const" :key="item">
          {{ t(`price_${item}_output`) }}:
          {{ tokenText(usage.model.tokens.output_details[item]) }}
        </p>
      </details>
      <h3>{{ t("estimatedCost") }}</h3>
      <p class="muted">{{ t("notOfficialBill") }}</p>
      <p v-if="usage.costs.no_pricing_configured">
        {{ t("noPriceConfigured") }}
      </p>
      <p v-else-if="!usage.costs.totals.length">{{ t("unknownCost") }}</p>
      <div v-for="group in usage.costs.totals" :key="group.currency">
        <strong
          >{{ group.currency }}
          {{
            group.estimated_total === null
              ? t("unknownCost")
              : group.estimated_total
          }}</strong
        >
        <p v-if="group.estimated_total === null">
          {{ t("partialCost") }}: {{ group.currency }}
          {{ group.priced_subtotal }}
        </p>
      </div>
      <p v-if="usage.audio.reset_at" class="muted">
        UTC · {{ displayDate(usage.audio.reset_at) }}
      </p>
      <details v-if="usage.costs.unpriced.length">
        <summary>{{ t("unknownCost") }}</summary>
        <p v-for="(row, index) in usage.costs.unpriced" :key="index">
          {{ row.region || t("unknownValue") }} · {{ t(`price_${row.item}`) }} ·
          {{ tokenText(row.tokens) }} token · {{ t(`cost_${row.reason}`) }}
        </p>
      </details></template
    >
  </section>
</template>
