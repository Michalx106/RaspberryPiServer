<?php
declare(strict_types=1);

/**
 * Zbiera aktualny zestaw danych do prezentacji.
 *
 * @param array<string, string> $servicesToCheck
 * @return array{
 *     time: string,
 *     generatedAt: string,
 *     cpuTemperature: ?string,
 *     systemLoad: ?string,
 *     uptime: ?string,
 *     memoryUsage: ?string,
 *     diskUsage: ?string,
 *     services: array<int, array{label: string, service: string, status: string, class: string, details: string|null}>
 * }
 */
function collectStatusSnapshot(array $servicesToCheck): array
{
    $now = new DateTimeImmutable('now');

    $snapshot = [
        'time' => $now->format('H:i:s'),
        'generatedAt' => $now->format(DATE_ATOM),
        'cpuTemperature' => getCpuTemperature(),
        'systemLoad' => getSystemLoad(),
        'uptime' => getUptime(),
        'memoryUsage' => getMemoryUsage(),
        'diskUsage' => getDiskUsage(),
        'services' => collectServiceStatuses($servicesToCheck),
    ];

    saveStatusHistorySnapshot($snapshot);

    return $snapshot;
}

/**
 * Koduje dane stanu na potrzeby API.
 *
 * @return array{payload: string, hasError: bool}
 */
function encodeStatusSnapshot(array $snapshot): array
{
    $payload = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        $fallback = [
            'error' => json_last_error_msg() ?: 'Nie można zakodować danych stanu',
            'generatedAt' => date(DATE_ATOM),
        ];

        $fallbackPayload = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($fallbackPayload === false) {
            $fallbackPayload = "{\"error\":\"Nie można zakodować danych stanu\"}";
        }

        return [
            'payload' => $fallbackPayload,
            'hasError' => true,
        ];
    }

    return [
        'payload' => $payload,
        'hasError' => false,
    ];
}
