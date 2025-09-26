export type CpuLoad = {
  avg1: number;
  avg5: number;
  avg15: number;
};

export type MemoryStats = {
  total: number;
  used: number;
};

export type DiskStats = {
  path: string;
  total: number;
  used: number;
};

export type SystemSnapshot = {
  generatedAt: string;
  hostname: string;
  uptime: string;
  cpuTemp: number;
  cpuClock: number;
  cpuLoad: CpuLoad;
  memory: MemoryStats;
  disk: DiskStats;
};

export type HistoryEntry = {
  timestamp: string;
  cpuTemp: number;
  memoryUsed: number;
  diskUsed: number;
  cpuLoad: number;
};

export type HistoryResponse = {
  enabled: boolean;
  maxEntries: number;
  entries: HistoryEntry[];
};

export type ShellyDevice = {
  id: string;
  label: string;
  online: boolean;
  powerOn: boolean;
  temperature: number | null;
  voltage: number | null;
};

export type ShellyResponse = {
  hasErrors: boolean;
  devices: ShellyDevice[];
};

export type PanelDataBundle = {
  snapshot: SystemSnapshot;
  history: HistoryResponse;
  shelly: ShellyResponse;
};

export type MetricKey = 'cpuTemp' | 'memoryUsed' | 'diskUsed' | 'cpuLoad';

export type TabKey = 'status' | 'history' | 'shelly';
