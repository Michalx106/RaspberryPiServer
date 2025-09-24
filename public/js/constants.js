export const STATUS_JSON_ENDPOINT = '?status=json';
export const STATUS_STREAM_ENDPOINT = '?status=stream';
export const STATUS_HISTORY_ENDPOINT = '?status=history';

export const STREAM_RECONNECT_DELAY = 5000;
export const HISTORY_DEFAULT_LIMIT = 360;
export const HISTORY_DEFAULT_METRIC = 'cpuTemperature';

export const SHELLY_LIST_ENDPOINT = '?shelly=list';
export const SHELLY_COMMAND_ENDPOINT = '?shelly=command';
export const SHELLY_AUTO_REFRESH_INTERVAL = 1000;

export const STATUS_TAB_ID = 'status';
export const SHELLY_TAB_ID = 'shelly';

export const TAB_STORAGE_KEY = 'dashboard-active-tab';
export const HISTORY_METRIC_STORAGE_KEY = 'dashboard-history-metric';
export const THEME_STORAGE_KEY = 'theme-preference';

export const SHELLY_CONFIG_ERROR_MESSAGE = 'Nie udało się wczytać konfiguracji urządzeń Shelly. Sprawdź ustawienia w pliku config/shelly.php.';
export const SHELLY_CONFIG_ERROR_HINT = 'Panel Shelly jest niedostępny do czasu poprawienia konfiguracji.';
