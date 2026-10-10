<script setup lang="ts">
import Modal from "./Modal.vue";
import { t } from "../i18n";
defineProps<{ password: string; error?: string; busy?: boolean }>();
defineEmits<{ close: []; submit: []; "update:password": [value: string] }>();
</script>
<template>
  <Modal :title="t('reauth')" @close="$emit('close')"
    ><form @submit.prevent="$emit('submit')">
      <p>{{ t("reauthHelp") }}</p>
      <label
        >{{ t("currentPassword")
        }}<input
          type="password"
          autocomplete="current-password"
          autofocus
          required
          :value="password"
          @input="
            $emit('update:password', ($event.target as HTMLInputElement).value)
          "
      /></label>
      <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
      <div class="dialog-actions">
        <button type="button" @click="$emit('close')">{{ t("cancel") }}</button
        ><button :disabled="busy">{{ t("confirm") }}</button>
      </div>
    </form></Modal
  >
</template>
