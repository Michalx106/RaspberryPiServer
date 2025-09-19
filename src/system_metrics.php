<?php
declare(strict_types=1);

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
 * Formatuje liczbę bajtów do czytelnej postaci.
 */
function formatBytesForMetrics(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $unitIndex = 0;
    $value = max($bytes, 0.0);

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    $precision = $unitIndex === 0 ? 0 : 1;

    return number_format($value, $precision, '.', ' ') . ' ' . $units[$unitIndex];
}

/**
 * Odczytuje użycie pamięci RAM.
 */
function getMemoryUsage(): ?string
{
    $meminfoFile = '/proc/meminfo';
    if (!is_readable($meminfoFile)) {
        return null;
    }

    $lines = @file($meminfoFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return null;
    }

    $values = [];
    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $rawValue = trim($parts[1]);

        if ($key === '' || $rawValue === '') {
            continue;
        }

        if (preg_match('/^(\d+)\s*kB$/i', $rawValue, $matches)) {
            $values[$key] = (float) $matches[1] * 1024;
        }
    }

    if (!isset($values['MemTotal'])) {
        return null;
    }

    $total = $values['MemTotal'];
    $available = $values['MemAvailable'] ?? null;

    if ($available === null) {
        $free = $values['MemFree'] ?? 0.0;
        $buffers = $values['Buffers'] ?? 0.0;
        $cached = $values['Cached'] ?? 0.0;
        $available = $free + $buffers + $cached;
    }

    $used = max($total - $available, 0.0);
    $percentage = $total > 0 ? ($used / $total) * 100 : 0.0;

    return sprintf(
        '%s / %s (%s%%)',
        formatBytesForMetrics($used),
        formatBytesForMetrics($total),
        number_format($percentage, 1, '.', ' ')
    );
}

/**
 * Zwraca ścieżkę używaną do monitorowania zajętości dysku.
 */
function resolveDiskUsagePath(): string
{
    static $resolvedPath;

    if ($resolvedPath !== null) {
        return $resolvedPath;
    }

    $defaultPath = '/';
    $configured = getenv('APP_DISK_USAGE_PATH');

    if ($configured === false) {
        $resolvedPath = $defaultPath;

        return $resolvedPath;
    }

    $path = trim((string) $configured);

    if ($path === '' || strpos($path, "\0") !== false) {
        $resolvedPath = $defaultPath;

        return $resolvedPath;
    }

    if ($path[0] !== '/') {
        $resolvedPath = $defaultPath;

        return $resolvedPath;
    }

    $realPath = @realpath($path);
    if ($realPath !== false) {
        $path = $realPath;
    }

    if ($path !== '/' && @file_exists($path) === false) {
        $resolvedPath = $defaultPath;

        return $resolvedPath;
    }

    $resolvedPath = $path;

    return $resolvedPath;
}

/**
 * Odczytuje użycie miejsca na dysku.
 */
function getDiskUsage(?string $path = null): ?string
{
    $pathToCheck = $path;

    if ($pathToCheck === null) {
        $pathToCheck = resolveDiskUsagePath();
    } else {
        $pathToCheck = trim($pathToCheck);

        if ($pathToCheck === '' || strpos($pathToCheck, "\0") !== false) {
            $pathToCheck = resolveDiskUsagePath();
        }
    }

    $total = @disk_total_space($pathToCheck);
    $free = @disk_free_space($pathToCheck);

    if ($total === false || $free === false || $total <= 0) {
        return null;
    }

    $used = max($total - $free, 0.0);
    $percentage = ($used / $total) * 100;

    return sprintf(
        '%s / %s (%s%%)',
        formatBytesForMetrics($used),
        formatBytesForMetrics($total),
        number_format($percentage, 1, '.', ' ')
    );
}
