import { THEME_STORAGE_KEY } from './constants.js';
import { readLocalStorage, writeLocalStorage } from './storage.js';

function updateThemeToggle(element, isDark) {
  if (!element) {
    return;
  }

  element.textContent = isDark ? 'Tryb ciemny włączony' : 'Włącz tryb ciemny';
  element.setAttribute('aria-pressed', isDark ? 'true' : 'false');
}

function applyThemeClass(isDark) {
  document.body.classList.toggle('theme-dark', isDark);
}

function readThemePreference() {
  const storedPreference = readLocalStorage(THEME_STORAGE_KEY);
  if (storedPreference === 'dark' || storedPreference === 'light') {
    return storedPreference === 'dark';
  }

  if (window.matchMedia && typeof window.matchMedia === 'function') {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');
    if (prefersDark && typeof prefersDark.matches === 'boolean') {
      return prefersDark.matches;
    }
  }

  return document.body.classList.contains('theme-dark');
}

export function createThemeController(elements) {
  const toggleElement = elements.themeToggle;

  const applyThemePreference = (isDark, persist = false) => {
    applyThemeClass(isDark);
    updateThemeToggle(toggleElement, isDark);
    if (persist) {
      writeLocalStorage(THEME_STORAGE_KEY, isDark ? 'dark' : 'light');
    }
  };

  const initialize = () => {
    const isDark = readThemePreference();
    applyThemePreference(isDark, false);

    if (toggleElement) {
      toggleElement.addEventListener('click', () => {
        const nextState = !document.body.classList.contains('theme-dark');
        applyThemePreference(nextState, true);
      });
    }
  };

  return {
    initialize,
    applyThemePreference,
  };
}
