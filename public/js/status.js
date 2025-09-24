import {
  STATUS_JSON_ENDPOINT,
  STATUS_STREAM_ENDPOINT,
  STREAM_RECONNECT_DELAY,
} from './constants.js';
import { supportsEventSource, supportsFetch } from './support.js';

const fallback = (value, fallbackValue = 'Brak danych') => {
  if (typeof value === 'string') {
    const trimmed = value.trim();
    return trimmed !== '' ? trimmed : fallbackValue;
  }
  return fallbackValue;
};

export function createStatusController(elements, options = {}) {
  const {
    streamIntervalSeconds = null,
    historyController,
  } = options;

  const fallbackInterval = 15000;
  const refreshInterval = (typeof streamIntervalSeconds === 'number'
    && Number.isFinite(streamIntervalSeconds)
    && streamIntervalSeconds > 0)
    ? Math.max(streamIntervalSeconds * 1000, fallbackInterval)
    : fallbackInterval;

  let refreshTimer = null;
  let eventSource = null;
  let reconnectTimer = null;
  let isFetching = false;
  let currentMode = supportsEventSource ? 'stream' : 'poll';

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

  const updateLabelSuccess = (generatedAt, serverTime, mode) => {
    if (!elements.refreshLabel) {
      return;
    }

    const timestamp = generatedAt
      ? new Date(generatedAt).toLocaleTimeString()
      : new Date().toLocaleTimeString();

    const modeLabel = mode === 'stream' ? 'tryb na żywo' : (mode === 'poll' ? 'tryb zapasowy' : 'odświeżenie');
    const serverLabel = serverTime ? ` (czas serwera: ${serverTime})` : '';
    const intervalLabel = mode === 'stream'
      && typeof streamIntervalSeconds === 'number'
      && Number.isFinite(streamIntervalSeconds)
      && streamIntervalSeconds > 0
        ? ` (interwał strumienia: ${streamIntervalSeconds} s)`
        : '';

    elements.refreshLabel.textContent = `Ostatnia aktualizacja (${modeLabel}): ${timestamp}${serverLabel}${intervalLabel}`;
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

    if (historyController && typeof historyController.pushSnapshot === 'function') {
      historyController.pushSnapshot(data);
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
      window.clearInterval(refreshTimer);
      refreshTimer = null;
    }
  };

  const startPolling = () => {
    if (!supportsFetch || refreshTimer) {
      return;
    }

    loadStatus();
    refreshTimer = window.setInterval(() => loadStatus(), refreshInterval);
    currentMode = 'poll';
  };

  const cancelReconnect = () => {
    if (reconnectTimer) {
      window.clearTimeout(reconnectTimer);
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
      currentMode = 'stream';
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

  const initialize = () => {
    if (supportsEventSource) {
      startStream();
    } else if (supportsFetch) {
      startPolling();
    }
  };

  const handleVisibilityChange = (isVisible) => {
    if (isVisible) {
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
  };

  const manualRefresh = () => {
    if (supportsFetch) {
      loadStatus(true);
      if (historyController && typeof historyController.loadHistory === 'function') {
        historyController.loadHistory();
      }
    } else if (supportsEventSource) {
      stopStream();
      startStream();
    }
  };

  return {
    initialize,
    manualRefresh,
    handleVisibilityChange,
    startStream,
    stopStream,
    startPolling,
    stopPolling,
    getCurrentMode: () => currentMode,
  };
}
