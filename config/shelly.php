<?php
declare(strict_types=1);

/**
 * Konfiguracja urządzeń Shelly dostępnych w panelu.
 *
 * Host podaj jako pełny adres URL (np. http://192.168.0.10).
 * W razie potrzeby możesz ustawić zmienną środowiskową APP_SHELLY_<ID>_HOST,
 * aby nadpisać adres bez modyfikacji pliku (np. APP_SHELLY_BOILER_HOST).
 */

$readEnv = static function (string $key): ?string {
    $value = getenv($key);
    if ($value === false) {
        return null;
    }

    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : null;
};

$buildDevice = static function (string $id, string $label, string $defaultHost, int $relayId = 0) use ($readEnv): array {
    $upperId = strtoupper($id);
    $host = $readEnv('APP_SHELLY_' . $upperId . '_HOST') ?? $defaultHost;
    $host = trim($host);

    if ($host === '') {
        throw new InvalidArgumentException(sprintf('Brak hosta Shelly dla "%s". Ustaw go w config/shelly.php lub zmiennej APP_SHELLY_%s_HOST.', $id, $upperId));
    }

    if (!filter_var($host, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException(sprintf('Nieprawidłowy adres URL Shelly dla "%s": %s. Podaj pełny adres, np. http://192.168.0.10.', $id, $host));
    }

    $device = [
        'label' => $label,
        'host' => rtrim($host, '/'),
        'relayId' => $relayId,
    ];

    $authKey = $readEnv('APP_SHELLY_' . $upperId . '_AUTH_KEY');
    if ($authKey !== null) {
        $device['authKey'] = $authKey;
    }

    $username = $readEnv('APP_SHELLY_' . $upperId . '_USERNAME');
    $password = $readEnv('APP_SHELLY_' . $upperId . '_PASSWORD');
    if ($username !== null && $password !== null) {
        $device['username'] = $username;
        $device['password'] = $password;
    }

    return $device;
};

return [
    // Zmień adresy hostów na odpowiadające Twojej sieci lokalnej lub ustaw zmienne środowiskowe APP_SHELLY_<ID>_HOST.
    'boiler' => $buildDevice('boiler', 'Podgrzewacz wody', 'http://192.168.0.10'),
    'gate' => $buildDevice('gate', 'Brama wjazdowa', 'http://192.168.0.11'),
];
