<?php
declare(strict_types=1);

/**
 * @param array{id?: string, label?: string, host: string, relayId?: int, authKey?: string, username?: string, password?: string} $device
 * @return array{ok: bool, state: string, description: string, error: string|null, data: mixed}
 */
function fetchShellyStatus(array $device): array
{
    $relayId = isset($device['relayId']) ? (int) $device['relayId'] : 0;
    $result = performShellyRpcRequest($device, 'Switch.GetStatus', ['id' => $relayId], 'status');

    if (!$result['ok']) {
        $description = 'Nie udało się pobrać stanu urządzenia.';
        if ($result['error'] !== null) {
            $description .= ' (' . $result['error'] . ')';
        }

        return buildShellyResponse($device, 'unknown', $description, $result['error'], $result['data']);
    }

    $decoded = is_array($result['data']) ? $result['data'] : [];
    $isOn = extractShellyOutputState($decoded);

    if ($isOn === true) {
        return buildShellyResponse($device, 'on', 'Urządzenie jest włączone.', null, $decoded);
    }

    if ($isOn === false) {
        return buildShellyResponse($device, 'off', 'Urządzenie jest wyłączone.', null, $decoded);
    }

    return buildShellyResponse($device, 'unknown', 'Nie udało się ustalić stanu przekaźnika.', null, $decoded);
}

/**
 * @param array{id?: string, label?: string, host: string, relayId?: int, authKey?: string, username?: string, password?: string} $device
 * @return array{ok: bool, state: string, description: string, error: string|null, data: mixed}
 */
function setShellyRelayState(array $device, string $action): array
{
    $normalized = strtolower(trim($action));
    $relayId = isset($device['relayId']) ? (int) $device['relayId'] : 0;

    switch ($normalized) {
        case 'on':
            $payload = ['id' => $relayId, 'on' => true];
            $targetState = 'on';
            $successMessage = 'Przekaźnik został włączony.';
            break;
        case 'off':
            $payload = ['id' => $relayId, 'on' => false];
            $targetState = 'off';
            $successMessage = 'Przekaźnik został wyłączony.';
            break;
        case 'toggle':
            $payload = ['id' => $relayId, 'toggle' => true];
            $targetState = 'unknown';
            $successMessage = 'Przekaźnik został przełączony.';
            break;
        default:
            return buildShellyResponse($device, 'unknown', 'Nieznana akcja. Dozwolone wartości: on, off, toggle.', 'command:invalid_action', null);
    }

    $result = performShellyRpcRequest($device, 'Switch.Set', $payload, 'command');

    if (!$result['ok']) {
        $description = 'Nie udało się zmienić stanu przekaźnika.';
        if ($result['error'] !== null) {
            $description .= ' (' . $result['error'] . ')';
        }

        return buildShellyResponse($device, 'unknown', $description, $result['error'], $result['data']);
    }

    $decoded = is_array($result['data']) ? $result['data'] : [];
    $isOn = extractShellyOutputState($decoded);

    if ($isOn === true) {
        return buildShellyResponse($device, 'on', $successMessage, null, $decoded);
    }

    if ($isOn === false) {
        return buildShellyResponse($device, 'off', $successMessage, null, $decoded);
    }

    return buildShellyResponse($device, $targetState, $successMessage, null, $decoded);
}

/**
 * @param array{id?: string, label?: string, host?: string, relayId?: int, authKey?: string, username?: string, password?: string} $device
 * @return array{ok: bool, error: string|null, data: mixed}
 */
function performShellyRpcRequest(array $device, string $method, array $payload, string $context): array
{
    if (!isset($device['host'])) {
        return [
            'ok' => false,
            'error' => $context . ':missing_host',
            'data' => null,
        ];
    }

    $host = (string) $device['host'];

    if (!filter_var($host, FILTER_VALIDATE_URL)) {
        return [
            'ok' => false,
            'error' => $context . ':invalid_host',
            'data' => null,
        ];
    }

    $endpoint = rtrim($host, '/') . '/rpc/' . $method;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        return [
            'ok' => false,
            'error' => $context . ':json_encode_error',
            'data' => null,
        ];
    }

    $curl = curl_init($endpoint);
    if ($curl === false) {
        return [
            'ok' => false,
            'error' => $context . ':curl_init_error',
            'data' => null,
        ];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Connection: close',
    ];

    if (isset($device['authKey'])) {
        $headers[] = 'Authorization: Bearer ' . $device['authKey'];
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    if (isset($device['username'], $device['password'])) {
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $device['username'] . ':' . $device['password']);
    }

    $response = curl_exec($curl);

    if ($response === false) {
        $errorCode = curl_errno($curl);
        $errorMessage = curl_error($curl) ?: 'Nieznany błąd cURL';
        curl_close($curl);

        return [
            'ok' => false,
            'error' => sprintf('%s:curl_error:%d:%s', $context, $errorCode, $errorMessage),
            'data' => null,
        ];
    }

    $httpInfoOption = defined('CURLINFO_RESPONSE_CODE') ? CURLINFO_RESPONSE_CODE : CURLINFO_HTTP_CODE;
    $httpCode = curl_getinfo($curl, $httpInfoOption);
    curl_close($curl);

    if (!is_int($httpCode)) {
        $httpCode = 0;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'error' => sprintf('%s:http_error:%d', $context, $httpCode),
            'data' => $response,
        ];
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'ok' => false,
            'error' => sprintf('%s:json_decode_error:%s', $context, json_last_error_msg()),
            'data' => $response,
        ];
    }

    return [
        'ok' => true,
        'error' => null,
        'data' => $decoded,
    ];
}

/**
 * @param array{id?: string, label?: string} $device
 */
function buildShellyResponse(array $device, string $state, string $description, ?string $error, $data): array
{
    return [
        'ok' => $error === null,
        'state' => $state,
        'description' => $description,
        'error' => $error,
        'data' => $data,
        'device' => [
            'id' => isset($device['id']) ? (string) $device['id'] : null,
            'label' => buildShellyLabel($device),
        ],
    ];
}

/**
 * @param array{id?: string, label?: string} $device
 */
function buildShellyLabel(array $device): string
{
    if (isset($device['label']) && $device['label'] !== '') {
        return (string) $device['label'];
    }

    if (isset($device['id']) && $device['id'] !== '') {
        return (string) $device['id'];
    }

    return 'Shelly';
}

/**
 * @param array<mixed> $data
 */
function extractShellyOutputState(array $data): ?bool
{
    $candidates = [
        ['output'],
        ['on'],
        ['ison'],
        ['state'],
        ['switch:0', 'output'],
        ['switch:0', 'on'],
    ];

    foreach ($candidates as $path) {
        $value = $data;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $value = null;
                break;
            }
            $value = $value[$segment];
        }

        if ($value === null) {
            continue;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower($value);
            if (in_array($normalized, ['true', 'on', '1'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', 'off', '0'], true)) {
                return false;
            }
        }
    }

    return null;
}
