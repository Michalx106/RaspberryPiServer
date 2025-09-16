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
