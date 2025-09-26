<script setup lang="ts">
import type { Component } from 'vue';
import type { TabKey } from '../types/panel';

type TabConfig = {
  key: TabKey;
  label: string;
  icon: Component;
};

const props = defineProps<{
  active: TabKey;
  tabs: TabConfig[];
}>();

const emit = defineEmits<{
  (e: 'change', key: TabKey): void;
}>();

const handleClick = (key: TabKey) => {
  emit('change', key);
};
</script>

<template>
  <nav class="flex flex-wrap gap-2 rounded-full bg-panel-card/60 p-2 shadow-inner">
    <button
      v-for="tab in props.tabs"
      :key="tab.key"
      type="button"
      class="flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium transition"
      :class="
        props.active === tab.key
          ? 'bg-panel-accent/90 text-white shadow'
          : 'text-panel-text/80 hover:bg-panel-card hover:text-panel-text'
      "
      @click="handleClick(tab.key)"
    >
      <component :is="tab.icon" class="h-4 w-4" />
      <span>{{ tab.label }}</span>
    </button>
  </nav>
</template>
