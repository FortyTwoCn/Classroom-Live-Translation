<script setup lang="ts">
defineProps<{
  name:
    | "qr"
    | "edit"
    | "history"
    | "screen"
    | "export"
    | "person"
    | "close"
    | "settings"
    | "book"
    | "chevron-right"
    | "plus"
    | "search";
}>();
</script>
<template>
  <svg
    viewBox="0 0 24 24"
    width="21"
    height="21"
    fill="none"
    stroke="currentColor"
    stroke-width="1.7"
    aria-hidden="true"
  >
    <template v-if="name === 'qr'">
      <path
        d="M3 3h6v6H3zM15 3h6v6h-6zM3 15h6v6H3zM15 15h3v3h3v3h-6zM12 3v3m0 6h3m-3 3v6m6-9h3"
      />
    </template>
    <path v-else-if="name === 'edit'" d="m4 16-1 5 5-1L20 8l-4-4ZM13 7l4 4" />
    <path
      v-else-if="name === 'history'"
      d="M4 6v5h5M4 11a8 8 0 1 1 1 7M12 7v5l3 2"
    />
    <path
      v-else-if="name === 'screen'"
      d="M3 9V3h6m6 0h6v6m0 6v6h-6m-6 0H3v-6"
    />
    <path
      v-else-if="name === 'export'"
      d="M12 3v12m-4-4 4 4 4-4M4 15v6h16v-6"
    />
    <path v-else-if="name === 'close'" d="m6 6 12 12M18 6 6 18" />
    <path
      v-else-if="name === 'book'"
      stroke-linecap="round"
      stroke-linejoin="round"
      d="M12 20c-2.2-1.8-5.5-2.3-9-1.3V5.4c3.5-1 6.8-.5 9 1.3 2.2-1.8 5.5-2.3 9-1.3v13.3c-3.5-1-6.8-.5-9 1.3Zm0-13.3V20"
    />
    <path v-else-if="name === 'chevron-right'" stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
    <path v-else-if="name === 'plus'" stroke-linecap="round" d="M12 5v14M5 12h14" />
    <template v-else-if="name === 'search'">
      <circle cx="10.5" cy="10.5" r="5.5" />
      <path stroke-linecap="round" d="m15 15 4 4" />
    </template>
    <template v-else-if="name === 'settings'">
      <path d="M4 7h16M4 17h16" />
      <circle cx="9" cy="7" r="3" fill="white" />
      <circle cx="15" cy="17" r="3" fill="white" />
    </template>
    <template v-else>
      <circle cx="12" cy="7" r="4" />
      <path d="M4 21v-2a8 8 0 0 1 16 0v2" />
    </template>
  </svg>
</template>

