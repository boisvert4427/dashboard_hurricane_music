<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class KpiRepository
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.reporting_connection')]
        private readonly Connection $reportingConnection,
    ) {
    }

    /**
     * @return array{
     *     current_period: array<string, mixed>,
     *     previous_period: array<string, mixed>,
     *     current_summary: array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float},
     *     previous_summary: array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float},
     *     kpis: array<int, array<string, mixed>>,
     *     occasion: array<string, mixed>,
     *     brand_highlights: array<string, mixed>,
     *     channels: array<int, array<string, mixed>>,
     *     trend: array<int, array<string, mixed>>,
     * }
     */
    public function getHomeData(): array
    {
        $today = new DateTimeImmutable('today');
        $currentStart = $today->modify('first day of this month');
        $previousStart = $currentStart->modify('-1 year');
        $previousEnd = $today->modify('-1 year');

        $current = $this->fetchPeriodSummary($currentStart, $today);
        $previous = $this->fetchPeriodSummary($previousStart, $previousEnd);

        return [
            'current_period' => [
                'start' => $currentStart,
                'end' => $today,
            ],
            'previous_period' => [
                'start' => $previousStart,
                'end' => $previousEnd,
            ],
            'current_summary' => $current,
            'previous_summary' => $previous,
            'kpis' => $this->buildKpis($current, $previous),
            'occasion' => $this->fetchOccasionSummary($currentStart, $today, $previousStart, $previousEnd),
            'brand_highlights' => $this->fetchBrandHighlights($currentStart, $today, $previousStart, $previousEnd),
            'channels' => $this->fetchChannelSummaries($currentStart, $today, $previousStart, $previousEnd),
            'trend' => $this->fetchTrend($currentStart, $today, $previousStart, $previousEnd),
        ];
    }

    /**
     * @return array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float}
     */
    private function fetchPeriodSummary(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $row = $this->reportingConnection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(total_ht), 0) AS total_ht,
                    COALESCE(SUM(margin_ht), 0) AS margin_ht,
                    COUNT(DISTINCT invoice_number) AS invoice_count,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity), 0) AS quantity
                FROM reporting_invoice_line_fact
                WHERE invoice_date BETWEEN :start_date AND :end_date
                  AND NOT (product_id = 18823 OR UPPER(COALESCE(supplier_reference, '')) LIKE 'REPRISE%')
            SQL,
            [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
            ]
        );

        return [
            'total_ht' => (float) ($row['total_ht'] ?? 0),
            'margin_ht' => (float) ($row['margin_ht'] ?? 0),
            'invoice_count' => (int) ($row['invoice_count'] ?? 0),
            'line_count' => (int) ($row['line_count'] ?? 0),
            'quantity' => (float) ($row['quantity'] ?? 0),
        ];
    }

    /**
     * @param array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float} $current
     * @param array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float} $previous
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildKpis(array $current, array $previous): array
    {
        $currentAverageBasket = $current['invoice_count'] > 0 ? $current['total_ht'] / $current['invoice_count'] : 0.0;
        $previousAverageBasket = $previous['invoice_count'] > 0 ? $previous['total_ht'] / $previous['invoice_count'] : 0.0;

        return [
            $this->buildKpiCard('CA mensuel', $current['total_ht'], $previous['total_ht'], 'Somme des lignes de facture HT sur le mois', 'money'),
            $this->buildKpiCard('Marge mensuelle', $current['margin_ht'], $previous['margin_ht'], 'Marge brute sur la même période', 'money'),
            $this->buildKpiCard('Factures', $current['invoice_count'], $previous['invoice_count'], 'Nombre de factures distinctes', 'count'),
            $this->buildKpiCard('Panier moyen', $currentAverageBasket, $previousAverageBasket, 'CA moyen par facture', 'money'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildKpiCard(string $label, float|int $current, float|int $previous, string $hint, string $type): array
    {
        $delta = $previous != 0.0 ? (($current - $previous) / $previous) * 100.0 : null;

        return [
            'label' => $label,
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'hint' => $hint,
            'type' => $type,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchChannelSummaries(DateTimeImmutable $currentStart, DateTimeImmutable $currentEnd, DateTimeImmutable $previousStart, DateTimeImmutable $previousEnd): array
    {
        $rows = $this->reportingConnection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(NULLIF(channel_name, ''), 'Autre') AS channel_name,
                    COALESCE(SUM(total_ht), 0) AS total_ht,
                    COALESCE(SUM(margin_ht), 0) AS margin_ht,
                    COUNT(DISTINCT invoice_number) AS invoice_count,
                    COUNT(*) AS line_count
                FROM reporting_invoice_line_fact
                WHERE invoice_date BETWEEN :start_date AND :end_date
                  AND NOT (product_id = 18823 OR UPPER(COALESCE(supplier_reference, '')) LIKE 'REPRISE%')
                GROUP BY COALESCE(NULLIF(channel_name, ''), 'Autre')
                ORDER BY total_ht DESC, channel_name ASC
            SQL,
            [
                'start_date' => $currentStart->format('Y-m-d'),
                'end_date' => $currentEnd->format('Y-m-d'),
            ]
        );

        $channels = [];
        foreach ($rows as $row) {
            $channelName = (string) ($row['channel_name'] ?? 'Autre');
            $channels[] = [
                'label' => $channelName,
                'current' => (float) ($row['total_ht'] ?? 0),
                'margin' => (float) ($row['margin_ht'] ?? 0),
                'invoices' => (int) ($row['invoice_count'] ?? 0),
                'lines' => (int) ($row['line_count'] ?? 0),
                'highlights' => $this->fetchBrandHighlights($currentStart, $currentEnd, $previousStart, $previousEnd, $channelName),
                'hint' => 'CA HT sur le mois en cours',
            ];
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOccasionSummary(DateTimeImmutable $currentStart, DateTimeImmutable $currentEnd, DateTimeImmutable $previousStart, DateTimeImmutable $previousEnd): array
    {
        $current = $this->fetchOccasionTotals($currentStart, $currentEnd);
        $previous = $this->fetchOccasionTotals($previousStart, $previousEnd);

        return [
            'current_total' => $current['total_ht'],
            'previous_total' => $previous['total_ht'],
            'delta' => $previous['total_ht'] > 0
                ? (($current['total_ht'] - $previous['total_ht']) / $previous['total_ht']) * 100.0
                : null,
            'current_lines' => $current['line_count'],
            'previous_lines' => $previous['line_count'],
            'current_invoices' => $current['invoice_count'],
            'previous_invoices' => $previous['invoice_count'],
            'channels' => $this->fetchOccasionChannelSummaries($currentStart, $currentEnd, $previousStart, $previousEnd),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchBrandHighlights(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd,
        ?string $channelName = null
    ): array {
        $currentTotals = $this->fetchBrandTotals($currentStart, $currentEnd, $channelName);
        $previousTotals = $this->fetchBrandTotals($previousStart, $previousEnd, $channelName);

        $brandIds = array_unique(array_merge(array_keys($currentTotals), array_keys($previousTotals)));
        $brands = [];
        foreach ($brandIds as $brandId) {
            $currentRow = $currentTotals[$brandId] ?? [
                'brand_id' => $brandId,
                'brand_name' => $previousTotals[$brandId]['brand_name'] ?? ('Marque ' . $brandId),
                'total_ht' => 0.0,
                'margin_ht' => 0.0,
                'invoice_count' => 0,
            ];
            $previousRow = $previousTotals[$brandId] ?? [
                'brand_id' => $brandId,
                'brand_name' => $currentRow['brand_name'],
                'total_ht' => 0.0,
                'margin_ht' => 0.0,
                'invoice_count' => 0,
            ];

            $currentTotal = (float) ($currentRow['total_ht'] ?? 0);
            $previousTotal = (float) ($previousRow['total_ht'] ?? 0);

            $brands[] = [
                'brand_id' => $brandId,
                'brand_name' => $currentRow['brand_name'],
                'current_total' => $currentTotal,
                'previous_total' => $previousTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'absolute_delta' => $currentTotal - $previousTotal,
            ];
        }

        usort($brands, static fn (array $a, array $b): int => $b['current_total'] <=> $a['current_total']);

        return [
            'scope_label' => $channelName ?? 'global',
            'top_brands' => array_map(
                static fn (array $brand): array => [
                    'brand_name' => $brand['brand_name'],
                    'current_total_raw' => $brand['current_total'],
                    'previous_total_raw' => $brand['previous_total'],
                    'current_total' => number_format((int) round((float) $brand['current_total']), 0, ',', ' ') . ' €',
                    'previous_total' => number_format((int) round((float) $brand['previous_total']), 0, ',', ' ') . ' €',
                    'delta' => $brand['delta'] === null ? 'n/a' : (($brand['delta'] > 0 ? '+' : '') . number_format((float) $brand['delta'], 1, ',', ' ') . ' % vs N-1'),
                    'delta_class' => $brand['delta'] === null ? 'delta-neutral' : ((float) $brand['delta'] > 0 ? 'delta-up' : ((float) $brand['delta'] < 0 ? 'delta-down' : 'delta-neutral')),
                ],
                array_slice($brands, 0, 5)
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchBrandTotals(DateTimeImmutable $start, DateTimeImmutable $end, ?string $channelName = null): array
    {
        $sql = <<<'SQL'
            SELECT
                COALESCE(r.brand_id, 0) AS brand_id,
                COALESCE(NULLIF(r.brand_name, ''), CASE
                    WHEN COALESCE(r.brand_id, 0) = 0 THEN 'Sans marque'
                    ELSE CONCAT('IDFAB ', COALESCE(r.brand_id, 0))
                END) AS brand_name,
                COALESCE(SUM(r.total_ht), 0) AS total_ht,
                COALESCE(SUM(r.margin_ht), 0) AS margin_ht,
                COUNT(DISTINCT r.invoice_number) AS invoice_count
            FROM reporting_invoice_line_fact r
            WHERE r.invoice_date BETWEEN :start_date AND :end_date
              AND NOT (r.product_id = 18823 OR UPPER(COALESCE(r.supplier_reference, '')) LIKE 'REPRISE%')
        SQL;

        $params = [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ];

        if ($channelName !== null) {
            $sql .= ' AND COALESCE(NULLIF(r.channel_name, \'\'), \'Autre\') = :channel_name';
            $params['channel_name'] = $channelName;
        }

        $sql .= ' AND NOT ' . $this->buildOccasionFilterSql('r');

        $sql .= ' GROUP BY COALESCE(r.brand_id, 0), brand_name ORDER BY total_ht DESC, brand_name ASC';

        $rows = $this->reportingConnection->fetchAllAssociative($sql, $params);

        $brands = [];
        foreach ($rows as $row) {
            $brandId = (int) ($row['brand_id'] ?? 0);
            $brands[$brandId] = [
                'brand_id' => $brandId,
                'brand_name' => (string) ($row['brand_name'] ?? ('Marque ' . $brandId)),
                'total_ht' => (float) ($row['total_ht'] ?? 0),
                'margin_ht' => (float) ($row['margin_ht'] ?? 0),
                'invoice_count' => (int) ($row['invoice_count'] ?? 0),
            ];
        }

        return $brands;
    }

    private function buildOccasionFilterSql(string $alias = 'r'): string
    {
        return <<<SQL
            (
                LOWER(COALESCE({$alias}.product_code, '')) LIKE 'b-%'
                OR LOWER(COALESCE({$alias}.product_code, '')) LIKE '%occas%'
                OR UPPER(COALESCE({$alias}.product_code, '')) LIKE 'DEPV%'
                OR LOWER(COALESCE({$alias}.product_name, '')) LIKE '%occas%'
            )
        SQL;
    }

    /**
     * @return array{total_ht: float, line_count: int, invoice_count: int}
     */
    private function fetchOccasionTotals(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $row = $this->reportingConnection->fetchAssociative(
            <<<SQL
                SELECT
                    COALESCE(SUM(r.total_ht), 0) AS total_ht,
                    COUNT(*) AS line_count,
                    COUNT(DISTINCT r.invoice_number) AS invoice_count
                FROM reporting_invoice_line_fact r
                WHERE r.invoice_date BETWEEN :start_date AND :end_date
                  AND NOT (r.product_id = 18823 OR UPPER(COALESCE(r.supplier_reference, '')) LIKE 'REPRISE%')
                  AND {$this->buildOccasionFilterSql('r')}
            SQL,
            [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
            ]
        );

        return [
            'total_ht' => (float) ($row['total_ht'] ?? 0),
            'line_count' => (int) ($row['line_count'] ?? 0),
            'invoice_count' => (int) ($row['invoice_count'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchOccasionChannelSummaries(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd
    ): array {
        $currentRows = $this->fetchOccasionChannelTotals($currentStart, $currentEnd);
        $previousRows = $this->fetchOccasionChannelTotals($previousStart, $previousEnd);

        $channelNames = array_unique(array_merge(array_keys($currentRows), array_keys($previousRows)));
        $channels = [];

        foreach ($channelNames as $channelName) {
            $current = $currentRows[$channelName] ?? ['total_ht' => 0.0, 'line_count' => 0, 'invoice_count' => 0];
            $previous = $previousRows[$channelName] ?? ['total_ht' => 0.0, 'line_count' => 0, 'invoice_count' => 0];

            $currentTotal = (float) $current['total_ht'];
            $previousTotal = (float) $previous['total_ht'];

            $channels[] = [
                'label' => $channelName,
                'current_total' => $currentTotal,
                'previous_total' => $previousTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'current_lines' => (int) $current['line_count'],
                'previous_lines' => (int) $previous['line_count'],
            ];
        }

        usort($channels, static fn (array $a, array $b): int => $b['current_total'] <=> $a['current_total']);

        return $channels;
    }

    /**
     * @return array<string, array{total_ht: float, line_count: int, invoice_count: int}>
     */
    private function fetchOccasionChannelTotals(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->reportingConnection->fetchAllAssociative(
            <<<SQL
                SELECT
                    COALESCE(NULLIF(r.channel_name, ''), 'Autre') AS channel_name,
                    COALESCE(SUM(r.total_ht), 0) AS total_ht,
                    COUNT(*) AS line_count,
                    COUNT(DISTINCT r.invoice_number) AS invoice_count
                FROM reporting_invoice_line_fact r
                WHERE r.invoice_date BETWEEN :start_date AND :end_date
                  AND NOT (r.product_id = 18823 OR UPPER(COALESCE(r.supplier_reference, '')) LIKE 'REPRISE%')
                  AND {$this->buildOccasionFilterSql('r')}
                GROUP BY COALESCE(NULLIF(r.channel_name, ''), 'Autre')
            SQL,
            [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
            ]
        );

        $indexed = [];
        foreach ($rows as $row) {
            $channelName = (string) ($row['channel_name'] ?? 'Autre');
            $indexed[$channelName] = [
                'total_ht' => (float) ($row['total_ht'] ?? 0),
                'line_count' => (int) ($row['line_count'] ?? 0),
                'invoice_count' => (int) ($row['invoice_count'] ?? 0),
            ];
        }

        return $indexed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchTrend(DateTimeImmutable $currentStart, DateTimeImmutable $currentEnd, DateTimeImmutable $previousStart, DateTimeImmutable $previousEnd): array
    {
        $currentTotals = $this->fetchDailyTotals($currentStart, $currentEnd);
        $previousTotals = $this->fetchDailyTotals($previousStart, $previousEnd);

        $series = [];
        $cursor = $currentStart;
        $previousCursor = $previousStart;

        while ($cursor <= $currentEnd && $previousCursor <= $previousEnd) {
            $currentKey = $cursor->format('Y-m-d');
            $previousKey = $previousCursor->format('Y-m-d');

            $series[] = [
                'date' => $currentKey,
                'label' => $cursor->format('d/m'),
                'current' => (float) ($currentTotals[$currentKey] ?? 0),
                'previous' => (float) ($previousTotals[$previousKey] ?? 0),
            ];

            $cursor = $cursor->modify('+1 day');
            $previousCursor = $previousCursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * @return array<string, float>
     */
    private function fetchDailyTotals(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->reportingConnection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    invoice_date,
                    COALESCE(SUM(total_ht), 0) AS total_ht
                FROM reporting_invoice_line_fact
                WHERE invoice_date BETWEEN :start_date AND :end_date
                  AND NOT (product_id = 18823 OR UPPER(COALESCE(supplier_reference, '')) LIKE 'REPRISE%')
                GROUP BY invoice_date
                ORDER BY invoice_date ASC
            SQL,
            [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
            ]
        );

        $totals = [];
        foreach ($rows as $row) {
            if (!isset($row['invoice_date'])) {
                continue;
            }

            $totals[(string) $row['invoice_date']] = (float) ($row['total_ht'] ?? 0);
        }

        return $totals;
    }
}
