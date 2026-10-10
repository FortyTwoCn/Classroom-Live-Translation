<script setup lang="ts">
import { computed, ref } from "vue";
import { t } from "../i18n";
import type { Lesson, User, Participant, Handoff } from "../api";
import Modal from "./Modal.vue";
import Icon from "./Icon.vue";
const props = defineProps<{
  lesson: Lesson;
  user: User;
  participants: Participant[];
  currentClientId: string;
  items: Handoff[];
  isRecorder: boolean;
  preparation: { id: string; live: boolean } | null;
  preparing: boolean;
}>();
const emit = defineEmits<{
  command: [type: string, fields: Record<string, unknown>];
  prepare: [id: string];
  takeover: [force: boolean];
}>();
const mode = ref<"invite" | "request" | "takeover" | null>(null),
  target = ref(""),
  force = ref(false);
const recipients = computed(() =>
  props.participants.filter((item) => item.client_id !== props.currentClientId),
);
const incoming = computed(() =>
  props.items.filter(
    (item) =>
      item.status === "pending" &&
      (item.role === "recipient" || item.role === "target"),
  ),
);
function send() {
  if (mode.value === "invite")
    emit("command", "recording.invite", {
      target_client_id: target.value,
      confirmed: true,
      idempotency_key: crypto.randomUUID(),
    });
  else if (mode.value === "request")
    emit("command", "recording.request", {
      confirmed: true,
      idempotency_key: crypto.randomUUID(),
    });
  else emit("takeover", force.value);
  mode.value = null;
}
</script>
<template>
  <div class="handoff-panel">
    <button
      v-if="isRecorder"
      @click="
        mode = 'invite';
        target = '';
      "
      :title="t('transfer')"
    >
      <Icon name="person" /> {{ t("transfer") }}</button
    ><button
      v-else-if="lesson.status !== 'ended' && lesson.recorder_user_id"
      @click="mode = 'request'"
    >
      {{ t("requestRecording") }}</button
    ><button
      v-if="
        lesson.status !== 'ended' &&
        (!lesson.recorder_user_id || lesson.recorder_offline_at)
      "
      @click="
        mode = 'takeover';
        force = false;
      "
    >
      {{ t("takeover") }}</button
    ><button
      v-if="user.role === 'admin' && lesson.status !== 'ended'"
      @click="
        mode = 'takeover';
        force = true;
      "
    >
      {{ t("forceTakeover") }}
    </button>
    <p v-if="lesson.recorder_offline_at" role="status">
      {{ t("recorderOffline") }}
    </p>
    <article v-for="item in items" :key="item.id" class="handoff-item">
      <p>
        {{
          t(item.kind === "invite" ? "inviteFrom" : "requestFrom", {
            name: item.requester_name,
          })
        }}
        · {{ t(`handoff_${item.status}`) }}
      </p>
      <small
        >{{ t("expiresAt") }}:
        {{ new Date(item.expires_at).toLocaleTimeString() }}</small
      ><button
        v-if="
          item.role === 'sender' &&
          ['pending', 'preparing'].includes(item.status)
        "
        @click="
          emit('command', 'recording.respond', {
            id: item.id,
            action: 'cancel',
          })
        "
      >
        {{ t("cancel") }}
      </button>
    </article>
  </div>
  <Modal
    v-if="mode"
    :title="
      t(
        mode === 'invite'
          ? 'transfer'
          : mode === 'request'
            ? 'requestRecording'
            : force
              ? 'forceTakeover'
              : 'takeover',
      )
    "
    @close="mode = null"
    ><p>
      {{
        t(
          mode === "invite"
            ? "transferHelp"
            : mode === "request"
              ? "recordingRequestHelp"
              : "takeoverHelp",
        )
      }}
    </p>
    <label v-if="mode === 'invite'"
      >{{ t("selectRecipient")
      }}<select v-model="target">
        <option value="">{{ t("selectRecipient") }}</option>
        <option
          v-for="person in recipients"
          :key="person.client_id"
          :value="person.client_id"
        >
          {{ person.name }} · {{ person.client_id.slice(-5) }}
        </option>
      </select></label
    >
    <p v-if="mode === 'invite' && !recipients.length">
      {{ t("noRecipients") }}
    </p>
    <div class="dialog-actions">
      <button @click="mode = null">{{ t("cancel") }}</button
      ><button
        class="primary"
        @click="send"
        :disabled="mode === 'invite' && !target"
      >
        {{ t(mode === "invite" ? "sendInvite" : "confirm") }}
      </button>
    </div></Modal
  ><Modal
    v-if="incoming.length && !preparation"
    :title="t(incoming[0]!.kind === 'invite' ? 'transfer' : 'requestRecording')"
    @close="
      emit('command', 'recording.respond', {
        id: incoming[0]!.id,
        action: 'reject',
      })
    "
    ><p>
      {{
        t(incoming[0]!.kind === "invite" ? "inviteFrom" : "requestFrom", {
          name: incoming[0]!.requester_name,
        })
      }}
    </p>
    <p>{{ t("handoffPrepareHelp") }}</p>
    <div class="dialog-actions">
      <button
        @click="
          emit('command', 'recording.respond', {
            id: incoming[0]!.id,
            action: 'reject',
          })
        "
      >
        {{ t("reject") }}</button
      ><button
        class="primary"
        @click="
          emit('command', 'recording.respond', {
            id: incoming[0]!.id,
            action: 'accept',
          })
        "
      >
        {{ t("accept") }}
      </button>
    </div></Modal
  ><Modal
    v-if="preparation"
    :title="t('prepareDevice')"
    :locked="preparing"
    @close="
      emit('command', 'recording.prepared', {
        id: preparation.id,
        ready: false,
      })
    "
    ><p>{{ t("handoffPrepareHelp") }}</p>
    <div class="dialog-actions">
      <button
        @click="
          emit('command', 'recording.prepared', {
            id: preparation.id,
            ready: false,
          })
        "
        :disabled="preparing"
      >
        {{ t("reject") }}</button
      ><button
        class="primary"
        @click="emit('prepare', preparation.id)"
        :disabled="preparing"
      >
        {{ t(preparing ? "preparingMic" : "prepareDevice") }}
      </button>
    </div></Modal
  >
</template>
