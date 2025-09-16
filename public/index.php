<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/** @var array<string, string> $servicesToCheck */
$servicesToCheck = require __DIR__ . '/../config/services.php';

$statusParam = isset($_GET['status']) ? (string) $_GET['status'] : null;
if (handleStatusRequest($statusParam, $servicesToCheck)) {
    return;
}

$snapshot = collectStatusSnapshot($servicesToCheck);

$time = $snapshot['time'];
$cpuTemperature = $snapshot['cpuTemperature'];
$systemLoad = $snapshot['systemLoad'];
$uptime = $snapshot['uptime'];
$serviceStatuses = $snapshot['services'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Moja strona na Raspberry Pi</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      max-width: 900px;
      margin: 40px auto;
      background: #e9eef5;
      padding: 20px;
      border-radius: 12px;
      box-shadow: 0 0 12px rgba(0, 0, 0, 0.2);
    }
    h1 {
      color: #0066cc;
    }
    p {
      font-size: 18px;
    }
    footer {
      margin-top: 40px;
      font-size: 14px;
      color: #666;
    }
    .status-panel {
      margin-top: 32px;
      background: #ffffff;
      border-radius: 12px;
      padding: 24px;
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
    }
    .status-panel h2 {
      margin-top: 0;
      margin-bottom: 16px;
      color: #0b5394;
    }
    .metrics {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .metric {
      background: #f0f6ff;
      border-radius: 10px;
      padding: 16px;
    }
    .metric-label {
      display: block;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #5a6a85;
      margin-bottom: 8px;
    }
    .metric-value {
      font-size: 22px;
      font-weight: bold;
      color: #003d80;
    }
    .service-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    .service-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #f7f8fa;
      border-radius: 10px;
      padding: 12px 16px;
      margin-bottom: 12px;
      transition: transform 0.1s ease;
    }
    .service-item:hover {
      transform: translateX(2px);
    }
    .service-name {
      font-weight: 600;
      color: #2b3d55;
    }
    .service-name small {
      display: block;
      font-size: 12px;
      font-weight: normal;
      color: #7a879c;
    }
    .service-status {
      font-weight: 600;
    }
    .status-ok {
      border-left: 4px solid #2c974b;
    }
    .status-ok .service-status {
      color: #2c974b;
    }
    .status-off {
      border-left: 4px solid #8a9099;
    }
    .status-off .service-status {
      color: #636b78;
    }
    .status-error {
      border-left: 4px solid #d93025;
    }
    .status-error .service-status {
      color: #d93025;
    }
    .status-warn {
      border-left: 4px solid #f6c026;
    }
    .status-warn .service-status {
      color: #b8860b;
    }
    .status-unknown {
      border-left: 4px solid #5f6368;
    }
    .status-unknown .service-status {
      color: #5f6368;
    }
    .status-note {
      margin-top: 16px;
      font-size: 14px;
      color: #6b7688;
    }
    .panel-footer {
      margin-top: 20px;
    }
    .status-refresh {
      margin-top: 8px;
      font-size: 13px;
      color: #5a6a85;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px;
    }
    .status-refresh.is-loading {
      opacity: 0.85;
    }
    .status-refresh.has-error {
      color: #d93025;
    }
    .status-refresh button {
      background: #0b5394;
      color: #ffffff;
      border: none;
      border-radius: 6px;
      padding: 6px 12px;
      font-size: 13px;
      cursor: pointer;
      transition: background 0.2s ease, transform 0.1s ease;
    }
    .status-refresh button:hover {
      background: #094579;
      transform: translateY(-1px);
    }
    .status-refresh button:disabled {
      background: #7a879c;
      cursor: not-allowed;
      opacity: 0.85;
      transform: none;
    }
  </style>
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
        <span class="metric-label">Obciążenie systemu</span>
        <span class="metric-value" data-role="system-load"><?= h($systemLoad ?? 'Brak danych'); ?></span>
      </div>
      <div class="metric">
        <span class="metric-label">Czas działania</span>
        <span class="metric-value" data-role="uptime"><?= h($uptime ?? 'Brak danych'); ?></span>
      </div>
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
      const REFRESH_INTERVAL = 15000;
      const STREAM_RECONNECT_DELAY = 5000;

      const elements = {
        time: document.querySelector('[data-role="server-time"]'),
        cpuTemperature: document.querySelector('[data-role="cpu-temperature"]'),
        systemLoad: document.querySelector('[data-role="system-load"]'),
        uptime: document.querySelector('[data-role="uptime"]'),
        serviceList: document.querySelector('[data-role="service-list"]'),
        refreshLabel: document.querySelector('[data-role="refresh-label"]'),
        refreshButton: document.querySelector('[data-role="refresh-button"]'),
        refreshContainer: document.querySelector('[data-role="refresh-container"]'),
      };

      const supportsFetch = typeof window.fetch === 'function';
      const supportsEventSource = typeof window.EventSource === 'function';

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
          } else if (supportsEventSource) {
            stopStream();
            startStream();
          }
        });
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
