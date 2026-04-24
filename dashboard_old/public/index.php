<?php

declare(strict_types=1);

use Dashboard\Database;
use Dashboard\Repository\KpiRepository;

$config = require __DIR__ . '/../config/bootstrap.php';
$title = $config['app_name'];

/**
 * @param array<int, array<string, mixed>> $currentRows
 * @param array<int, array<string, mixed>> $previousRows
 * @return array<int, array<string, mixed>>
 */
function mergeChannelPeriods(array $currentRows, array $previousRows): array
{
    $merged = [];

    foreach ($previousRows as $row) {
        $channel = trim((string) ($row['channel'] ?? 'Autre'));
        $key = strtolower($channel);
        $merged[$key] = [
            'channel' => $channel !== '' ? $channel : 'Autre',
            'current_revenue' => 0,
            'previous_revenue' => (float) ($row['total_revenue'] ?? 0),
            'current_orders' => 0,
            'previous_orders' => (int) ($row['orders_count'] ?? 0),
            'current_margin' => 0,
            'previous_margin' => (float) ($row['gross_margin'] ?? 0),
        ];
    }

    foreach ($currentRows as $row) {
        $channel = trim((string) ($row['channel'] ?? 'Autre'));
        $key = strtolower($channel);

        if (!isset($merged[$key])) {
            $merged[$key] = [
                'channel' => $channel !== '' ? $channel : 'Autre',
                'current_revenue' => 0,
                'previous_revenue' => 0,
                'current_orders' => 0,
                'previous_orders' => 0,
                'current_margin' => 0,
                'previous_margin' => 0,
            ];
        }

        $merged[$key]['current_revenue'] = (float) ($row['total_revenue'] ?? 0);
        $merged[$key]['current_orders'] = (int) ($row['orders_count'] ?? 0);
        $merged[$key]['current_margin'] = (float) ($row['gross_margin'] ?? 0);
    }

    usort($merged, static function (array $left, array $right): int {
        return ($right['current_revenue'] <=> $left['current_revenue']) ?: strcmp((string) $left['channel'], (string) $right['channel']);
    });

    return array_values($merged);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dashboard\\';
    $baseDir = __DIR__ . '/../src/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$repository = null;

try {
    $database = new Database($config['database']);
    $repository = new KpiRepository($database->pdo());
} catch (Throwable $e) {
}

$referenceDate = new DateTimeImmutable('today');
$monthStart = $referenceDate->modify('first day of this month');
$monthEnd = $referenceDate;
$previousYearStart = $monthStart->modify('-1 year');
$previousYearEnd = $monthEnd->modify('-1 year');

$monthToDateKpis = [
    'kpi_date' => $monthEnd->format('Y-m-d'),
    'total_revenue' => 0,
    'gross_margin' => 0,
    'orders_count' => 0,
    'average_basket' => 0,
    'traffic' => 0,
    'conversion_rate' => 0,
];
$previousYearMonthToDateKpis = $monthToDateKpis;
$salesByChannel = [];

if ($repository instanceof KpiRepository) {
    $monthToDateKpis = $repository->getKpisForPeriod($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
    $previousYearMonthToDateKpis = $repository->getKpisForPeriod($previousYearStart->format('Y-m-d'), $previousYearEnd->format('Y-m-d'));
    $salesByChannel = mergeChannelPeriods(
        $repository->getSalesByChannelForPeriod($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')),
        $repository->getSalesByChannelForPeriod($previousYearStart->format('Y-m-d'), $previousYearEnd->format('Y-m-d'))
    );
}

require __DIR__ . '/../templates/dashboard.php';
