<?php
declare(strict_types=1);

/**
 * Zwraca status usługi systemd.
 *
 * @return array{label: string, service: string, status: string, class: string, details: string|null}
 */
function getServiceStatus(string $service, string $label): array
{
    $output = [];
    $returnCode = 0;
    $command = sprintf('systemctl is-active %s 2>&1', escapeshellarg($service));
    @exec($command, $output, $returnCode);

    $firstLine = strtolower(trim($output[0] ?? ''));
    $fullOutput = trim(implode(' ', $output));

    $statusLabel = 'Nieznany';
    $cssClass = 'status-unknown';

    switch ($firstLine) {
        case 'active':
            $statusLabel = 'Aktywna';
            $cssClass = 'status-ok';
            break;
        case 'inactive':
            $statusLabel = 'Nieaktywna';
            $cssClass = 'status-off';
            break;
        case 'failed':
            $statusLabel = 'Błąd';
            $cssClass = 'status-error';
            break;
        case 'activating':
            $statusLabel = 'Uruchamianie';
            $cssClass = 'status-warn';
            break;
        case 'deactivating':
            $statusLabel = 'Zatrzymywanie';
            $cssClass = 'status-warn';
            break;
        default:
            if ($fullOutput !== '') {
                if (stripos($fullOutput, 'System has not been booted with systemd') !== false) {
                    $statusLabel = 'Brak systemd';
                    $cssClass = 'status-warn';
                } elseif (stripos($fullOutput, 'not found') !== false) {
                    $statusLabel = 'Nie znaleziono usługi';
                    $cssClass = 'status-off';
                } elseif (stripos($fullOutput, 'command not found') !== false) {
                    $statusLabel = 'Polecenie niedostępne';
                    $cssClass = 'status-warn';
                } else {
                    $statusLabel = $fullOutput;
                }
            }
            break;
    }

    return [
        'label' => $label,
        'service' => $service,
        'status' => $statusLabel,
        'class' => $cssClass,
        'details' => $fullOutput !== '' ? $fullOutput : null,
    ];
}

/**
 * Buduje listę statusów monitorowanych usług.
 *
 * @param array<string, string> $servicesToCheck
 * @return array<int, array{label: string, service: string, status: string, class: string, details: string|null}>
 */
function collectServiceStatuses(array $servicesToCheck): array
{
    $serviceStatuses = [];

    foreach ($servicesToCheck as $service => $label) {
        $serviceStatuses[] = getServiceStatus($service, $label);
    }

    return $serviceStatuses;
}
