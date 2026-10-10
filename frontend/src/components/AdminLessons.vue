<script setup lang="ts">
import { onMounted, ref } from "vue";
import { api, ApiError, errorCode, type Course, type Lesson } from "../api";
import { t } from "../i18n";
import Modal from "./Modal.vue";
const emit = defineEmits<{ navigate: [path: string] }>();
const courses = ref<Course[]>([]),
  lessons = ref<Array<Lesson & { deleted_at?: string }>>([]),
  courseId = ref(""),
  search = ref(""),
  status = ref("all"),
  deleted = ref(false),
  sort = ref("created_at"),
  direction = ref("desc"),
  page = ref(1),
  total = ref(0),
  error = ref(""),
  busy = ref(false);
const confirmation = ref<{
    item: Lesson;
    operation: string;
    method: "POST" | "DELETE";
  } | null>(null),
  reauth = ref(false),
  password = ref("");
let pending: (() => Promise<void>) | null = null;
async function load(reset = false) {
  if (reset) page.value = 1;
  try {
    const query = new URLSearchParams({
      course_id: courseId.value,
      search: search.value,
      status: status.value,
      deleted: deleted.value ? "1" : "0",
      sort: sort.value,
      direction: direction.value,
      page: String(page.value),
    });
    const response = await api.request<{
      lessons:
        Lesson[] | { data: Lesson[]; total: number; current_page: number };
      total?: number;
    }>(`/api/admin/lessons?${query}`);
    lessons.value = Array.isArray(response.lessons)
      ? response.lessons
      : response.lessons.data;
    total.value = Array.isArray(response.lessons)
      ? response.total || response.lessons.length
      : response.lessons.total;
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
async function execute() {
  if (!confirmation.value) return;
  const action = confirmation.value;
  await run(async () => {
    await api.request(
      `/api/admin/lessons/${action.item.id}${action.operation ? `/${action.operation}` : ""}`,
      { confirmed: true },
      action.method,
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
    courses.value = (
      await api.request<{ courses: { data: Course[] } }>("/api/admin/courses")
    ).courses.data;
  } catch (e) {
    error.value = errorCode(e);
  }
  await load();
});
</script>
<template>
  <section>
    <h2>{{ t("manageLessons") }}</h2>
    <form class="filter-bar" @submit.prevent="load(true)">
      <label
        >{{ t("courses")
        }}<select v-model="courseId">
          <option value="">{{ t("all") }}</option>
          <option v-for="course in courses" :key="course.id" :value="course.id">
            {{ course.name }}
          </option>
        </select></label
      ><label
        >{{ t("search")
        }}<input v-model="search" :placeholder="t('searchLesson')" /></label
      ><label
        >{{ t("status")
        }}<select v-model="status">
          <option value="all">{{ t("all") }}</option>
          <option
            v-for="value in ['ready', 'live', 'paused', 'ended']"
            :key="value"
            :value="value"
          >
            {{ t(`status_${value}`) }}
          </option>
        </select></label
      ><label
        >{{ t("sort")
        }}<select v-model="sort">
          <option value="created_at">{{ t("createdAt") }}</option>
          <option value="name">{{ t("name") }}</option>
          <option value="duration">{{ t("mediaDuration") }}</option>
        </select></label
      ><label
        >{{ t("direction")
        }}<select v-model="direction">
          <option value="desc">{{ t("descending") }}</option>
          <option value="asc">{{ t("ascending") }}</option>
        </select></label
      ><label class="consent"
        ><input v-model="deleted" type="checkbox" />{{
          t("deletedLessons")
        }}</label
      ><button>{{ t("search") }}</button>
    </form>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <div class="card-grid">
      <article v-for="item in lessons" :key="item.id" class="course-card">
        <p>{{ item.course_name }}</p>
        <h3>{{ item.name }}</h3>
        <p>
          {{ t(`status_${item.status}`) }} ·
          {{ t(`archive_${item.archive_status}`) }}
        </p>
        <p>{{ t("recorder") }}: {{ item.recorder_name || t("notAssigned") }}</p>
        <div class="inline-actions">
          <button
            @click="emit('navigate', `/lessons/${item.id}`)"
            :disabled="!!item.deleted_at"
          >
            {{ t("openLesson") }}</button
          ><button
            v-if="item.deleted_at"
            @click="
              confirmation = { item, operation: 'restore', method: 'POST' }
            "
          >
            {{ t("restoreLesson") }}</button
          ><template v-else
            ><button
              v-if="item.status !== 'ended'"
              @click="confirmation = { item, operation: 'end', method: 'POST' }"
            >
              {{ t("end") }}</button
            ><button
              v-if="item.archive_status === 'failed'"
              @click="
                confirmation = {
                  item,
                  operation: 'retry-archive',
                  method: 'POST',
                }
              "
            >
              {{ t("retryArchive") }}</button
            ><button
              v-if="item.status === 'ended'"
              class="danger"
              @click="confirmation = { item, operation: '', method: 'DELETE' }"
            >
              {{ t("deleteLesson") }}
            </button></template
          >
        </div>
      </article>
    </div>
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
  <Modal
    v-if="confirmation"
    :title="
      t(
        confirmation.operation === 'restore'
          ? 'restoreLesson'
          : confirmation.operation === 'end'
            ? 'end'
            : confirmation.operation === 'retry-archive'
              ? 'retryArchive'
              : 'deleteLesson',
      )
    "
    @close="confirmation = null"
    ><p>{{ confirmation.item.name }}</p>
    <p>{{ t("confirmAction") }}</p>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <div class="dialog-actions">
      <button @click="confirmation = null">{{ t("cancel") }}</button
      ><button class="danger" @click="execute" :disabled="busy">
        {{ t("confirm") }}
      </button>
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
