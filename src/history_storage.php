<?php
declare(strict_types=1);

/**
 * Zwraca konfigurację modułu historii stanu.
 *
 * @return array{
 *     enabled: bool,
 *     path: string,
 *     maxEntries: int,
 *     maxAge: int|null,
 *     minInterval: int,
 * }
 */
function getStatusHistoryConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $defaultPath = __DIR__ . '/../var/status-history.json';

    $configuredPath = getenv('APP_HISTORY_PATH');
    if (is_string($configuredPath) && trim($configuredPath) !== '') {
        $path = trim($configuredPath);
    } else {
        $path = $defaultPath;
    }

    $maxEntriesEnv = getenv('APP_HISTORY_MAX_ENTRIES');
    $maxEntries = is_string($maxEntriesEnv) && is_numeric($maxEntriesEnv)
        ? max((int) $maxEntriesEnv, 0)
        : 360;

    $minIntervalEnv = getenv('APP_HISTORY_MIN_INTERVAL');
    $minInterval = 60;
    if (is_string($minIntervalEnv) && is_numeric($minIntervalEnv)) {
        $minIntervalValue = (int) $minIntervalEnv;
        if ($minIntervalValue >= 0) {
            $minInterval = $minIntervalValue;
        }
    }

    $maxAgeEnv = getenv('APP_HISTORY_MAX_AGE');
    $maxAge = null;
    if (is_string($maxAgeEnv) && is_numeric($maxAgeEnv)) {
        $maxAgeValue = (int) $maxAgeEnv;
        if ($maxAgeValue > 0) {
            $maxAge = $maxAgeValue;
        }
    }

    $config = [
        'enabled' => $maxEntries > 0,
        'path' => $path,
        'maxEntries' => $maxEntries,
        'maxAge' => $maxAge,
        'minInterval' => $minInterval,
    ];

    return $config;
}

/**
 * Zapisuje nowy snapshot do historii, dbając o rotację danych.
 *
 * @param array<string, mixed> $snapshot
 */
function saveStatusHistorySnapshot(array $snapshot): void
{
    $config = getStatusHistoryConfig();
    if (!$config['enabled']) {
        return;
    }

    $entry = transformSnapshotToHistoryEntry($snapshot);

    $path = $config['path'];
    $directory = dirname($path);
    if (!is_dir($directory)) {
        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        $contents = '';
        $stats = @fstat($handle);
        if ($stats !== false && isset($stats['size']) && (int) $stats['size'] > 0) {
            rewind($handle);
            $contents = (string) stream_get_contents($handle);
        }

        $history = [];
        if ($contents !== '') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (is_array($row)) {
                        $history[] = normaliseHistoryEntryStructure($row);
                    }
                }
            }
        }

        $historyCount = count($history);
        $minInterval = max((int) $config['minInterval'], 0);
        $historyChanged = false;

        if ($historyCount > 0) {
            $lastEntry = $history[$historyCount - 1];
            $replacementReason = null;

            if (isset($lastEntry['generatedAt'], $entry['generatedAt'])
                && $lastEntry['generatedAt'] === $entry['generatedAt']
            ) {
                $replacementReason = 'generatedAt';
            } elseif ($minInterval > 0) {
                $lastTimestamp = historyEntryTimestamp($lastEntry);
                $currentTimestamp = historyEntryTimestamp($entry);

                if ($lastTimestamp !== null && $currentTimestamp !== null
                    && ($currentTimestamp - $lastTimestamp) < $minInterval
                ) {
                    $replacementReason = 'minInterval';
                }
            }

            if ($replacementReason === 'generatedAt') {
                if ($lastEntry !== $entry) {
                    $history[$historyCount - 1] = $entry;
                    $historyChanged = true;
                }
            } elseif ($replacementReason === 'minInterval') {
                return;
            } else {
                $history[] = $entry;
                $historyChanged = true;
            }
        } else {
            $history[] = $entry;
            $historyChanged = true;
        }

        $historyBeforeRotation = $history;
        $history = applyHistoryRotation($history, $config['maxEntries'], $config['maxAge']);
        $rotationOccurred = $history !== $historyBeforeRotation;

        if (!$historyChanged && !$rotationOccurred) {
            return;
        }

        $encoded = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded);
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Zwraca wpisy historii do prezentacji w API.
 *
 * @return array<int, array{
 *     generatedAt: string|null,
 *     time: string|null,
 *     cpuTemperature: array{value: float|null, label: string|null},
 *     memoryUsage: array{percentage: float|null, label: string|null},
 *     diskUsage: array{percentage: float|null, label: string|null},
 *     systemLoad: array{one: float|null, five: float|null, fifteen: float|null, label: string|null}
 * }>
 */
function loadStatusHistory(?int $limit = null): array
{
    $config = getStatusHistoryConfig();

    $path = $config['path'];
    if (!is_readable($path)) {
        return [];
    }

    $contents = @file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    $entries = [];
    foreach ($decoded as $row) {
        if (is_array($row)) {
            $entries[] = normaliseHistoryEntryStructure($row);
        }
    }

    if ($limit !== null && $limit > 0 && count($entries) > $limit) {
        $entries = array_slice($entries, -$limit);
    }

    return $entries;
}

/**
 * Przekształca snapshot do struktury przechowywanej w historii.
 *
 * @param array<string, mixed> $snapshot
 * @return array{
 *     generatedAt: string|null,
 *     time: string|null,
 *     cpuTemperature: array{value: float|null, label: string|null},
 *     memoryUsage: array{percentage: float|null, label: string|null},
 *     diskUsage: array{percentage: float|null, label: string|null},
 *     systemLoad: array{one: float|null, five: float|null, fifteen: float|null, label: string|null}
 * }
 */
function transformSnapshotToHistoryEntry(array $snapshot): array
{
    $generatedAt = isset($snapshot['generatedAt']) && is_string($snapshot['generatedAt'])
        ? $snapshot['generatedAt']
        : date(DATE_ATOM);

    $timeLabel = isset($snapshot['time']) && is_string($snapshot['time'])
        ? $snapshot['time']
        : null;

    $cpuLabel = isset($snapshot['cpuTemperature']) && is_string($snapshot['cpuTemperature'])
        ? $snapshot['cpuTemperature']
        : null;
    $cpuValue = parseTemperatureValue($cpuLabel);

    $memoryLabel = isset($snapshot['memoryUsage']) && is_string($snapshot['memoryUsage'])
        ? $snapshot['memoryUsage']
        : null;
    $memoryPercentage = parsePercentageValue($memoryLabel);

    $diskLabel = isset($snapshot['diskUsage']) && is_string($snapshot['diskUsage'])
        ? $snapshot['diskUsage']
        : null;
    $diskPercentage = parsePercentageValue($diskLabel);

    $systemLoadLabel = isset($snapshot['systemLoad']) && is_string($snapshot['systemLoad'])
        ? $snapshot['systemLoad']
        : null;
    $systemLoadValues = parseSystemLoadValues($systemLoadLabel);

    return [
        'generatedAt' => $generatedAt,
        'time' => $timeLabel,
        'cpuTemperature' => [
            'value' => $cpuValue,
            'label' => $cpuLabel,
        ],
        'memoryUsage' => [
            'percentage' => $memoryPercentage,
            'label' => $memoryLabel,
        ],
        'diskUsage' => [
            'percentage' => $diskPercentage,
            'label' => $diskLabel,
        ],
        'systemLoad' => [
            'one' => $systemLoadValues['one'],
            'five' => $systemLoadValues['five'],
            'fifteen' => $systemLoadValues['fifteen'],
            'label' => $systemLoadLabel,
        ],
    ];
}
/**
 * Normalizuje strukturę wpisu historii.
 *
 * @param array<string, mixed> $entry
 * @return array{
 *     generatedAt: string|null,
 *     time: string|null,
 *     cpuTemperature: array{value: float|null, label: string|null},
 *     memoryUsage: array{percentage: float|null, label: string|null},
 *     diskUsage: array{percentage: float|null, label: string|null},
 *     systemLoad: array{one: float|null, five: float|null, fifteen: float|null, label: string|null}
 * }
 */
function normaliseHistoryEntryStructure(array $entry): array
{
    $generatedAt = isset($entry['generatedAt']) && is_string($entry['generatedAt'])
        ? $entry['generatedAt']
        : null;

    $timeLabel = isset($entry['time']) && is_string($entry['time'])
        ? $entry['time']
        : null;

    $cpuTemperature = extractTemperatureMetric($entry);
    $memoryUsage = extractPercentageMetric($entry, 'memoryUsage');
    $diskUsage = extractPercentageMetric($entry, 'diskUsage');
    $systemLoad = extractSystemLoadMetric($entry);

    return [
        'generatedAt' => $generatedAt,
        'time' => $timeLabel,
        'cpuTemperature' => $cpuTemperature,
        'memoryUsage' => $memoryUsage,
        'diskUsage' => $diskUsage,
        'systemLoad' => $systemLoad,
    ];
}

/**
 * @param array<string, mixed> $entry
 * @return array{value: float|null, label: string|null}
 */
function extractTemperatureMetric(array $entry): array
{
    $label = null;
    $value = null;

    $raw = $entry['cpuTemperature'] ?? null;
    if (is_array($raw)) {
        if (isset($raw['label']) && is_string($raw['label'])) {
            $label = $raw['label'];
        }
        if (isset($raw['value']) && is_numeric($raw['value'])) {
            $value = (float) $raw['value'];
        }
    } elseif (is_string($raw)) {
        $label = $raw;
    }

    if ($label === null && isset($entry['cpuTemperatureLabel']) && is_string($entry['cpuTemperatureLabel'])) {
        $label = $entry['cpuTemperatureLabel'];
    }
    if ($value === null && isset($entry['cpuTemperatureValue']) && is_numeric($entry['cpuTemperatureValue'])) {
        $value = (float) $entry['cpuTemperatureValue'];
    }
    if ($value === null && $label !== null) {
        $value = parseTemperatureValue($label);
    }
    if ($label === null && $value !== null) {
        $label = number_format($value, 1, '.', ' ') . ' °C';
    }

    return [
        'value' => $value,
        'label' => $label,
    ];
}

/**
 * @param array<string, mixed> $entry
 * @param string $key
 * @return array{percentage: float|null, label: string|null}
 */
function extractPercentageMetric(array $entry, string $key): array
{
    $label = null;
    $value = null;

    $raw = $entry[$key] ?? null;
    if (is_array($raw)) {
        if (isset($raw['label']) && is_string($raw['label'])) {
            $label = $raw['label'];
        }
        if (isset($raw['percentage']) && is_numeric($raw['percentage'])) {
            $value = (float) $raw['percentage'];
        }
    } elseif (is_string($raw)) {
        $label = $raw;
    }

    $labelKey = $key . 'Label';
    $valueKey = $key . 'Percentage';

    if ($label === null && isset($entry[$labelKey]) && is_string($entry[$labelKey])) {
        $label = $entry[$labelKey];
    }
    if ($value === null && isset($entry[$valueKey]) && is_numeric($entry[$valueKey])) {
        $value = (float) $entry[$valueKey];
    }
    if ($value === null && $label !== null) {
        $value = parsePercentageValue($label);
    }

    return [
        'percentage' => $value,
        'label' => $label,
    ];
}

/**
 * @param array<string, mixed> $entry
 * @return array{one: float|null, five: float|null, fifteen: float|null, label: string|null}
 */
function extractSystemLoadMetric(array $entry): array
{
    $label = null;
    $one = null;
    $five = null;
    $fifteen = null;

    $raw = $entry['systemLoad'] ?? null;
    if (is_array($raw)) {
        if (isset($raw['label']) && is_string($raw['label'])) {
            $label = $raw['label'];
        }
        if (isset($raw['one']) && is_numeric($raw['one'])) {
            $one = (float) $raw['one'];
        }
        if (isset($raw['five']) && is_numeric($raw['five'])) {
            $five = (float) $raw['five'];
        }
        if (isset($raw['fifteen']) && is_numeric($raw['fifteen'])) {
            $fifteen = (float) $raw['fifteen'];
        }
    } elseif (is_string($raw)) {
        $label = $raw;
    }

    if ($label === null && isset($entry['systemLoadLabel']) && is_string($entry['systemLoadLabel'])) {
        $label = $entry['systemLoadLabel'];
    }

    if (($one === null || $five === null || $fifteen === null)
        && isset($entry['systemLoadValues']) && is_array($entry['systemLoadValues'])
    ) {
        $values = $entry['systemLoadValues'];
        if ($one === null && isset($values['one']) && is_numeric($values['one'])) {
            $one = (float) $values['one'];
        }
        if ($five === null && isset($values['five']) && is_numeric($values['five'])) {
            $five = (float) $values['five'];
        }
        if ($fifteen === null && isset($values['fifteen']) && is_numeric($values['fifteen'])) {
            $fifteen = (float) $values['fifteen'];
        }
    }

    if ($label !== null && ($one === null || $five === null || $fifteen === null)) {
        $parsed = parseSystemLoadValues($label);
        if ($one === null) {
            $one = $parsed['one'];
        }
        if ($five === null) {
            $five = $parsed['five'];
        }
        if ($fifteen === null) {
            $fifteen = $parsed['fifteen'];
        }
    }

    return [
        'one' => $one,
        'five' => $five,
        'fifteen' => $fifteen,
        'label' => $label,
    ];
}

/**
 * @param array<int, array<string, mixed>> $history
 * @return array<int, array<string, mixed>>
 */
function applyHistoryRotation(array $history, int $maxEntries, ?int $maxAge): array
{
    if ($maxAge !== null && $maxAge > 0) {
        $threshold = time() - $maxAge;
        $history = array_values(array_filter(
            $history,
            static function ($entry) use ($threshold): bool {
                if (!is_array($entry)) {
                    return false;
                }
                $timestamp = historyEntryTimestamp($entry);
                if ($timestamp === null) {
                    return true;
                }
                return $timestamp >= $threshold;
            }
        ));
    }

    if ($maxEntries > 0 && count($history) > $maxEntries) {
        $history = array_slice($history, -$maxEntries);
    }

    return array_values(array_map('normaliseHistoryEntryStructure', $history));
}

/**
 * @param array<string, mixed> $entry
 */
function historyEntryTimestamp(array $entry): ?int
{
    if (isset($entry['generatedAt']) && is_string($entry['generatedAt'])) {
        $timestamp = strtotime($entry['generatedAt']);
        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    if (isset($entry['time']) && is_string($entry['time'])) {
        $timestamp = strtotime($entry['time']);
        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    return null;
}

function parseTemperatureValue(?string $value): ?float
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    if (preg_match('/(-?\d+(?:[\.,]\d+)?)/', $value, $matches)) {
        $normalized = str_replace(',', '.', $matches[1]);
        $number = (float) $normalized;
        if (is_finite($number)) {
            return $number;
        }
    }

    return null;
}

function parsePercentageValue(?string $value): ?float
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    if (preg_match('/(-?\d+(?:[\.,]\d+)?)\s*%/', $value, $matches)) {
        $normalized = str_replace(',', '.', $matches[1]);
        $number = (float) $normalized;
        if (is_finite($number)) {
            return $number;
        }
    }

    return null;
}

/**
 * @return array{one: float|null, five: float|null, fifteen: float|null}
 */
function parseSystemLoadValues(?string $value): array
{
    $result = [
        'one' => null,
        'five' => null,
        'fifteen' => null,
    ];

    if (!is_string($value) || trim($value) === '') {
        return $result;
    }

    if (preg_match('/1\s*min\s*:\s*([\d\.,]+)/u', $value, $match)) {
        $result['one'] = (float) str_replace(',', '.', $match[1]);
    }
    if (preg_match('/5\s*min\s*:\s*([\d\.,]+)/u', $value, $match)) {
        $result['five'] = (float) str_replace(',', '.', $match[1]);
    }
    if (preg_match('/15\s*min\s*:\s*([\d\.,]+)/u', $value, $match)) {
        $result['fifteen'] = (float) str_replace(',', '.', $match[1]);
    }

    if (($result['one'] === null || $result['five'] === null || $result['fifteen'] === null)
        && preg_match_all('/(-?\d+(?:[\.,]\d+)?)/', $value, $matches)
        && count($matches[1]) >= 3
    ) {
        $numbers = array_map(
            static fn(string $number): float => (float) str_replace(',', '.', $number),
            array_slice($matches[1], 0, 3)
        );
        if ($result['one'] === null) {
            $result['one'] = $numbers[0];
        }
        if ($result['five'] === null) {
            $result['five'] = $numbers[1];
        }
        if ($result['fifteen'] === null) {
            $result['fifteen'] = $numbers[2];
        }
    }

    foreach ($result as $key => $number) {
        if (!is_finite($number ?? 0.0)) {
            $result[$key] = null;
        }
    }

    return $result;
}
