<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/** @var array{username: string|null, password: string|null} $authConfig */
$authConfig = require __DIR__ . '/../config/auth.php';

/** @var array<string, string> $servicesToCheck */
$servicesToCheck = require __DIR__ . '/../config/services.php';

/** @var array<string, array{label: string, host: string, relayId?: int, authKey?: string, username?: string, password?: string}> $shellyDevices */
$shellyDevices = require __DIR__ . '/../config/shelly.php';

$authUsername = $authConfig['username'] ?? null;
$authPassword = $authConfig['password'] ?? null;

if ($authUsername !== null && $authPassword !== null) {
    $credentialsMatch = false;

    $providedUsernameRaw = $_SERVER['PHP_AUTH_USER'] ?? null;
    $providedPasswordRaw = $_SERVER['PHP_AUTH_PW'] ?? null;

    if ($providedUsernameRaw !== null && $providedPasswordRaw !== null) {
        $providedUsername = (string) $providedUsernameRaw;
        $providedPassword = (string) $providedPasswordRaw;
        $expectedUsername = (string) $authUsername;
        $expectedPassword = (string) $authPassword;

        if (
            $providedUsername !== ''
            && $providedPassword !== ''
            && $expectedUsername !== ''
            && $expectedPassword !== ''
        ) {
            $credentialsMatch = hash_equals($expectedUsername, $providedUsername)
                && hash_equals($expectedPassword, $providedPassword);
        }
    }

    if (!$credentialsMatch) {
        header('WWW-Authenticate: Basic realm="RaspberryPiServer"');
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(401);
        echo 'Unauthorized';
        return;
    }
}

$statusParam = isset($_GET['status']) ? (string) $_GET['status'] : null;

if (handleShellyRequest($shellyDevices)) {
    return;
}

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
  <div class="page-header">
    <h1>Witaj na mojej stronie! 🎉</h1>
    <div class="theme-toggle theme-toggle--top">
      <button type="button" data-role="theme-toggle" aria-pressed="false">Włącz tryb ciemny</button>
    </div>
  </div>
  <p>Ta strona działa na <strong>Raspberry Pi + Nginx + PHP</strong>.</p>

  <p>Aktualny czas serwera to: <strong data-role="server-time"><?= h($time); ?></strong></p>

  <div class="tab-navigation" data-role="tabs" role="tablist" aria-label="Sekcje panelu">
    <button type="button" class="tab-button is-active" data-role="tab" data-tab="status" id="tab-status" role="tab" aria-selected="true" aria-controls="panel-status">Status systemu</button>
    <button type="button" class="tab-button" data-role="tab" data-tab="shelly" id="tab-shelly" role="tab" aria-selected="false" aria-controls="panel-shelly" tabindex="-1">Shelly</button>
  </div>

  <section class="status-panel tab-panel is-active" data-role="tab-panel" data-tab-panel="status" id="panel-status" role="tabpanel" aria-labelledby="tab-status">
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

  <section class="shelly-panel tab-panel" data-role="tab-panel" data-tab-panel="shelly" id="panel-shelly" role="tabpanel" aria-labelledby="tab-shelly" hidden>
    <h2>Urządzenia Shelly</h2>
    <p class="shelly-intro">Steruj przekaźnikami Shelly dostępnych w Twojej sieci domowej bezpośrednio z tego panelu.</p>
    <div class="shelly-toolbar">
      <button type="button" data-role="shelly-reload">Odśwież listę</button>
      <span class="shelly-last-update" data-role="shelly-last-update"></span>
    </div>
    <p class="shelly-error" data-role="shelly-error" hidden></p>
    <p class="shelly-message" data-role="shelly-message">Przełącz na kartę „Shelly”, aby pobrać stan urządzeń.</p>
    <div class="shelly-list" data-role="shelly-list" aria-live="polite" aria-busy="false"></div>
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
      const SHELLY_LIST_ENDPOINT = '?shelly=list';
      const SHELLY_COMMAND_ENDPOINT = '?shelly=command';
      const STATUS_TAB_ID = 'status';
      const SHELLY_TAB_ID = 'shelly';
      const TAB_STORAGE_KEY = 'dashboard-active-tab';

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
        themeToggle: document.querySelector('[data-role="theme-toggle"]'),
        tabs: document.querySelector('[data-role="tabs"]'),
        tabButtons: document.querySelectorAll('[data-role="tab"]'),
        tabPanels: document.querySelectorAll('[data-role="tab-panel"]'),
        shellyList: document.querySelector('[data-role="shelly-list"]'),
        shellyError: document.querySelector('[data-role="shelly-error"]'),
        shellyMessage: document.querySelector('[data-role="shelly-message"]'),
        shellyReload: document.querySelector('[data-role="shelly-reload"]'),
        shellyLastUpdate: document.querySelector('[data-role="shelly-last-update"]'),
      };

      const THEME_STORAGE_KEY = 'theme-preference';

      const readThemePreference = () => {
        try {
          if (typeof window.localStorage === 'undefined') {
            return null;
          }
          return window.localStorage.getItem(THEME_STORAGE_KEY);
        } catch (error) {
          return null;
        }
      };

      const writeThemePreference = (value) => {
        try {
          if (typeof window.localStorage === 'undefined') {
            return;
          }
          window.localStorage.setItem(THEME_STORAGE_KEY, value);
        } catch (error) {
          // Ignorujemy błędy zapisu (np. tryb prywatny)
        }
      };

      const updateThemeToggle = (isDark) => {
        if (!elements.themeToggle) {
          return;
        }
        elements.themeToggle.textContent = isDark ? 'Tryb ciemny włączony' : 'Włącz tryb ciemny';
        elements.themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
      };

      const applyThemePreference = (isDark, persist = false) => {
        document.body.classList.toggle('theme-dark', isDark);
        updateThemeToggle(isDark);
        if (persist) {
          writeThemePreference(isDark ? 'dark' : 'light');
        }
      };

      const initializeTheme = () => {
        const storedPreference = readThemePreference();
        let isDark = storedPreference === 'dark';

        if (storedPreference !== 'dark' && storedPreference !== 'light') {
          if (window.matchMedia && typeof window.matchMedia === 'function') {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');
            if (prefersDark && typeof prefersDark.matches === 'boolean') {
              isDark = prefersDark.matches;
            }
          } else {
            isDark = document.body.classList.contains('theme-dark');
          }
        }

        applyThemePreference(isDark, storedPreference !== 'dark' && storedPreference !== 'light');
      };

      let currentTab = STATUS_TAB_ID;

      const readActiveTabPreference = () => {
        try {
          if (typeof window.localStorage === 'undefined') {
            return null;
          }
          const stored = window.localStorage.getItem(TAB_STORAGE_KEY);
          return typeof stored === 'string' && stored.trim() !== '' ? stored : null;
        } catch (error) {
          return null;
        }
      };

      const writeActiveTabPreference = (value) => {
        try {
          if (typeof window.localStorage === 'undefined') {
            return;
          }
          window.localStorage.setItem(TAB_STORAGE_KEY, value);
        } catch (error) {
          // Ignorujemy błędy zapisu.
        }
      };

      const setActiveTab = (tabId, persist = false) => {
        const tabButtons = Array.from(elements.tabButtons || []);
        const tabPanels = Array.from(elements.tabPanels || []);

        if (tabButtons.length === 0 || tabPanels.length === 0) {
          currentTab = STATUS_TAB_ID;
          return currentTab;
        }

        const normalized = typeof tabId === 'string' && tabId.trim() !== '' ? tabId.trim() : STATUS_TAB_ID;

        let found = false;

        tabButtons.forEach((button) => {
          const target = button.getAttribute('data-tab');
          const isActive = target === normalized;
          if (isActive) {
            found = true;
          }
          button.classList.toggle('is-active', isActive);
          button.setAttribute('aria-selected', isActive ? 'true' : 'false');
          button.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        tabPanels.forEach((panel) => {
          const target = panel.getAttribute('data-tab-panel');
          const isActive = target === normalized;
          panel.classList.toggle('is-active', isActive);
          panel.hidden = !isActive;
        });

        if (!found && normalized !== STATUS_TAB_ID) {
          return setActiveTab(STATUS_TAB_ID, persist);
        }

        const finalTab = found ? normalized : STATUS_TAB_ID;
        currentTab = finalTab;

        if (persist) {
          writeActiveTabPreference(finalTab);
        }

        return finalTab;
      };

      const initializeTabs = () => {
        const tabButtons = Array.from(elements.tabButtons || []);
        if (tabButtons.length === 0) {
          return;
        }

        tabButtons.forEach((button) => {
          button.addEventListener('click', () => {
            const target = button.getAttribute('data-tab');
            if (!target) {
              return;
            }

            const active = setActiveTab(target, true);
            handleTabActivation(active, true);
          });
        });

        const stored = readActiveTabPreference();
        const active = setActiveTab(stored || STATUS_TAB_ID, false);
        handleTabActivation(active, false);
      };

      const supportsFetch = typeof window.fetch === 'function';
      const supportsEventSource = typeof window.EventSource === 'function';

      const shellyState = {
        devices: [],
        loading: false,
        error: null,
        hasErrors: false,
        pending: new Set(),
        lastUpdated: null,
        lastAttempt: null,
        initialized: false,
        requestId: 0,
      };

      const setShellyError = (message) => {
        if (!elements.shellyError) {
          return;
        }

        if (typeof message === 'string' && message.trim() !== '') {
          elements.shellyError.textContent = message;
          elements.shellyError.hidden = false;
        } else {
          elements.shellyError.textContent = '';
          elements.shellyError.hidden = true;
        }
      };

      const setShellyMessage = (message) => {
        if (!elements.shellyMessage) {
          return;
        }

        if (typeof message === 'string' && message.trim() !== '') {
          elements.shellyMessage.textContent = message;
          elements.shellyMessage.hidden = false;
        } else {
          elements.shellyMessage.textContent = '';
          elements.shellyMessage.hidden = true;
        }
      };

      const updateShellyLastUpdate = () => {
        if (!elements.shellyLastUpdate) {
          return;
        }

        if (shellyState.loading) {
          elements.shellyLastUpdate.textContent = 'Trwa ładowanie...';
          return;
        }

        if (typeof shellyState.lastUpdated === 'string' && shellyState.lastUpdated.trim() !== '') {
          const parsed = new Date(shellyState.lastUpdated);
          const formatted = Number.isNaN(parsed.getTime())
            ? shellyState.lastUpdated
            : parsed.toLocaleString();
          elements.shellyLastUpdate.textContent = `Ostatnia aktualizacja: ${formatted}`;
          return;
        }

        if (
          shellyState.error
          && typeof shellyState.lastAttempt === 'string'
          && shellyState.lastAttempt.trim() !== ''
        ) {
          const attempt = new Date(shellyState.lastAttempt);
          const formattedAttempt = Number.isNaN(attempt.getTime())
            ? shellyState.lastAttempt
            : attempt.toLocaleString();
          elements.shellyLastUpdate.textContent = `Ostatnia próba: ${formattedAttempt}`;
          return;
        }

        elements.shellyLastUpdate.textContent = '';
      };

      const formatShellyStateLabel = (state) => {
        switch (state) {
          case 'on':
            return 'Włączone';
          case 'off':
            return 'Wyłączone';
          default:
            return 'Nieznany stan';
        }
      };

      const renderShellyDevices = () => {
        const container = elements.shellyList;
        if (!container) {
          return;
        }

        container.innerHTML = '';

        if (shellyState.loading) {
          container.classList.add('is-loading');
        } else {
          container.classList.remove('is-loading');
        }

        container.setAttribute('aria-busy', shellyState.loading ? 'true' : 'false');

        if (!shellyState.initialized && !shellyState.loading && !shellyState.error) {
          setShellyMessage('Przełącz na kartę „Shelly”, aby pobrać stan urządzeń.');
        }

        if (shellyState.loading) {
          setShellyMessage('Ładowanie urządzeń Shelly...');
          setShellyError(null);

          const loader = document.createElement('div');
          loader.className = 'shelly-loading';
          loader.setAttribute('role', 'status');
          loader.setAttribute('aria-live', 'polite');
          loader.textContent = 'Ładowanie urządzeń Shelly...';
          container.appendChild(loader);

          updateShellyLastUpdate();
          return;
        }

        if (shellyState.error) {
          setShellyError(shellyState.error);
        } else {
          setShellyError(null);
        }

        if (shellyState.devices.length === 0) {
          if (!shellyState.error && shellyState.initialized) {
            setShellyMessage('Brak skonfigurowanych urządzeń Shelly.');

            const empty = document.createElement('p');
            empty.className = 'shelly-empty';
            empty.textContent = 'Brak danych do wyświetlenia.';
            container.appendChild(empty);
          } else if (shellyState.error) {
            setShellyMessage('');
          }

          updateShellyLastUpdate();
          return;
        }

        if (!shellyState.error) {
          if (shellyState.hasErrors) {
            setShellyMessage('Część urządzeń zgłosiła błąd podczas odczytu stanu.');
          } else {
            setShellyMessage('');
          }
        }

        shellyState.devices.forEach((device) => {
          const card = document.createElement('article');
          card.className = 'shelly-device';

          if (!device.ok) {
            card.classList.add('has-error');
          }

          if (shellyState.pending.has(device.id)) {
            card.classList.add('is-busy');
          }

          const title = document.createElement('h3');
          title.className = 'shelly-device__title';
          title.textContent = device.label || device.id;
          card.appendChild(title);

          const stateLine = document.createElement('p');
          stateLine.className = 'shelly-device__state';
          const stateValue = document.createElement('span');
          stateValue.className = 'shelly-device__state-value';
          stateValue.dataset.state = device.state;
          stateValue.textContent = formatShellyStateLabel(device.state);
          stateLine.textContent = 'Stan: ';
          stateLine.appendChild(stateValue);
          card.appendChild(stateLine);

          if (device.description) {
            const description = document.createElement('p');
            description.className = 'shelly-device__description';
            description.textContent = device.description;
            card.appendChild(description);
          }

          const actions = document.createElement('div');
          actions.className = 'shelly-device__actions';

          const actionButton = document.createElement('button');
          const nextAction = device.state === 'on' ? 'off' : 'on';
          actionButton.type = 'button';
          actionButton.dataset.role = 'shelly-action';
          actionButton.dataset.device = device.id;
          actionButton.dataset.action = nextAction;
          actionButton.classList.add('shelly-device__action');
          actionButton.setAttribute('aria-label', 'Przełącz zasilanie');
          actionButton.setAttribute('aria-pressed', device.state === 'on' ? 'true' : 'false');
          actionButton.title = nextAction === 'on' ? 'Włącz urządzenie' : 'Wyłącz urządzenie';

          const icon = document.createElement('span');
          icon.className = 'icon-power';
          icon.setAttribute('aria-hidden', 'true');
          const srText = document.createElement('span');
          srText.className = 'sr-only';
          srText.textContent = `${nextAction === 'on' ? 'Włącz' : 'Wyłącz'} urządzenie. Stan: ${formatShellyStateLabel(device.state)}.`;

          actionButton.appendChild(icon);
          actionButton.appendChild(srText);

          if (shellyState.pending.has(device.id)) {
            actionButton.disabled = true;
          }

          actions.appendChild(actionButton);

          card.appendChild(actions);

          if (device.error) {
            const errorLine = document.createElement('p');
            errorLine.className = 'shelly-device__error';
            errorLine.textContent = `Błąd: ${device.error}`;
            card.appendChild(errorLine);
          }

          container.appendChild(card);
        });

        updateShellyLastUpdate();
      };

      const loadShellyDevices = (force = false) => {
        if (!supportsFetch) {
          setShellyError('Sterowanie Shelly wymaga przeglądarki obsługującej funkcję fetch.');
          return;
        }

        if (shellyState.loading && !force) {
          return;
        }

        const requestId = shellyState.requestId + 1;
        shellyState.requestId = requestId;
        shellyState.loading = true;
        shellyState.error = null;
        shellyState.initialized = true;
        shellyState.lastAttempt = new Date().toISOString();
        renderShellyDevices();

        fetch(SHELLY_LIST_ENDPOINT, {
          method: 'GET',
          headers: {
            Accept: 'application/json',
          },
          cache: 'no-store',
        })
          .then((response) => {
            if (!response.ok) {
              return response
                .json()
                .catch(() => null)
                .then((data) => {
                  let message = '';
                  if (data && typeof data === 'object') {
                    if (typeof data.error === 'string' && data.error.trim() !== '') {
                      message = data.error.trim();
                    }
                    if (typeof data.message === 'string' && data.message.trim() !== '') {
                      message = message !== '' ? `${message} - ${data.message.trim()}` : data.message.trim();
                    }
                    if (typeof data.description === 'string' && data.description.trim() !== '') {
                      message = message !== '' ? `${message} - ${data.description.trim()}` : data.description.trim();
                    }
                  }

                  if (message === '') {
                    message = `HTTP ${response.status}`;
                  }

                  throw new Error(message);
                });
            }

            return response.json();
          })
          .then((payload) => {
            if (shellyState.requestId !== requestId) {
              return;
            }

            const devicesData = payload && typeof payload === 'object' && Array.isArray(payload.devices)
              ? payload.devices
              : [];

            shellyState.devices = devicesData.map((entry, index) => {
              let id = '';
              if (entry && typeof entry === 'object') {
                if (typeof entry.id === 'string' && entry.id.trim() !== '') {
                  id = entry.id.trim();
                } else if (typeof entry.id === 'number' || typeof entry.id === 'boolean') {
                  id = String(entry.id);
                }
              }

              if (id === '') {
                id = `device-${index + 1}`;
              }

              let label = id;
              if (entry && typeof entry === 'object' && typeof entry.label === 'string' && entry.label.trim() !== '') {
                label = entry.label.trim();
              }

              let state = 'unknown';
              if (entry && typeof entry === 'object' && typeof entry.state === 'string') {
                const normalizedState = entry.state.trim().toLowerCase();
                if (normalizedState !== '') {
                  state = normalizedState;
                }
              }

              let description = '';
              if (entry && typeof entry === 'object' && typeof entry.description === 'string') {
                description = entry.description.trim();
              }

              let error = null;
              if (entry && typeof entry === 'object' && typeof entry.error === 'string' && entry.error.trim() !== '') {
                error = entry.error.trim();
              }

              let ok = true;
              if (entry && typeof entry === 'object' && typeof entry.ok === 'boolean') {
                ok = entry.ok;
              } else if (error !== null) {
                ok = false;
              }

              return {
                id,
                label,
                state,
                description,
                ok: Boolean(ok),
                error,
              };
            });

            let hasErrors = false;
            if (payload && typeof payload === 'object' && typeof payload.hasErrors === 'boolean') {
              hasErrors = payload.hasErrors;
            }

            shellyState.hasErrors = Boolean(hasErrors) || shellyState.devices.some((device) => !device.ok);

            let generatedAt = null;
            if (payload && typeof payload === 'object' && typeof payload.generatedAt === 'string') {
              generatedAt = payload.generatedAt.trim();
            }

            shellyState.lastUpdated = generatedAt !== '' && generatedAt !== null ? generatedAt : shellyState.lastAttempt;
            shellyState.error = null;
            shellyState.loading = false;
            renderShellyDevices();
            updateShellyLastUpdate();
          })
          .catch((error) => {
            if (shellyState.requestId !== requestId) {
              return;
            }

            shellyState.loading = false;
            shellyState.devices = [];
            shellyState.hasErrors = false;
            const message = error && typeof error.message === 'string' && error.message.trim() !== ''
              ? error.message.trim()
              : 'Nie udało się pobrać listy urządzeń Shelly.';
            shellyState.error = message;
            renderShellyDevices();
            updateShellyLastUpdate();
          });
      };

      const ensureShellyDataLoaded = (force = false) => {
        if (!supportsFetch) {
          return;
        }

        if (force) {
          loadShellyDevices(true);
          return;
        }

        if (!shellyState.initialized && !shellyState.loading) {
          loadShellyDevices(false);
        }
      };

      const toggleShellyDevice = (deviceId, action) => {
        if (!supportsFetch) {
          setShellyError('Sterowanie Shelly wymaga przeglądarki obsługującej funkcję fetch.');
          return;
        }

        const normalizedId = typeof deviceId === 'string' ? deviceId.trim() : '';
        const normalizedAction = typeof action === 'string' ? action.trim().toLowerCase() : '';

        if (normalizedId === '') {
          return;
        }

        const device = shellyState.devices.find((item) => item.id === normalizedId);
        let finalAction = normalizedAction === 'on' || normalizedAction === 'off'
          ? normalizedAction
          : null;

        if (!finalAction && device) {
          finalAction = device.state === 'on' ? 'off' : 'on';
        }

        if (finalAction !== 'on' && finalAction !== 'off') {
          return;
        }

        if (shellyState.pending.has(normalizedId)) {
          return;
        }

        shellyState.pending.add(normalizedId);
        shellyState.error = null;
        setShellyError(null);
        renderShellyDevices();

        fetch(SHELLY_COMMAND_ENDPOINT, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify({
            device: normalizedId,
            action: finalAction,
          }),
        })
          .then((response) => {
            if (!response.ok) {
              return response
                .json()
                .catch(() => null)
                .then((data) => {
                  const messageParts = [];
                  if (data && typeof data === 'object') {
                    if (typeof data.error === 'string' && data.error.trim() !== '') {
                      messageParts.push(data.error.trim());
                    }
                    if (typeof data.description === 'string' && data.description.trim() !== '') {
                      messageParts.push(data.description.trim());
                    }
                    if (typeof data.message === 'string' && data.message.trim() !== '') {
                      messageParts.push(data.message.trim());
                    }
                  }

                  if (messageParts.length === 0) {
                    messageParts.push(`HTTP ${response.status}`);
                  }

                  throw new Error(messageParts.join(' - '));
                });
            }

            return response.json();
          })
          .then((payload) => {
            shellyState.pending.delete(normalizedId);

            if (payload && typeof payload === 'object' && typeof payload.description === 'string') {
              const trimmedDescription = payload.description.trim();
              if (trimmedDescription !== '') {
                setShellyMessage(trimmedDescription);
              }
            }

            loadShellyDevices(true);
          })
          .catch((error) => {
            shellyState.pending.delete(normalizedId);
            const message = error && typeof error.message === 'string' && error.message.trim() !== ''
              ? error.message.trim()
              : 'Nie udało się wykonać polecenia Shelly.';
            shellyState.error = message;
            setShellyError(message);
            renderShellyDevices();
          });
      };

      const handleShellyActionClick = (event) => {
        const target = event.target;
        if (!target || typeof target.closest !== 'function') {
          return;
        }

        const button = target.closest('[data-role="shelly-action"]');
        if (!button) {
          return;
        }

        const deviceId = button.getAttribute('data-device');
        let action = button.getAttribute('data-action') || '';

        if (!deviceId) {
          return;
        }

        const device = shellyState.devices.find((item) => item.id === deviceId);
        if (device) {
          action = device.state === 'on' ? 'off' : 'on';
        }

        if (action !== 'on' && action !== 'off') {
          return;
        }

        button.dataset.action = action;

        event.preventDefault();
        toggleShellyDevice(deviceId, action);
      };

      function handleTabActivation(tabId, fromUser) {
        if (tabId === SHELLY_TAB_ID) {
          if (!supportsFetch) {
            setShellyError('Sterowanie Shelly wymaga przeglądarki obsługującej funkcję fetch.');
            setShellyMessage('Sterowanie Shelly wymaga nowszej przeglądarki.');
            if (elements.shellyReload) {
              elements.shellyReload.disabled = true;
            }
            return;
          }

          if (elements.shellyReload) {
            elements.shellyReload.disabled = false;
          }

          if (fromUser) {
            ensureShellyDataLoaded(false);
          } else {
            ensureShellyDataLoaded(false);
          }
        }
      }

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
          if (typeof customText === 'string' && customText.trim() !== '') {
            elements.refreshLabel.textContent = customText;
            return;
          }

          if (loading) {
            const timestamp = new Date().toLocaleTimeString();
            const prefix = manual ? 'Trwa ręczne odświeżanie' : 'Trwa odświeżanie';
            elements.refreshLabel.textContent = `${prefix} (${timestamp})...`;
          }
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

      renderShellyDevices();

      if (elements.shellyList) {
        elements.shellyList.addEventListener('click', handleShellyActionClick);
      }

      if (elements.shellyReload) {
        elements.shellyReload.addEventListener('click', () => {
          loadShellyDevices(true);
        });

        if (!supportsFetch) {
          elements.shellyReload.disabled = true;
        }
      }

      initializeTabs();

      if (elements.themeToggle) {
        elements.themeToggle.addEventListener('click', () => {
          const isDark = !document.body.classList.contains('theme-dark');
          applyThemePreference(isDark, true);
        });
      }

      initializeTheme();

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
