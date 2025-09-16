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
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-OLBgp1GsljhM2TJ+sbHjaiH9txEUvgdDTAzHv2P24donTt6/529l+9Ua0vFImLlb" crossorigin="anonymous"></script>
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
        <canvas data-role="history-chart" height="260"></canvas>
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
        chart: null,
        maxEntries: null,
        limit: null,
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
        if (historyState.chart) {
          historyState.chart.destroy();
          historyState.chart = null;
        }
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

        if (historyState.enabled === null) {
          destroyHistoryChart();
          if (elements.historyChartWrapper) {
            elements.historyChartWrapper.classList.remove('is-visible');
          }
          setHistoryMessage('Historia ładuje się...');
          return;
        }

        if (historyState.enabled === false) {
          destroyHistoryChart();
          if (elements.historyChartWrapper) {
            elements.historyChartWrapper.classList.remove('is-visible');
          }
          setHistoryMessage('Historia jest wyłączona. Skonfiguruj zmienne środowiskowe, aby ją włączyć.');
          return;
        }

        const validEntries = historyState.entries.filter((entry) => entry && typeof entry.cpuTemperatureValue === 'number' && Number.isFinite(entry.cpuTemperatureValue));

        if (validEntries.length === 0) {
          destroyHistoryChart();
          if (elements.historyChartWrapper) {
            elements.historyChartWrapper.classList.remove('is-visible');
          }
          setHistoryMessage('Historia nie zawiera jeszcze danych.');
          return;
        }

        if (typeof window.Chart !== 'function') {
          destroyHistoryChart();
          if (elements.historyChartWrapper) {
            elements.historyChartWrapper.classList.remove('is-visible');
          }
          setHistoryMessage('Nie można wyświetlić wykresu (biblioteka Chart.js niedostępna).');
          return;
        }

        setHistoryMessage('', true);

        const labels = validEntries.map((entry) => entry.time ?? (entry.generatedAt ? new Date(entry.generatedAt).toLocaleTimeString() : '—'));
        const values = validEntries.map((entry) => entry.cpuTemperatureValue);

        if (!historyState.chart) {
          const canvas = elements.historyChart;
          if (!canvas) {
            setHistoryMessage('Brak elementu canvas dla wykresu.');
            return;
          }

          const context = canvas.getContext('2d');
          if (!context) {
            setHistoryMessage('Nie można zainicjować kontekstu wykresu.');
            return;
          }

          historyState.chart = new window.Chart(context, {
            type: 'line',
            data: {
              labels,
              datasets: [
                {
                  label: 'Temperatura CPU [°C]',
                  data: values,
                  borderColor: '#0b5394',
                  backgroundColor: 'rgba(11, 83, 148, 0.2)',
                  tension: 0.3,
                  fill: true,
                  pointRadius: 2,
                  pointHoverRadius: 4,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              interaction: {
                intersect: false,
                mode: 'nearest',
              },
              scales: {
                x: {
                  ticks: {
                    maxRotation: 0,
                  },
                },
                y: {
                  ticks: {
                    callback: (value) => `${value}°`,
                  },
                },
              },
              plugins: {
                legend: {
                  display: false,
                },
                tooltip: {
                  callbacks: {
                    label: (context) => {
                      const entry = validEntries[context.dataIndex];
                      return entry?.cpuTemperatureLabel ?? `${context.parsed.y} °C`;
                    },
                  },
                },
              },
            },
          });
        } else {
          historyState.chart.data.labels = labels;
          historyState.chart.data.datasets[0].data = values;
          historyState.chart.update('none');
        }

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
