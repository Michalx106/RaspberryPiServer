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
