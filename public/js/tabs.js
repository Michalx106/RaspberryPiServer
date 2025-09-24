import { STATUS_TAB_ID, TAB_STORAGE_KEY } from './constants.js';
import { readLocalStorage, writeLocalStorage } from './storage.js';

export function createTabsController(elements, options = {}) {
  const tabButtons = Array.from(elements.tabButtons || []);
  const tabPanels = Array.from(elements.tabPanels || []);
  const { onTabActivated } = options;
  let currentTab = STATUS_TAB_ID;

  const setActiveTab = (tabId, { persist = false, fromUser = false, notify = true } = {}) => {
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
      return setActiveTab(STATUS_TAB_ID, { persist, fromUser, notify });
    }

    const finalTab = found ? normalized : STATUS_TAB_ID;
    const changed = currentTab !== finalTab;
    currentTab = finalTab;

    if (persist) {
      writeLocalStorage(TAB_STORAGE_KEY, finalTab);
    }

    if (notify && typeof onTabActivated === 'function') {
      onTabActivated(finalTab, fromUser);
    }

    return finalTab;
  };

  const initialize = () => {
    if (tabButtons.length === 0) {
      return;
    }

    tabButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const target = button.getAttribute('data-tab');
        if (!target) {
          return;
        }
        setActiveTab(target, { persist: true, fromUser: true });
      });
    });

    const stored = readLocalStorage(TAB_STORAGE_KEY);
    setActiveTab(stored || STATUS_TAB_ID, { notify: true, fromUser: false });
  };

  return {
    initialize,
    getCurrentTab: () => currentTab,
    setActiveTab,
  };
}
