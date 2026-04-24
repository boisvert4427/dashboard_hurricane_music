<?php

declare(strict_types=1);

use Dashboard\Database;
use Dashboard\EtlRunner;

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

$config = require __DIR__ . '/../config/bootstrap.php';

try {
    $database = new Database($config['database']);
    $runner = new EtlRunner($database->pdo());
    $stats = $runner->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'ETL failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "ETL complete: %d rows loaded from K_Li_FAC into reporting_invoice_line_fact.%s",
        $stats['inserted'],
        PHP_EOL
    )
);
