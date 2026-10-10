<script setup lang="ts">
import { ref } from "vue";
import { api, errorCode, type Unit } from "../api";
import { t } from "../i18n";
const props = defineProps<{ courseId: number }>();
defineEmits<{ navigate: [path: string] }>();
const query = ref(""),
  page = ref(1),
  units = ref<Array<Unit & { lesson_id: number; lesson_name: string }>>([]),
  total = ref(0),
  perPage = ref(30),
  busy = ref(false),
  error = ref(""),
  searched = ref(false);
async function search(reset = false) {
  if (reset) page.value = 1;
  busy.value = true;
  error.value = "";
  try {
    const result = await api.request<{
      units: typeof units.value;
      total: number;
      per_page: number;
    }>(
      `/api/courses/${props.courseId}/subtitles?q=${encodeURIComponent(query.value)}&page=${page.value}`,
    );
    units.value = result.units;
    total.value = result.total;
    perPage.value = result.per_page;
    searched.value = true;
  } catch (e) {
    error.value = errorCode(e);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <details class="course-search panel">
    <summary>{{ t("courseSubtitleSearch") }}</summary>
    <form class="search-form" @submit.prevent="search(true)">
      <label class="sr-only" for="course-subtitles">{{
        t("searchSubtitle")
      }}</label
      ><input
        id="course-subtitles"
        v-model="query"
        :placeholder="t('searchSubtitle')"
        required
      /><button :disabled="busy">{{ t("search") }}</button>
    </form>
    <p v-if="error" class="alert">{{ t(`error_${error}`) }}</p>
    <p v-if="searched && !units.length">{{ t("noSearchResults") }}</p>
    <article v-for="unit in units" :key="unit.id" class="subtitle-unit">
      <div class="unit-meta">
        <strong>{{ unit.lesson_name }}</strong
        ><span>{{
          t(unit.kind === "source" ? "original" : "translation")
        }}</span
        ><button
          @click="
            $emit('navigate', `/lessons/${unit.lesson_id}?unit_id=${unit.id}`)
          "
        >
          {{ t("openAtSegment") }}
        </button>
      </div>
      <p>{{ unit.text }}</p>
    </article>
    <div v-if="searched" class="pagination">
      <button
        @click="
          page--;
          search();
        "
        :disabled="page === 1 || busy"
      >
        {{ t("previous") }}</button
      ><span>{{ t("pageOf", { page, total }) }}</span
      ><button
        @click="
          page++;
          search();
        "
        :disabled="page * perPage >= total || busy"
      >
        {{ t("next") }}
      </button>
    </div>
  </details>
</template>
