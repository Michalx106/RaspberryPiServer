import { collectDomElements } from './domElements.js';
import { createThemeController } from './theme.js';
import { createTabsController } from './tabs.js';
import { createShellyController } from './shelly.js';
import { createHistoryController } from './history.js';
import { createStatusController } from './status.js';
import { parsePositiveInteger, supportsFetch } from './support.js';
import { SHELLY_TAB_ID } from './constants.js';

const elements = collectDomElements();
const bodyDataset = document.body && document.body.dataset ? document.body.dataset : {};
const csrfToken = typeof bodyDataset.csrfToken === 'string' ? bodyDataset.csrfToken : '';
const streamIntervalSeconds = parsePositiveInteger(bodyDataset.streamInterval ?? null);

const shellyPanel = elements.shellyPanel;
const shellyConfigError = shellyPanel && shellyPanel.getAttribute('data-shelly-config-error') === 'true';

const themeController = createThemeController(elements);
const historyController = createHistoryController(elements);
const shellyController = createShellyController(elements, {
  csrfToken,
  configError: shellyConfigError,
});

const statusController = createStatusController(elements, {
  streamIntervalSeconds,
  historyController,
});

const tabsController = createTabsController(elements, {
  onTabActivated: (tabId) => {
    shellyController.handleTabActivation(tabId);
  },
});

themeController.initialize();
historyController.initialize();
shellyController.initialize();
tabsController.initialize();
statusController.initialize();

if (supportsFetch) {
  historyController.loadHistory();
} else {
  historyController.setHistoryEnabled(false);
  if (elements.historyChartWrapper) {
    elements.historyChartWrapper.classList.remove('is-visible');
  }
  if (elements.historyEmpty) {
    elements.historyEmpty.textContent = 'Historia wymaga przeglądarki obsługującej funkcję fetch.';
    elements.historyEmpty.hidden = false;
  }
}

if (elements.statusNote && typeof streamIntervalSeconds === 'number') {
  elements.statusNote.textContent = `Dane odświeżają się automatycznie (interwał: ${streamIntervalSeconds} s). W przypadku problemów spróbuj kliknąć przycisk poniżej.`;
}

if (elements.refreshButton) {
  elements.refreshButton.addEventListener('click', () => {
    statusController.manualRefresh();
  });
}

if (elements.themeToggle) {
  elements.themeToggle.setAttribute('type', 'button');
}

document.addEventListener('visibilitychange', () => {
  const isVisible = document.visibilityState === 'visible';
  statusController.handleVisibilityChange(isVisible);
  const activeTab = tabsController.getCurrentTab();
  shellyController.handleVisibilityChange(isVisible, activeTab === SHELLY_TAB_ID);
});
