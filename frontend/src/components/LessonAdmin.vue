<script setup lang="ts">
import { ref } from "vue";
import { api, ApiError, errorCode, type Lesson, type Unit } from "../api";
import { t } from "../i18n";
import Modal from "./Modal.vue";
const props = defineProps<{ lesson: Lesson; unit?: Unit }>();
const emit = defineEmits<{ updated: [] }>();
const confirm = ref<{
    label: string;
    path: string;
    body: Record<string, unknown>;
    method?: "PATCH" | "POST" | "DELETE";
  } | null>(null),
  busy = ref(false),
  error = ref(""),
  reauth = ref(false),
  password = ref(""),
  expiry = ref(""),
  sharing = ref(false);
let pending: (() => Promise<void>) | null = null;
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
  if (!confirm.value) return;
  const value = confirm.value;
  await run(async () => {
    await api.request(value.path, value.body, value.method);
    confirm.value = null;
    emit("updated");
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
function ask(
  label: string,
  operation: string,
  body: Record<string, unknown> = { confirmed: true },
  method: "PATCH" | "POST" | "DELETE" = "POST",
) {
  confirm.value = {
    label,
    path: `/api/admin/lessons/${props.lesson.id}/${operation}`,
    body,
    method,
  };
}
function resetShare() {
  confirm.value = {
    label: "resetShare",
    path: `/api/admin/lessons/${props.lesson.id}/share/reset`,
    body: {
      confirmed: true,
      expires_at: expiry.value ? new Date(expiry.value).toISOString() : null,
    },
  };
  sharing.value = false;
}
</script>
<template>
  <div class="inline-actions admin-tools">
    <template v-if="unit"
      ><button
        class="icon-button danger"
        @click="
          ask(
            'deleteSubtitle',
            `subtitles/${unit.id}`,
            { confirmed: true },
            'DELETE',
          )
        "
        :title="t('deleteSubtitle')"
      >
        ×
      </button></template
    ><template v-else
      ><button
        @click="
          ask(
            lesson.editing_frozen ? 'unfreezeEditing' : 'freezeEditing',
            'editing',
            { frozen: !lesson.editing_frozen, confirmed: true },
            'PATCH',
          )
        "
      >
        {{
          t(lesson.editing_frozen ? "unfreezeEditing" : "freezeEditing")
        }}</button
      ><button @click="sharing = true">{{ t("manageShare") }}</button
      ><button
        v-if="lesson.archive_status === 'failed'"
        @click="ask('retryArchive', 'retry-archive')"
      >
        {{ t("retryArchive") }}</button
      ><button
        v-if="lesson.status === 'ended'"
        @click="
          ask('forceArchive', 'retry-archive', { confirmed: true, force: true })
        "
      >
        {{ t("forceArchive") }}
      </button></template
    >
  </div>
  <Modal v-if="sharing" :title="t('manageShare')" @close="sharing = false"
    ><p>{{ t("shareAdminHelp") }}</p>
    <label
      >{{ t("shareExpiry") }}<input v-model="expiry" type="datetime-local"
    /></label>
    <p v-if="!expiry" class="muted">{{ t("noExpiry") }}</p>
    <div class="dialog-actions">
      <button @click="sharing = false">{{ t("close") }}</button
      ><button @click="resetShare">{{ t("resetShare") }}</button
      ><button
        class="danger"
        @click="
          ask('revokeShare', 'share/revoke');
          sharing = false;
        "
      >
        {{ t("revokeShare") }}
      </button>
    </div></Modal
  ><Modal v-if="confirm" :title="t(confirm.label)" @close="confirm = null"
    ><p>{{ t("confirmAction") }}</p>
    <p v-if="confirm.label === 'forceArchive'">{{ t("forceArchiveHelp") }}</p>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <div class="dialog-actions">
      <button @click="confirm = null">{{ t("cancel") }}</button
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
