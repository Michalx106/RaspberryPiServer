import {
  HISTORY_DEFAULT_LIMIT,
  HISTORY_DEFAULT_METRIC,
  HISTORY_METRIC_STORAGE_KEY,
  STATUS_HISTORY_ENDPOINT,
} from './constants.js';
import { readLocalStorage, writeLocalStorage } from './storage.js';
import { supportsFetch } from './support.js';

const historyState = {
  enabled: null,
  entries: [],
  maxEntries: null,
  limit: null,
  chartSignature: null,
  metric: HISTORY_DEFAULT_METRIC,
};

const CHART_VIEWBOX_WIDTH = 600;
const CHART_VIEWBOX_HEIGHT = 260;
const CHART_PADDING = { top: 24, right: 24, bottom: 36, left: 56 };
const CHART_GRID_LINES = 4;
const SVG_NS = 'http://www.w3.org/2000/svg';

const createSvgElement = (name, attributes = {}) => {
  const element = document.createElementNS(SVG_NS, name);
  Object.entries(attributes).forEach(([attribute, rawValue]) => {
    if (rawValue === undefined || rawValue === null) {
      return;
    }
    element.setAttribute(attribute, String(rawValue));
  });
  return element;
};

const formatTemperatureValue = (value) => {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return '';
  }
  return `${value.toFixed(1)} °C`;
};

const formatPercentageValue = (value) => {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return '';
  }
  return `${value.toFixed(1)} %`;
};

const formatLoadAverageValue = (value) => {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return '';
  }
  return value.toFixed(2);
};

const formatMetricValue = (metric, value) => {
  if (!metric) {
    return typeof value === 'number' && Number.isFinite(value) ? String(value) : '';
  }
  const formatted = metric.formatValue(value);
  if (typeof formatted === 'string' && formatted.trim() !== '') {
    return formatted;
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    return String(value);
  }
  return '';
};

const parseTemperatureValue = (value) => {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  if (typeof value !== 'string') {
    return null;
  }
  const match = value.match(/(-?\d+(?:[\.,]\d+)?)/);
  if (!match) {
    return null;
  }
  const normalized = match[1].replace(',', '.');
  const parsed = Number.parseFloat(normalized);
  return Number.isFinite(parsed) ? parsed : null;
};

const parsePercentageValue = (value) => {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  if (typeof value !== 'string') {
    return null;
  }
  const match = value.match(/(-?\d+(?:[\.,]\d+)?)\s*%/);
  if (!match) {
    return null;
  }
  const normalized = match[1].replace(',', '.');
  const parsed = Number.parseFloat(normalized);
  return Number.isFinite(parsed) ? parsed : null;
};

const parseSystemLoadValues = (value) => {
  const result = { one: null, five: null, fifteen: null };
  if (typeof value !== 'string') {
    return result;
  }
  const trimmed = value.trim();
  if (trimmed === '') {
    return result;
  }

  const matchValue = (pattern) => {
    const match = trimmed.match(pattern);
    if (!match) {
      return null;
    }
    const normalized = match[1].replace(',', '.');
    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : null;
  };

  result.one = matchValue(/1\s*min\s*:\s*([\d\.,]+)/i);
  result.five = matchValue(/5\s*min\s*:\s*([\d\.,]+)/i);
  result.fifteen = matchValue(/15\s*min\s*:\s*([\d\.,]+)/i);

  if (result.one === null || result.five === null || result.fifteen === null) {
    const matches = trimmed.match(/(-?\d+(?:[\.,]\d+)?)/g);
    if (matches && matches.length >= 3) {
      const numbers = matches.slice(0, 3).map((item) => {
        const normalizedNumber = item.replace(',', '.');
        const parsed = Number.parseFloat(normalizedNumber);
        return Number.isFinite(parsed) ? parsed : null;
      });
      const [first, second, third] = numbers;
      if (result.one === null && typeof first === 'number' && Number.isFinite(first)) {
        result.one = first;
      }
      if (result.five === null && typeof second === 'number' && Number.isFinite(second)) {
        result.five = second;
      }
      if (result.fifteen === null && typeof third === 'number' && Number.isFinite(third)) {
        result.fifteen = third;
      }
    }
  }

  return result;
};

const normalizePercentageMetric = (entry, key) => {
  const result = { percentage: null, label: null };

  if (!entry || typeof entry !== 'object') {
    return result;
  }

  const rawMetric = entry[key];

  if (rawMetric && typeof rawMetric === 'object' && !Array.isArray(rawMetric)) {
    if (typeof rawMetric.label === 'string' && rawMetric.label.trim() !== '') {
      result.label = rawMetric.label.trim();
    }
    if (typeof rawMetric.percentage === 'number' && Number.isFinite(rawMetric.percentage)) {
      result.percentage = rawMetric.percentage;
    }
  } else if (typeof rawMetric === 'string' && rawMetric.trim() !== '') {
    result.label = rawMetric.trim();
  } else if (typeof rawMetric === 'number' && Number.isFinite(rawMetric)) {
    result.percentage = rawMetric;
  }

  const labelKey = `${key}Label`;
  if (result.label === null && typeof entry[labelKey] === 'string' && entry[labelKey].trim() !== '') {
    result.label = entry[labelKey].trim();
  }

  const percentageKey = `${key}Percentage`;
  const rawPercentage = entry[percentageKey];
  if (result.percentage === null && typeof rawPercentage === 'number' && Number.isFinite(rawPercentage)) {
    result.percentage = rawPercentage;
  } else if (result.percentage === null && typeof rawPercentage === 'string' && rawPercentage.trim() !== '') {
    const parsed = Number.parseFloat(rawPercentage.replace(',', '.'));
    if (Number.isFinite(parsed)) {
      result.percentage = parsed;
    }
  }

  if (result.percentage === null && result.label !== null) {
    const parsedFromLabel = parsePercentageValue(result.label);
    if (Number.isFinite(parsedFromLabel)) {
      result.percentage = parsedFromLabel;
    }
  }

  if (result.label === null && result.percentage !== null) {
    const formatted = formatPercentageValue(result.percentage);
    result.label = formatted && formatted.trim() !== '' ? formatted : null;
  }

  return result;
};

const normalizeSystemLoadMetric = (entry) => {
  const result = {
    one: null,
    five: null,
    fifteen: null,
    label: null,
  };

  if (!entry || typeof entry !== 'object') {
    return result;
  }

  const rawMetric = entry.systemLoad;

  if (rawMetric && typeof rawMetric === 'object' && !Array.isArray(rawMetric)) {
    if (typeof rawMetric.label === 'string' && rawMetric.label.trim() !== '') {
      result.label = rawMetric.label.trim();
    }
    if (typeof rawMetric.one === 'number' && Number.isFinite(rawMetric.one)) {
      result.one = rawMetric.one;
    }
    if (typeof rawMetric.five === 'number' && Number.isFinite(rawMetric.five)) {
      result.five = rawMetric.five;
    }
    if (typeof rawMetric.fifteen === 'number' && Number.isFinite(rawMetric.fifteen)) {
      result.fifteen = rawMetric.fifteen;
    }
  } else if (typeof rawMetric === 'string' && rawMetric.trim() !== '') {
    result.label = rawMetric.trim();
  }

  if (result.label === null && typeof entry.systemLoadLabel === 'string' && entry.systemLoadLabel.trim() !== '') {
    result.label = entry.systemLoadLabel.trim();
  }

  if (
    (result.one === null || result.five === null || result.fifteen === null)
    && entry.systemLoadValues
    && typeof entry.systemLoadValues === 'object'
    && entry.systemLoadValues !== null
  ) {
    const values = entry.systemLoadValues;
    if (result.one === null && typeof values.one === 'number' && Number.isFinite(values.one)) {
      result.one = values.one;
    }
    if (result.five === null && typeof values.five === 'number' && Number.isFinite(values.five)) {
      result.five = values.five;
    }
    if (result.fifteen === null && typeof values.fifteen === 'number' && Number.isFinite(values.fifteen)) {
      result.fifteen = values.fifteen;
    }
  }

  if (result.label !== null) {
    const parsed = parseSystemLoadValues(result.label);
    if (result.one === null && parsed.one !== null) {
      result.one = parsed.one;
    }
    if (result.five === null && parsed.five !== null) {
      result.five = parsed.five;
    }
    if (result.fifteen === null && parsed.fifteen !== null) {
      result.fifteen = parsed.fifteen;
    }
  }

  if (result.label === null) {
    const parts = [];
    if (typeof result.one === 'number' && Number.isFinite(result.one)) {
      parts.push(`1 min: ${formatLoadAverageValue(result.one)}`);
    }
    if (typeof result.five === 'number' && Number.isFinite(result.five)) {
      parts.push(`5 min: ${formatLoadAverageValue(result.five)}`);
    }
    if (typeof result.fifteen === 'number' && Number.isFinite(result.fifteen)) {
      parts.push(`15 min: ${formatLoadAverageValue(result.fifteen)}`);
    }
    if (parts.length > 0) {
      result.label = parts.join(' • ');
    }
  }

  return result;
};

const HISTORY_METRICS = [
  {
    id: 'cpuTemperature',
    label: 'Temperatura CPU',
    axisLabel: 'Temperatura CPU (°C)',
    description: 'Wizualizacja historii temperatury CPU.',
    formatValue: formatTemperatureValue,
    getValue: (entry) => {
      if (!entry || typeof entry !== 'object') {
        return null;
      }
      const value = entry.cpuTemperatureValue;
      return typeof value === 'number' && Number.isFinite(value) ? value : null;
    },
    getTooltipLabel: (entry, value) => {
      if (entry && typeof entry.cpuTemperatureLabel === 'string' && entry.cpuTemperatureLabel.trim() !== '') {
        return entry.cpuTemperatureLabel.trim();
      }
      const formatted = formatTemperatureValue(value);
      if (formatted && formatted.trim() !== '') {
        return formatted;
      }
      if (typeof value === 'number' && Number.isFinite(value)) {
        return `${value.toFixed(1)} °C`;
      }
      return 'Brak danych';
    },
  },
  {
    id: 'memoryUsage',
    label: 'Użycie pamięci RAM',
    axisLabel: 'Użycie pamięci (%)',
    description: 'Wizualizacja historii zużycia pamięci RAM.',
    formatValue: formatPercentageValue,
    getValue: (entry) => {
      if (!entry || typeof entry !== 'object' || !entry.memoryUsage || typeof entry.memoryUsage !== 'object') {
        return null;
      }
      const value = entry.memoryUsage.percentage;
      return typeof value === 'number' && Number.isFinite(value) ? value : null;
    },
    getTooltipLabel: (entry, value) => {
      if (entry && typeof entry.memoryUsageLabel === 'string' && entry.memoryUsageLabel.trim() !== '') {
        return entry.memoryUsageLabel.trim();
      }
      const formatted = formatPercentageValue(value);
      if (formatted && formatted.trim() !== '') {
        return formatted;
      }
      if (typeof value === 'number' && Number.isFinite(value)) {
        return `${value.toFixed(1)} %`;
      }
      return 'Brak danych';
    },
  },
  {
    id: 'diskUsage',
    label: 'Użycie dysku',
    axisLabel: 'Użycie dysku (%)',
    description: 'Wizualizacja historii wykorzystania miejsca na dysku.',
    formatValue: formatPercentageValue,
    getValue: (entry) => {
      if (!entry || typeof entry !== 'object' || !entry.diskUsage || typeof entry.diskUsage !== 'object') {
        return null;
      }
      const value = entry.diskUsage.percentage;
      return typeof value === 'number' && Number.isFinite(value) ? value : null;
    },
    getTooltipLabel: (entry, value) => {
      if (entry && typeof entry.diskUsageLabel === 'string' && entry.diskUsageLabel.trim() !== '') {
        return entry.diskUsageLabel.trim();
      }
      const formatted = formatPercentageValue(value);
      if (formatted && formatted.trim() !== '') {
        return formatted;
      }
      if (typeof value === 'number' && Number.isFinite(value)) {
        return `${value.toFixed(1)} %`;
      }
      return 'Brak danych';
    },
  },
  {
    id: 'systemLoad',
    label: 'Obciążenie systemu',
    axisLabel: 'Load average',
    description: 'Historia obciążenia systemu (1, 5 i 15 minut).',
    formatValue: formatLoadAverageValue,
    getValue: (entry) => {
      if (!entry || typeof entry !== 'object' || !entry.systemLoad || typeof entry.systemLoad !== 'object') {
        return null;
      }
      const value = entry.systemLoad.one;
      return typeof value === 'number' && Number.isFinite(value) ? value : null;
    },
    getTooltipLabel: (entry, value) => {
      if (entry && typeof entry.systemLoadLabel === 'string' && entry.systemLoadLabel.trim() !== '') {
        return entry.systemLoadLabel.trim();
      }
      const formatted = formatLoadAverageValue(value);
      if (formatted && formatted.trim() !== '') {
        return `1 min: ${formatted}`;
      }
      if (typeof value === 'number' && Number.isFinite(value)) {
        return `1 min: ${value.toFixed(2)}`;
      }
      return 'Brak danych';
    },
  },
];

const HISTORY_METRIC_MAP = HISTORY_METRICS.reduce((map, metric) => {
  map[metric.id] = metric;
  return map;
}, {});

const getHistoryMetricDefinition = (metricId) => HISTORY_METRIC_MAP[metricId] || HISTORY_METRICS[0];

const readHistoryMetricPreference = () => readLocalStorage(HISTORY_METRIC_STORAGE_KEY);

const setHistoryMessage = (elements, message, hidden = false) => {
  if (!elements.historyEmpty) {
    return;
  }

  if (hidden) {
    elements.historyEmpty.textContent = '';
    elements.historyEmpty.hidden = true;
    return;
  }

  elements.historyEmpty.textContent = message;
  elements.historyEmpty.hidden = false;
};

const destroyHistoryChart = (elements) => {
  if (elements.historyChart) {
    elements.historyChart.innerHTML = '';
  }
  historyState.chartSignature = null;
};

const getHistoryCapacity = () => {
  if (typeof historyState.maxEntries === 'number' && historyState.maxEntries > 0) {
    return historyState.maxEntries;
  }
  if (typeof historyState.limit === 'number' && historyState.limit > 0) {
    return historyState.limit;
  }
  return HISTORY_DEFAULT_LIMIT;
};

const trimHistoryEntries = () => {
  const capacity = getHistoryCapacity();
  if (capacity > 0 && historyState.entries.length > capacity) {
    historyState.entries = historyState.entries.slice(-capacity);
  }
};

const setHistoryMetricState = (elements, metricDefinition, options = {}) => {
  const { persist = false } = options;
  if (!metricDefinition || typeof metricDefinition !== 'object') {
    return { changed: false, label: 'Metryka' };
  }

  const label = typeof metricDefinition.label === 'string' && metricDefinition.label.trim() !== ''
    ? metricDefinition.label.trim()
    : 'Metryka';
  const previousMetric = historyState.metric;
  const normalizedId = typeof metricDefinition.id === 'string' && metricDefinition.id.trim() !== ''
    ? metricDefinition.id.trim()
    : HISTORY_DEFAULT_METRIC;

  historyState.metric = normalizedId;

  const metricChanged = previousMetric !== normalizedId;
  if (metricChanged) {
    historyState.chartSignature = null;
  }

  if (persist) {
    writeLocalStorage(HISTORY_METRIC_STORAGE_KEY, normalizedId);
  }

  if (elements.historyChart) {
    elements.historyChart.setAttribute('aria-label', `Historia: ${label}`);
  }

  if (elements.historyTitle) {
    elements.historyTitle.textContent = `Historia: ${label}`;
  }

  const metricButtons = Array.from(elements.historyMetricButtons || []);
  metricButtons.forEach((button) => {
    if (!button || typeof button !== 'object') {
      return;
    }
    button.setAttribute('type', 'button');
    const target = button.getAttribute('data-metric');
    const isActive = target === normalizedId;
    button.classList.toggle('is-active', isActive);
    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
  });

  return { changed: metricChanged, label };
};

const renderHistoryChart = (elements, entries, metricId) => {
  const svg = elements.historyChart;
  if (!svg) {
    return false;
  }
  if (typeof SVGElement !== 'undefined' && !(svg instanceof SVGElement)) {
    return false;
  }

  const metric = getHistoryMetricDefinition(metricId);
  if (!metric) {
    return false;
  }

  svg.setAttribute('viewBox', `0 0 ${CHART_VIEWBOX_WIDTH} ${CHART_VIEWBOX_HEIGHT}`);
  svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
  svg.innerHTML = '';

  if (!Array.isArray(entries) || entries.length === 0) {
    return false;
  }

  const values = entries.map((entry) => metric.getValue(entry));
  if (values.some((value) => typeof value !== 'number' || !Number.isFinite(value))) {
    return false;
  }

  const minValue = Math.min(...values);
  const maxValue = Math.max(...values);

  if (!Number.isFinite(minValue) || !Number.isFinite(maxValue)) {
    return false;
  }

  let chartMin = minValue;
  let chartMax = maxValue;

  if (chartMax === chartMin) {
    const offset = chartMax === 0 ? 1 : Math.abs(chartMax) * 0.1;
    chartMin -= offset;
    chartMax += offset;
  } else {
    const paddingValue = (chartMax - chartMin) * 0.05;
    chartMin -= paddingValue;
    chartMax += paddingValue;
  }

  const range = chartMax - chartMin;
  if (range <= 0) {
    return false;
  }

  const width = CHART_VIEWBOX_WIDTH;
  const height = CHART_VIEWBOX_HEIGHT;
  const padding = CHART_PADDING;
  const innerWidth = width - padding.left - padding.right;
  const innerHeight = height - padding.top - padding.bottom;

  if (innerWidth <= 0 || innerHeight <= 0) {
    return false;
  }

  const baseY = padding.top + innerHeight;
  const baseXStart = padding.left;
  const baseXEnd = width - padding.right;

  const desc = createSvgElement('desc');
  desc.textContent = metric.description || 'Wizualizacja historii metryki.';
  svg.appendChild(desc);

  const gridGroup = createSvgElement('g', { class: 'history-grid' });
  for (let i = 0; i <= CHART_GRID_LINES; i += 1) {
    const ratio = i / CHART_GRID_LINES;
    const y = padding.top + (innerHeight * ratio);
    const line = createSvgElement('line', {
      x1: baseXStart,
      y1: y,
      x2: baseXEnd,
      y2: y,
      class: 'history-grid-line',
    });
    gridGroup.appendChild(line);

    const value = chartMax - (range * ratio);
    const label = createSvgElement('text', {
      x: baseXStart - 8,
      y,
      class: 'history-axis-label history-axis-label--y',
      'text-anchor': 'end',
      'dominant-baseline': 'middle',
    });
    label.textContent = formatMetricValue(metric, value);
    gridGroup.appendChild(label);
  }
  svg.appendChild(gridGroup);

  const axisGroup = createSvgElement('g', { class: 'history-axis' });
  const axisY = createSvgElement('line', {
    x1: baseXStart,
    y1: padding.top,
    x2: baseXStart,
    y2: baseY,
    class: 'history-axis-line',
  });
  axisGroup.appendChild(axisY);

  const axisX = createSvgElement('line', {
    x1: baseXStart,
    y1: baseY,
    x2: baseXEnd,
    y2: baseY,
    class: 'history-axis-line',
  });
  axisGroup.appendChild(axisX);

  const axisTitle = createSvgElement('text', {
    x: baseXStart,
    y: padding.top - 10,
    class: 'history-axis-label history-axis-label--title',
    'text-anchor': 'start',
  });
  axisTitle.textContent = metric.axisLabel || metric.label || 'Metryka';
  axisGroup.appendChild(axisTitle);

  const summary = createSvgElement('text', {
    x: baseXEnd,
    y: padding.top - 10,
    class: 'history-axis-label history-axis-label--summary',
    'text-anchor': 'end',
  });
  summary.textContent = `Min: ${formatMetricValue(metric, minValue)} · Max: ${formatMetricValue(metric, maxValue)}`;
  axisGroup.appendChild(summary);

  svg.appendChild(axisGroup);

  const points = entries.map((entry, index) => {
    const value = metric.getValue(entry);
    const ratioX = index / Math.max(1, entries.length - 1);
    const ratioY = (value - chartMin) / range;
    const x = baseXStart + (ratioX * innerWidth);
    const y = baseY - (ratioY * innerHeight);
    return { x, y, entry, value };
  });

  const areaPath = points.map((point, index) => {
    if (index === 0) {
      return `M ${point.x} ${baseY} L ${point.x} ${point.y}`;
    }
    return `L ${point.x} ${point.y}`;
  }).join(' ');

  const area = createSvgElement('path', {
    d: `${areaPath} L ${points[points.length - 1].x} ${baseY} Z`,
    class: 'history-area',
  });
  svg.appendChild(area);

  const linePath = points.map((point, index) => {
    if (index === 0) {
      return `M ${point.x} ${point.y}`;
    }
    return `L ${point.x} ${point.y}`;
  }).join(' ');

  const line = createSvgElement('path', {
    d: linePath,
    class: 'history-line',
  });
  svg.appendChild(line);

  const dotsGroup = createSvgElement('g', { class: 'history-dots' });
  points.forEach((point) => {
    const dot = createSvgElement('circle', {
      cx: point.x,
      cy: point.y,
      r: 4,
      class: 'history-dot',
    });
    dotsGroup.appendChild(dot);
  });
  svg.appendChild(dotsGroup);

  const labelsGroup = createSvgElement('g', { class: 'history-labels' });
  const metricDefinition = metric;
  points.forEach((point) => {
    const tooltipLabel = metricDefinition.getTooltipLabel(point.entry, point.value);
    const tooltip = typeof tooltipLabel === 'string'
      ? tooltipLabel.trim().slice(0, 160)
      : '';
    const label = createSvgElement('title');
    label.textContent = tooltip;
    const circle = createSvgElement('circle', {
      cx: point.x,
      cy: point.y,
      r: 12,
      class: 'history-dot-hitarea',
    });
    circle.appendChild(label);
    labelsGroup.appendChild(circle);
  });
  svg.appendChild(labelsGroup);

  return true;
};

const showHistoryChart = (elements) => {
  if (elements.historyChartWrapper) {
    elements.historyChartWrapper.classList.add('is-visible');
  }
};

const hideHistoryChart = (elements) => {
  if (elements.historyChartWrapper) {
    elements.historyChartWrapper.classList.remove('is-visible');
  }
};

const createHistoryEntry = (raw) => {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const generatedAt = typeof raw.generatedAt === 'string' ? raw.generatedAt : null;
  const timeLabel = typeof raw.time === 'string'
    ? raw.time
    : (generatedAt ? new Date(generatedAt).toLocaleTimeString() : null);

  let label = null;
  let value = null;

  if (raw.cpuTemperature && typeof raw.cpuTemperature === 'object' && !Array.isArray(raw.cpuTemperature)) {
    if (typeof raw.cpuTemperature.label === 'string' && raw.cpuTemperature.label.trim() !== '') {
      label = raw.cpuTemperature.label.trim();
    }
    if (typeof raw.cpuTemperature.value === 'number' && Number.isFinite(raw.cpuTemperature.value)) {
      value = raw.cpuTemperature.value;
    }
  }

  if (value === null && typeof raw.cpuTemperatureValue === 'number' && Number.isFinite(raw.cpuTemperatureValue)) {
    value = raw.cpuTemperatureValue;
  }

  const normalizedMemory = normalizePercentageMetric(raw, 'memoryUsage');
  const normalizedDisk = normalizePercentageMetric(raw, 'diskUsage');
  const normalizedLoad = normalizeSystemLoadMetric(raw);

  const cpuTemperatureValue = value !== null ? value : parseTemperatureValue(label);
  const cpuTemperatureLabel = label !== null
    ? label
    : (typeof raw.cpuTemperatureLabel === 'string' ? raw.cpuTemperatureLabel : null);

  const entry = {
    generatedAt,
    time: timeLabel,
    cpuTemperatureValue: typeof cpuTemperatureValue === 'number' && Number.isFinite(cpuTemperatureValue)
      ? cpuTemperatureValue
      : null,
    cpuTemperatureLabel: cpuTemperatureLabel !== null ? cpuTemperatureLabel : null,
    memoryUsage: normalizedMemory,
    memoryUsageLabel: normalizedMemory.label,
    diskUsage: normalizedDisk,
    diskUsageLabel: normalizedDisk.label,
    systemLoad: normalizedLoad,
    systemLoadLabel: normalizedLoad.label,
  };

  return entry;
};

const updateHistoryChart = (elements) => {
  const metricDefinition = getHistoryMetricDefinition(historyState.metric);
  const historyLabel = typeof metricDefinition.label === 'string' && metricDefinition.label.trim() !== ''
    ? metricDefinition.label.trim()
    : 'Metryka';

  if (!elements.historyChartWrapper) {
    return;
  }

  const hideChart = () => hideHistoryChart(elements);

  if (historyState.enabled === null) {
    destroyHistoryChart(elements);
    hideChart();

    setHistoryMessage(elements, `Historia metryki „${historyLabel}” ładuje się...`);
    return;
  }

  if (historyState.enabled === false) {
    destroyHistoryChart(elements);
    hideChart();

    setHistoryMessage(elements, `Historia metryki „${historyLabel}” jest wyłączona. Skonfiguruj zmienne środowiskowe, aby ją włączyć.`);
    return;
  }

  const validEntries = historyState.entries.filter((entry) => {
    if (!entry || typeof entry !== 'object') {
      return false;
    }
    const value = metricDefinition.getValue(entry);
    return typeof value === 'number' && Number.isFinite(value);
  });

  if (validEntries.length === 0) {
    destroyHistoryChart(elements);
    hideChart();

    setHistoryMessage(elements, `Brak danych dla metryki „${historyLabel}”.`);
    return;
  }

  const signature = JSON.stringify({
    metric: metricDefinition.id,
    entries: validEntries.map((entry) => {
      const value = metricDefinition.getValue(entry);
      const tooltipLabel = metricDefinition.getTooltipLabel(entry, value);
      const tooltip = typeof tooltipLabel === 'string'
        ? tooltipLabel.trim().slice(0, 160)
        : '';
      return [
        entry.generatedAt ?? entry.time ?? '',
        value,
        tooltip,
      ];
    }),
  });

  if (historyState.chartSignature !== signature) {
    const success = renderHistoryChart(elements, validEntries, metricDefinition.id);
    if (!success) {
      destroyHistoryChart(elements);
      hideChart();
      setHistoryMessage(elements, 'Nie udało się narysować wykresu historii.');
      return;
    }
    historyState.chartSignature = signature;
  }

  setHistoryMessage(elements, '', true);
  showHistoryChart(elements);
};

const pushSnapshotToHistory = (elements, snapshot) => {
  if (historyState.enabled !== true) {
    return;
  }

  const entry = createHistoryEntry(snapshot);
  if (!entry) {
    return;
  }

  const lastEntry = historyState.entries.length > 0
    ? historyState.entries[historyState.entries.length - 1]
    : null;

  if (lastEntry && entry.generatedAt && lastEntry.generatedAt === entry.generatedAt) {
    historyState.entries[historyState.entries.length - 1] = entry;
  } else {
    historyState.entries.push(entry);
  }

  trimHistoryEntries();
  updateHistoryChart(elements);
};

const initializeHistoryMetricSwitch = (elements) => {
  const storedPreference = readHistoryMetricPreference();
  const metricDefinition = getHistoryMetricDefinition(storedPreference || historyState.metric);
  setHistoryMetricState(elements, metricDefinition);

  const metricButtons = Array.from(elements.historyMetricButtons || []);
  if (metricButtons.length === 0) {
    return;
  }

  metricButtons.forEach((button) => {
    if (!button || typeof button !== 'object') {
      return;
    }
    button.setAttribute('type', 'button');
    button.addEventListener('click', (event) => {
      event.preventDefault();
      const target = button.getAttribute('data-metric');
      if (typeof target !== 'string' || target.trim() === '') {
        return;
      }
      const metricDefinitionNext = getHistoryMetricDefinition(target.trim());
      setHistoryMetricState(elements, metricDefinitionNext, { persist: true });
      updateHistoryChart(elements);
    });
  });
};

const loadHistory = async (elements) => {
  if (!supportsFetch) {
    historyState.enabled = false;
    updateHistoryChart(elements);
    return;
  }

  if (!historyState.entries.length) {
    setHistoryMessage(elements, 'Ładowanie historii...');
  }

  try {
    const response = await fetch(STATUS_HISTORY_ENDPOINT, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    if (!response.ok) {
      throw new Error(`Błąd ${response.status}`);
    }

    const data = await response.json();

    historyState.enabled = typeof data.enabled === 'boolean' ? data.enabled : true;
    historyState.maxEntries = typeof data.maxEntries === 'number' && Number.isFinite(data.maxEntries)
      ? data.maxEntries
      : historyState.maxEntries;
    historyState.limit = typeof data.limit === 'number' && Number.isFinite(data.limit)
      ? data.limit
      : historyState.limit;

    if (Array.isArray(data.entries)) {
      historyState.entries = data.entries
        .map((entry) => createHistoryEntry(entry))
        .filter((entry) => entry !== null);
    } else {
      historyState.entries = [];
    }

    trimHistoryEntries();
    updateHistoryChart(elements);
  } catch (error) {
    destroyHistoryChart(elements);
    hideHistoryChart(elements);
    const message = error instanceof Error ? error.message : String(error);
    setHistoryMessage(elements, `Nie udało się załadować historii: ${message}`);
  }
};

export function createHistoryController(elements) {
  const initialize = () => {
    initializeHistoryMetricSwitch(elements);
    updateHistoryChart(elements);
  };

  return {
    initialize,
    loadHistory: () => loadHistory(elements),
    pushSnapshot: (snapshot) => pushSnapshotToHistory(elements, snapshot),
    updateHistoryChart: () => updateHistoryChart(elements),
    setMetric: (metricId) => {
      const definition = getHistoryMetricDefinition(metricId);
      setHistoryMetricState(elements, definition, { persist: true });
      updateHistoryChart(elements);
    },
    setHistoryEnabled: (enabled) => {
      historyState.enabled = enabled;
      updateHistoryChart(elements);
    },
  };
}
