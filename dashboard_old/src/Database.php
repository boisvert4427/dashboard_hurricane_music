<?php

declare(strict_types=1);

namespace Dashboard;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        try {
            $this->pdo = new PDO(
                $config['dsn'],
                $config['user'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
