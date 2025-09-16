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
