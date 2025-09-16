<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/** @var array{username: string|null, password: string|null} $authConfig */
$authConfig = require __DIR__ . '/../config/auth.php';

/** @var array<string, string> $servicesToCheck */
$servicesToCheck = require __DIR__ . '/../config/services.php';

$authUsername = $authConfig['username'] ?? null;
$authPassword = $authConfig['password'] ?? null;

if ($authUsername !== null && $authPassword !== null) {
    $providedUsername = $_SERVER['PHP_AUTH_USER'] ?? null;
    $providedPassword = $_SERVER['PHP_AUTH_PW'] ?? null;

    $credentialsMatch = $providedUsername === $authUsername && $providedPassword === $authPassword;

    if (!$credentialsMatch) {
        header('WWW-Authenticate: Basic realm="RaspberryPiServer"');
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(401);
        echo 'Unauthorized';
        return;
    }
}

$statusParam = isset($_GET['status']) ? (string) $_GET['status'] : null;
if (handleStatusRequest($statusParam, $servicesToCheck)) {
    return;
}

$snapshot = collectStatusSnapshot($servicesToCheck);

$time = $snapshot['time'];
$cpuTemperature = $snapshot['cpuTemperature'];
$systemLoad = $snapshot['systemLoad'];
$uptime = $snapshot['uptime'];
$memoryUsage = $snapshot['memoryUsage'];
$diskUsage = $snapshot['diskUsage'];
$serviceStatuses = $snapshot['services'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Moja strona na Raspberry Pi</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <h1>Witaj na mojej stronie! 🎉</h1>
  <p>Ta strona działa na <strong>Raspberry Pi + Nginx + PHP</strong>.</p>

  <p>Aktualny czas serwera to: <strong data-role="server-time"><?= h($time); ?></strong></p>

  <section class="status-panel">
    <h2>Panel stanu Raspberry Pi</h2>
    <div class="metrics">
      <div class="metric">
        <span class="metric-label">Temperatura CPU</span>
        <span class="metric-value" data-role="cpu-temperature"><?= h($cpuTemperature ?? 'Brak danych'); ?></span>
      </div>
      <div class="metric">
        <span class="metric-label">Pamięć RAM</span>
        <span class="metric-value" data-role="memory-usage"><?= h($memoryUsage ?? 'Brak danych'); ?></span>
      </div>
      <div class="metric">
        <span class="metric-label">Miejsce na dysku</span>
        <span class="metric-value" data-role="disk-usage"><?= h($diskUsage ?? 'Brak danych'); ?></span>
      </div>
      <div class="metric">
        <span class="metric-label">Obciążenie systemu</span>
        <span class="metric-value" data-role="system-load"><?= h($systemLoad ?? 'Brak danych'); ?></span>
      </div>
      <div class="metric">
        <span class="metric-label">Czas działania</span>
        <span class="metric-value" data-role="uptime"><?= h($uptime ?? 'Brak danych'); ?></span>
      </div>
    </div>

    <div class="history-container" data-role="history-container">
      <h3>Historia temperatury CPU</h3>
      <div class="history-chart" data-role="history-chart-wrapper">
        <svg data-role="history-chart" viewBox="0 0 600 260" role="img" aria-label="Historia temperatury CPU"></svg>
      </div>
      <p class="history-empty" data-role="history-empty">Historia ładuje się...</p>
    </div>

    <ul class="service-list" data-role="service-list">
      <?php foreach ($serviceStatuses as $service): ?>
        <?php
          $cssClass = trim((string) ($service['class'] ?? ''));
          if ($cssClass === '') {
              $cssClass = 'status-unknown';
          }
        ?>
        <li class="service-item <?= h($cssClass); ?>"<?= isset($service['details']) && $service['details'] !== null ? ' title="' . h($service['details']) . '"' : ''; ?>>
          <span class="service-name">
            <?= h($service['label']); ?>
            <small><?= h($service['service']); ?></small>
          </span>
          <span class="service-status"><?= h($service['status']); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>

    <p class="status-note">
      Dane odświeżają się automatycznie. W przypadku problemów spróbuj kliknąć przycisk poniżej.
    </p>

    <div class="panel-footer">
      <div class="status-refresh" data-role="refresh-container">
        <span data-role="refresh-label">Ostatnie odświeżenie: <?= h($snapshot['generatedAt']); ?></span>
        <button type="button" data-role="refresh-button">Odśwież teraz</button>
      </div>
    </div>
  </section>

  <footer>
    <p>Miłego dnia! 😊</p>
  </footer>

  <script>
    (function () {
      const STATUS_JSON_ENDPOINT = '?status=json';
      const STATUS_STREAM_ENDPOINT = '?status=stream';
      const STATUS_HISTORY_ENDPOINT = '?status=history';
      const REFRESH_INTERVAL = 15000;
      const STREAM_RECONNECT_DELAY = 5000;
      const HISTORY_DEFAULT_LIMIT = 360;

      const elements = {
        time: document.querySelector('[data-role="server-time"]'),
        cpuTemperature: document.querySelector('[data-role="cpu-temperature"]'),
        memoryUsage: document.querySelector('[data-role="memory-usage"]'),
        diskUsage: document.querySelector('[data-role="disk-usage"]'),
        systemLoad: document.querySelector('[data-role="system-load"]'),
        uptime: document.querySelector('[data-role="uptime"]'),
        serviceList: document.querySelector('[data-role="service-list"]'),
        refreshLabel: document.querySelector('[data-role="refresh-label"]'),
        refreshButton: document.querySelector('[data-role="refresh-button"]'),
        refreshContainer: document.querySelector('[data-role="refresh-container"]'),
        historyContainer: document.querySelector('[data-role="history-container"]'),
        historyChartWrapper: document.querySelector('[data-role="history-chart-wrapper"]'),
        historyChart: document.querySelector('[data-role="history-chart"]'),
        historyEmpty: document.querySelector('[data-role="history-empty"]'),
      };

      const supportsFetch = typeof window.fetch === 'function';
      const supportsEventSource = typeof window.EventSource === 'function';

      const historyState = {
        enabled: null,
        entries: [],
        maxEntries: null,
        limit: null,
        chartSignature: null,
      };

      let refreshTimer = null;
      let eventSource = null;
      let reconnectTimer = null;
      let isFetching = false;

      const fallback = (value, fallbackValue = 'Brak danych') => {
        if (typeof value === 'string') {
          const trimmed = value.trim();
          return trimmed !== '' ? trimmed : fallbackValue;
        }
        return fallbackValue;
      };

      const setFetchingState = (loading, manual = false, customText = null) => {
        if (!elements.refreshContainer) {
          return;
        }

        if (loading) {
          elements.refreshContainer.classList.add('is-loading');
        } else {
          elements.refreshContainer.classList.remove('is-loading');
        }

        if (elements.refreshButton) {
          elements.refreshButton.disabled = loading;
        }

        if (elements.refreshLabel) {
          if (customText) {
            elements.refreshLabel.textContent = customText;
            return;
          }

          const timestamp = new Date().toLocaleTimeString();
          const prefix = manual ? 'Ręczne odświeżenie' : 'Ostatnie odświeżenie';
          elements.refreshLabel.textContent = `${prefix}: ${timestamp}`;
        }
      };

      const setHistoryMessage = (message, hidden = false) => {
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

      const destroyHistoryChart = () => {
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

      const formatHistoryEntryTime = (entry) => {
        if (!entry || typeof entry !== 'object') {
          return '—';
        }

        if (typeof entry.time === 'string' && entry.time.trim() !== '') {
          return entry.time.trim();
        }

        if (typeof entry.generatedAt === 'string') {
          const parsed = new Date(entry.generatedAt);
          if (!Number.isNaN(parsed.getTime())) {
            return parsed.toLocaleTimeString();
          }
        }

        return '—';
      };

      const renderHistoryChart = (entries) => {
        const svg = elements.historyChart;
        if (!svg) {
          return false;
        }
        if (typeof SVGElement !== 'undefined' && !(svg instanceof SVGElement)) {
          return false;
        }

        svg.setAttribute('viewBox', `0 0 ${CHART_VIEWBOX_WIDTH} ${CHART_VIEWBOX_HEIGHT}`);
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svg.innerHTML = '';

        if (!Array.isArray(entries) || entries.length === 0) {
          return false;
        }

        const values = entries.map((entry) => entry.cpuTemperatureValue);
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
        desc.textContent = 'Wizualizacja historii temperatury CPU.';
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
          label.textContent = formatTemperatureValue(value);
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
        axisTitle.textContent = 'Temperatura CPU [°C]';
        axisGroup.appendChild(axisTitle);

        const summary = createSvgElement('text', {
          x: baseXEnd,
          y: padding.top - 10,
          class: 'history-axis-label history-axis-label--summary',
          'text-anchor': 'end',
        });
        summary.textContent = `Min: ${formatTemperatureValue(minValue)} · Max: ${formatTemperatureValue(maxValue)}`;
        axisGroup.appendChild(summary);

        svg.appendChild(axisGroup);

        const points = entries.map((entry, index) => {
          const ratio = entries.length > 1 ? index / (entries.length - 1) : 0.5;
          const x = padding.left + (innerWidth * ratio);
          let normalized = (entry.cpuTemperatureValue - chartMin) / range;
          if (!Number.isFinite(normalized)) {
            normalized = 0;
          }
          normalized = Math.max(0, Math.min(1, normalized));
          const y = padding.top + innerHeight - (normalized * innerHeight);
          return { x, y, entry };
        });

        let areaPath = `M ${points[0].x.toFixed(2)} ${baseY.toFixed(2)}`;
        let linePath = '';
        points.forEach((point, index) => {
          linePath += `${index === 0 ? 'M' : 'L'}${point.x.toFixed(2)},${point.y.toFixed(2)} `;
          areaPath += ` L ${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
        });
        areaPath += ` L ${points[points.length - 1].x.toFixed(2)} ${baseY.toFixed(2)} Z`;

        const area = createSvgElement('path', {
          d: areaPath.trim(),
          class: 'history-area',
        });
        svg.appendChild(area);

        const line = createSvgElement('path', {
          d: linePath.trim(),
          class: 'history-line',
        });
        svg.appendChild(line);

        const dotsGroup = createSvgElement('g', { class: 'history-points' });
        const radius = entries.length > 160 ? 1.2 : entries.length > 80 ? 1.6 : 2.4;
        points.forEach((point) => {
          const circle = createSvgElement('circle', {
            cx: point.x.toFixed(2),
            cy: point.y.toFixed(2),
            r: radius,
            class: 'history-dot',
          });
          const title = createSvgElement('title');
          const label = typeof point.entry.cpuTemperatureLabel === 'string'
            ? point.entry.cpuTemperatureLabel
            : formatTemperatureValue(point.entry.cpuTemperatureValue);
          title.textContent = `${label} (${formatHistoryEntryTime(point.entry)})`;
          circle.appendChild(title);
          dotsGroup.appendChild(circle);
        });
        svg.appendChild(dotsGroup);

        const xLabelIndices = Array.from(new Set([
          0,
          entries.length > 1 ? Math.floor((entries.length - 1) / 2) : 0,
          entries.length - 1,
        ])).filter((index) => index >= 0 && index < entries.length).sort((a, b) => a - b);

        const labelsGroup = createSvgElement('g', { class: 'history-x-labels' });
        xLabelIndices.forEach((index) => {
          const entry = entries[index];
          const text = createSvgElement('text', {
            x: points[index].x.toFixed(2),
            y: (baseY + 18).toFixed(2),
            class: 'history-axis-label history-axis-label--x',
            'text-anchor': 'middle',
            'dominant-baseline': 'hanging',
          });
          text.textContent = formatHistoryEntryTime(entry);
          labelsGroup.appendChild(text);
        });
        svg.appendChild(labelsGroup);

        return true;
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

        if (label === null && typeof raw.cpuTemperatureLabel === 'string' && raw.cpuTemperatureLabel.trim() !== '') {
          label = raw.cpuTemperatureLabel.trim();
        }

        if (value === null && typeof raw.cpuTemperature === 'string') {
          const parsed = parseTemperatureValue(raw.cpuTemperature);
          if (Number.isFinite(parsed)) {
            value = parsed;
          }
          if (!label && raw.cpuTemperature.trim() !== '') {
            label = raw.cpuTemperature.trim();
          }
        }

        if (value === null && label !== null) {
          const parsed = parseTemperatureValue(label);
          if (Number.isFinite(parsed)) {
            value = parsed;
          }
        }

        if (value === null) {
          return null;
        }

        if (!label || label.trim() === '') {
          label = `${value.toFixed(1)} °C`;
        }

        return {
          generatedAt,
          time: timeLabel,
          cpuTemperatureValue: value,
          cpuTemperatureLabel: label,
        };
      };

      const updateHistoryChart = () => {
        if (!elements.historyContainer) {
          return;
        }

        const hideChart = () => {
          if (elements.historyChartWrapper) {
            elements.historyChartWrapper.classList.remove('is-visible');
          }
        };

        if (historyState.enabled === null) {
          destroyHistoryChart();
          hideChart();
          setHistoryMessage('Historia ładuje się...');
          return;
        }

        if (historyState.enabled === false) {
          destroyHistoryChart();
          hideChart();
          setHistoryMessage('Historia jest wyłączona. Skonfiguruj zmienne środowiskowe, aby ją włączyć.');
          return;
        }

        const validEntries = historyState.entries.filter(
          (entry) => entry && typeof entry.cpuTemperatureValue === 'number' && Number.isFinite(entry.cpuTemperatureValue),
        );

        if (validEntries.length === 0) {
          destroyHistoryChart();
          hideChart();
          setHistoryMessage('Historia nie zawiera jeszcze danych.');
          return;
        }

        const signature = JSON.stringify(
          validEntries.map((entry) => [
            entry.generatedAt ?? entry.time ?? '',
            entry.cpuTemperatureValue,
            entry.cpuTemperatureLabel ?? '',
          ]),
        );

        if (historyState.chartSignature !== signature) {
          const success = renderHistoryChart(validEntries);
          if (!success) {
            destroyHistoryChart();
            hideChart();
            setHistoryMessage('Nie udało się narysować wykresu historii.');
            return;
          }
          historyState.chartSignature = signature;
        }

        setHistoryMessage('', true);

        if (elements.historyChartWrapper) {
          elements.historyChartWrapper.classList.add('is-visible');
        }
      };

      const pushSnapshotToHistory = (snapshot) => {
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
        updateHistoryChart();
      };

      const loadHistory = async () => {
        if (!supportsFetch) {
          historyState.enabled = false;
          updateHistoryChart();
          return;
        }

        if (!historyState.entries.length) {
          setHistoryMessage('Ładowanie historii...');
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
          updateHistoryChart();
        } catch (error) {
          destroyHistoryChart();
          if (elements.historyChartWrapper) {
            elements.historyChartWrapper.classList.remove('is-visible');
          }
          const message = error instanceof Error ? error.message : String(error);
          setHistoryMessage(`Nie udało się załadować historii: ${message}`);
        }
      };

      const updateLabelSuccess = (generatedAt, serverTime, mode) => {
        if (!elements.refreshLabel) {
          return;
        }

        const timestamp = generatedAt
          ? new Date(generatedAt).toLocaleTimeString()
          : new Date().toLocaleTimeString();

        const modeLabel = mode === 'stream' ? 'tryb na żywo' : (mode === 'poll' ? 'tryb zapasowy' : 'odświeżenie');
        elements.refreshLabel.textContent = `Ostatnia aktualizacja (${modeLabel}): ${timestamp}${serverTime ? ` (czas serwera: ${serverTime})` : ''}`;
      };

      const renderServices = (services) => {
        if (!elements.serviceList || !Array.isArray(services)) {
          return;
        }

        const fragment = document.createDocumentFragment();

        services.forEach((service) => {
          if (!service || typeof service !== 'object') {
            return;
          }

          const item = document.createElement('li');
          const cssClass = typeof service.class === 'string' && service.class.trim() !== ''
            ? service.class.trim()
            : 'status-unknown';

          item.className = `service-item ${cssClass}`;

          if (service.details && typeof service.details === 'string' && service.details.trim() !== '') {
            item.title = service.details;
          }

          const name = document.createElement('span');
          name.className = 'service-name';
          name.appendChild(document.createTextNode(fallback(service.label)));

          const small = document.createElement('small');
          if (typeof service.service === 'string' && service.service.trim() !== '') {
            small.textContent = service.service;
          } else {
            small.textContent = '—';
          }
          name.appendChild(small);

          const status = document.createElement('span');
          status.className = 'service-status';
          status.textContent = fallback(service.status);

          item.appendChild(name);
          item.appendChild(status);

          fragment.appendChild(item);
        });

        elements.serviceList.innerHTML = '';
        elements.serviceList.appendChild(fragment);
      };

      const applySnapshot = (data, mode = 'stream') => {
        if (!data || typeof data !== 'object') {
          return;
        }

        const serverTime = fallback(data.time ?? null);

        if (elements.time) {
          elements.time.textContent = serverTime;
        }

        if (elements.cpuTemperature) {
          elements.cpuTemperature.textContent = fallback(data.cpuTemperature ?? null);
        }

        if (elements.memoryUsage) {
          elements.memoryUsage.textContent = fallback(data.memoryUsage ?? null);
        }

        if (elements.diskUsage) {
          elements.diskUsage.textContent = fallback(data.diskUsage ?? null);
        }

        if (elements.systemLoad) {
          elements.systemLoad.textContent = fallback(data.systemLoad ?? null);
        }

        if (elements.uptime) {
          elements.uptime.textContent = fallback(data.uptime ?? null);
        }

        renderServices(data.services);

        updateLabelSuccess(data.generatedAt ?? null, serverTime, mode);

        if (elements.refreshContainer) {
          elements.refreshContainer.classList.remove('has-error');
        }

        pushSnapshotToHistory(data);
      };

      const loadStatus = async (manual = false) => {
        if (!supportsFetch || isFetching) {
          return;
        }

        isFetching = true;
        setFetchingState(true, manual);

        try {
          const response = await fetch(STATUS_JSON_ENDPOINT, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
          });

          if (!response.ok) {
            throw new Error(`Błąd ${response.status}`);
          }

          const data = await response.json();
          const mode = manual
            ? (supportsEventSource && eventSource ? 'stream' : 'manual')
            : (supportsEventSource && eventSource ? 'stream' : 'poll');

          applySnapshot(data, mode);
        } catch (error) {
          if (elements.refreshContainer) {
            elements.refreshContainer.classList.add('has-error');
          }

          if (elements.refreshLabel) {
            const timestamp = new Date().toLocaleTimeString();
            const message = error instanceof Error ? error.message : String(error);
            elements.refreshLabel.textContent = `Błąd odświeżania (${timestamp}): ${message}`;
          }
        } finally {
          isFetching = false;
          setFetchingState(false);
        }
      };

      const stopPolling = () => {
        if (refreshTimer) {
          clearInterval(refreshTimer);
          refreshTimer = null;
        }
      };

      const startPolling = () => {
        if (!supportsFetch || refreshTimer) {
          return;
        }

        loadStatus();
        refreshTimer = window.setInterval(() => loadStatus(), REFRESH_INTERVAL);
      };

      const cancelReconnect = () => {
        if (reconnectTimer) {
          clearTimeout(reconnectTimer);
          reconnectTimer = null;
        }
      };

      const stopStream = () => {
        if (eventSource) {
          eventSource.close();
          eventSource = null;
        }
        cancelReconnect();
      };

      const scheduleReconnect = () => {
        if (reconnectTimer) {
          return;
        }

        reconnectTimer = window.setTimeout(() => {
          reconnectTimer = null;
          startStream();
        }, STREAM_RECONNECT_DELAY);
      };

      const startStream = () => {
        if (!supportsEventSource) {
          if (supportsFetch) {
            startPolling();
          }
          return;
        }

        stopStream();
        stopPolling();

        if (elements.refreshContainer) {
          elements.refreshContainer.classList.remove('has-error');
        }

        setFetchingState(true, false, 'Łączenie ze strumieniem danych...');

        eventSource = new EventSource(STATUS_STREAM_ENDPOINT);

        const handleStatusEvent = (event) => {
          try {
            const data = JSON.parse(event.data);
            applySnapshot(data, 'stream');
            setFetchingState(false);
            stopPolling();
          } catch (parseError) {
            if (elements.refreshContainer) {
              elements.refreshContainer.classList.add('has-error');
            }
            if (elements.refreshLabel) {
              const timestamp = new Date().toLocaleTimeString();
              elements.refreshLabel.textContent = `Błąd danych strumienia (${timestamp})`;
            }
          }
        };

        eventSource.addEventListener('status', handleStatusEvent);
        eventSource.addEventListener('message', handleStatusEvent);

        eventSource.addEventListener('open', () => {
          setFetchingState(false);
        });

        eventSource.addEventListener('error', () => {
          setFetchingState(false);
          if (elements.refreshContainer) {
            elements.refreshContainer.classList.add('has-error');
          }
          if (elements.refreshLabel) {
            const timestamp = new Date().toLocaleTimeString();
            elements.refreshLabel.textContent = `Błąd strumienia (${timestamp}). Próba ponownego połączenia...`;
          }
          stopStream();
          if (supportsFetch) {
            startPolling();
          }
          scheduleReconnect();
        });
      };

      if (elements.refreshButton) {
        elements.refreshButton.addEventListener('click', () => {
          if (supportsFetch) {
            loadStatus(true);
            loadHistory();
          } else if (supportsEventSource) {
            stopStream();
            startStream();
          }
        });
      }

      updateHistoryChart();

      if (supportsFetch) {
        loadHistory();
      } else {
        historyState.enabled = false;
        destroyHistoryChart();
        if (elements.historyChartWrapper) {
          elements.historyChartWrapper.classList.remove('is-visible');
        }
        setHistoryMessage('Historia wymaga przeglądarki obsługującej funkcję fetch.');
      }

      if (supportsEventSource) {
        startStream();
      } else if (supportsFetch) {
        startPolling();
      }

      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
          if (supportsEventSource) {
            if (!eventSource) {
              startStream();
            }
          } else if (supportsFetch && !refreshTimer) {
            startPolling();
          }
        } else {
          if (supportsEventSource) {
            stopStream();
          }
          stopPolling();
        }
      });
    })();
  </script>
</body>
</html>
