import fs from 'node:fs/promises';
import os from 'node:os';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

const SNAPSHOT_TTL = 5000; // ms
const HISTORY_LIMIT = 360;
const DISK_PATH = '/';

let lastSnapshot = null;
let lastSnapshotTimestamp = 0;
let pendingSnapshotPromise = null;
const historyEntries = [];

const safeNumber = (value, fallback = 0) => {
  return Number.isFinite(value) ? value : fallback;
};

const formatUptime = (seconds) => {
  const days = Math.floor(seconds / 86_400);
  const hours = Math.floor((seconds % 86_400) / 3_600);
  const minutes = Math.floor((seconds % 3_600) / 60);

  const parts = [];
  if (days > 0) {
    parts.push(`${days} dni`);
  }
  if (hours > 0 || days > 0) {
    parts.push(`${hours} godzin`);
  }
  parts.push(`${minutes} minut`);

  return parts.join(' ');
};

const readCpuTemp = async () => {
  try {
    const raw = await fs.readFile('/sys/class/thermal/thermal_zone0/temp', 'utf8');
    const value = Number.parseFloat(raw.trim());
    return safeNumber(value / 1000);
  } catch {
    return 0;
  }
};

const readCpuClock = async () => {
  const candidates = [
    '/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq',
    '/sys/devices/system/cpu/cpufreq/policy0/scaling_cur_freq'
  ];

  for (const file of candidates) {
    try {
      const raw = await fs.readFile(file, 'utf8');
      const value = Number.parseFloat(raw.trim());
      return safeNumber(value / 1_000_000);
    } catch {
      // try next file
    }
  }

  return 0;
};

const readDiskUsage = async (path = DISK_PATH) => {
  try {
    const { stdout } = await execFileAsync('df', ['-k', path]);
    const lines = stdout.trim().split('\n');
    const dataLine = lines.at(-1);
    if (!dataLine) {
      throw new Error('Brak danych df');
    }
    const parts = dataLine.trim().split(/\s+/);
    const totalKb = Number.parseInt(parts[1] ?? '0', 10);
    const usedKb = Number.parseInt(parts[2] ?? '0', 10);
    const totalGb = safeNumber(totalKb / (1024 * 1024));
    const usedGb = safeNumber(usedKb / (1024 * 1024));

    return {
      path,
      total: totalGb,
      used: usedGb
    };
  } catch {
    return {
      path,
      total: 0,
      used: 0
    };
  }
};

const readMemoryUsage = () => {
  const totalBytes = os.totalmem();
  const freeBytes = os.freemem();
  const usedBytes = totalBytes - freeBytes;

  return {
    total: safeNumber(totalBytes / (1024 * 1024)),
    used: safeNumber(usedBytes / (1024 * 1024))
  };
};

const readCpuLoad = () => {
  const [avg1, avg5, avg15] = os.loadavg();
  return {
    avg1: safeNumber(avg1),
    avg5: safeNumber(avg5),
    avg15: safeNumber(avg15)
  };
};

const collectSnapshot = async () => {
  const [cpuTemp, cpuClock, disk] = await Promise.all([
    readCpuTemp(),
    readCpuClock(),
    readDiskUsage()
  ]);

  const memory = readMemoryUsage();
  const cpuLoad = readCpuLoad();

  return {
    generatedAt: new Date().toISOString(),
    hostname: os.hostname(),
    uptime: formatUptime(os.uptime()),
    cpuTemp,
    cpuClock,
    cpuLoad,
    memory,
    disk
  };
};

const pushHistoryEntry = (snapshot) => {
  historyEntries.push({
    timestamp: snapshot.generatedAt,
    cpuTemp: snapshot.cpuTemp,
    memoryUsed: snapshot.memory.used,
    diskUsed: snapshot.disk.used,
    cpuLoad: snapshot.cpuLoad.avg1
  });

  if (historyEntries.length > HISTORY_LIMIT) {
    historyEntries.splice(0, historyEntries.length - HISTORY_LIMIT);
  }
};

const ensureSnapshot = async () => {
  const now = Date.now();
  if (lastSnapshot && now - lastSnapshotTimestamp < SNAPSHOT_TTL) {
    return lastSnapshot;
  }

  if (!pendingSnapshotPromise) {
    pendingSnapshotPromise = collectSnapshot()
      .then((snapshot) => {
        lastSnapshot = snapshot;
        lastSnapshotTimestamp = Date.now();
        pushHistoryEntry(snapshot);
        return snapshot;
      })
      .finally(() => {
        pendingSnapshotPromise = null;
      });
  }

  return pendingSnapshotPromise;
};

export const getSnapshot = async () => {
  return ensureSnapshot();
};

export const getHistory = async () => {
  await ensureSnapshot();
  return {
    enabled: true,
    maxEntries: HISTORY_LIMIT,
    entries: [...historyEntries]
  };
};
