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

    public function getKpisForPeriod(string $startDate, string $endDate): array
    {
        return $this->fetchOne(
            <<<'SQL'
                SELECT
                    MIN(invoice_date) AS kpi_date,
                    COALESCE(SUM(total_ht), 0) AS total_revenue,
                    COALESCE(SUM(margin_ht), 0) AS gross_margin,
                    COALESCE(COUNT(DISTINCT source_invoice_id), 0) AS orders_count,
                    CASE
                        WHEN COALESCE(COUNT(DISTINCT source_invoice_id), 0) > 0
                            THEN COALESCE(SUM(total_ht), 0) / COALESCE(COUNT(DISTINCT source_invoice_id), 0)
                        ELSE 0
                    END AS average_basket,
                    0 AS traffic,
                    0 AS conversion_rate
                FROM reporting_invoice_line_fact
                WHERE invoice_date BETWEEN :start_date AND :end_date
            SQL,
            ['start_date' => $startDate, 'end_date' => $endDate]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSalesByChannelForPeriod(string $startDate, string $endDate): array
    {
        $sql = <<<'SQL'
            SELECT
                COALESCE(NULLIF(channel_name, ''), NULLIF(mode_vente, ''), 'Autre') AS channel,
                COALESCE(SUM(total_ht), 0) AS total_revenue,
                COALESCE(SUM(margin_ht), 0) AS gross_margin,
                COALESCE(COUNT(DISTINCT source_invoice_id), 0) AS orders_count
            FROM reporting_invoice_line_fact
            WHERE invoice_date BETWEEN :start_date AND :end_date
            GROUP BY COALESCE(NULLIF(channel_name, ''), NULLIF(mode_vente, ''), 'Autre')
            ORDER BY total_revenue DESC
        SQL;

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            $rows = $statement->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    private function fetchOne(string $sql, array $params = []): array
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
