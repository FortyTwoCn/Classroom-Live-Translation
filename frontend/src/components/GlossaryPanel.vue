<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { api, ApiError, errorCode, type Course } from "../api";
import { t, locale } from "../i18n";
import { loadAdminCourseOptions } from "../admin-courses";
import { useProtectedAction } from "../composables/useProtectedAction";
import ReauthDialog from "./ReauthDialog.vue";
import Modal from "./Modal.vue";
interface Entry {
  id: number;
  source_language: string;
  target_language: string;
  source_text: string;
  target_text: string;
  note: string;
  enabled: boolean;
  row_version: number;
  deleted_at: string | null;
}
const courses = ref<Course[]>([]),
  courseId = ref(""),
  entries = ref<Entry[]>([]),
  loading = ref(false),
  version = ref(""),
  page = ref(1),
  total = ref(0),
  maxEntries = ref(1000),
  sourceLimit = ref(256),
  targetLimit = ref(64),
  search = ref(""),
  source = ref(""),
  target = ref(""),
  enabled = ref(""),
  deleted = ref("active"),
  draft = ref<Entry | null>(null),
  conflict = ref(false),
  confirm = ref<{ entry: Entry; operation: "delete" | "restore" } | null>(null);
const { busy, error, reauth, password, run, cancel, authorize } =
  useProtectedAction();
const termTooLarge = computed(
  () =>
    !!draft.value &&
    (new TextEncoder().encode(draft.value.source_text.trim()).length >
      sourceLimit.value ||
      new TextEncoder().encode(draft.value.target_text.trim()).length >
        targetLimit.value),
);
function languageName(code: string) {
  return (
    new Intl.DisplayNames([locale.value], { type: "language" }).of(code) || code
  );
}
async function load(reset = false, keepDraft = false) {
  if (reset) page.value = 1;
  if (!courseId.value) return;
  loading.value = true;
  try {
    const q = new URLSearchParams({
      page: String(page.value),
      search: search.value,
      source_language: source.value,
      target_language: target.value,
      enabled: enabled.value,
      deleted: deleted.value,
    });
    const result = await api.request<{
      entries: Entry[];
      version: string | number;
      total: number;
      limits: {
        max_entries: number;
        max_source_bytes: number;
        max_target_bytes: number;
      };
    }>(`/api/admin/courses/${courseId.value}/glossary?${q}`);
    entries.value = result.entries;
    version.value = String(result.version);
    total.value = result.total;
    maxEntries.value = result.limits.max_entries;
    sourceLimit.value = result.limits.max_source_bytes;
    targetLimit.value = result.limits.max_target_bytes;
    if (!keepDraft) conflict.value = false;
    return true;
  } catch (e) {
    error.value = errorCode(e);
    return false;
  } finally {
    loading.value = false;
  }
}
function add() {
  draft.value = {
    id: 0,
    source_language: "en",
    target_language: "zh",
    source_text: "",
    target_text: "",
    note: "",
    enabled: true,
    row_version: 1,
    deleted_at: null,
  };
  conflict.value = false;
}
async function save() {
  if (!draft.value || termTooLarge.value) return;
  const body = { ...draft.value, expected_glossary_version: version.value };
  await run(async () => {
    try {
      await api.request(
        `/api/admin/courses/${courseId.value}/glossary${body.id ? `/${body.id}` : ""}`,
        body,
        body.id ? "PATCH" : "POST",
      );
      draft.value = null;
      await load();
    } catch (e) {
      if (e instanceof ApiError && e.code === "glossary_version_conflict")
        conflict.value = true;
      throw e;
    }
  });
}
async function reloadDraft() {
  if (await load(false, true)) conflict.value = false;
}
async function execute() {
  if (!confirm.value) return;
  const action = confirm.value;
  await run(async () => {
    await api.request(
      `/api/admin/courses/${courseId.value}/glossary/${action.entry.id}${action.operation === "restore" ? "/restore" : ""}`,
      { confirmed: true, expected_glossary_version: version.value },
      action.operation === "delete" ? "DELETE" : "POST",
    );
    confirm.value = null;
    await load();
  });
}
onMounted(async () => {
  try {
    courses.value = await loadAdminCourseOptions();
    courseId.value = String(courses.value[0]?.id || "");
    await load();
  } catch (e) {
    error.value = errorCode(e);
  }
});
</script>
<template>
  <section>
    <h2>{{ t("glossary") }}</h2>
    <p class="muted">{{ t("glossaryHelp") }}</p>
    <p>
      {{ t("glossaryLimits", { n: maxEntries }) }} · {{ t("glossaryVersion") }}
      {{ version }}
    </p>
    <p class="muted">
      {{ t("termByteLimits", { source: sourceLimit, target: targetLimit }) }}
    </p>
    <form class="filter-bar" @submit.prevent="load(true)">
      <label
        >{{ t("courses")
        }}<select v-model="courseId" @change="load(true)">
          <option
            v-for="item in courses"
            :key="item.id"
            :value="String(item.id)"
          >
            {{ item.name }}
          </option>
        </select></label
      ><label>{{ t("search") }}<input v-model="search" /></label
      ><label
        >{{ t("sourceLanguage")
        }}<select v-model="source">
          <option value="">{{ t("all") }}</option>
          <option
            v-for="lang in ['en', 'uk', 'zh', 'ru']"
            :key="lang"
            :value="lang"
          >
            {{ languageName(lang) }}
          </option>
        </select></label
      ><label
        >{{ t("targetLanguage")
        }}<select v-model="target">
          <option value="">{{ t("all") }}</option>
          <option v-for="lang in ['zh', 'en', 'uk']" :key="lang" :value="lang">
            {{ languageName(lang) }}
          </option>
        </select></label
      ><label
        >{{ t("status")
        }}<select v-model="enabled">
          <option value="">{{ t("all") }}</option>
          <option value="1">{{ t("enabled") }}</option>
          <option value="0">{{ t("disabled") }}</option>
        </select></label
      ><label
        >{{ t("termDeletionState")
        }}<select v-model="deleted">
          <option value="active">{{ t("activeOnly") }}</option>
          <option value="only">{{ t("deletedOnly") }}</option>
          <option value="all">{{ t("all") }}</option>
        </select></label
      ><button>{{ t("search") }}</button>
    </form>
    <p v-if="error" role="alert" class="alert">{{ t(`error_${error}`) }}</p>
    <button class="primary" @click="add" :disabled="!courseId || loading">
      {{ t("addTerm") }}
    </button>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>{{ t("sourceTerm") }}</th>
            <th>{{ t("targetTerm") }}</th>
            <th>{{ t("sourceLanguage") }}</th>
            <th>{{ t("targetLanguage") }}</th>
            <th>{{ t("status") }}</th>
            <th>{{ t("action") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entry in entries" :key="entry.id">
            <td>{{ entry.source_text }}</td>
            <td>{{ entry.target_text }}</td>
            <td>{{ languageName(entry.source_language) }}</td>
            <td>{{ languageName(entry.target_language) }}</td>
            <td>{{ t(entry.enabled ? "enabled" : "disabled") }}</td>
            <td>
              <template v-if="!entry.deleted_at"
                ><button
                  @click="
                    draft = { ...entry };
                    conflict = false;
                  "
                >
                  {{ t("edit") }}</button
                ><button @click="confirm = { entry, operation: 'delete' }">
                  {{ t("remove") }}
                </button></template
              ><button
                v-else
                @click="confirm = { entry, operation: 'restore' }"
              >
                {{ t("restoreTerm") }}
              </button>
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
        :disabled="page * 30 >= total"
      >
        {{ t("next") }}
      </button>
    </div>
    <Modal
      v-if="draft"
      :title="t(draft.id ? 'edit' : 'addTerm')"
      @close="draft = null"
      ><form @submit.prevent="save">
        <label
          >{{ t("sourceLanguage")
          }}<select v-model="draft.source_language">
            <option
              v-for="lang in ['en', 'uk', 'zh', 'ru']"
              :key="lang"
              :value="lang"
            >
              {{ languageName(lang) }}
            </option>
          </select></label
        ><label
          >{{ t("targetLanguage")
          }}<select v-model="draft.target_language">
            <option
              v-for="lang in ['zh', 'en', 'uk']"
              :key="lang"
              :value="lang"
            >
              {{ languageName(lang) }}
            </option>
          </select></label
        ><label
          >{{ t("sourceTerm")
          }}<input
            v-model="draft.source_text"
            maxlength="1000"
            required /></label
        ><label
          >{{ t("targetTerm")
          }}<input
            v-model="draft.target_text"
            maxlength="1000"
            required /></label
        ><label
          >{{ t("note")
          }}<textarea v-model="draft.note" maxlength="2000"></textarea></label
        ><label class="consent"
          ><input v-model="draft.enabled" type="checkbox" />{{
            t("enabled")
          }}</label
        >
        <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
        <p class="muted">
          {{
            t("termByteLimits", { source: sourceLimit, target: targetLimit })
          }}
        </p>
        <p v-if="termTooLarge" class="alert">
          {{ t("error_glossary_term_invalid") }}
        </p>
        <template v-if="conflict"
          ><p>{{ t("glossaryConflictHelp") }}</p>
          <button type="button" @click="reloadDraft">
            {{ t("reloadLatest") }}
          </button></template
        >
        <div class="dialog-actions">
          <button type="button" @click="draft = null">{{ t("cancel") }}</button
          ><button :disabled="busy || conflict || termTooLarge">
            {{ t("save") }}
          </button>
        </div>
      </form></Modal
    ><Modal
      v-if="confirm"
      :title="t(confirm.operation === 'restore' ? 'restoreTerm' : 'remove')"
      @close="confirm = null"
      ><p>{{ confirm.entry.source_text }} → {{ confirm.entry.target_text }}</p>
      <p>{{ t("confirmAction") }}</p>
      <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
      <button @click="execute" :disabled="busy">
        {{ t("confirm") }}
      </button></Modal
    ><ReauthDialog
      v-if="reauth"
      v-model:password="password"
      :error="error"
      :busy="busy"
      @submit="authorize"
      @close="cancel"
    />
  </section>
</template>
