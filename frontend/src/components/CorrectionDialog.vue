<script setup lang="ts">
import { displayDate } from "../public-settings";
import { onMounted, ref, watch } from "vue";
import { api, ApiError, errorCode, type Lesson, type Unit } from "../api";
import { t } from "../i18n";
import Modal from "./Modal.vue";
const props = defineProps<{
  lesson: Lesson;
  unit: Unit;
  mode: "edit" | "history";
  draft: string;
}>();
const emit = defineEmits<{
  close: [];
  draft: [text: string];
  saved: [units: Unit[]];
}>();
interface Revision {
  id: number;
  version: number;
  before_text: string;
  after_text: string;
  actor_name: string;
  created_at: string;
}
const text = ref(props.draft),
  expected = ref(props.unit.version || 1),
  history = ref<{
    original: string | { text: string };
    revisions: Revision[];
    next_before_id?: number | null;
    related_units?: Array<{
      unit_id: number;
      kind: string;
      original_text: string;
      text: string;
      status: string;
      version: number;
    }>;
  } | null>(null),
  latest = ref<Unit | null>(null),
  busy = ref(false),
  error = ref("");
watch(text, (value) => emit("draft", value));
async function loadHistory(earlier = false) {
  try {
    const result = await api.request<NonNullable<typeof history.value>>(
      `/api/lessons/${props.lesson.id}/subtitles/${props.unit.id}/history${earlier && history.value?.next_before_id ? `?before_id=${history.value.next_before_id}` : ""}`,
    );
    history.value =
      earlier && history.value
        ? {
            ...result,
            revisions: [...history.value.revisions, ...result.revisions],
          }
        : result;
  } catch (e) {
    error.value = errorCode(e);
  }
}
function conflict(e: unknown) {
  error.value = errorCode(e);
  if (e instanceof ApiError && e.code === "subtitle_version_conflict") {
    const units = e.params.latest_units as Unit[] | undefined;
    latest.value = units?.find((unit) => unit.id === props.unit.id) || null;
  }
}
async function save() {
  if (busy.value || latest.value) return;
  busy.value = true;
  try {
    const response = await api.request<{ units: Unit[] }>(
      `/api/lessons/${props.lesson.id}/subtitles`,
      {
        changes: [
          {
            unit_id: props.unit.id,
            expected_version: expected.value,
            text: text.value,
          },
        ],
        idempotency_key: crypto.randomUUID(),
      },
      "PATCH",
    );
    emit("saved", response.units);
    emit("close");
  } catch (e) {
    conflict(e);
  } finally {
    busy.value = false;
  }
}
async function restore(revision: number | null) {
  if (busy.value) return;
  busy.value = true;
  try {
    const response = await api.request<{ units?: Unit[]; unit?: Unit }>(
      `/api/lessons/${props.lesson.id}/subtitles/${props.unit.id}/restore`,
      {
        revision_id: revision,
        expected_version: expected.value,
        idempotency_key: crypto.randomUUID(),
      },
    );
    const units = response.units || (response.unit ? [response.unit] : []);
    emit("saved", units);
    expected.value =
      units.find((unit) => unit.id === props.unit.id)?.version ||
      expected.value + 1;
    await loadHistory();
  } catch (e) {
    conflict(e);
  } finally {
    busy.value = false;
  }
}
function acceptLatest() {
  if (!latest.value) return;
  expected.value = latest.value.version || expected.value;
  text.value = latest.value.text;
  latest.value = null;
  error.value = "";
}
onMounted(() => {
  if (props.mode === "history") void loadHistory();
});
</script>
<template>
  <Modal
    :title="t(mode === 'edit' ? 'edit' : 'history')"
    wide
    @close="emit('close')"
    ><p class="muted">
      {{ t(unit.kind === "source" ? "original" : "translation") }} ·
      {{ t("version") }} {{ expected }}
    </p>
    <form v-if="mode === 'edit'" @submit.prevent="save">
      <label
        >{{ t("currentText")
        }}<textarea
          v-model="text"
          rows="8"
          maxlength="20000"
          required
          autofocus
        ></textarea></label
      ><template v-if="latest"
        ><h3>{{ t("conflictTitle") }}</h3>
        <p>{{ t("conflictHelp") }}</p>
        <div class="comparison">
          <section>
            <h4>{{ t("myDraft") }}</h4>
            <p>{{ text }}</p>
          </section>
          <section>
            <h4>{{ t("latestText") }} · {{ latest.version }}</h4>
            <p>{{ latest.text }}</p>
          </section>
        </div>
        <button type="button" @click="acceptLatest">
          {{ t("useLatest") }}
        </button></template
      >
      <p v-if="error" class="alert" role="alert">{{ t(`error_${error}`) }}</p>
      <div class="dialog-actions">
        <button type="button" @click="emit('close')">{{ t("cancel") }}</button
        ><button
          type="submit"
          class="primary"
          :disabled="busy || !!latest || !text.trim()"
        >
          {{ t("save") }}
        </button>
      </div>
    </form>
    <template v-else
      ><p v-if="!history && !error">{{ t("loading") }}</p>
      <p v-if="error" class="alert" role="alert">{{ t(`error_${error}`) }}</p>
      <template v-if="history"
        ><h3>{{ t("originalModel") }}</h3>
        <template v-if="history.related_units?.length"
          ><section
            v-for="related in history.related_units"
            :key="related.unit_id"
          >
            <h4>
              {{ t(related.kind === "source" ? "original" : "translation") }}
            </h4>
            <p class="history-text">{{ related.original_text }}</p>
          </section></template
        >
        <p v-else class="history-text">
          {{
            typeof history.original === "string"
              ? history.original
              : history.original.text
          }}
        </p>
        <button
          @click="restore(null)"
          :disabled="busy || lesson.editing_frozen"
        >
          {{ t("restoreOriginal") }}
        </button>
        <p v-if="!history.revisions.length">{{ t("noHistory") }}</p>
        <article
          v-for="revision in history.revisions"
          :key="revision.id"
          class="revision"
        >
          <h4>
            {{ t("version") }} {{ revision.version }} ·
            {{ revision.actor_name }}
          </h4>
          <time>{{ displayDate(revision.created_at) }}</time>
          <div class="comparison">
            <section>
              <h5>{{ t("beforeText") }}</h5>
              <p>{{ revision.before_text }}</p>
            </section>
            <section>
              <h5>{{ t("afterText") }}</h5>
              <p>{{ revision.after_text }}</p>
            </section>
          </div>
          <button
            @click="restore(revision.id)"
            :disabled="busy || lesson.editing_frozen"
          >
            {{ t("restore") }}
          </button>
        </article>
        <button v-if="history.next_before_id" @click="loadHistory(true)">
          {{ t("loadEarlier") }}
        </button></template
      >
      <div v-if="latest">
        <p>{{ t("conflictHelp") }}</p>
        <p>{{ latest.text }}</p>
        <button
          @click="
            expected = latest.version || expected;
            latest = null;
            error = '';
          "
        >
          {{ t("useLatest") }}
        </button>
      </div>
      <div class="dialog-actions">
        <button @click="emit('close')">{{ t("close") }}</button>
      </div></template
    ></Modal
  >
</template>
