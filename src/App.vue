<script setup lang="ts">
import { computed, ref, type Component } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { VueQueryDevtools } from '@tanstack/vue-query-devtools';
import { fetchHistory, fetchShelly, fetchSnapshot } from './api/client';
import HistoryChart from './components/HistoryChart.vue';
import ShellyPanel from './components/ShellyPanel.vue';
import StatusOverview from './components/StatusOverview.vue';
import Tabs from './components/Tabs.vue';
import ThemeToggle from './components/ThemeToggle.vue';
import { useTheme } from './composables/useTheme';
import ChartIcon from './components/icons/ChartIcon.vue';
import ChipIcon from './components/icons/ChipIcon.vue';
import PowerIcon from './components/icons/PowerIcon.vue';
import type { MetricKey, TabKey } from './types/panel';

type TabConfig = {
  key: TabKey;
  label: string;
  icon: Component;
};

const tabConfig: TabConfig[] = [
  { key: 'status', label: 'Status', icon: ChipIcon },
  { key: 'history', label: 'Historia', icon: ChartIcon },
  { key: 'shelly', label: 'Shelly', icon: PowerIcon }
];

const { theme, toggleTheme } = useTheme();
const activeTab = ref<TabKey>('status');
const metric = ref<MetricKey>('cpuTemp');

const snapshotQuery = useQuery({
  queryKey: ['snapshot'],
  queryFn: fetchSnapshot,
  refetchInterval: 15000
});

const historyQuery = useQuery({
  queryKey: ['history'],
  queryFn: fetchHistory,
  refetchInterval: 60000
});

const shellyQuery = useQuery({
  queryKey: ['shelly'],
  queryFn: fetchShelly,
  refetchInterval: 20000
});

const isLoading = computed(
  () => snapshotQuery.isLoading.value || historyQuery.isLoading.value || shellyQuery.isLoading.value
);

const getErrorMessage = (error: unknown) => {
  if (!error) {
    return null;
  }
  if (error instanceof Error) {
    return error.message;
  }
  if (typeof error === 'string') {
    return error;
  }
  return 'Wystąpił nieznany błąd.';
};

const statusErrorMessage = computed(() =>
  snapshotQuery.isError.value ? getErrorMessage(snapshotQuery.error.value) : null
);
const historyErrorMessage = computed(() =>
  historyQuery.isError.value ? getErrorMessage(historyQuery.error.value) : null
);
const shellyErrorMessage = computed(() =>
  shellyQuery.isError.value ? getErrorMessage(shellyQuery.error.value) : null
);

const statusIsLoading = computed(
  () => snapshotQuery.isLoading.value || snapshotQuery.isFetching.value
);
const historyIsLoading = computed(
  () => historyQuery.isLoading.value || historyQuery.isFetching.value
);
const shellyIsLoading = computed(
  () => shellyQuery.isLoading.value || shellyQuery.isFetching.value
);

const lastUpdate = computed(() => {
  if (!snapshotQuery.data.value) {
    return null;
  }
  return new Date(snapshotQuery.data.value.generatedAt);
});

const statusPayload = computed(() => {
  const snapshot = snapshotQuery.data.value;
  if (!snapshot) {
    return null;
  }
  return {
    snapshot,
    isUpdating: snapshotQuery.isFetching.value
  };
});

const historyEntries = computed(() => historyQuery.data.value?.entries ?? []);
const hasHistory = computed(() => historyQuery.data.value !== undefined);

const shellyDevices = computed(() => shellyQuery.data.value?.devices ?? []);
const hasShelly = computed(() => shellyQuery.data.value !== undefined);

const handleTabChange = (key: TabKey) => {
  activeTab.value = key;
};

const handleMetricChange = (key: MetricKey) => {
  metric.value = key;
};

const refreshSnapshot = () => {
  snapshotQuery.refetch();
};
</script>

<template>
  <main class="min-h-screen bg-gradient-to-br from-panel-bg to-panel-bg/70 pb-16">
    <div class="mx-auto flex max-w-6xl flex-col gap-8 px-6 pt-10">
      <header class="flex flex-col gap-6 rounded-3xl bg-panel-card/70 p-6 shadow-xl backdrop-blur">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 class="text-3xl font-semibold text-panel-text">Panel Raspberry Pi</h1>
            <p class="text-sm text-panel-muted">
              Dane są pobierane z lokalnego API Express i odświeżane automatycznie.
            </p>
          </div>
          <ThemeToggle :theme="theme" @toggle="toggleTheme" />
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4">
          <Tabs :active="activeTab" :tabs="tabConfig" @change="handleTabChange" />
          <div class="rounded-full bg-panel-card/80 px-4 py-2 text-xs font-medium text-panel-muted">
            <template v-if="isLoading">Ładowanie danych...</template>
            <template v-else-if="lastUpdate">Ostatnia aktualizacja: {{ lastUpdate.toLocaleTimeString() }}</template>
          </div>
        </div>
      </header>

      <section v-if="activeTab === 'status'">
        <StatusOverview
          v-if="statusPayload"
          v-bind="statusPayload"
          @refresh="refreshSnapshot"
        />
        <div
          v-else-if="statusIsLoading"
          class="rounded-3xl bg-panel-card/70 p-10 text-center text-panel-muted shadow-xl backdrop-blur"
        >
          Ładowanie danych dotyczących stanu Raspberry Pi...
        </div>
        <div
          v-else
          class="flex flex-col items-center gap-2 rounded-3xl bg-panel-card/70 p-10 text-center shadow-xl backdrop-blur"
        >
          <p class="text-base font-medium text-panel-text">
            {{ statusErrorMessage ?? 'Brak danych do wyświetlenia dla zakładki Status.' }}
          </p>
          <p v-if="!statusErrorMessage" class="text-sm text-panel-muted">
            Spróbuj ponownie odświeżyć dane lub sprawdź połączenie z API.
          </p>
        </div>
      </section>

      <section v-else-if="activeTab === 'history'">
        <HistoryChart
          v-if="hasHistory && historyEntries.length"
          :entries="historyEntries"
          :active-metric="metric"
          @metric-change="handleMetricChange"
        />
        <div
          v-else-if="historyIsLoading"
          class="rounded-3xl bg-panel-card/70 p-10 text-center text-panel-muted shadow-xl backdrop-blur"
        >
          Trwa ładowanie historii pomiarów...
        </div>
        <div
          v-else
          class="flex flex-col items-center gap-2 rounded-3xl bg-panel-card/70 p-10 text-center shadow-xl backdrop-blur"
        >
          <p class="text-base font-medium text-panel-text">
            {{ historyErrorMessage ?? 'Brak danych historycznych do wyświetlenia.' }}
          </p>
          <p v-if="!historyErrorMessage" class="text-sm text-panel-muted">
            Gdy tylko pojawią się nowe wpisy, zobaczysz je w tym miejscu.
          </p>
        </div>
      </section>

      <section v-else>
        <ShellyPanel v-if="hasShelly && shellyDevices.length" :devices="shellyDevices" />
        <div
          v-else-if="shellyIsLoading"
          class="rounded-3xl bg-panel-card/70 p-10 text-center text-panel-muted shadow-xl backdrop-blur"
        >
          Ładowanie danych urządzeń Shelly...
        </div>
        <div
          v-else
          class="flex flex-col items-center gap-2 rounded-3xl bg-panel-card/70 p-10 text-center shadow-xl backdrop-blur"
        >
          <p class="text-base font-medium text-panel-text">
            {{ shellyErrorMessage ?? 'Brak informacji o urządzeniach Shelly.' }}
          </p>
          <p v-if="!shellyErrorMessage" class="text-sm text-panel-muted">
            Upewnij się, że integracja z Shelly jest poprawnie skonfigurowana.
          </p>
        </div>
      </section>
    </div>
    <VueQueryDevtools :initial-is-open="false" />
  </main>
</template>
