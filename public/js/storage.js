export function readLocalStorage(key) {
  try {
    if (typeof window.localStorage === 'undefined') {
      return null;
    }
    const value = window.localStorage.getItem(key);
    return typeof value === 'string' && value.trim() !== '' ? value : null;
  } catch (error) {
    return null;
  }
}

export function writeLocalStorage(key, value) {
  try {
    if (typeof window.localStorage === 'undefined') {
      return;
    }
    window.localStorage.setItem(key, value);
  } catch (error) {
    // Ignorujemy błędy zapisu (np. tryb prywatny)
  }
}

export function removeLocalStorage(key) {
  try {
    if (typeof window.localStorage === 'undefined') {
      return;
    }
    window.localStorage.removeItem(key);
  } catch (error) {
    // Ignorujemy błędy usunięcia
  }
}
