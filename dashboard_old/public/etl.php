<?php

declare(strict_types=1);

use Dashboard\Database;
use Dashboard\EtlRunner;

header('Content-Type: application/json; charset=UTF-8');

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
$expectedToken = (string) ($config['etl']['web_token'] ?? '');
$providedToken = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_ETL_TOKEN'] ?? '');

if ($expectedToken === '') {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'ETL web token is not configured.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Forbidden',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

try {
    $database = new Database($config['database']);
    $runner = new EtlRunner($database->pdo());
    $stats = $runner->run();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

echo json_encode([
    'ok' => true,
    'inserted' => $stats['inserted'],
    'source_rows' => $stats['source_rows'],
    'last_id' => $stats['last_id'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
