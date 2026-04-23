<?php

declare(strict_types=1);

use Dashboard\Database;
use Dashboard\Repository\KpiRepository;

$config = require __DIR__ . '/../config/bootstrap.php';
$title = $config['app_name'];
$monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');

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

try {
    $database = new Database($config['database']);
    $repository = new KpiRepository($database->pdo());
    $kpis = $repository->getLatestDailyKpis();
    $previousKpis = $repository->getPreviousDailyKpis();
    $monthToDateKpis = $repository->getMonthToDateKpis($monthStart);
} catch (Throwable $e) {
    $kpis = [
        'kpi_date' => date('Y-m-d'),
        'total_revenue' => 0,
        'gross_margin' => 0,
        'orders_count' => 0,
        'average_basket' => 0,
        'traffic' => 0,
        'conversion_rate' => 0,
    ];
    $previousKpis = $kpis;
    $monthToDateKpis = $kpis;
}

require __DIR__ . '/../templates/dashboard.php';
