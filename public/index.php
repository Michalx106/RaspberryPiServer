<?php
// Prosty przykład dynamicznej strony rozbudowany o panel stanu urządzenia.

// Ustaw domyślną strefę czasową (możesz ją zmienić na własną).
date_default_timezone_set('Europe/Warsaw');

$time = date("H:i:s");

/**
 * Bezpieczne escapowanie ciągów znaków do HTML.
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Odczytuje temperaturę CPU Raspberry Pi (w stopniach Celsjusza).
 */
function getCpuTemperature(): ?string
{
    $thermalPaths = [
        '/sys/class/thermal/thermal_zone0/temp',
        '/sys/devices/virtual/thermal/thermal_zone0/temp',
    ];

    foreach ($thermalPaths as $path) {
        if (is_readable($path)) {
            $raw = trim((string) @file_get_contents($path));
            if ($raw !== '' && is_numeric($raw)) {
                $temperature = (float) $raw;
                if ($temperature > 1000) {
                    $temperature /= 1000;
                }

                return number_format($temperature, 1, '.', ' ') . ' °C';
            }
        }
    }

    $commandOutput = @shell_exec('vcgencmd measure_temp 2>/dev/null');
    if ($commandOutput && preg_match('/temp=([\d\.]+)/', $commandOutput, $matches)) {
        $temperature = (float) $matches[1];
        return number_format($temperature, 1, '.', ' ') . ' °C';
    }

    return null;
}

/**
 * Zwraca średnie obciążenie systemu (1, 5, 15 minut).
 */
function getSystemLoad(): ?string
{
    if (!function_exists('sys_getloadavg')) {
        return null;
    }

    $load = sys_getloadavg();
    if (!is_array($load) || count($load) < 3) {
        return null;
    }

    return sprintf('1 min: %.2f • 5 min: %.2f • 15 min: %.2f', $load[0], $load[1], $load[2]);
}

/**
 * Zamienia ilość sekund na prostą reprezentację tekstową.
 */
function formatDuration(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ' d';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' h';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' min';
    }
    if (empty($parts)) {
        $parts[] = 'mniej niż minuta';
    }

    return implode(' ', $parts);
}

/**
 * Odczytuje czas działania systemu.
 */
function getUptime(): ?string
{
    $output = @shell_exec('uptime -p 2>/dev/null');
    if ($output) {
        return trim($output);
    }

    $uptimeFile = '/proc/uptime';
    if (is_readable($uptimeFile)) {
        $contents = trim((string) @file_get_contents($uptimeFile));
        if ($contents !== '') {
            $parts = explode(' ', $contents);
            if (isset($parts[0]) && is_numeric($parts[0])) {
                return formatDuration((int) $parts[0]);
            }
        }
    }

    return null;
}

/**
 * Zwraca status usługi systemd.
 *
 * @return array{label: string, service: string, status: string, class: string, details: string|null}
 */
function getServiceStatus(string $service, string $label): array
{
    $output = [];
    $returnCode = 0;
    $command = sprintf('systemctl is-active %s 2>&1', escapeshellarg($service));
    @exec($command, $output, $returnCode);

    $firstLine = strtolower(trim($output[0] ?? ''));
    $fullOutput = trim(implode(' ', $output));

    $statusLabel = 'Nieznany';
    $cssClass = 'status-unknown';

    switch ($firstLine) {
        case 'active':
            $statusLabel = 'Aktywna';
            $cssClass = 'status-ok';
            break;
        case 'inactive':
            $statusLabel = 'Nieaktywna';
            $cssClass = 'status-off';
            break;
        case 'failed':
            $statusLabel = 'Błąd';
            $cssClass = 'status-error';
            break;
        case 'activating':
            $statusLabel = 'Uruchamianie';
            $cssClass = 'status-warn';
            break;
        case 'deactivating':
            $statusLabel = 'Zatrzymywanie';
            $cssClass = 'status-warn';
            break;
        default:
            if ($fullOutput !== '') {
                if (stripos($fullOutput, 'System has not been booted with systemd') !== false) {
                    $statusLabel = 'Brak systemd';
                    $cssClass = 'status-warn';
                } elseif (stripos($fullOutput, 'not found') !== false) {
                    $statusLabel = 'Nie znaleziono usługi';
                    $cssClass = 'status-off';
                } elseif (stripos($fullOutput, 'command not found') !== false) {
                    $statusLabel = 'Polecenie niedostępne';
                    $cssClass = 'status-warn';
                } else {
                    $statusLabel = $fullOutput;
                }
            }
            break;
    }

    return [
        'label' => $label,
        'service' => $service,
        'status' => $statusLabel,
        'class' => $cssClass,
        'details' => $fullOutput !== '' ? $fullOutput : null,
    ];
}

$cpuTemperature = getCpuTemperature();
$systemLoad = getSystemLoad();
$uptime = getUptime();

$servicesToCheck = [
    'ssh' => 'SSH',
    'nginx' => 'Nginx',
    'php-fpm' => 'PHP-FPM',
];

$serviceStatuses = [];
foreach ($servicesToCheck as $service => $label) {
    $serviceStatuses[] = getServiceStatus($service, $label);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Moja strona na Raspberry Pi</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      max-width: 900px;
      margin: 40px auto;
      background: #e9eef5;
      padding: 20px;
      border-radius: 12px;
      box-shadow: 0 0 12px rgba(0, 0, 0, 0.2);
    }
    h1 {
      color: #0066cc;
    }
    p {
      font-size: 18px;
    }
    footer {
      margin-top: 40px;
      font-size: 14px;
      color: #666;
    }
    .status-panel {
      margin-top: 32px;
      background: #ffffff;
      border-radius: 12px;
      padding: 24px;
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
    }
    .status-panel h2 {
      margin-top: 0;
      margin-bottom: 16px;
      color: #0b5394;
    }
    .metrics {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .metric {
      background: #f0f6ff;
      border-radius: 10px;
      padding: 16px;
    }
    .metric-label {
      display: block;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #5a6a85;
      margin-bottom: 8px;
    }
    .metric-value {
      font-size: 22px;
      font-weight: bold;
      color: #003d80;
    }
    .service-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    .service-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #f7f8fa;
      border-radius: 10px;
      padding: 12px 16px;
      margin-bottom: 12px;
      transition: transform 0.1s ease;
    }
    .service-item:hover {
      transform: translateX(2px);
    }
    .service-name {
      font-weight: 600;
      color: #2b3d55;
    }
    .service-name small {
      display: block;
      font-size: 12px;
      font-weight: normal;
      color: #7a879c;
    }
    .service-status {
      font-weight: 600;
    }
    .status-ok {
      border-left: 4px solid #2c974b;
    }
    .status-ok .service-status {
      color: #2c974b;
    }
    .status-off {
      border-left: 4px solid #8a9099;
    }
    .status-off .service-status {
      color: #636b78;
    }
    .status-error {
      border-left: 4px solid #d93025;
    }
    .status-error .service-status {
      color: #d93025;
    }
    .status-warn {
      border-left: 4px solid #f6c026;
    }
    .status-warn .service-status {
      color: #b8860b;
    }
    .status-unknown {
      border-left: 4px solid #5f6368;
    }
    .status-unknown .service-status {
      color: #5f6368;
    }
    .status-note {
      margin-top: 16px;
      font-size: 14px;
      color: #6b7688;
    }
  </style>
</head>
<body>
  <h1>Witaj na mojej stronie! 🎉</h1>
  <p>Ta strona działa na <strong>Raspberry Pi + Nginx + PHP</strong>.</p>
  <p>Aktualny czas serwera to: <strong><?= h($time); ?></strong></p>

  <section class="status-panel">
    <h2>Panel stanu Raspberry Pi</h2>
    <div class="metrics">
      <div class="metric">
        <span class="metric-label">Temperatura CPU</span>
        <span class="metric-value"><?= h($cpuTemperature ?? 'Brak danych'); ?></span>
      </div>
      <div class="metric">
        <span class="metric-label">Obciążenie systemu</span>
        <span class="metric-value"><?= h($systemLoad ?? 'Brak danych'); ?></span>
      </div>
      <div class="metric">
        <span class="metric-label">Czas działania</span>
        <span class="metric-value"><?= h($uptime ?? 'Brak danych'); ?></span>
      </div>
    </div>

    <h3>Status usług</h3>
    <ul class="service-list">
      <?php foreach ($serviceStatuses as $service): ?>
        <li class="service-item <?= h($service['class']); ?>"<?php if (!empty($service['details'])): ?> title="<?= h($service['details']); ?>"<?php endif; ?>>
          <span class="service-name">
            <?= h($service['label']); ?>
            <small><?= h($service['service']); ?></small>
          </span>
          <span class="service-status"><?= h($service['status']); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>

    <p class="status-note">Dostosuj listę monitorowanych usług w tablicy <code>$servicesToCheck</code>, aby dopasować panel do swojej instalacji.</p>
  </section>

  <footer>
    &copy; <?= h(date("Y")); ?> Michał Grzesiewicz
  </footer>
</body>
</html>
