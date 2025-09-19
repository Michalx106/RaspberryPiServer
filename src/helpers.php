<?php
declare(strict_types=1);

/**
 * Bezpieczne escapowanie ciągów znaków do HTML.
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
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
 * Generates a cryptographically secure random token encoded as hexadecimal string.
 *
 * @throws RuntimeException When no secure random source is available.
 */
function generateSecureToken(int $lengthBytes = 32, string $logPrefix = '[Token]'): string
{
    if ($lengthBytes <= 0) {
        throw new InvalidArgumentException('Token length must be a positive integer.');
    }

    $logPrefix = trim($logPrefix);
    if ($logPrefix === '') {
        $logPrefix = '[Token]';
    }
    if (substr($logPrefix, -1) !== ' ') {
        $logPrefix .= ' ';
    }

    try {
        return bin2hex(random_bytes($lengthBytes));
    } catch (Throwable $randomBytesException) {
        error_log(sprintf(
            '%sNie udało się wygenerować tokenu przy użyciu random_bytes(): %s: %s',
            $logPrefix,
            get_class($randomBytesException),
            $randomBytesException->getMessage()
        ));
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        try {
            $opensslBytes = openssl_random_pseudo_bytes($lengthBytes, $strong);
        } catch (Throwable $opensslException) {
            error_log(sprintf(
                '%sNie udało się wygenerować tokenu przy użyciu openssl_random_pseudo_bytes(): %s: %s',
                $logPrefix,
                get_class($opensslException),
                $opensslException->getMessage()
            ));
            $opensslBytes = false;
        }

        if ($opensslBytes !== false) {
            if ($strong) {
                return bin2hex($opensslBytes);
            }

            error_log(sprintf(
                '%sopenssl_random_pseudo_bytes() zwróciło bajty, które nie są kryptograficznie silne.',
                $logPrefix
            ));
        }
    } else {
        error_log(sprintf('%sFunkcja openssl_random_pseudo_bytes() jest niedostępna.', $logPrefix));
    }

    error_log(sprintf('%sBrak kryptograficznie silnego generatora losowego.', $logPrefix));

    throw new RuntimeException('Brak kryptograficznie silnego generatora losowego.');
}
