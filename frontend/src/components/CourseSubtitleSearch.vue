<script setup lang="ts">
import { ref } from "vue";
import { api, errorCode, type Unit } from "../api";
import { t } from "../i18n";
import CaptionText from "./CaptionText.vue";
import Icon from "./Icon.vue";

const props = defineProps<{ courseId: number }>();
defineEmits<{ navigate: [path: string] }>();

const query = ref(""),
  submittedQuery = ref(""),
  page = ref(1),
  units = ref<Array<Unit & { lesson_id: number; lesson_name: string }>>([]),
  total = ref(0),
  perPage = ref(30),
  busy = ref(false),
  error = ref("");

async function search(targetPage = 1, keyword = query.value.trim()) {
  if (!keyword || busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    const params = new URLSearchParams({ q: keyword, page: String(targetPage) });
    const result = await api.request<{
      units: typeof units.value;
      total: number;
      per_page: number;
    }>(`/api/courses/${props.courseId}/subtitles?${params}`);
    units.value = result.units;
    total.value = result.total;
    perPage.value = result.per_page;
    submittedQuery.value = keyword;
    page.value = targetPage;
  } catch (cause) {
    error.value = errorCode(cause);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <form
    class="course-search-panel course-search"
    role="search"
    aria-labelledby="course-subtitles-heading"
    @submit.prevent="search()"
  >
    <h2 id="course-subtitles-heading">{{ t("courseSubtitleSearch") }}</h2>
    <div class="course-search-row">
      <div class="course-search-field">
        <Icon name="search" />
        <input
          v-model="query"
          type="search"
          maxlength="200"
          :aria-label="t('searchSubtitle')"
          :placeholder="t('searchSubtitle')"
          required
        />
      </div>
      <button type="submit" :disabled="busy || !query.trim()">
        {{ t("searchCourseSubtitles") }}
      </button>
    </div>
  </form>

  <section
    v-if="busy || error || submittedQuery"
    class="course-subtitle-results"
    aria-labelledby="course-subtitle-results-heading"
    :aria-busy="busy"
  >
    <h2 id="course-subtitle-results-heading">{{ t("courseSubtitleResults") }}</h2>
    <p v-if="busy" role="status" class="muted">{{ t("loading") }}</p>
    <p v-if="error" role="alert" class="alert">{{ t(`error_${error}`) }}</p>
    <p v-else-if="!busy && !units.length" role="status" class="muted">
      {{ t("noSearchResults") }}
    </p>
    <div v-if="units.length" class="course-subtitle-hits" tabindex="0">
      <article v-for="unit in units" :key="unit.id" class="course-subtitle-hit">
        <div class="course-subtitle-hit-meta">
          <strong>{{ unit.lesson_name }}</strong>
          <span>{{ t(unit.kind === "source" ? "original" : "translation") }}</span>
          <button
            type="button"
            @click="$emit('navigate', `/lessons/${unit.lesson_id}?unit_id=${unit.id}`)"
          >
            {{ t("openAtSegment") }}
          </button>
        </div>
        <CaptionText :text="unit.text" />
      </article>
    </div>
    <div v-if="total" class="pagination course-pagination">
      <button
        v-if="total > perPage"
        type="button"
        :disabled="page === 1 || busy"
        @click="search(page - 1, submittedQuery)"
      >
        {{ t("previous") }}
      </button>
      <span role="status">{{ t("pageOf", { page, total }) }}</span>
      <button
        v-if="total > perPage"
        type="button"
        :disabled="page * perPage >= total || busy"
        @click="search(page + 1, submittedQuery)"
      >
        {{ t("next") }}
      </button>
    </div>
  </section>
</template>
