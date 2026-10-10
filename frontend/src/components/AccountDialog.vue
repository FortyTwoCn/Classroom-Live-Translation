<script setup lang="ts">
import { displayDate } from "../public-settings";
import { onMounted, ref } from "vue";
import { api, errorCode } from "../api";
import { t } from "../i18n";
import Modal from "./Modal.vue";
const emit = defineEmits<{ close: []; signedOut: []; passwordChanged: [] }>();
interface Device {
  id?: string;
  uuid?: string;
  name?: string;
  user_agent?: string;
  current?: boolean;
  last_used_at?: string;
  expires_at?: string;
}
const devices = ref<Device[]>([]),
  current = ref(""),
  password = ref(""),
  busy = ref(false),
  error = ref(""),
  saved = ref(false),
  all = ref(false);
async function load() {
  try {
    const result = await api.request<{ devices: Device[] }>("/api/devices");
    devices.value = result.devices;
  } catch (e) {
    error.value = errorCode(e);
  }
}
async function revoke(device: Device) {
  busy.value = true;
  try {
    await api.request(`/api/devices/${device.uuid || device.id}`, {}, "DELETE");
    if (device.current) emit("signedOut");
    else await load();
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
async function change() {
  busy.value = true;
  error.value = "";
  try {
    await api.request("/api/password", {
      password: password.value,
      current_password: current.value,
    });
    password.value = "";
    current.value = "";
    saved.value = true;
    emit("passwordChanged");
    await load();
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
async function logoutAll() {
  busy.value = true;
  error.value = "";
  try {
    await api.request("/api/logout-all", { current_password: current.value });
    current.value = "";
    emit("signedOut");
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <Modal :title="t('account')" @close="emit('close')"
    ><p v-if="error" class="alert" role="alert">{{ t(`error_${error}`) }}</p>
    <p v-if="saved" role="status">{{ t("passwordChanged") }}</p>
    <h3>{{ t("devices") }}</h3>
    <p v-if="!devices.length" class="muted">{{ t("noDevices") }}</p>
    <article
      v-for="device in devices"
      :key="device.uuid || device.id"
      class="device-card"
    >
      <p>
        {{ device.name || device.user_agent }}
        <strong v-if="device.current">{{ t("deviceCurrent") }}</strong>
      </p>
      <p v-if="device.last_used_at" class="muted">
        {{ t("lastUsed") }}:
        {{ displayDate(device.last_used_at) }}
      </p>
      <button @click="revoke(device)" :disabled="busy">
        {{ t("revokeDevice") }}
      </button>
    </article>
    <form @submit.prevent="all ? logoutAll() : change()">
      <h3>{{ t(all ? "logoutAll" : "changePassword") }}</h3>
      <p v-if="all">{{ t("logoutAllHelp") }}</p>
      <label
        >{{ t("currentPassword")
        }}<input
          v-model="current"
          type="password"
          autocomplete="current-password"
          required /></label
      ><label v-if="!all"
        >{{ t("newPassword")
        }}<input
          v-model="password"
          type="password"
          autocomplete="new-password"
          minlength="12"
          required
      /></label>
      <div class="dialog-actions">
        <button type="button" @click="all = !all">
          {{ t(all ? "changePassword" : "logoutAll") }}</button
        ><button
          type="submit"
          :class="all ? 'danger' : 'primary'"
          :disabled="busy"
        >
          {{ t(all ? "confirm" : "save") }}
        </button>
      </div>
    </form></Modal
  >
</template>
