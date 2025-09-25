import {
  SHELLY_AUTO_REFRESH_INTERVAL,
  SHELLY_COMMAND_ENDPOINT,
  SHELLY_LIST_ENDPOINT,
  SHELLY_CONFIG_ERROR_HINT,
  SHELLY_CONFIG_ERROR_MESSAGE,
  SHELLY_TAB_ID,
} from './constants.js';
import { supportsFetch } from './support.js';

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

const haveShellyDevicesChanged = (previousDevices, nextDevices) => {
  if (!Array.isArray(previousDevices) || !Array.isArray(nextDevices)) {
    return true;
  }

  if (previousDevices.length !== nextDevices.length) {
    return true;
  }

  const previousById = new Map();
  previousDevices.forEach((device) => {
    if (device && typeof device.id === 'string') {
      previousById.set(device.id, device);
    }
  });

  if (previousById.size !== nextDevices.length) {
    return true;
  }

  for (const device of nextDevices) {
    if (!device || typeof device.id !== 'string') {
      return true;
    }

    const previous = previousById.get(device.id);
    if (!previous) {
      return true;
    }

    if (
      previous.label !== device.label
      || previous.state !== device.state
      || previous.description !== device.description
      || previous.ok !== device.ok
      || previous.error !== device.error
    ) {
      return true;
    }
  }

  return false;
};

export function createShellyController(elements, options = {}) {
  const {
    csrfToken = '',
    configError = false,
  } = options;

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

  if (configError) {
    shellyState.error = SHELLY_CONFIG_ERROR_MESSAGE;
    shellyState.initialized = true;
  }

  let shellyRefreshTimer = null;

  const markShellyListIdle = () => {
    const container = elements.shellyList;
    if (!container) {
      return;
    }

    container.classList.remove('is-refreshing');
    if (!shellyState.loading) {
      container.classList.remove('is-loading');
    }
    container.setAttribute('aria-busy', 'false');
  };

  const setShellyError = (message) => {
    const errorElement = elements.shellyError;
    if (!errorElement) {
      return;
    }

    if (typeof message === 'string' && message.trim() !== '') {
      errorElement.textContent = message;
      errorElement.hidden = false;
    } else {
      errorElement.textContent = '';
      errorElement.hidden = true;
    }
  };

  const setShellyMessage = (message) => {
    const messageElement = elements.shellyMessage;
    if (!messageElement) {
      return;
    }

    if (typeof message === 'string' && message.trim() !== '') {
      messageElement.textContent = message;
      messageElement.hidden = false;
    } else {
      messageElement.textContent = '';
      messageElement.hidden = true;
    }
  };

  const updateShellyLastUpdate = () => {
    const lastUpdateElement = elements.shellyLastUpdate;
    if (!lastUpdateElement) {
      return;
    }

    if (shellyState.loading) {
      lastUpdateElement.textContent = 'Trwa ładowanie...';
      return;
    }

    if (typeof shellyState.lastUpdated === 'string' && shellyState.lastUpdated.trim() !== '') {
      const parsed = new Date(shellyState.lastUpdated);
      const formatted = Number.isNaN(parsed.getTime())
        ? shellyState.lastUpdated
        : parsed.toLocaleString();
      lastUpdateElement.textContent = `Ostatnia aktualizacja: ${formatted}`;
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
      lastUpdateElement.textContent = `Ostatnia próba: ${formattedAttempt}`;
      return;
    }

    lastUpdateElement.textContent = '';
  };

  const renderShellyDevices = () => {
    const container = elements.shellyList;
    if (!container) {
      return;
    }

    const hasDevices = shellyState.devices.length > 0;

    if (shellyState.loading && hasDevices) {
      container.classList.remove('is-loading');
      container.classList.add('is-refreshing');
      container.setAttribute('aria-busy', 'true');
      updateShellyLastUpdate();
      return;
    }

    container.classList.remove('is-refreshing');
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

      const body = document.createElement('div');
      body.className = 'shelly-device__body';

      const title = document.createElement('h3');
      title.className = 'shelly-device__title';
      title.textContent = device.label || device.id;
      body.appendChild(title);

      const stateLine = document.createElement('p');
      stateLine.className = 'shelly-device__state';
      const stateValue = document.createElement('span');
      stateValue.className = 'shelly-device__state-value';
      stateValue.dataset.state = device.state;
      stateValue.textContent = formatShellyStateLabel(device.state);
      stateLine.textContent = 'Stan: ';
      stateLine.appendChild(stateValue);
      body.appendChild(stateLine);

      if (device.description) {
        const description = document.createElement('p');
        description.className = 'shelly-device__description';
        description.textContent = device.description;
        body.appendChild(description);
      }

      card.appendChild(body);

      const actions = document.createElement('div');
      actions.className = 'shelly-device__actions';

      const actionButton = document.createElement('button');
      const nextAction = device.state === 'on' ? 'off' : 'on';
      actionButton.type = 'button';
      actionButton.dataset.role = 'shelly-action';
      actionButton.dataset.deviceId = device.id;
      actionButton.dataset.action = nextAction;
      actionButton.className = 'shelly-device__action';

      const actionLabel = nextAction === 'on' ? 'Włącz' : 'Wyłącz';
      actionButton.setAttribute('aria-label', actionLabel);
      actionButton.title = actionLabel;

      const icon = document.createElement('span');
      icon.className = 'icon-power';
      actionButton.appendChild(icon);

      const srLabel = document.createElement('span');
      srLabel.className = 'sr-only';
      srLabel.textContent = actionLabel;
      actionButton.appendChild(srLabel);

      if (!supportsFetch || configError) {
        actionButton.disabled = true;
        actionButton.setAttribute('aria-disabled', 'true');
      }

      actions.appendChild(actionButton);
      card.appendChild(actions);

      if (device.error) {
        const error = document.createElement('p');
        error.className = 'shelly-device__error';
        error.textContent = device.error;
        card.appendChild(error);
      }

      container.appendChild(card);
    });

    updateShellyLastUpdate();
  };

  const loadShellyDevices = (force = false) => {
    if (configError) {
      setShellyError(SHELLY_CONFIG_ERROR_MESSAGE);
      setShellyMessage(SHELLY_CONFIG_ERROR_HINT);
      return;
    }

    if (!supportsFetch) {
      setShellyError('Sterowanie Shelly wymaga przeglądarki obsługującej funkcję fetch.');
      setShellyMessage('Sterowanie Shelly wymaga nowszej przeglądarki.');
      return;
    }

    if (shellyState.loading && !force) {
      return;
    }

    shellyState.requestId += 1;
    const requestId = shellyState.requestId;

    shellyState.loading = true;
    shellyState.initialized = true;
    shellyState.lastAttempt = new Date().toISOString();
    if (!force) {
      shellyState.error = null;
    }

    renderShellyDevices();

    const headers = {
      Accept: 'application/json',
    };

    if (csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    fetch(SHELLY_LIST_ENDPOINT, {
      headers,
      cache: 'no-store',
      credentials: 'same-origin',
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

        const previousDevices = shellyState.devices;
        const previousHasErrors = shellyState.hasErrors;
        const errorBeforeRequest = shellyState.error;

        const devicesData = payload && typeof payload === 'object' && Array.isArray(payload.devices)
          ? payload.devices
          : [];

        const normalizedDevices = devicesData.map((entry, index) => {
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

        const devicesChanged = haveShellyDevicesChanged(previousDevices, normalizedDevices);
        shellyState.devices = normalizedDevices;

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
        if (devicesChanged || previousHasErrors !== shellyState.hasErrors || errorBeforeRequest !== shellyState.error) {
          renderShellyDevices();
        } else {
          markShellyListIdle();
        }
        updateShellyLastUpdate();
      })
      .catch((error) => {
        if (shellyState.requestId !== requestId) {
          return;
        }

        shellyState.loading = false;
        const hadDevices = Array.isArray(shellyState.devices) && shellyState.devices.length > 0;
        shellyState.hasErrors = false;
        const message = error && typeof error.message === 'string' && error.message.trim() !== ''
          ? error.message.trim()
          : 'Nie udało się pobrać listy urządzeń Shelly.';
        shellyState.error = message;
        if (hadDevices) {
          setShellyError(message);
          markShellyListIdle();
        } else {
          renderShellyDevices();
        }
        updateShellyLastUpdate();
      });
  };

  const ensureShellyDataLoaded = (force = false) => {
    if (configError || !supportsFetch) {
      return;
    }

    loadShellyDevices(Boolean(force));
  };

  const stopShellyAutoRefresh = () => {
    if (shellyRefreshTimer !== null) {
      window.clearInterval(shellyRefreshTimer);
      shellyRefreshTimer = null;
    }
  };

  const startShellyAutoRefresh = () => {
    if (shellyRefreshTimer !== null) {
      return;
    }

    if (configError || !supportsFetch) {
      return;
    }

    if (typeof SHELLY_AUTO_REFRESH_INTERVAL !== 'number'
      || !Number.isFinite(SHELLY_AUTO_REFRESH_INTERVAL)
      || SHELLY_AUTO_REFRESH_INTERVAL <= 0
    ) {
      return;
    }

    if (typeof document.visibilityState === 'string'
      && document.visibilityState !== 'visible'
    ) {
      return;
    }

    shellyRefreshTimer = window.setInterval(() => {
      ensureShellyDataLoaded(false);
    }, SHELLY_AUTO_REFRESH_INTERVAL);
  };

  const toggleShellyDevice = (deviceId, action) => {
    if (configError) {
      setShellyError(SHELLY_CONFIG_ERROR_MESSAGE);
      setShellyMessage(SHELLY_CONFIG_ERROR_HINT);
      return;
    }

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

    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };

    const payload = {
      device: normalizedId,
      action: finalAction,
    };

    if (csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
      payload.csrfToken = csrfToken;
    }

    fetch(SHELLY_COMMAND_ENDPOINT, {
      method: 'POST',
      headers,
      cache: 'no-store',
      credentials: 'same-origin',
      body: JSON.stringify(payload),
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
      .then(() => {
        shellyState.pending.delete(normalizedId);
        ensureShellyDataLoaded(true);
      })
      .catch((error) => {
        shellyState.pending.delete(normalizedId);
        const message = error && typeof error.message === 'string' && error.message.trim() !== ''
          ? error.message.trim()
          : 'Nie udało się wysłać polecenia do Shelly.';
        setShellyError(message);
        renderShellyDevices();
      });
  };

  const handleShellyActionClick = (event) => {
    const target = event.target;
    if (!target || !(target instanceof HTMLElement)) {
      return;
    }

    if (target.dataset.role !== 'shelly-action') {
      return;
    }

    const deviceId = target.dataset.deviceId || '';
    let action = target.dataset.action || '';

    if (!deviceId) {
      return;
    }

    if (action !== 'on' && action !== 'off') {
      const device = shellyState.devices.find((item) => item.id === deviceId);
      if (device) {
        action = device.state === 'on' ? 'off' : 'on';
      }
    }

    if (action !== 'on' && action !== 'off') {
      return;
    }

    target.dataset.action = action;

    event.preventDefault();
    toggleShellyDevice(deviceId, action);
  };

  const handleTabActivation = (tabId) => {
    if (tabId === SHELLY_TAB_ID) {
      if (configError) {
        stopShellyAutoRefresh();
        setShellyError(SHELLY_CONFIG_ERROR_MESSAGE);
        setShellyMessage(SHELLY_CONFIG_ERROR_HINT);
        if (elements.shellyReload) {
          elements.shellyReload.disabled = true;
          elements.shellyReload.setAttribute('aria-disabled', 'true');
        }
        return;
      }

      if (!supportsFetch) {
        stopShellyAutoRefresh();
        setShellyError('Sterowanie Shelly wymaga przeglądarki obsługującej funkcję fetch.');
        setShellyMessage('Sterowanie Shelly wymaga nowszej przeglądarki.');
        if (elements.shellyReload) {
          elements.shellyReload.disabled = true;
          elements.shellyReload.setAttribute('aria-disabled', 'true');
        }
        return;
      }

      if (elements.shellyReload) {
        elements.shellyReload.disabled = false;
        elements.shellyReload.removeAttribute('aria-disabled');
      }

      ensureShellyDataLoaded(false);
      startShellyAutoRefresh();
    } else {
      stopShellyAutoRefresh();
    }
  };

  const handleVisibilityChange = (isVisible, isTabActive) => {
    if (!supportsFetch || configError) {
      return;
    }

    if (isVisible) {
      if (isTabActive) {
        ensureShellyDataLoaded(false);
        startShellyAutoRefresh();
      }
    } else {
      stopShellyAutoRefresh();
    }
  };

  const initialize = () => {
    renderShellyDevices();

    if (configError) {
      stopShellyAutoRefresh();
      setShellyError(SHELLY_CONFIG_ERROR_MESSAGE);
      setShellyMessage(SHELLY_CONFIG_ERROR_HINT);
      if (elements.shellyList) {
        elements.shellyList.innerHTML = '';
        elements.shellyList.classList.remove('is-loading');
        elements.shellyList.setAttribute('aria-busy', 'false');
      }
      if (elements.shellyReload) {
        elements.shellyReload.disabled = true;
        elements.shellyReload.setAttribute('aria-disabled', 'true');
      }
    } else {
      if (elements.shellyList) {
        elements.shellyList.addEventListener('click', handleShellyActionClick);
      }

      if (elements.shellyReload) {
        elements.shellyReload.addEventListener('click', () => {
          loadShellyDevices(true);
        });

        if (!supportsFetch) {
          elements.shellyReload.disabled = true;
          elements.shellyReload.setAttribute('aria-disabled', 'true');
        } else {
          elements.shellyReload.removeAttribute('aria-disabled');
        }
      }
    }
  };

  return {
    initialize,
    handleTabActivation,
    handleVisibilityChange,
    ensureShellyDataLoaded,
    startShellyAutoRefresh,
    stopShellyAutoRefresh,
    loadShellyDevices,
  };
}
