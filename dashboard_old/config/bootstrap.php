<?php

declare(strict_types=1);

function env_value(string $key, mixed $default = null): mixed
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

$config = [
    'app_name' => 'Dashboard Business - Hurricane Music',
    'environment' => (string) env_value('APP_ENV', 'production'),
    'database' => [
        'dsn' => env_value('DB_DSN', ''),
        'user' => env_value('DB_USER', ''),
        'password' => env_value('DB_PASSWORD', ''),
    ],
    'targets' => [
        'monthly_revenue' => (float) env_value('TARGET_MONTHLY_REVENUE', 0),
        'monthly_orders' => (int) env_value('TARGET_MONTHLY_ORDERS', 0),
    ],
    'etl' => [
        'web_token' => (string) env_value('ETL_WEB_TOKEN', ''),
    ],
];

$privateConfigPath = dirname(__DIR__, 2) . '/../dashboard-private/config.php';

if (is_file($privateConfigPath)) {
    $privateConfig = require $privateConfigPath;

    if (is_array($privateConfig)) {
        $config = array_replace_recursive($config, $privateConfig);
    }
}

return $config;
