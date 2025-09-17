<?php
declare(strict_types=1);

/**
 * Obsługuje endpointy Shelly.
 *
 * @param array<string, array{label: string, host: string, relayId?: int, authKey?: string, username?: string, password?: string}> $devices
 */
function handleShellyRequest(array $devices): bool
{
    $paramRaw = $_GET['shelly'] ?? null;
    if (!is_string($paramRaw) || $paramRaw === '') {
        return false;
    }

    $param = strtolower($paramRaw);

    switch ($param) {
        case 'list':
            respondWithShellyList($devices);
            return true;
        case 'command':
            respondWithShellyCommand($devices);
            return true;
        default:
            sendShellyJsonResponse([
                'error' => 'unknown_endpoint',
                'message' => 'Nieznany typ żądania Shelly.',
            ], 404);
            return true;
    }
}

/**
 * @param array<string, array{label: string, host: string, relayId?: int, authKey?: string, username?: string, password?: string}> $devices
 */
function respondWithShellyList(array $devices): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (strtoupper($method) !== 'GET') {
        header('Allow: GET');
        sendShellyJsonResponse([
            'error' => 'method_not_allowed',
            'message' => 'Ten endpoint obsługuje wyłącznie zapytania GET.',
        ], 405);
        return;
    }

    if (!function_exists('curl_init')) {
        sendShellyJsonResponse([
            'generatedAt' => date(DATE_ATOM),
            'count' => 0,
            'hasErrors' => true,
            'error' => 'environment:missing_curl',
            'message' => 'Obsługa Shelly wymaga zainstalowania rozszerzenia PHP php-curl.',
            'devices' => [],
        ], 500);
        return;
    }

    $items = [];
    $hasErrors = false;

    foreach ($devices as $deviceId => $config) {
        if (!is_array($config)) {
            continue;
        }

        $deviceConfig = $config;
        $deviceConfig['id'] = (string) $deviceId;

        $status = fetchShellyStatus($deviceConfig);
        $items[] = [
            'id' => (string) $deviceId,
            'label' => $deviceConfig['label'] ?? (string) $deviceId,
            'state' => $status['state'],
            'description' => $status['description'],
            'error' => $status['error'],
            'ok' => $status['ok'],
        ];

        if (!$status['ok']) {
            $hasErrors = true;
        }
    }

    sendShellyJsonResponse([
        'generatedAt' => date(DATE_ATOM),
        'count' => count($items),
        'hasErrors' => $hasErrors,
        'devices' => $items,
    ]);
}

/**
 * @param array<string, array{label: string, host: string, relayId?: int, authKey?: string, username?: string, password?: string}> $devices
 */
function respondWithShellyCommand(array $devices): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (strtoupper($method) !== 'POST') {
        header('Allow: POST');
        sendShellyJsonResponse([
            'error' => 'method_not_allowed',
            'message' => 'Ten endpoint obsługuje wyłącznie zapytania POST.',
        ], 405);
        return;
    }

    if (!function_exists('curl_init')) {
        sendShellyJsonResponse([
            'error' => 'environment:missing_curl',
            'message' => 'Obsługa Shelly wymaga zainstalowania rozszerzenia PHP php-curl.',
        ], 500);
        return;
    }

    $rawBody = (string) file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);

    if (!is_array($decoded)) {
        sendShellyJsonResponse([
            'error' => 'invalid_payload',
            'message' => 'Nieprawidłowe dane JSON.',
        ], 400);
        return;
    }

    $deviceId = isset($decoded['device']) ? trim((string) $decoded['device']) : '';
    $action = isset($decoded['action']) ? trim((string) $decoded['action']) : '';

    if ($deviceId === '' || $action === '') {
        sendShellyJsonResponse([
            'error' => 'missing_parameters',
            'message' => 'Wymagane pola: device, action.',
        ], 400);
        return;
    }

    if (!isset($devices[$deviceId]) || !is_array($devices[$deviceId])) {
        sendShellyJsonResponse([
            'error' => 'device_not_found',
            'message' => 'Nie znaleziono urządzenia o podanym identyfikatorze.',
        ], 404);
        return;
    }

    $deviceConfig = $devices[$deviceId];
    $deviceConfig['id'] = $deviceId;

    $result = setShellyRelayState($deviceConfig, $action);

    if (!$result['ok']) {
        $statusCode = 502;
        $errorCode = $result['error'] ?? 'command:unknown_error';

        if (
            $errorCode === 'command:invalid_action'
            || strpos($errorCode, 'command:invalid_host') === 0
            || strpos($errorCode, 'command:missing_host') === 0
        ) {
            $statusCode = 400;
        }

        sendShellyJsonResponse([
            'device' => [
                'id' => $deviceId,
                'label' => $deviceConfig['label'] ?? $deviceId,
            ],
            'requestedAction' => $action,
            'state' => $result['state'],
            'description' => $result['description'],
            'error' => $errorCode,
        ], $statusCode);
        return;
    }

    sendShellyJsonResponse([
        'device' => [
            'id' => $deviceId,
            'label' => $deviceConfig['label'] ?? $deviceId,
        ],
        'requestedAction' => $action,
        'state' => $result['state'],
        'description' => $result['description'],
        'error' => null,
    ]);
}

/**
 * @param array<mixed> $payload
 */
function sendShellyJsonResponse(array $payload, int $statusCode = 200): void
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        $statusCode = 500;
        $encoded = json_encode([
            'error' => 'encoding_error',
            'message' => 'Nie udało się zakodować odpowiedzi.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"error":"encoding_error"}';
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    http_response_code($statusCode);

    echo $encoded;
}
