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
 * @return array{payload: array<string, mixed>, statusCode: int}
 */
function buildShellyListPayload(array $devices, bool $configError = false): array
{
    $payload = [
        'generatedAt' => date(DATE_ATOM),
        'count' => 0,
        'hasErrors' => false,
        'devices' => [],
        'configError' => $configError,
    ];

    if (!function_exists('curl_init')) {
        $payload['hasErrors'] = true;
        $payload['error'] = 'environment:missing_curl';
        $payload['message'] = 'Obsługa Shelly wymaga zainstalowania rozszerzenia PHP php-curl.';

        return [
            'payload' => $payload,
            'statusCode' => 500,
        ];
    }

    $normalizedDevices = [];

    foreach ($devices as $deviceId => $config) {
        if (!is_array($config)) {
            continue;
        }

        $deviceConfig = $config;
        $deviceConfig['id'] = (string) $deviceId;
        $normalizedDevices[(string) $deviceId] = $deviceConfig;
    }

    if (count($normalizedDevices) === 0) {
        return [
            'payload' => $payload,
            'statusCode' => 200,
        ];
    }

    $statuses = fetchShellyStatusesBatch($normalizedDevices);
    $items = [];
    $hasErrors = false;

    foreach ($normalizedDevices as $deviceId => $deviceConfig) {
        $status = $statuses[$deviceId] ?? buildShellyResponse(
            $deviceConfig,
            'unknown',
            'Nie udało się pobrać stanu urządzenia.',
            'status:missing_result',
            null
        );

        $items[] = [
            'id' => $deviceId,
            'label' => $deviceConfig['label'] ?? $deviceId,
            'state' => $status['state'],
            'description' => $status['description'],
            'error' => $status['error'],
            'ok' => $status['ok'],
        ];

        if (!$status['ok']) {
            $hasErrors = true;
        }
    }

    $payload['count'] = count($items);
    $payload['devices'] = $items;
    $payload['hasErrors'] = $hasErrors;

    return [
        'payload' => $payload,
        'statusCode' => 200,
    ];
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

    if (!ensureShellyRequestSecurity()) {
        return;
    }

    $result = buildShellyListPayload($devices);
    sendShellyJsonResponse($result['payload'], $result['statusCode']);
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

    $rawBody = (string) file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);

    if (!is_array($decoded)) {
        sendShellyJsonResponse([
            'error' => 'invalid_payload',
            'message' => 'Nieprawidłowe dane JSON.',
        ], 400);
        return;
    }

    if (!ensureShellyRequestSecurity($decoded)) {
        return;
    }

    if (!function_exists('curl_init')) {
        sendShellyJsonResponse([
            'error' => 'environment:missing_curl',
            'message' => 'Obsługa Shelly wymaga zainstalowania rozszerzenia PHP php-curl.',
        ], 500);
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

/**
 * @param array<mixed>|null $payload
 */
function ensureShellyRequestSecurity(?array $payload = null): bool
{
    if (!isShellyOriginAllowed()) {
        sendShellyJsonResponse([
            'error' => 'invalid_origin',
            'message' => 'Nieprawidłowy nagłówek Origin.',
        ], 403);

        return false;
    }

    $cookieToken = isset($_COOKIE['panel_csrf']) ? trim((string) $_COOKIE['panel_csrf']) : '';

    if ($cookieToken === '') {
        sendShellyJsonResponse([
            'error' => 'invalid_csrf_token',
            'message' => 'Brak tokenu CSRF. Odśwież stronę panelu.',
        ], 403);

        return false;
    }

    $providedTokens = [];

    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $headerToken = trim((string) $_SERVER['HTTP_X_CSRF_TOKEN']);
        if ($headerToken !== '') {
            $providedTokens[] = $headerToken;
        }
    }

    if ($payload !== null && isset($payload['csrfToken'])) {
        $payloadToken = trim((string) $payload['csrfToken']);
        if ($payloadToken !== '') {
            $providedTokens[] = $payloadToken;
        }
    }

    foreach ($providedTokens as $candidate) {
        if (hash_equals($cookieToken, $candidate)) {
            return true;
        }
    }

    sendShellyJsonResponse([
        'error' => 'invalid_csrf_token',
        'message' => 'Nieprawidłowy token CSRF. Odśwież stronę panelu.',
    ], 403);

    return false;
}

function isShellyOriginAllowed(): bool
{
    if (!isset($_SERVER['HTTP_ORIGIN'])) {
        return true;
    }

    $originRaw = $_SERVER['HTTP_ORIGIN'];
    if (!is_string($originRaw)) {
        return false;
    }

    $origin = strtolower(trim($originRaw));

    if ($origin === '') {
        return false;
    }

    $hosts = [];

    if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
        $hosts[] = strtolower(trim($_SERVER['HTTP_HOST']));
    }

    if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && is_string($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $forwardedHosts = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']);
        foreach ($forwardedHosts as $forwardedHost) {
            $normalized = strtolower(trim($forwardedHost));
            if ($normalized !== '') {
                $hosts[] = $normalized;
            }
        }
    }

    if (isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])) {
        $hosts[] = strtolower(trim($_SERVER['SERVER_NAME']));
    }

    $hosts = array_values(array_unique(array_filter($hosts, static function ($value): bool {
        return is_string($value) && $value !== '';
    })));

    if (count($hosts) === 0) {
        return true;
    }

    $schemes = [];

    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && is_string($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $protoParts = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO']);
        foreach ($protoParts as $part) {
            $normalized = strtolower(trim($part));
            if ($normalized === 'https' || $normalized === 'http') {
                $schemes[] = $normalized;
            }
        }
    }

    if (isset($_SERVER['HTTPS']) && is_string($_SERVER['HTTPS'])) {
        $httpsValue = strtolower(trim($_SERVER['HTTPS']));
        if ($httpsValue !== '' && $httpsValue !== 'off') {
            $schemes[] = 'https';
        }
    }

    if (count($schemes) === 0) {
        $schemes[] = 'http';
    }

    $schemes = array_values(array_unique($schemes));

    foreach ($hosts as $host) {
        foreach ($schemes as $scheme) {
            $candidate = sprintf('%s://%s', $scheme, $host);
            if (hash_equals($candidate, $origin)) {
                return true;
            }
        }
    }

    return false;
}
