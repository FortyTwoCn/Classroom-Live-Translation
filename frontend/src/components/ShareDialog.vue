<script setup lang="ts">
import { displayDate } from "../public-settings";
import { nextTick, onMounted, ref } from "vue";
import { api, errorCode, type Lesson } from "../api";
import { t } from "../i18n";
import { renderQr, qrCard, saveCanvas } from "../qr";
import Modal from "./Modal.vue";
const props = defineProps<{ lesson: Lesson }>();
const emit = defineEmits<{ close: [] }>();
const canvas = ref<HTMLCanvasElement | null>(null),
  share = ref<{
    url: string;
    course_name: string;
    lesson_name: string;
    status: string;
    access: string;
    expires_at: string | null;
  } | null>(null);
const loading = ref(true),
  failure = ref(""),
  copyState = ref(""),
  fallback = ref<HTMLInputElement | null>(null);
async function load() {
  loading.value = true;
  failure.value = "";
  try {
    share.value = await api.request(`/api/lessons/${props.lesson.id}/share`);
    await nextTick();
    if (canvas.value && share.value)
      await renderQr(canvas.value, share.value.url);
  } catch (error) {
    failure.value = errorCode(error);
  } finally {
    loading.value = false;
  }
}
async function copy() {
  if (!share.value) return;
  try {
    await navigator.clipboard.writeText(share.value.url);
    copyState.value = "copied";
  } catch {
    copyState.value = "copyFallback";
    await nextTick();
    fallback.value?.focus();
    fallback.value?.select();
  }
}
async function save() {
  if (!share.value) return;
  try {
    saveCanvas(
      await qrCard(
        share.value.url,
        share.value.course_name,
        share.value.lesson_name,
      ),
      `${share.value.course_name}-${share.value.lesson_name}`,
    );
  } catch {
    failure.value = "qrFailed";
  }
}
onMounted(load);
</script>
<template>
  <Modal :title="t('share')" @close="emit('close')"
    ><p class="eyebrow">{{ share?.course_name || lesson.course_name }}</p>
    <h3>{{ share?.lesson_name || lesson.name }}</h3>
    <p>{{ t(`status_${share?.status || lesson.status}`) }}</p>
    <p v-if="loading" aria-live="polite">{{ t("loading") }}</p>
    <div v-if="failure" class="alert" role="alert">
      {{ t(failure === "qrFailed" ? failure : `error_${failure}`) }}
      <button @click="load">{{ t("retry") }}</button>
    </div>
    <canvas
      v-show="share && !failure"
      ref="canvas"
      class="qr-canvas"
      role="img"
      :aria-label="t('scanHelp')"
    ></canvas>
    <p>{{ t("scanHelp") }}</p>
    <template v-if="share"
      ><p class="muted">
        {{ t(share.access === "public" ? "publicShare" : "privateShare")
        }}<span v-if="share.expires_at">
          · {{ t("shareExpiry") }}: {{ displayDate(share.expires_at) }}</span
        >
      </p>
      <label
        >{{ t("shareLink")
        }}<input
          ref="fallback"
          :value="share.url"
          readonly
          @focus="($event.target as HTMLInputElement).select()"
      /></label>
      <p v-if="copyState" role="status">{{ t(copyState) }}</p></template
    >
    <div class="dialog-actions">
      <button @click="copy" :disabled="!share || !!failure">
        {{ t("copyLink") }}</button
      ><button @click="save" :disabled="!share || !!failure">
        {{ t("saveQr") }}</button
      ><button @click="emit('close')">{{ t("close") }}</button>
    </div></Modal
  >
</template>
