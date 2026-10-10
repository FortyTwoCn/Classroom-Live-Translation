<script setup lang="ts">
import { onMounted, ref } from "vue";
import {
  api,
  ApiError,
  errorCode,
  type Unit,
  type Course,
  type Lesson,
} from "../api";
import { t } from "../i18n";
import Modal from "./Modal.vue";
import CorrectionDialog from "./CorrectionDialog.vue";
import { loadAdminCourseOptions } from "../admin-courses";
const emit = defineEmits<{ navigate: [path: string] }>();
type ManagedUnit = Unit & {
  lesson_id: number;
  lesson_name: string;
  course_name: string;
};
const courses = ref<Course[]>([]),
  units = ref<ManagedUnit[]>([]),
  course = ref(""),
  lessonId = ref(""),
  search = ref(""),
  edited = ref("all"),
  deleted = ref("active"),
  kind = ref("all"),
  page = ref(1),
  total = ref(0),
  error = ref(""),
  busy = ref(false),
  reauth = ref(false),
  password = ref("");
let pending: (() => Promise<void>) | null = null;
const correction = ref<{
    unit: Unit;
    lesson: Lesson;
    mode: "edit" | "history";
  } | null>(null),
  confirmation = ref<ManagedUnit | null>(null);
async function load(reset = false) {
  if (reset) page.value = 1;
  try {
    const query = new URLSearchParams({
      course_id: course.value,
      lesson_id: lessonId.value,
      search: search.value,
      edited: edited.value,
      deleted: deleted.value,
      kind: kind.value,
      page: String(page.value),
    });
    const response = await api.request<{ units: ManagedUnit[]; total: number }>(
      `/api/admin/subtitles?${query}`,
    );
    units.value = response.units;
    total.value = response.total;
  } catch (e) {
    error.value = errorCode(e);
  }
}
async function open(unit: ManagedUnit, mode: "edit" | "history") {
  try {
    const result = await api.request<{ lesson: Lesson }>(
      `/api/lessons/${unit.lesson_id}`,
    );
    correction.value = { unit, lesson: result.lesson, mode };
  } catch (e) {
    error.value = errorCode(e);
  }
}
async function run(callback: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    await callback();
  } catch (e) {
    if (e instanceof ApiError && e.code === "reauth_required") {
      pending = callback;
      reauth.value = true;
    } else error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
async function restore() {
  if (!confirmation.value) return;
  const unit = confirmation.value;
  await run(async () => {
    await api.request(
      `/api/admin/lessons/${unit.lesson_id}/subtitles/${unit.id}/restore-deleted`,
      { confirmed: true },
    );
    confirmation.value = null;
    await load();
  });
}
async function authorize() {
  await run(async () => {
    await api.request("/api/reauth", { password: password.value });
    password.value = "";
    reauth.value = false;
    const action = pending;
    pending = null;
    if (action) await action();
  });
}
onMounted(async () => {
  try {
    courses.value = await loadAdminCourseOptions();
  } catch (e) {
    error.value = errorCode(e);
  }
  await load();
});
</script>
<template>
  <section>
    <h2>{{ t("manageSubtitles") }}</h2>
    <form class="filter-bar" @submit.prevent="load(true)">
      <label
        >{{ t("courses")
        }}<select v-model="course">
          <option value="">{{ t("all") }}</option>
          <option v-for="item in courses" :key="item.id" :value="item.id">
            {{ item.name }}
          </option>
        </select></label
      ><label
        >{{ t("lessonId")
        }}<input v-model="lessonId" inputmode="numeric" /></label
      ><label
        >{{ t("search")
        }}<input v-model="search" :placeholder="t('searchSubtitle')" /></label
      ><label
        >{{ t("edited")
        }}<select v-model="edited">
          <option value="all">{{ t("all") }}</option>
          <option value="yes">{{ t("edited") }}</option>
          <option value="no">{{ t("notEdited") }}</option>
        </select></label
      ><label
        >{{ t("deletedSubtitles")
        }}<select v-model="deleted">
          <option value="active">{{ t("activeContent") }}</option>
          <option value="only">{{ t("deletedContent") }}</option>
          <option value="all">{{ t("all") }}</option>
        </select></label
      ><label
        >{{ t("displayLayout")
        }}<select v-model="kind">
          <option value="all">{{ t("all") }}</option>
          <option value="source">{{ t("original") }}</option>
          <option value="translation">{{ t("translation") }}</option>
        </select></label
      ><button class="primary">{{ t("search") }}</button>
    </form>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <article v-for="unit in units" :key="unit.id" class="panel">
      <p>
        {{ unit.course_name }} · {{ unit.lesson_name }} ·
        {{ t(unit.kind === "source" ? "original" : "translation") }}
      </p>
      <p class="history-text">{{ unit.text }}</p>
      <div class="inline-actions">
        <button @click="emit('navigate', `/lessons/${unit.lesson_id}`)">
          {{ t("openLesson") }}</button
        ><button
          v-if="!unit.deleted_at && unit.status === 'completed'"
          @click="open(unit, 'edit')"
        >
          {{ t("edit") }}</button
        ><button v-if="!unit.deleted_at" @click="open(unit, 'history')">
          {{ t("history") }}</button
        ><button v-if="unit.deleted_at" @click="confirmation = unit">
          {{ t("restoreSubtitle") }}
        </button>
      </div>
    </article>
    <div class="pagination">
      <button
        @click="
          page--;
          load();
        "
        :disabled="page === 1"
      >
        {{ t("previous") }}</button
      ><span>{{ t("pageOf", { page, total }) }}</span
      ><button
        @click="
          page++;
          load();
        "
        :disabled="page * 30 >= total"
      >
        {{ t("next") }}
      </button>
    </div>
  </section>
  <CorrectionDialog
    v-if="correction"
    :unit="correction.unit"
    :lesson="correction.lesson"
    :mode="correction.mode"
    :draft="correction.unit.text"
    @close="correction = null"
    @saved="load()"
  /><Modal
    v-if="confirmation"
    :title="t('restoreSubtitle')"
    @close="confirmation = null"
    ><p>{{ t("confirmAction") }}</p>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <div class="dialog-actions">
      <button @click="confirmation = null">{{ t("cancel") }}</button
      ><button @click="restore" :disabled="busy">{{ t("confirm") }}</button>
    </div></Modal
  ><Modal
    v-if="reauth"
    :title="t('reauth')"
    @close="
      reauth = false;
      pending = null;
      password = '';
    "
    ><form @submit.prevent="authorize">
      <p>{{ t("reauthHelp") }}</p>
      <label
        >{{ t("currentPassword")
        }}<input
          v-model="password"
          type="password"
          required
          autofocus
          autocomplete="current-password"
      /></label>
      <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
      <div class="dialog-actions">
        <button
          type="button"
          @click="
            reauth = false;
            pending = null;
            password = '';
          "
        >
          {{ t("cancel") }}</button
        ><button class="primary" :disabled="busy">{{ t("confirm") }}</button>
      </div>
    </form></Modal
  >
</template>
