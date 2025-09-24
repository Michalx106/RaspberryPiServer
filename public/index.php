<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/** @var array{username: string|null, password: string|null} $authConfig */
$authConfig = require __DIR__ . '/../config/auth.php';

/** @var array<string, string> $servicesToCheck */
$servicesToCheck = require __DIR__ . '/../config/services.php';

/**
 * @var array<string, array{label: string, host: string, relayId?: int, authKey?: string, username?: string, password?: string}> $shellyDevices
 */
$shellyDevices = [];
/** @var bool $shellyConfigError */
$shellyConfigError = false;

try {
    $shellyConfig = require __DIR__ . '/../config/shelly.php';

    if (!is_array($shellyConfig)) {
        $type = is_object($shellyConfig) ? get_class($shellyConfig) : gettype($shellyConfig);
        error_log(sprintf('[Shelly] Plik konfiguracji powinien zwracać tablicę urządzeń, otrzymano: %s', (string) $type));
        $shellyConfigError = true;
    } else {
        $shellyDevices = $shellyConfig;
    }
} catch (Throwable $exception) {
    error_log(sprintf('[Shelly] Błąd wczytywania konfiguracji: %s: %s', get_class($exception), $exception->getMessage()));
    $shellyConfigError = true;
}

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

if (handleStatusRequest($statusParam, $servicesToCheck, $shellyDevices, $shellyConfigError)) {
    return;
}

try {
    $csrfToken = generateSecureToken(32, '[CSRF]');
} catch (Throwable $exception) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'Internal Server Error';
    return;
}

$isHttps = (
    (isset($_SERVER['HTTPS']) && is_string($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    || (
        isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && is_string($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false
    )
);

setcookie('panel_csrf', $csrfToken, [
    'expires' => time() + 3600,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => false,
    'samesite' => 'Strict',
]);

$snapshot = collectStatusSnapshot($servicesToCheck);

$time = $snapshot['time'];
$cpuTemperature = $snapshot['cpuTemperature'];
$systemLoad = $snapshot['systemLoad'];
$uptime = $snapshot['uptime'];
$memoryUsage = $snapshot['memoryUsage'];
$diskUsage = $snapshot['diskUsage'];
$serviceStatuses = $snapshot['services'];

$streamInterval = getStatusStreamInterval();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Moja strona na Raspberry Pi</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body data-csrf-token="<?= h($csrfToken); ?>" data-stream-interval="<?= h((string) $streamInterval); ?>">
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
      <h3 data-role="history-title">Historia temperatury CPU</h3>
      <div class="history-metric-switch" data-role="history-metric-switch" role="group" aria-label="Wybór metryki historii">
        <button type="button" class="history-metric-button is-active" data-role="history-metric" data-metric="cpuTemperature" aria-pressed="true">Temperatura</button>
        <button type="button" class="history-metric-button" data-role="history-metric" data-metric="memoryUsage" aria-pressed="false">Pamięć</button>
        <button type="button" class="history-metric-button" data-role="history-metric" data-metric="diskUsage" aria-pressed="false">Dysk</button>
        <button type="button" class="history-metric-button" data-role="history-metric" data-metric="systemLoad" aria-pressed="false">Obciążenie</button>
      </div>
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

    <p class="status-note" data-role="status-note">
      Dane odświeżają się automatycznie. W przypadku problemów spróbuj kliknąć przycisk poniżej.
    </p>

    <div class="panel-footer">
      <div class="status-refresh" data-role="refresh-container">
        <span data-role="refresh-label">Ostatnie odświeżenie: <?= h($snapshot['generatedAt']); ?></span>
        <button type="button" data-role="refresh-button">Odśwież teraz</button>
      </div>
    </div>
  </section>

  <section class="shelly-panel tab-panel" data-role="tab-panel" data-tab-panel="shelly" id="panel-shelly" role="tabpanel" aria-labelledby="tab-shelly" data-shelly-config-error="<?= $shellyConfigError ? 'true' : 'false'; ?>" hidden>
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

  <script type="module" src="js/main.js"></script>
</body>
</html>
