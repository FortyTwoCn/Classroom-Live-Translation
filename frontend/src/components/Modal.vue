<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from "vue";
import { t } from "../i18n";
const props = defineProps<{
  title: string;
  locked?: boolean;
  wide?: boolean;
}>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);
let previous: HTMLElement | null = null;
function cancel(event: Event) {
  event.preventDefault();
  if (!props.locked) emit("close");
}
function trap(event: KeyboardEvent) {
  if (event.key !== "Tab") return;
  const targets = Array.from(
    dialog.value?.querySelectorAll<HTMLElement>(
      'button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href],[tabindex="0"]',
    ) || [],
  ).filter((element) => element.getClientRects().length);
  const first = targets[0],
    last = targets.at(-1);
  if (!first) {
    event.preventDefault();
    dialog.value?.focus();
  } else if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last?.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}
onMounted(async () => {
  previous = document.activeElement as HTMLElement;
  dialog.value?.showModal();
  await nextTick();
  (
    dialog.value?.querySelector<HTMLElement>("[autofocus]") ||
    dialog.value?.querySelector<HTMLElement>("input:not([disabled]),textarea:not([disabled]),select:not([disabled])") ||
    dialog.value?.querySelector<HTMLElement>("button:not([disabled])") || dialog.value
  )?.focus({ preventScroll: true });
});
onUnmounted(() => {
  dialog.value?.close();
  if (previous?.isConnected) previous.focus({ preventScroll: true });
});
</script>
<template>
  <Teleport to="body"
    ><dialog
      ref="dialog"
      class="modal"
      :class="{ wide }"
      :aria-label="title"
      @cancel="cancel"
      @keydown="trap"
    >
      <div class="section-header modal-title">
        <h2>{{ title }}</h2>
        <button
          v-if="!locked"
          type="button"
          class="icon-button"
          @click="emit('close')"
          :aria-label="t('close')"
          :title="t('close')"
        >
          ×
        </button>
      </div>
      <slot /></dialog
  ></Teleport>
</template>

