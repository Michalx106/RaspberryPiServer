<script setup lang="ts">
import { computed } from 'vue';
import type { SystemSnapshot } from '../types/panel';
import ChipIcon from './icons/ChipIcon.vue';
import MemoryIcon from './icons/MemoryIcon.vue';
import RefreshIcon from './icons/RefreshIcon.vue';
import StorageIcon from './icons/StorageIcon.vue';

const props = defineProps<{
  snapshot: SystemSnapshot;
  isUpdating: boolean;
}>();

const emit = defineEmits<{
  (e: 'refresh'): void;
}>();

const memoryUsage = computed(() => (props.snapshot.memory.used / props.snapshot.memory.total) * 100);
const diskUsage = computed(() => (props.snapshot.disk.used / props.snapshot.disk.total) * 100);
const formattedTimestamp = computed(() => new Date(props.snapshot.generatedAt).toLocaleTimeString());

const formatGb = (value: number) => `${value.toFixed(1)} GB`;
const formatLoad = (value: number) => value.toFixed(2);
</script>

<template>
  <section class="card flex flex-col gap-6">
    <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="text-2xl font-semibold text-panel-text">Stan systemu</h2>
        <p class="text-sm text-panel-muted">
          Host <strong class="text-panel-text">{{ props.snapshot.hostname }}</strong> · Uptime {{ props.snapshot.uptime }}
        </p>
      </div>
      <button
        type="button"
        class="inline-flex items-center gap-2 rounded-full border border-panel-muted/40 px-4 py-2 text-sm font-medium text-panel-text transition hover:border-panel-accent hover:text-panel-accent disabled:opacity-60"
        :disabled="props.isUpdating"
        @click="emit('refresh')"
      >
        <RefreshIcon :class="['h-4 w-4', { 'animate-spin text-panel-accent': props.isUpdating }]" />
        Odśwież dane
      </button>
    </header>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <article class="card bg-panel-card/70">
        <div class="flex items-center gap-3">
          <div class="rounded-full bg-panel-accent/10 p-3 text-panel-accent">
            <ChipIcon class="h-6 w-6" />
          </div>
          <div>
            <p class="metric-label">Temperatura CPU</p>
            <p class="metric-value">{{ props.snapshot.cpuTemp.toFixed(1) }}°C</p>
          </div>
        </div>
        <p class="mt-4 text-sm text-panel-muted">
          Taktowanie: {{ props.snapshot.cpuClock.toFixed(2) }} GHz · Load avg:
          {{ formatLoad(props.snapshot.cpuLoad.avg1) }} /
          {{ formatLoad(props.snapshot.cpuLoad.avg5) }} /
          {{ formatLoad(props.snapshot.cpuLoad.avg15) }}
        </p>
      </article>

      <article class="card bg-panel-card/70">
        <div class="flex items-center gap-3">
          <div class="rounded-full bg-panel-accent/10 p-3 text-panel-accent">
            <MemoryIcon class="h-6 w-6" />
          </div>
          <div>
            <p class="metric-label">Zużycie RAM</p>
            <p class="metric-value">{{ props.snapshot.memory.used.toFixed(0) }} MB</p>
          </div>
        </div>
        <p class="mt-4 text-sm text-panel-muted">
          Łącznie: {{ props.snapshot.memory.total.toFixed(0) }} MB ({{ memoryUsage.toFixed(0) }}%)
        </p>
      </article>

      <article class="card bg-panel-card/70">
        <div class="flex items-center gap-3">
          <div class="rounded-full bg-panel-accent/10 p-3 text-panel-accent">
            <StorageIcon class="h-6 w-6" />
          </div>
          <div>
            <p class="metric-label">Dysk ({{ props.snapshot.disk.path }})</p>
            <p class="metric-value">{{ formatGb(props.snapshot.disk.used) }} / {{ formatGb(props.snapshot.disk.total) }}</p>
          </div>
        </div>
        <p class="mt-4 text-sm text-panel-muted">Zajętość: {{ diskUsage.toFixed(0) }}%</p>
      </article>

      <article class="card bg-panel-card/70">
        <div class="flex items-center gap-3">
          <div class="rounded-full bg-panel-accent/10 p-3 text-panel-accent">
            <ChipIcon class="h-6 w-6" />
          </div>
          <div>
            <p class="metric-label">Load 1 min</p>
            <p class="metric-value">{{ formatLoad(props.snapshot.cpuLoad.avg1) }}</p>
          </div>
        </div>
        <p class="mt-4 text-sm text-panel-muted">Aktualizacja {{ formattedTimestamp }}</p>
      </article>
    </div>
  </section>
</template>
