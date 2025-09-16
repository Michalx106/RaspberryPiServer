<?php
declare(strict_types=1);

$envUsername = getenv('APP_BASIC_AUTH_USER');
$envPassword = getenv('APP_BASIC_AUTH_PASSWORD');

$normalize = static function ($value): ?string {
    if ($value === false) {
        return null;
    }

    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : null;
};

return [
    'username' => $normalize($envUsername),
    'password' => $normalize($envPassword),
];
