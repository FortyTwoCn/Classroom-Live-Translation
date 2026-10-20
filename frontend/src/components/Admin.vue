<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { api, ApiError, errorCode, type User, type Course } from "../api";
import { locale, t } from "../i18n";
import Modal from "./Modal.vue";
import AdminLessons from "./AdminLessons.vue";
import AdminSubtitles from "./AdminSubtitles.vue";
import SettingsPanel from "./SettingsPanel.vue";
import GlossaryPanel from "./GlossaryPanel.vue";
import UsagePanel from "./UsagePanel.vue";
import DiagnosticsPanel from "./DiagnosticsPanel.vue";
import { displayDate } from "../public-settings";
const emit = defineEmits<{ navigate: [path: string] }>();
const tab = ref<
    | "users"
    | "courses"
    | "imports"
    | "lessons"
    | "subtitles"
    | "settings"
    | "glossary"
    | "usage"
    | "diagnostics"
  >("users"),
  users = ref<User[]>([]),
  courses = ref<Course[]>([]),
  search = ref(""),
  page = ref(1),
  total = ref(0),
  busy = ref(false),
  error = ref(""),
  notice = ref("");
const deletedCourses = ref(false),
  coursePage = ref(1),
  courseTotal = ref(0);
const userForm = ref<(User & { student_id: string }) | null>(null),
  courseForm = ref<Course | null>(null),
  confirm = ref<{
    title: string;
    help: string;
    dependencyCount?: number;
    run: () => Promise<void>;
  } | null>(null),
  reauth = ref(false),
  reauthPassword = ref("");
let pending: (() => Promise<void>) | null = null;
interface ImportResult {
  id: string;
  status: string;
  counts: Record<string, number>;
  preview_hash: string;
  expires_at: string;
  failure_code?: string;
}
interface ImportRow {
  row: number;
  name: string;
  student_id: string;
  action: string;
  errors: Array<{ code: string; params?: Record<string, string | number> }>;
}
const file = ref<File | null>(null),
  policy = ref("skip"),
  importJob = ref<ImportResult | null>(null),
  rows = ref<ImportRow[]>([]),
  importPage = ref(1);
let pollTimer: number | undefined;
let alive = true;
async function load() {
  error.value = "";
  try {
    if (tab.value === "users") {
      const result = await api.request<{
        users: { data: User[]; total: number };
      }>(
        `/api/admin/users?q=${encodeURIComponent(search.value)}&page=${page.value}`,
      );
      users.value = result.users.data;
      total.value = result.users.total;
    } else if (tab.value === "courses") {
      const result = await api.request<{
        courses: { data: Course[]; total: number };
      }>(
        `/api/admin/courses?deleted=${deletedCourses.value ? "1" : "0"}&page=${coursePage.value}`,
      );
      courses.value = result.courses.data;
      courseTotal.value = result.courses.total;
    }
  } catch (e) {
    error.value = errorCode(e);
  }
}
async function run(callback: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  notice.value = "";
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
async function authorize() {
  await run(async () => {
    await api.request("/api/reauth", { password: reauthPassword.value });
    reauthPassword.value = "";
    reauth.value = false;
    const action = pending;
    pending = null;
    if (action) await action();
  });
}
function newUser() {
  userForm.value = {
    id: 0,
    name: "",
    student_id: "",
    role: "user",
    must_change_password: true,
    disabled: false,
  };
}
function newCourse() {
  courseForm.value = {
    id: 0,
    name: "",
    description: "",
    target_language: "zh",
    guest_access: "public",
    enabled: true,
    archived: false,
    sort_order: 0,
  };
}
async function saveUser() {
  if (!userForm.value) return;
  const draft = { ...userForm.value };
  await run(async () => {
    const body = {
      name: draft.name,
      student_id: draft.student_id,
      ...(draft.id ? { role: draft.role, disabled: !!draft.disabled } : {}),
    };
    await api.request(
      draft.id ? `/api/admin/users/${draft.id}` : "/api/admin/users",
      body,
      draft.id ? "PATCH" : "POST",
    );
    userForm.value = null;
    notice.value = "saved";
    await load();
  });
}
async function saveCourse() {
  if (!courseForm.value) return;
  const draft = { ...courseForm.value };
  await run(async () => {
    await api.request(
      draft.id ? `/api/admin/courses/${draft.id}` : "/api/admin/courses",
      {
        name: draft.name,
        description: draft.description,
        target_language: draft.target_language,
        guest_access: draft.guest_access,
        enabled: draft.enabled,
        archived: draft.archived,
        allow_audio_download: !!draft.allow_audio_download,
        sort_order: Number(draft.sort_order || 0),
      },
      draft.id ? "PATCH" : "POST",
    );
    courseForm.value = null;
    notice.value = "saved";
    await load();
  });
}
function askUser(item: User, operation: "reset-password" | "revoke-sessions") {
  confirm.value = {
    title: operation === "reset-password" ? "resetPassword" : "revokeSessions",
    help:
      operation === "reset-password"
        ? "initialPasswordResetHelp"
        : "confirmAction",
    run: async () => {
      await api.request(`/api/admin/users/${item.id}/${operation}`, {
        confirm: true,
      });
      confirm.value = null;
      notice.value = "saved";
      await load();
    },
  };
}
function askDelete(item: Course) {
  confirm.value = {
    title: "deleteCourse",
    help: "deleteCourseHelp",
    dependencyCount: item.lesson_count || 0,
    run: async () => {
      await api.request(
        `/api/admin/courses/${item.id}`,
        { confirm: true },
        "DELETE",
      );
      confirm.value = null;
      await load();
    },
  };
}
async function restoreCourse(item: Course) {
  await run(async () => {
    await api.request(`/api/admin/courses/${item.id}/restore`, {
      confirmed: true,
    });
    await load();
  });
}
async function loadImport() {
  if (!importJob.value || !alive) return;
  try {
    const response = await api.request<{
      import: ImportResult;
      rows: ImportRow[];
    }>(`/api/admin/imports/${importJob.value.id}?page=${importPage.value}`);
    importJob.value = response.import;
    rows.value = response.rows;
    if (importJob.value.status === "processing")
      pollTimer = window.setTimeout(loadImport, 1200);
  } catch (e) {
    error.value = errorCode(e);
  }
}
async function preview() {
  if (!file.value) return;
  await run(async () => {
    const form = new FormData();
    form.append("file", file.value!);
    form.append("duplicate_policy", policy.value);
    const response = await api.request<{
      import: ImportResult;
      rows: ImportRow[];
    }>("/api/admin/imports", form);
    importJob.value = response.import;
    rows.value = response.rows;
    importPage.value = 1;
  });
}
async function confirmImport() {
  if (!importJob.value || importJob.value.status !== "preview") return;
  const job = importJob.value;
  await run(async () => {
    const response = await api.request<{
      import: ImportResult;
      rows: ImportRow[];
    }>(`/api/admin/imports/${job.id}/confirm`, {
      preview_hash: job.preview_hash,
      confirm: true,
    });
    importJob.value = response.import;
    rows.value = response.rows;
    void loadImport();
  });
}
function chooseFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] || null;
  importJob.value = null;
  rows.value = [];
  window.clearTimeout(pollTimer);
}
function selectTab(value: typeof tab.value) {
  tab.value = value;
  page.value = 1;
  search.value = "";
  void load();
}
onMounted(load);
onUnmounted(() => {
  alive = false;
  window.clearTimeout(pollTimer);
});
</script>
<template>
  <section class="admin">
    <h1>{{ t("admin") }}</h1>
    <nav class="tabs" :aria-label="t('admin')">
      <button
        v-for="item in [
          'users',
          'courses',
          'lessons',
          'subtitles',
          'imports',
          'glossary',
          'usage',
          'settings',
          'diagnostics',
        ] as const"
        :key="item"
        :aria-current="tab === item ? 'page' : undefined"
        @click="selectTab(item)"
      >
        {{
          t(
            item === "courses"
              ? "manageCourses"
              : item === "imports"
                ? "excelImport"
                : item === "lessons"
                  ? "manageLessons"
                  : item === "subtitles"
                    ? "manageSubtitles"
                    : item === "diagnostics" ||
                        item === "settings" ||
                        item === "glossary" ||
                        item === "usage"
                      ? item
                      : "users",
          )
        }}
      </button>
    </nav>
    <p v-if="error" class="alert" role="alert">{{ t(`error_${error}`) }}</p>
    <p v-if="notice" role="status">{{ t(notice) }}</p>
    <template v-if="tab === 'users'"
      ><p class="foreground-note">{{ t("initialPasswordHelp") }}</p>
      <div class="section-header">
        <form
          class="search-form"
          @submit.prevent="
            page = 1;
            load();
          "
        >
          <label class="sr-only" for="admin-search">{{ t("search") }}</label
          ><input
            id="admin-search"
            v-model="search"
            :placeholder="t('search')"
          /><button>{{ t("search") }}</button>
        </form>
        <button class="primary" @click="newUser" :aria-label="t('addUser')">
          + {{ t("addUser") }}
        </button>
      </div>
      <div class="table-wrap">
        <table class="user-table">
          <thead>
            <tr>
              <th>{{ t("personName") }}</th>
              <th>{{ t("studentId") }}</th>
              <th>{{ t("role") }}</th>
              <th>{{ t("status") }}</th>
              <th>{{ t("action") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in users" :key="item.id">
              <td>{{ item.name }}</td>
              <td>{{ item.student_id }}</td>
              <td>{{ t(`role_${item.role}`) }}</td>
              <td>
                {{ t(item.disabled ? "disabled" : "enabled")
                }}<span v-if="item.must_change_password">
                  · {{ t("requiredPassword") }}</span
                >
              </td>
              <td>
                <div class="inline-actions">
                  <button
                    @click="
                      userForm = { ...item, student_id: item.student_id || '' }
                    "
                  >
                    {{ t("editUser") }}</button
                  ><button @click="askUser(item, 'reset-password')">
                    {{ t("resetPassword") }}</button
                  ><button @click="askUser(item, 'revoke-sessions')">
                    {{ t("revokeSessions") }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
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
          :disabled="page * 20 >= total"
        >
          {{ t("next") }}
        </button>
      </div></template
    ><template v-else-if="tab === 'courses'"
      ><div class="section-header">
        <h2>{{ t("manageCourses") }}</h2>
        <button class="primary" @click="newCourse" :aria-label="t('newCourse')">
          + {{ t("newCourse") }}
        </button>
      </div>
      <label class="consent"
        ><input
          v-model="deletedCourses"
          type="checkbox"
          @change="
            coursePage = 1;
            load();
          "
        />{{ t("deletedCourses") }}</label
      >
      <div class="card-grid admin-course-grid">
        <article class="course-card" v-for="item in courses" :key="item.id">
          <h3>{{ item.name }}</h3>
          <p>{{ item.description }}</p>
          <p>
            {{ t(item.enabled === false ? "disabled" : "enabled") }} ·
            {{
              t(item.guest_access === "public" ? "publicShare" : "privateShare")
            }}
            · {{ t("order") }} {{ item.sort_order }}
          </p>
          <p v-if="item.archived_at">{{ t("archived") }}</p>
          <div class="inline-actions">
            <button
              v-if="!item.deleted_at"
              @click="courseForm = { ...item, archived: !!item.archived_at }"
            >
              {{ t("editCourse") }}</button
            ><button @click="emit('navigate', `/courses/${item.id}/lessons`)">
              {{ t("lessons") }}</button
            ><button
              v-if="!item.deleted_at"
              class="danger"
              @click="askDelete(item)"
            >
              {{ t("deleteCourse") }}
            </button>
            <button v-if="item.deleted_at" @click="restoreCourse(item)">
              {{ t("restoreCourse") }}
            </button>
          </div>
        </article>
      </div>
      <div class="pagination">
        <button
          @click="
            coursePage--;
            load();
          "
          :disabled="coursePage === 1"
        >
          {{ t("previous") }}</button
        ><span>{{ t("pageOf", { page: coursePage, total: courseTotal }) }}</span
        ><button
          @click="
            coursePage++;
            load();
          "
          :disabled="coursePage * 30 >= courseTotal"
        >
          {{ t("next") }}
        </button>
      </div></template
    ><AdminLessons
      v-else-if="tab === 'lessons'"
      @navigate="emit('navigate', $event)"
    /><AdminSubtitles
      v-else-if="tab === 'subtitles'"
      @navigate="emit('navigate', $event)"
    /><SettingsPanel v-else-if="tab === 'settings'" /><GlossaryPanel
      v-else-if="tab === 'glossary'"
    /><UsagePanel v-else-if="tab === 'usage'" /><DiagnosticsPanel
      v-else-if="tab === 'diagnostics'"
    /><template v-else
      ><h2>{{ t("excelImport") }}</h2>
      <p>{{ t("importHelp") }}</p>
      <p class="foreground-note">{{ t("initialPasswordHelp") }}</p>
      <a
        class="button"
        :href="`/api/admin/users/template.xlsx?locale=${locale}`"
        >{{ t("template") }}</a
      >
      <form @submit.prevent="preview">
        <label
          >{{ t("chooseFile")
          }}<input
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            @change="chooseFile"
            required /></label
        ><label
          >{{ t("duplicatePolicy")
          }}<select v-model="policy">
            <option value="skip">{{ t("skipDuplicates") }}</option>
            <option value="update_name">{{ t("updateNames") }}</option>
          </select></label
        ><button class="primary" :disabled="busy || !file">
          {{ t("previewImport") }}
        </button>
      </form>
      <template v-if="importJob"
        ><h3 aria-live="polite">{{ t(`import_${importJob.status}`) }}</h3>
        <dl class="counts">
          <template v-for="(value, key) in importJob.counts" :key="key"
            ><dt>{{ t(`count_${key}`) }}</dt>
            <dd>{{ value }}</dd></template
          >
        </dl>
        <p>
          {{ t("expiresAt") }}:
          {{ displayDate(importJob.expires_at) }}
        </p>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ t("rowNumber") }}</th>
                <th>{{ t("personName") }}</th>
                <th>{{ t("studentId") }}</th>
                <th>{{ t("action") }}</th>
                <th>{{ t("errors") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.row">
                <td>{{ row.row }}</td>
                <td>{{ row.name }}</td>
                <td>{{ row.student_id }}</td>
                <td>{{ t(`count_${row.action}`) }}</td>
                <td>
                  <p v-for="(issue, index) in row.errors" :key="index">
                    {{ t(`error_${issue.code}`, issue.params) }}
                  </p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pagination">
          <button
            @click="
              importPage--;
              loadImport();
            "
            :disabled="importPage === 1"
          >
            {{ t("previous") }}</button
          ><span>{{ importPage }}</span
          ><button
            @click="
              importPage++;
              loadImport();
            "
            :disabled="rows.length < 50"
          >
            {{ t("next") }}
          </button>
        </div>
        <div class="inline-actions">
          <button
            v-if="importJob.status === 'preview'"
            class="primary"
            @click="confirmImport"
            :disabled="busy"
          >
            {{ t("confirmImport") }}</button
          ><a
            class="button"
            :href="`/api/admin/imports/${importJob.id}/errors.csv`"
            >{{ t("errorReport") }}</a
          >
        </div></template
      ></template
    >
  </section>
  <Modal
    v-if="userForm"
    :title="t(userForm.id ? 'editUser' : 'addUser')"
    @close="userForm = null"
    ><form @submit.prevent="saveUser">
      <label
        >{{ t("personName")
        }}<input
          v-model="userForm.name"
          required
          maxlength="200"
          autofocus /></label
      ><label
        >{{ t("studentId")
        }}<input
          v-model="userForm.student_id"
          minlength="6"
          maxlength="100"
          required /></label
      ><label v-if="userForm.id"
        >{{ t("role")
        }}<select v-model="userForm.role">
          <option value="user">{{ t("role_user") }}</option>
          <option value="admin">{{ t("role_admin") }}</option>
        </select></label
      ><label v-if="userForm.id" class="consent"
        ><input v-model="userForm.disabled" type="checkbox" />{{
          t("disabled")
        }}</label
      >
      <p>{{ t("initialPasswordHelp") }}</p>
      <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
      <div class="dialog-actions">
        <button type="button" @click="userForm = null">{{ t("cancel") }}</button
        ><button class="primary" :disabled="busy">{{ t("save") }}</button>
      </div>
    </form></Modal
  ><Modal
    v-if="courseForm"
    :title="t(courseForm.id ? 'editCourse' : 'newCourse')"
    @close="courseForm = null"
    ><form @submit.prevent="saveCourse">
      <label
        >{{ t("name")
        }}<input
          v-model="courseForm.name"
          maxlength="200"
          required
          autofocus /></label
      ><label
        >{{ t("courseDescriptionOptional")
        }}<textarea
          v-model="courseForm.description"
          rows="3"
          maxlength="5000"
        ></textarea></label
      ><label
        >{{ t("targetLanguage")
        }}<select v-model="courseForm.target_language">
          <option value="zh">中文</option>
          <option value="en">English</option>
          <option value="uk">Українська</option>
        </select></label
      ><label
        >{{ t("order")
        }}<input
          v-model.number="courseForm.sort_order"
          type="number"
          min="-100000"
          max="100000" /></label
      ><label
        >{{ t("guestAccess")
        }}<select v-model="courseForm.guest_access">
          <option value="public">{{ t("publicShare") }}</option>
          <option value="link">{{ t("privateShare") }}</option>
        </select></label
      >
      <p v-if="courseForm.guest_access === 'public'" class="foreground-note">
        {{ t("publicWarning") }}
      </p>
      <label class="consent"
        ><input v-model="courseForm.allow_audio_download" type="checkbox" />{{
          t("setting_audio_download")
        }}</label
      >
      <label class="consent"
        ><input v-model="courseForm.enabled" type="checkbox" />{{
          t("enabled")
        }}</label
      ><label class="consent"
        ><input v-model="courseForm.archived" type="checkbox" />{{
          t("archived")
        }}</label
      >
      <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
      <div class="dialog-actions">
        <button type="button" @click="courseForm = null">
          {{ t("cancel") }}</button
        ><button class="primary" :disabled="busy">{{ t("save") }}</button>
      </div>
    </form></Modal
  ><Modal v-if="confirm" :title="t(confirm.title)" @close="confirm = null"
    ><p>{{ t(confirm.help) }}</p>
    <p v-if="confirm.dependencyCount !== undefined">
      {{ t("courseDependencies", { n: confirm.dependencyCount }) }}
    </p>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <div class="dialog-actions">
      <button @click="confirm = null">{{ t("cancel") }}</button
      ><button class="danger" @click="run(confirm.run)" :disabled="busy">
        {{ t("confirm") }}
      </button>
    </div></Modal
  ><Modal
    v-if="reauth"
    :title="t('reauth')"
    @close="
      reauth = false;
      pending = null;
      reauthPassword = '';
    "
    ><form @submit.prevent="authorize">
      <p>{{ t("reauthHelp") }}</p>
      <label
        >{{ t("currentPassword")
        }}<input
          v-model="reauthPassword"
          type="password"
          autocomplete="current-password"
          required
          autofocus
      /></label>
      <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
      <div class="dialog-actions">
        <button
          type="button"
          @click="
            reauth = false;
            pending = null;
            reauthPassword = '';
          "
        >
          {{ t("cancel") }}</button
        ><button class="primary" :disabled="busy">{{ t("confirm") }}</button>
      </div>
    </form></Modal
  >
</template>

