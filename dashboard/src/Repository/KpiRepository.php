<?php

declare(strict_types=1);

namespace Dashboard\Repository;

use PDO;

final class KpiRepository
{
    private const DEFAULT_KPIS = [
        'kpi_date' => null,
        'total_revenue' => 0,
        'gross_margin' => 0,
        'orders_count' => 0,
        'average_basket' => 0,
        'traffic' => 0,
        'conversion_rate' => 0,
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function getLatestDailyKpis(): array
    {
        return $this->fetchOne(
            <<<'SQL'
                SELECT
                    kpi_date,
                    total_revenue,
                    gross_margin,
                    orders_count,
                    average_basket,
                    traffic,
                    conversion_rate
                FROM reporting_kpi_daily
                ORDER BY kpi_date DESC
                LIMIT 1
            SQL
        );
    }

    public function getPreviousDailyKpis(): array
    {
        return $this->fetchOne(
            <<<'SQL'
                SELECT
                    kpi_date,
                    total_revenue,
                    gross_margin,
                    orders_count,
                    average_basket,
                    traffic,
                    conversion_rate
                FROM reporting_kpi_daily
                ORDER BY kpi_date DESC
                LIMIT 1 OFFSET 1
            SQL
        );
    }

    public function getMonthToDateKpis(string $startDate): array
    {
        return $this->fetchOne(
            <<<'SQL'
                SELECT
                    MIN(kpi_date) AS kpi_date,
                    COALESCE(SUM(total_revenue), 0) AS total_revenue,
                    COALESCE(SUM(gross_margin), 0) AS gross_margin,
                    COALESCE(SUM(orders_count), 0) AS orders_count,
                    CASE
                        WHEN COALESCE(SUM(orders_count), 0) > 0
                            THEN COALESCE(SUM(total_revenue), 0) / COALESCE(SUM(orders_count), 0)
                        ELSE 0
                    END AS average_basket,
                    COALESCE(SUM(traffic), 0) AS traffic,
                    CASE
                        WHEN COALESCE(SUM(traffic), 0) > 0
                            THEN (COALESCE(SUM(orders_count), 0) / COALESCE(SUM(traffic), 0)) * 100
                        ELSE 0
                    END AS conversion_rate
                FROM reporting_kpi_daily
                WHERE kpi_date >= :start_date
            SQL,
            ['start_date' => $startDate]
        );
    }

    private function fetchOne(string $sql, array $params = []): array
    {
        return $this->fetchOnePrepared($sql, $params);
    }

    private function fetchOnePrepared(string $sql, array $params): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $row = $statement->fetch();
        } catch (\Throwable) {
            return self::DEFAULT_KPIS;
        }

        if (!$row) {
            return self::DEFAULT_KPIS;
        }

        return array_merge(self::DEFAULT_KPIS, $row);
    }
}
