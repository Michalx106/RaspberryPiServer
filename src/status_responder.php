<?php
declare(strict_types=1);

/**
 * Obsługuje parametry zapytań statusu.
 *
 * @param array<string, string> $servicesToCheck
 */
function handleStatusRequest(?string $statusParam, array $servicesToCheck): bool
{
    switch ($statusParam) {
        case 'json':
            respondWithStatusJson($servicesToCheck);
            return true;
        case 'stream':
            streamStatus($servicesToCheck);
            return true;
        case 'history':
            respondWithStatusHistory();
            return true;
        default:
            return false;
    }
}

/**
 * Wysyła pojedynczą odpowiedź JSON ze stanem.
 *
 * @param array<string, string> $servicesToCheck
 */
function respondWithStatusJson(array $servicesToCheck): void
{
    $snapshot = collectStatusSnapshot($servicesToCheck);
    $encoding = encodeStatusSnapshot($snapshot);
    $payload = $encoding['payload'];
    $hasError = $encoding['hasError'];

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($hasError) {
        http_response_code(500);
    }

    echo $payload;
}

/**
 * Strumieniuje stan w formacie Server-Sent Events.
 *
 * @param array<string, string> $servicesToCheck
 */
function streamStatus(array $servicesToCheck): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }

    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }

    echo "retry: 5000\n\n";
    @ob_flush();
    @flush();

    $streamInterval = 1;

    while (true) {
        if (function_exists('connection_aborted') && connection_aborted()) {
            break;
        }

        $snapshot = collectStatusSnapshot($servicesToCheck);
        $encoding = encodeStatusSnapshot($snapshot);
        $payload = $encoding['payload'];

        echo "event: status\n";
        echo "data: {$payload}\n\n";

        @ob_flush();
        @flush();

        if ($streamInterval > 0) {
            sleep($streamInterval);
        }
    }
}

/**
 * Zwraca historię snapshotów w formacie JSON.
 */
function respondWithStatusHistory(): void
{
    $config = getStatusHistoryConfig();

    $limit = null;
    $limitParam = $_GET['limit'] ?? null;
    if (is_string($limitParam) && is_numeric($limitParam)) {
        $limitValue = (int) $limitParam;
        if ($limitValue > 0) {
            $limit = $limitValue;
        }
    }

    $entries = $config['enabled'] ? loadStatusHistory($limit) : [];

    $payloadData = [
        'generatedAt' => date(DATE_ATOM),
        'enabled' => $config['enabled'],
        'maxEntries' => $config['maxEntries'],
        'maxAge' => $config['maxAge'],
        'count' => count($entries),
        'entries' => $entries,
    ];

    if ($limit !== null) {
        $payloadData['limit'] = $limit;
    }

    $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hasError = false;

    if ($payload === false) {
        $payload = json_encode([
            'error' => json_last_error_msg() ?: 'Nie można zakodować danych historii',
            'generatedAt' => date(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            $payload = '{"error":"Nie można zakodować danych historii"}';
        }

        $hasError = true;
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($hasError) {
        http_response_code(500);
    }

    echo $payload;
}
