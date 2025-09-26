const toIso = (date) => date.toISOString();

const round = (value, precision = 1) => {
  const factor = 10 ** precision;
  return Math.round(value * factor) / factor;
};

const createSnapshot = () => {
  const now = new Date();
  const cpuTemp = 44 + Math.random() * 4;
  const cpuClock = 1.4 + Math.random() * 0.2;
  const loadBase = 0.25 + Math.random() * 0.2;

  return {
    generatedAt: toIso(now),
    hostname: 'raspberrypi',
    uptime: '4 dni 12 godzin',
    cpuTemp: round(cpuTemp, 1),
    cpuClock: round(cpuClock, 2),
    cpuLoad: {
      avg1: round(loadBase, 2),
      avg5: round(loadBase + 0.05, 2),
      avg15: round(loadBase + 0.08, 2)
    },
    memory: {
      total: 3976,
      used: Math.round(1500 + Math.random() * 400)
    },
    disk: {
      path: '/',
      total: 29.8,
      used: round(11.8 + Math.random() * 1.2, 1)
    }
  };
};

const createHistory = () => {
  const now = new Date();
  const entries = Array.from({ length: 48 }, (_, index) => {
    const reverse = 47 - index;
    const timestamp = new Date(now.getTime() - reverse * 30 * 60 * 1000);
    const cpuTemp = 43 + Math.sin(index / 5) * 3 + Math.random();
    const cpuLoad = 0.22 + Math.sin(index / 7) * 0.08 + Math.random() * 0.02;
    return {
      timestamp: toIso(timestamp),
      cpuTemp: round(cpuTemp, 1),
      memoryUsed: Math.round(1500 + Math.cos(index / 3) * 120 + Math.random() * 30),
      diskUsed: round(11.8 + index * 0.02, 2),
      cpuLoad: round(cpuLoad, 2)
    };
  });

  return {
    enabled: true,
    maxEntries: 360,
    entries
  };
};

const createShelly = () => {
  const boilerOnline = Math.random() > 0.05;
  const gateOnline = Math.random() > 0.4;
  return {
    hasErrors: false,
    devices: [
      {
        id: 'boiler',
        label: 'Podgrzewacz wody',
        online: boilerOnline,
        powerOn: boilerOnline,
        temperature: boilerOnline ? round(40 + Math.random() * 5, 1) : null,
        voltage: boilerOnline ? round(230 + Math.random() * 2, 1) : null
      },
      {
        id: 'gate',
        label: 'Brama wjazdowa',
        online: gateOnline,
        powerOn: false,
        temperature: gateOnline ? round(28 + Math.random() * 2, 1) : null,
        voltage: gateOnline ? round(12 + Math.random(), 1) : null
      }
    ]
  };
};

export const createBundle = () => ({
  snapshot: createSnapshot(),
  history: createHistory(),
  shelly: createShelly()
});

export const getSnapshot = () => createBundle().snapshot;
export const getHistory = () => createBundle().history;
export const getShelly = () => createBundle().shelly;
