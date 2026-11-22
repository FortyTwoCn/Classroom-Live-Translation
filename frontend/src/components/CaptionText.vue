<script setup lang="ts">
import { computed } from 'vue';
import { captionParagraphs } from '../caption-paragraphs';

const props = defineProps<{ text: string }>();
const paragraphs = computed(() => captionParagraphs(props.text));
</script>

<template>
  <div class="unit-text">
    <p v-for="(paragraph, index) in paragraphs" :key="index">{{ paragraph }}</p>
  </div>
</template>
