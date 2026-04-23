<?php

declare(strict_types=1);

$lines = [
    'ETL skeleton for Hurricane Music dashboard',
    '- connect to PrestaShop source data',
    '- extract the metrics needed for the reporting tables',
    '- compute daily aggregates',
    '- persist to reporting_kpi_daily and the other reporting_* tables',
];

foreach ($lines as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}
