<script setup lang="ts">
import { computed } from 'vue';
import type { HistoryEntry, MetricKey } from '../types/panel';
import ChartIcon from './icons/ChartIcon.vue';

type MetricConfig = {
  key: MetricKey;
  label: string;
  unit: string;
};

const METRICS: MetricConfig[] = [
  { key: 'cpuTemp', label: 'Temperatura CPU', unit: '°C' },
  { key: 'memoryUsed', label: 'Zużycie RAM', unit: 'MB' },
  { key: 'diskUsed', label: 'Zajętość dysku', unit: 'GB' },
  { key: 'cpuLoad', label: 'Load average', unit: '' }
];

const props = defineProps<{
  entries: HistoryEntry[];
  activeMetric: MetricKey;
}>();

const emit = defineEmits<{
  (e: 'metric-change', metric: MetricKey): void;
}>();

const metric = computed(() => METRICS.find((item) => item.key === props.activeMetric) ?? METRICS[0]);

const points = computed(() => {
  const values = props.entries.map((entry) => entry[metric.value.key]);
  if (!values.length) {
    return { path: '', min: 0, max: 0 };
  }
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;

  const path = values
    .map((value, index) => {
      const x = (index / (values.length - 1 || 1)) * 100;
      const y = 100 - ((value - min) / range) * 100;
      return `${index === 0 ? 'M' : 'L'} ${x.toFixed(2)} ${y.toFixed(2)}`;
    })
    .join(' ');

  return { path, min, max };
});

const handleMetricChange = (key: MetricKey) => {
  emit('metric-change', key);
};
</script>

<template>
  <section class="card flex flex-col gap-4">
    <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex items-center gap-3">
        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-panel-accent/15 text-panel-accent">
          <ChartIcon class="h-5 w-5" />
        </span>
        <div>
          <h2 class="text-xl font-semibold text-panel-text">Historia metryk</h2>
          <p class="text-sm text-panel-muted">Ostatnie {{ props.entries.length }} rekordów w odstępie 30 minut</p>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="item in METRICS"
          :key="item.key"
          type="button"
          class="rounded-full px-4 py-2 text-sm font-medium transition"
          :class="
            props.activeMetric === item.key
              ? 'bg-panel-accent text-white shadow'
              : 'border border-panel-muted/40 text-panel-text hover:border-panel-accent/70'
          "
          @click="handleMetricChange(item.key)"
        >
          {{ item.label }}
        </button>
      </div>
    </header>

    <div v-if="props.entries.length" class="relative h-64 w-full">
      <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-full w-full">
        <defs>
          <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="rgba(74, 222, 128, 0.6)" />
            <stop offset="100%" stop-color="rgba(74, 222, 128, 0.05)" />
          </linearGradient>
        </defs>
        <path :d="`${points.path} L 100 100 L 0 100 Z`" fill="url(#chartGradient)" stroke="none" />
        <path :d="points.path" fill="none" :stroke="'rgb(var(--color-panel-accent))'" stroke-width="1.5" />
      </svg>
      <div class="absolute bottom-2 left-2 rounded-full bg-panel-card/70 px-3 py-1 text-xs text-panel-muted">
        Zakres: {{ points.min.toFixed(1) }}{{ metric.unit }} – {{ points.max.toFixed(1) }}{{ metric.unit }}
      </div>
    </div>
    <p v-else class="rounded-xl bg-panel-card/60 px-4 py-12 text-center text-panel-muted">
      Historia jest niedostępna. Upewnij się, że zapisy są aktywne.
    </p>
  </section>
</template>
