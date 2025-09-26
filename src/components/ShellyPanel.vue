<script setup lang="ts">
import type { ShellyDevice } from '../types/panel';
import PowerIcon from './icons/PowerIcon.vue';
import WifiIcon from './icons/WifiIcon.vue';
import WifiOffIcon from './icons/WifiOffIcon.vue';

const props = defineProps<{
  devices: ShellyDevice[];
}>();
</script>

<template>
  <section class="card flex flex-col gap-4">
    <header class="flex flex-col gap-1">
      <h2 class="text-xl font-semibold text-panel-text">Sterowanie Shelly</h2>
      <p class="text-sm text-panel-muted">
        Urządzenia są symulowane lokalnie przez backend Express.
      </p>
    </header>

    <div class="grid gap-4 md:grid-cols-2">
      <article
        v-for="device in props.devices"
        :key="device.id"
        class="card flex flex-col gap-4 border border-panel-muted/30"
      >
        <header class="flex items-start justify-between">
          <div>
            <h3 class="text-lg font-semibold text-panel-text">{{ device.label }}</h3>
            <p class="text-sm text-panel-muted">ID: {{ device.id }}</p>
          </div>
          <span
            class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold"
            :class="device.online ? 'bg-emerald-500/15 text-emerald-500' : 'bg-rose-500/15 text-rose-500'"
          >
            <WifiIcon v-if="device.online" class="h-4 w-4" />
            <WifiOffIcon v-else class="h-4 w-4" />
            {{ device.online ? 'Online' : 'Offline' }}
          </span>
        </header>

        <div class="flex flex-col gap-3 text-sm text-panel-text/80">
          <div class="flex items-center justify-between">
            <span>Status</span>
            <span class="font-medium text-panel-text">{{ device.powerOn ? 'Włączone' : 'Wyłączone' }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span>Temperatura</span>
            <span class="font-medium text-panel-text">
              {{ device.temperature !== null ? `${device.temperature.toFixed(1)}°C` : '—' }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span>Napięcie</span>
            <span class="font-medium text-panel-text">
              {{ device.voltage !== null ? `${device.voltage.toFixed(1)} V` : '—' }}
            </span>
          </div>
        </div>

        <button
          type="button"
          class="inline-flex items-center justify-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition"
          :class="device.powerOn ? 'bg-rose-500 text-white hover:bg-rose-400' : 'bg-panel-accent text-white hover:bg-emerald-400'"
        >
          <PowerIcon class="h-4 w-4" />
          Symuluj {{ device.powerOn ? 'wyłączenie' : 'włączenie' }}
        </button>
      </article>
    </div>
  </section>
</template>
