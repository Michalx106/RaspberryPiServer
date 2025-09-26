<script setup lang="ts">
import MoonIcon from './icons/MoonIcon.vue';
import SunIcon from './icons/SunIcon.vue';

type Theme = 'light' | 'dark';

const props = defineProps<{
  theme: Theme;
}>();

const emit = defineEmits<{
  (e: 'toggle'): void;
}>();
</script>

<template>
  <button
    type="button"
    class="flex items-center gap-2 rounded-full border border-panel-muted/40 bg-panel-card/60 px-4 py-2 text-sm font-medium text-panel-text shadow-sm transition hover:border-panel-accent/80 hover:text-panel-accent"
    @click="emit('toggle')"
  >
    <MoonIcon v-if="props.theme === 'dark'" class="h-4 w-4" />
    <SunIcon v-else class="h-4 w-4" />
    <span>{{ props.theme === 'dark' ? 'Tryb ciemny' : 'Tryb jasny' }}</span>
  </button>
</template>
