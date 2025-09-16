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
        memoryUsage: document.querySelector('[data-role="memory-usage"]'),
        diskUsage: document.querySelector('[data-role="disk-usage"]'),
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
