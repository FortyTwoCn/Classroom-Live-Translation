<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { api, ApiError, errorCode } from "../api";
import { t } from "../i18n";
import { loadPublicSettings } from "../public-settings";
import { useProtectedAction } from "../composables/useProtectedAction";
import ReauthDialog from "./ReauthDialog.vue";
import Modal from "./Modal.vue";
type Kind = "model" | "oss" | "policies";
type Values = Record<string, string | number | boolean | Price[]>;
interface Price {
  region: string;
  item: string;
  unit_price: string;
  effective_at: string;
  currency: string;
  source_url: string;
  verified_at: string;
}
interface Test {
  id: string;
  status: string;
  kind?: string;
  failure_code?: string;
  report?: {
    steps?: Array<{
      name?: string;
      step?: string;
      error_code?: string;
      code?: string;
      status?: string;
      ok?: boolean;
    }>;
    real_model?: boolean;
    real_oss?: boolean;
    synthetic_audio?: boolean;
    [key: string]: unknown;
  };
}
const kind = ref<Kind>("model"),
  loading = ref(true),
  values = ref<Values>({}),
  saved = ref<Record<Kind, Values>>({ model: {}, oss: {}, policies: {} }),
  versions = ref<Record<Kind, string | null>>({
    model: null,
    oss: null,
    policies: null,
  }),
  notice = ref(""),
  test = ref<Test | null>(null),
  testConsent = ref(false),
  confirm = ref<"save" | "test" | null>(null),
  clearToken = ref(false),
  conflict = ref(false);
const { busy, error, reauth, password, run, cancel, authorize } =
  useProtectedAction();
let timer: number | undefined,
  alive = true;
const fields: Record<Kind, string[]> = {
  model: ["model", "region", "workspace_id", "endpoint", "api_key"],
  oss: [
    "type",
    "endpoint",
    "region",
    "bucket",
    "key",
    "secret",
    "token",
    "prefix",
    "presign_seconds",
  ],
  policies: [
    "default_locale",
    "time_zone",
    "retention_days",
    "audio_download",
    "auto_purge",
    "max_lesson_seconds",
    "max_active_lessons",
    "max_daily_audio_seconds",
    "warning_ratio",
    "rotation_soft_seconds",
    "rotation_hard_seconds",
    "min_disk_bytes",
    "max_import_bytes",
    "max_import_rows",
  ],
};
const secretFields = new Set(["api_key", "key", "secret", "token"]),
  booleanFields = new Set(["audio_download", "auto_purge"]),
  numberFields = new Set([
    "presign_seconds",
    "retention_days",
    "max_lesson_seconds",
    "max_active_lessons",
    "max_daily_audio_seconds",
    "warning_ratio",
    "rotation_soft_seconds",
    "rotation_hard_seconds",
    "min_disk_bytes",
    "max_import_bytes",
    "max_import_rows",
  ]);
function select(value: Kind) {
  kind.value = value;
  const clone: Values = JSON.parse(JSON.stringify(saved.value[value]));
  if (Array.isArray(clone.prices))
    clone.prices = clone.prices.map((row) => ({
      ...row,
      source_url: row.source_url || "",
      verified_at: row.verified_at?.slice(0, 10) || "",
    }));
  for (const field of secretFields)
    if (fields[value].includes(field)) clone[field] = "";
  values.value = clone;
  clearToken.value = false;
  testConsent.value = false;
  test.value = null;
  notice.value = "";
  conflict.value = false;
  if (timer) clearTimeout(timer);
}
async function load() {
  loading.value = true;
  try {
    const result = await api.request<{
      model: Values;
      oss: Values;
      policies: Values;
      versions: Record<Kind, string | null>;
    }>("/api/admin/settings");
    saved.value = {
      model: result.model,
      oss: result.oss,
      policies: result.policies,
    };
    versions.value = result.versions;
    select(kind.value);
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    loading.value = false;
  }
}
function priceRows() {
  return Array.isArray(values.value.prices)
    ? (values.value.prices as Price[])
    : [];
}
function addPrice() {
  values.value.prices = [
    ...priceRows(),
    {
      region: "cn-beijing",
      item: "audio_input",
      unit_price: "",
      effective_at: new Date().toISOString().slice(0, 19) + "Z",
      currency: "CNY",
      source_url: "",
      verified_at: "",
    },
  ];
}
async function save() {
  const current = kind.value;
  const body: Values = {};
  for (const field of fields[current]) {
    const value = values.value[field];
    body[field] = numberFields.has(field)
      ? Number(value)
      : booleanFields.has(field)
        ? !!value
        : String(value ?? "");
  }
  if (current === "policies")
    body.prices = priceRows().map(
      (row) =>
        ({
          region: row.region,
          item: row.item,
          currency: row.currency,
          unit_price: row.unit_price,
          effective_at: row.effective_at,
          ...(row.source_url ? { source_url: row.source_url } : {}),
          ...(row.verified_at ? { verified_at: row.verified_at } : {}),
        }) as Price,
    );
  if (current === "oss" && clearToken.value) body.clear_token = true;
  await run(async () => {
    try {
      await api.request(
        `/api/admin/settings/${current}`,
        {
          values: body,
          expected_version: versions.value[current],
          confirmed: true,
        },
        "PUT",
      );
      confirm.value = null;
      notice.value = "saved";
      await load();
      await loadPublicSettings();
      notice.value = "saved";
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        conflict.value = true;
        confirm.value = null;
      }
      throw e;
    }
  });
}
async function poll() {
  if (!test.value || !alive) return;
  try {
    const result = await api.request<{ test: Test }>(
      `/api/admin/connection-tests/${test.value.id}`,
    );
    test.value = result.test;
    if (["queued", "processing", "running"].includes(result.test.status))
      timer = window.setTimeout(poll, 1200);
  } catch (e) {
    error.value = errorCode(e);
  }
}
async function startTest() {
  if (!testConsent.value) return;
  await run(async () => {
    const result = await api.request<{ test: Test }>(
      `/api/admin/settings/${kind.value}/test`,
      { confirmed: true },
    );
    test.value = result.test;
    confirm.value = null;
    void poll();
  });
}
onMounted(load);
onUnmounted(() => {
  alive = false;
  if (timer) clearTimeout(timer);
});
</script>
<template>
  <section class="settings-panel">
    <h2>{{ t("settings") }}</h2>
    <p class="muted">{{ t("settingsHelp") }}</p>
    <nav class="tabs">
      <button
        v-for="value in ['model', 'oss', 'policies'] as const"
        :key="value"
        @click="select(value)"
        :disabled="loading"
        :aria-current="kind === value ? 'page' : undefined"
      >
        {{
          t(
            value === "model"
              ? "modelSettings"
              : value === "oss"
                ? "ossSettings"
                : "policySettings",
          )
        }}
      </button>
    </nav>
    <p v-if="error" class="alert" role="alert">{{ t(`error_${error}`) }}</p>
    <p v-if="notice" role="status">{{ t(notice) }}</p>
    <p v-if="conflict">
      {{ t("settingsConflictHelp") }}
      <button @click="load">{{ t("reloadLatest") }}</button>
    </p>
    <form
      v-if="!loading"
      @submit.prevent="confirm = 'save'"
      class="settings-form"
    >
      <label
        v-for="field in fields[kind]"
        :key="`${kind}:${field}`"
        :class="{ consent: booleanFields.has(field) }"
        ><span>{{ t(`setting_${field}`) }}</span
        ><input
          v-if="booleanFields.has(field)"
          type="checkbox"
          v-model="values[field]"
        /><select
          v-else-if="field === 'default_locale'"
          v-model="values[field]"
        >
          <option value="zh-CN">中文</option>
          <option value="en">English</option>
          <option value="uk">Українська</option></select
        ><select v-else-if="field === 'type'" v-model="values[field]">
          <option value="aliyun_s3">Aliyun S3</option></select
        ><input
          v-else
          :aria-label="t(`setting_${field}`)"
          :type="
            secretFields.has(field)
              ? 'password'
              : numberFields.has(field)
                ? 'number'
                : 'text'
          "
          v-model="values[field]"
          :step="field === 'warning_ratio' ? '0.01' : '1'"
          :min="
            field === 'max_import_bytes'
              ? 65536
              : field === 'max_import_rows'
                ? 1
                : numberFields.has(field)
                  ? 0
                  : undefined
          "
          :max="
            field === 'max_import_bytes'
              ? 4194304
              : field === 'max_import_rows'
                ? 2000
                : undefined
          "
          :autocomplete="secretFields.has(field) ? 'new-password' : 'off'"
          :placeholder="secretFields.has(field) ? t('keepSecret') : undefined"
        /><small v-if="secretFields.has(field)">{{
          t(saved[kind][`${field}_set`] ? "configured" : "notConfigured")
        }}</small></label
      >
      <label v-if="kind === 'oss'" class="consent"
        ><input type="checkbox" v-model="clearToken" />{{
          t("clearToken")
        }}</label
      >
      <fieldset v-if="kind === 'policies'">
        <legend>{{ t("prices") }}</legend>
        <p class="muted">{{ t("pricesHelp") }}</p>
        <div v-for="(row, index) in priceRows()" :key="index" class="price-row">
          <label>{{ t("setting_region") }}<input v-model="row.region" /></label
          ><label
            >{{ t("priceItem")
            }}<select v-model="row.item">
              <option
                v-for="item in [
                  'audio_input',
                  'text_input',
                  'image_input',
                  'text_output',
                  'audio_output',
                ]"
                :key="item"
                :value="item"
              >
                {{ t(`price_${item}`) }}
              </option>
            </select></label
          ><label
            >{{ t("unitPrice")
            }}<input
              v-model="row.unit_price"
              inputmode="decimal"
              required /></label
          ><label
            >{{ t("currency")
            }}<input v-model="row.currency" maxlength="3" required /></label
          ><label
            >{{ t("effectiveAt")
            }}<input
              v-model="row.effective_at"
              required
              placeholder="2026-10-09T00:00:00Z" /></label
          ><label
            >{{ t("priceSource")
            }}<input v-model="row.source_url" type="url" /></label
          ><label
            >{{ t("verifiedAt")
            }}<input v-model="row.verified_at" type="date" /></label
          ><button
            type="button"
            @click="values.prices = priceRows().filter((_, i) => i !== index)"
          >
            {{ t("remove") }}
          </button>
        </div>
        <button type="button" @click="addPrice">{{ t("addPrice") }}</button>
      </fieldset>
      <button class="primary" :disabled="busy">{{ t("save") }}</button>
    </form>
    <section v-if="kind !== 'policies'" class="connection-test">
      <h3>{{ t("testResult") }}</h3>
      <label class="consent"
        ><input v-model="testConsent" type="checkbox" />{{
          t("testConsent")
        }}</label
      ><button
        @click="confirm = 'test'"
        :disabled="
          busy ||
          !testConsent ||
          (!!test && ['queued', 'processing', 'running'].includes(test.status))
        "
      >
        {{ t("connectionTest") }}
      </button>
      <p v-if="!test">{{ t("testNotRun") }}</p>
      <template v-else
        ><p role="status">{{ t(`test_${test.status}`) }}</p>
        <p v-if="test.failure_code" class="alert">
          {{ t(`error_${test.failure_code}`) }}
        </p>
        <ul v-if="test.report?.steps">
          <li v-for="(step, index) in test.report.steps" :key="index">
            {{ t(`probe_${step.step || step.name || step.code}`) }} ·
            {{
              t(
                step.ok || step.status === "passed"
                  ? "test_passed"
                  : "test_failed",
              )
            }}
            <span v-if="step.error_code">
              · {{ t(`error_${step.error_code}`) }}</span
            >
          </li>
        </ul></template
      >
    </section>
    <Modal
      v-if="confirm"
      :title="t(confirm === 'save' ? 'save' : 'connectionTest')"
      @close="confirm = null"
      ><p>{{ t(confirm === "save" ? "settingsHelp" : "testConsent") }}</p>
      <div class="dialog-actions">
        <button @click="confirm = null">{{ t("cancel") }}</button
        ><button
          :disabled="busy"
          @click="confirm === 'save' ? save() : startTest()"
        >
          {{ t("confirm") }}
        </button>
      </div></Modal
    ><ReauthDialog
      v-if="reauth"
      v-model:password="password"
      :error="error"
      :busy="busy"
      @close="cancel"
      @submit="authorize"
    />
  </section>
</template>
