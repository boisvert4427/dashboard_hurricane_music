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
        #[Autowire('%dashboard_monthly_target_ht%')]
        private readonly float $monthlyTargetHt,
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
    public function getHomeData(array $filters = []): array
    {
        $period = $this->resolvePeriod($filters);
        $current = $this->fetchPeriodSummary($period['current_start'], $period['current_end'], $filters);
        $previousYear = $this->fetchPeriodSummary($period['previous_year_start'], $period['previous_year_end'], $filters);
        $previousMonth = $this->fetchPeriodSummary($period['previous_month_start'], $period['previous_month_end'], $filters);
        $objective = $this->buildObjectiveSummary($current['total_ht']);

        return [
            'current_period' => [
                'start' => $period['current_start'],
                'end' => $period['current_end'],
            ],
            'previous_period' => [
                'start' => $period['previous_year_start'],
                'end' => $period['previous_year_end'],
            ],
            'previous_month_period' => [
                'start' => $period['previous_month_start'],
                'end' => $period['previous_month_end'],
            ],
            'current_summary' => $current,
            'previous_summary' => $previousYear,
            'previous_month_summary' => $previousMonth,
            'objective_summary' => $objective,
            'kpis' => $this->buildKpis($current, $previousYear, $previousMonth, $objective),
            'occasion' => $this->fetchOccasionSummary($period['current_start'], $period['current_end'], $period['previous_year_start'], $period['previous_year_end'], $filters),
            'brand_highlights' => $this->fetchBrandHighlights($period['current_start'], $period['current_end'], $period['previous_year_start'], $period['previous_year_end'], $filters),
            'channels' => $this->fetchChannelSummaries($period['current_start'], $period['current_end'], $period['previous_year_start'], $period['previous_year_end'], $filters),
            'trend' => $this->fetchTrend($period['current_start'], $period['current_end'], $period['previous_year_start'], $period['previous_year_end'], $filters),
            'alerts' => $this->fetchAlerts($period['current_start'], $period['current_end'], $period['previous_year_start'], $period['previous_year_end'], $period['previous_month_start'], $period['previous_month_end'], $filters),
            'filters' => $this->getFilterOptions(),
            'active_filters' => $filters,
        ];
    }

    /**
     * @return array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float}
     */
    private function fetchPeriodSummary(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $sql = sprintf(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(total_ht), 0) AS total_ht,
                    COALESCE(SUM(margin_ht), 0) AS margin_ht,
                    COUNT(DISTINCT invoice_number) AS invoice_count,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity), 0) AS quantity
                FROM reporting_invoice_line_fact r
                WHERE %s
            SQL,
            $whereSql
        );
        $row = $this->reportingConnection->fetchAssociative(
            $sql,
            $params
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
     * @param array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float} $previousMonth
     * @param array{objective_ht: float, objective_ratio: null|float, objective_remaining: null|float} $objective
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildKpis(array $current, array $previous, array $previousMonth, array $objective): array
    {
        $currentAverageBasket = $current['invoice_count'] > 0 ? $current['total_ht'] / $current['invoice_count'] : 0.0;
        $previousAverageBasket = $previous['invoice_count'] > 0 ? $previous['total_ht'] / $previous['invoice_count'] : 0.0;

        return [
            $this->buildKpiCard('CA mensuel', $current['total_ht'], $previous['total_ht'], 'Somme des lignes de facture HT sur le mois', 'money'),
            $this->buildKpiCard('Marge mensuelle', $current['margin_ht'], $previous['margin_ht'], 'Marge brute sur la même période', 'money'),
            $this->buildKpiCard('Factures', $current['invoice_count'], $previous['invoice_count'], 'Nombre de factures distinctes', 'count'),
            $this->buildKpiCard('Panier moyen', $currentAverageBasket, $previousAverageBasket, 'CA moyen par facture', 'money'),
            $this->buildKpiCard('CA vs mois précédent', $current['total_ht'], $previousMonth['total_ht'], 'Lecture rapide vs mois précédent', 'money'),
            $this->buildKpiCard('Objectif mensuel', $current['total_ht'], $objective['objective_ht'] > 0 ? $objective['objective_ht'] : $current['total_ht'], $objective['objective_ht'] > 0 ? 'Cumul vs objectif' : 'Objectif non configuré', 'money'),
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
     * @return array{
     *     current_start: DateTimeImmutable,
     *     current_end: DateTimeImmutable,
     *     previous_year_start: DateTimeImmutable,
     *     previous_year_end: DateTimeImmutable,
     *     previous_month_start: DateTimeImmutable,
     *     previous_month_end: DateTimeImmutable
     * }
     */
    private function resolvePeriod(array $filters): array
    {
        $today = new DateTimeImmutable('today');

        $currentStart = isset($filters['start']) && $filters['start'] !== ''
            ? new DateTimeImmutable((string) $filters['start'])
            : $today->modify('first day of this month');

        $currentEnd = isset($filters['end']) && $filters['end'] !== ''
            ? new DateTimeImmutable((string) $filters['end'])
            : $today;

        if ($currentStart > $currentEnd) {
            [$currentStart, $currentEnd] = [$currentEnd, $currentStart];
        }

        return [
            'current_start' => $currentStart,
            'current_end' => $currentEnd,
            'previous_year_start' => $currentStart->modify('-1 year'),
            'previous_year_end' => $currentEnd->modify('-1 year'),
            'previous_month_start' => $currentStart->modify('-1 month'),
            'previous_month_end' => $currentEnd->modify('-1 month'),
        ];
    }

    /**
     * @return array{objective_ht: float, objective_ratio: null|float, objective_remaining: null|float}
     */
    private function buildObjectiveSummary(float $currentTotal): array
    {
        if ($this->monthlyTargetHt <= 0) {
            return [
                'objective_ht' => 0.0,
                'objective_ratio' => null,
                'objective_remaining' => null,
            ];
        }

        return [
            'objective_ht' => $this->monthlyTargetHt,
            'objective_ratio' => ($currentTotal / $this->monthlyTargetHt) * 100.0,
            'objective_remaining' => max(0.0, $this->monthlyTargetHt - $currentTotal),
        ];
    }

    /**
     * @return array{channels: array<int, array{id:int, label:string}>, brands: array<int, array{id:int, label:string}>}
     */
    public function getFilterOptions(): array
    {
        $channels = $this->reportingConnection->fetchAllAssociative(
            <<<SQL
                SELECT DISTINCT
                    COALESCE(NULLIF(channel_name, ''), 'Autre') AS label
                FROM reporting_invoice_line_fact
                ORDER BY label ASC
            SQL
        );

        $brands = $this->reportingConnection->fetchAllAssociative(
            <<<SQL
                SELECT DISTINCT
                    COALESCE(brand_id, 0) AS id,
                    COALESCE(NULLIF(brand_name, ''), CASE
                        WHEN COALESCE(brand_id, 0) = 0 THEN 'Sans marque'
                        ELSE CONCAT('IDFAB ', COALESCE(brand_id, 0))
                    END) AS label
                FROM reporting_invoice_line_fact
                ORDER BY label ASC
            SQL
        );

        return [
            'channels' => array_map(static fn (array $row): array => [
                'value' => (string) ($row['label'] ?? 'Autre'),
                'label' => (string) ($row['label'] ?? 'Autre'),
            ], $channels),
            'brands' => array_map(static fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['label'] ?? 'Marque'),
            ], $brands),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetailData(array $filters, int $page = 1, int $perPage = 50, string $sort = 'invoice_date', string $direction = 'desc'): array
    {
        $period = $this->resolvePeriod($filters);
        [$whereSql, $params] = $this->buildWhereClause($period['current_start'], $period['current_end'], $filters, 'r');

        $countSql = 'SELECT COUNT(*) FROM reporting_invoice_line_fact r WHERE ' . $whereSql;
        $totalRows = (int) $this->reportingConnection->fetchOne($countSql, $params);

        $orderSql = $this->buildOrderByClause($sort, $direction);
        $offset = max(0, ($page - 1) * $perPage);

        $rowsSql = <<<SQL
            SELECT
                r.invoice_date,
                r.invoice_number,
                COALESCE(NULLIF(r.channel_name, ''), 'Autre') AS channel_name,
                r.product_id AS idart,
                COALESCE(r.brand_id, 0) AS brand_id,
                COALESCE(NULLIF(r.brand_name, ''), CASE
                    WHEN COALESCE(r.brand_id, 0) = 0 THEN 'Sans marque'
                    ELSE CONCAT('IDFAB ', COALESCE(r.brand_id, 0))
                END) AS brand_name,
                r.product_code,
                r.product_name,
                r.supplier_name,
                r.supplier_reference,
                r.quantity,
                r.total_ht,
                r.margin_ht,
                r.customer_id,
                CASE
                    WHEN LOWER(COALESCE(r.product_code, '')) LIKE 'b-%'
                        OR LOWER(COALESCE(r.product_code, '')) LIKE '%occas%'
                        OR UPPER(COALESCE(r.product_code, '')) LIKE 'DEPV%'
                        OR LOWER(COALESCE(r.product_name, '')) LIKE '%occas%'
                    THEN 1
                    ELSE 0
                END AS is_occasion
            FROM reporting_invoice_line_fact r
            WHERE {$whereSql}
            ORDER BY {$orderSql}
            LIMIT {$perPage} OFFSET {$offset}
        SQL;

        $rows = $this->reportingConnection->fetchAllAssociative($rowsSql, $params);

        return [
            'period' => $period,
            'summary' => $this->fetchPeriodSummary($period['current_start'], $period['current_end'], $filters),
            'comparison_previous_year' => $this->fetchPeriodSummary($period['previous_year_start'], $period['previous_year_end'], $filters),
            'comparison_previous_month' => $this->fetchPeriodSummary($period['previous_month_start'], $period['previous_month_end'], $filters),
            'objective' => $this->buildObjectiveSummary($this->fetchPeriodSummary($period['current_start'], $period['current_end'], $filters)['total_ht']),
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'total_pages' => (int) max(1, ceil($totalRows / $perPage)),
            ],
            'alerts' => $this->fetchAlerts($period['current_start'], $period['current_end'], $period['previous_year_start'], $period['previous_year_end'], $period['previous_month_start'], $period['previous_month_end'], $filters),
            'filters' => $this->getFilterOptions(),
            'active_filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function iterateDetailRows(array $filters, string $sort = 'invoice_date', string $direction = 'desc'): iterable
    {
        $period = $this->resolvePeriod($filters);
        [$whereSql, $params] = $this->buildWhereClause($period['current_start'], $period['current_end'], $filters, 'r');
        $orderSql = $this->buildOrderByClause($sort, $direction);

        $sql = sprintf(
            <<<'SQL'
                SELECT
                    r.invoice_date,
                    r.invoice_number,
                    COALESCE(NULLIF(r.channel_name, ''), 'Autre') AS channel_name,
                    r.product_id AS idart,
                    COALESCE(r.brand_id, 0) AS brand_id,
                    COALESCE(NULLIF(r.brand_name, ''), CASE
                        WHEN COALESCE(r.brand_id, 0) = 0 THEN 'Sans marque'
                        ELSE CONCAT('IDFAB ', COALESCE(r.brand_id, 0))
                    END) AS brand_name,
                    r.product_code,
                    r.product_name,
                    r.supplier_name,
                    r.supplier_reference,
                    r.quantity,
                    r.total_ht,
                    r.margin_ht,
                    r.customer_id,
                    CASE
                        WHEN LOWER(COALESCE(r.product_code, '')) LIKE 'b-%'
                            OR LOWER(COALESCE(r.product_code, '')) LIKE '%occas%'
                            OR UPPER(COALESCE(r.product_code, '')) LIKE 'DEPV%%'
                            OR LOWER(COALESCE(r.product_name, '')) LIKE '%occas%'
                        THEN 1
                        ELSE 0
                    END AS is_occasion
                FROM reporting_invoice_line_fact r
                WHERE %s
                ORDER BY %s
            SQL,
            $whereSql,
            $orderSql
        );

        return $this->reportingConnection->iterateAssociative($sql, $params);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function buildWhereClause(DateTimeImmutable $start, DateTimeImmutable $end, array $filters, string $alias = 'r'): array
    {
        $conditions = [
            sprintf('%s.invoice_date BETWEEN :start_date AND :end_date', $alias),
            sprintf('NOT (%s.product_id = 18823 OR UPPER(COALESCE(%s.supplier_reference, \'\')) LIKE \'REPRISE%%\')', $alias, $alias),
        ];

        $params = [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ];

        if (!empty($filters['channel'])) {
            $conditions[] = sprintf('COALESCE(NULLIF(%s.channel_name, \'\'), \'Autre\') = :channel_name', $alias);
            $params['channel_name'] = (string) $filters['channel'];
        }

        if (array_key_exists('brand_id', $filters) && $filters['brand_id'] !== '') {
            $conditions[] = sprintf('COALESCE(%s.brand_id, 0) = :brand_id', $alias);
            $params['brand_id'] = (int) $filters['brand_id'];
        }

        if (!empty($filters['occasion'])) {
            $occasionSql = $this->buildOccasionFilterSql($alias);
            if ($filters['occasion'] === 'occasion') {
                $conditions[] = $occasionSql;
            } elseif ($filters['occasion'] === 'hors_occasion') {
                $conditions[] = 'NOT ' . $occasionSql;
            }
        }

        if (array_key_exists('q', $filters) && $filters['q'] !== '') {
            $conditions[] = sprintf(
                '(
                    LOWER(COALESCE(%1$s.invoice_number, \'\')) LIKE :search
                    OR LOWER(COALESCE(%1$s.product_code, \'\')) LIKE :search
                    OR LOWER(COALESCE(%1$s.product_name, \'\')) LIKE :search
                    OR LOWER(COALESCE(%1$s.brand_name, \'\')) LIKE :search
                    OR LOWER(COALESCE(%1$s.channel_name, \'\')) LIKE :search
                    OR LOWER(COALESCE(%1$s.supplier_reference, \'\')) LIKE :search
                )',
                $alias
            );
            $params['search'] = '%' . mb_strtolower((string) $filters['q']) . '%';
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function buildOrderByClause(string $sort, string $direction): string
    {
        $allowed = [
            'invoice_date' => 'r.invoice_date',
            'invoice_number' => 'r.invoice_number',
            'channel_name' => 'channel_name',
            'brand_name' => 'brand_name',
            'product_name' => 'r.product_name',
            'total_ht' => 'r.total_ht',
            'margin_ht' => 'r.margin_ht',
            'quantity' => 'r.quantity',
        ];

        $column = $allowed[$sort] ?? 'r.invoice_date';
        $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        return $column . ' ' . $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAlerts(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousYearStart,
        DateTimeImmutable $previousYearEnd,
        DateTimeImmutable $previousMonthStart,
        DateTimeImmutable $previousMonthEnd,
        array $filters
    ): array {
        $brandMovers = $this->buildBrandMovers($currentStart, $currentEnd, $previousYearStart, $previousYearEnd, $filters);
        $channelMovers = $this->buildChannelMovers($currentStart, $currentEnd, $previousYearStart, $previousYearEnd, $filters);
        $basketMovers = $this->buildBasketMovers($currentStart, $currentEnd, $previousMonthStart, $previousMonthEnd, $filters);

        return [
            'brand_gainers' => array_slice($brandMovers['gainers'], 0, 3),
            'brand_losers' => array_slice($brandMovers['losers'], 0, 3),
            'channel_anomalies' => array_slice($channelMovers, 0, 4),
            'basket_anomalies' => array_slice($basketMovers, 0, 4),
        ];
    }

    /**
     * @return array{gainers: array<int, array<string, mixed>>, losers: array<int, array<string, mixed>>}
     */
    private function buildBrandMovers(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd,
        array $filters
    ): array {
        $currentTotals = $this->fetchBrandTotals($currentStart, $currentEnd, null, false, $filters);
        $previousTotals = $this->fetchBrandTotals($previousStart, $previousEnd, null, false, $filters);

        $rows = [];
        foreach (array_unique(array_merge(array_keys($currentTotals), array_keys($previousTotals))) as $brandId) {
            $current = (float) ($currentTotals[$brandId]['total_ht'] ?? 0);
            $previous = (float) ($previousTotals[$brandId]['total_ht'] ?? 0);
            $delta = $previous > 0 ? (($current - $previous) / $previous) * 100.0 : null;

            $rows[] = [
                'brand_id' => $brandId,
                'brand_name' => $currentTotals[$brandId]['brand_name'] ?? ($previousTotals[$brandId]['brand_name'] ?? ('Marque ' . $brandId)),
                'current_total' => $current,
                'previous_total' => $previous,
                'delta' => $delta,
                'absolute_delta' => $current - $previous,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['delta'] ?? -INF) <=> ($a['delta'] ?? -INF));
        $gainers = array_values(array_filter($rows, static fn (array $row): bool => ($row['delta'] ?? null) !== null && (float) $row['delta'] > 0));
        $losers = array_values(array_filter($rows, static fn (array $row): bool => ($row['delta'] ?? null) !== null && (float) $row['delta'] < 0));

        return [
            'gainers' => array_map($this->formatMover(...), $gainers),
            'losers' => array_map($this->formatMover(...), $losers),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildChannelMovers(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd,
        array $filters
    ): array {
        $currentTotals = $this->fetchChannelTotals($currentStart, $currentEnd, $filters);
        $previousTotals = $this->fetchChannelTotals($previousStart, $previousEnd, $filters);

        $rows = [];
        foreach (array_unique(array_merge(array_keys($currentTotals), array_keys($previousTotals))) as $channelName) {
            $current = $currentTotals[$channelName] ?? ['total_ht' => 0.0, 'invoice_count' => 0, 'line_count' => 0];
            $previous = $previousTotals[$channelName] ?? ['total_ht' => 0.0, 'invoice_count' => 0, 'line_count' => 0];
            $currentTotal = (float) $current['total_ht'];
            $previousTotal = (float) $previous['total_ht'];
            $currentBasket = ((int) $current['invoice_count']) > 0 ? $currentTotal / (int) $current['invoice_count'] : 0.0;
            $previousBasket = ((int) $previous['invoice_count']) > 0 ? $previousTotal / (int) $previous['invoice_count'] : 0.0;

            $rows[] = [
                'label' => $channelName,
                'current_total' => $currentTotal,
                'previous_total' => $previousTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'basket_delta' => $previousBasket > 0 ? (($currentBasket - $previousBasket) / $previousBasket) * 100.0 : null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aScore = abs((float) ($a['basket_delta'] ?? 0)) + abs((float) ($a['delta'] ?? 0));
            $bScore = abs((float) ($b['basket_delta'] ?? 0)) + abs((float) ($b['delta'] ?? 0));

            return $bScore <=> $aScore;
        });

        return array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'current_total' => number_format((int) round((float) $row['current_total']), 0, ',', ' ') . ' €',
            'previous_total' => number_format((int) round((float) $row['previous_total']), 0, ',', ' ') . ' €',
            'delta' => $row['delta'] === null ? 'n/a' : (($row['delta'] > 0 ? '+' : '') . number_format((float) $row['delta'], 1, ',', ' ') . ' % vs N-1'),
            'basket_delta' => $row['basket_delta'] === null ? 'n/a' : (($row['basket_delta'] > 0 ? '+' : '') . number_format((float) $row['basket_delta'], 1, ',', ' ') . ' % vs N-1'),
            'delta_class' => $row['delta'] === null ? 'delta-neutral' : ((float) $row['delta'] > 0 ? 'delta-up' : 'delta-down'),
        ], array_slice($rows, 0, 5));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBasketMovers(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd,
        array $filters
    ): array {
        $currentTotals = $this->fetchChannelTotals($currentStart, $currentEnd, $filters);
        $previousTotals = $this->fetchChannelTotals($previousStart, $previousEnd, $filters);

        $rows = [];
        foreach (array_unique(array_merge(array_keys($currentTotals), array_keys($previousTotals))) as $channelName) {
            $current = $currentTotals[$channelName] ?? ['total_ht' => 0.0, 'invoice_count' => 0];
            $previous = $previousTotals[$channelName] ?? ['total_ht' => 0.0, 'invoice_count' => 0];
            $currentBasket = ((int) $current['invoice_count']) > 0 ? (float) $current['total_ht'] / (int) $current['invoice_count'] : 0.0;
            $previousBasket = ((int) $previous['invoice_count']) > 0 ? (float) $previous['total_ht'] / (int) $previous['invoice_count'] : 0.0;

            $rows[] = [
                'label' => $channelName,
                'current_basket' => $currentBasket,
                'previous_basket' => $previousBasket,
                'basket_delta' => $previousBasket > 0 ? (($currentBasket - $previousBasket) / $previousBasket) * 100.0 : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => abs((float) ($b['basket_delta'] ?? 0)) <=> abs((float) ($a['basket_delta'] ?? 0)));

        return array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'current_basket' => number_format((int) round((float) $row['current_basket']), 0, ',', ' ') . ' €',
            'previous_basket' => number_format((int) round((float) $row['previous_basket']), 0, ',', ' ') . ' €',
            'basket_delta' => $row['basket_delta'] === null ? 'n/a' : (($row['basket_delta'] > 0 ? '+' : '') . number_format((float) $row['basket_delta'], 1, ',', ' ') . ' % vs mois précédent'),
            'delta_class' => $row['basket_delta'] === null ? 'delta-neutral' : ((float) $row['basket_delta'] > 0 ? 'delta-up' : 'delta-down'),
        ], array_slice($rows, 0, 5));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function formatMover(array $row): array
    {
        return [
            'brand_id' => $row['brand_id'],
            'brand_name' => $row['brand_name'],
            'current_total' => number_format((int) round((float) $row['current_total']), 0, ',', ' ') . ' €',
            'previous_total' => number_format((int) round((float) $row['previous_total']), 0, ',', ' ') . ' €',
            'delta' => $row['delta'] === null ? 'n/a' : (($row['delta'] > 0 ? '+' : '') . number_format((float) $row['delta'], 1, ',', ' ') . ' % vs N-1'),
            'delta_class' => $row['delta'] === null ? 'delta-neutral' : ((float) $row['delta'] > 0 ? 'delta-up' : 'delta-down'),
            'absolute_delta' => number_format((int) round((float) $row['absolute_delta']), 0, ',', ' ') . ' €',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchChannelSummaries(DateTimeImmutable $currentStart, DateTimeImmutable $currentEnd, DateTimeImmutable $previousStart, DateTimeImmutable $previousEnd, array $filters = []): array
    {
        $rows = $this->fetchChannelTotals($currentStart, $currentEnd, $filters);
        $previousRows = $this->fetchChannelTotals($previousStart, $previousEnd, $filters);

        $channels = [];
        foreach ($rows as $row) {
            $channelName = (string) ($row['channel_name'] ?? 'Autre');
            $currentTotal = (float) ($row['total_ht'] ?? 0);
            $invoiceCount = (int) ($row['invoice_count'] ?? 0);
            $previousRow = $previousRows[$channelName] ?? [
                'total_ht' => 0.0,
                'margin_ht' => 0.0,
                'invoice_count' => 0,
                'line_count' => 0,
            ];
            $previousTotal = (float) ($previousRow['total_ht'] ?? 0);
            $previousInvoices = (int) ($previousRow['invoice_count'] ?? 0);
            $channels[] = [
                'label' => $channelName,
                'current' => $currentTotal,
                'margin' => (float) ($row['margin_ht'] ?? 0),
                'invoices' => $invoiceCount,
                'lines' => (int) ($row['line_count'] ?? 0),
                'average_basket' => $invoiceCount > 0 ? $currentTotal / $invoiceCount : 0.0,
                'previous' => $previousTotal,
                'previous_margin' => (float) ($previousRow['margin_ht'] ?? 0),
                'previous_invoices' => $previousInvoices,
                'previous_lines' => (int) ($previousRow['line_count'] ?? 0),
                'previous_average_basket' => $previousInvoices > 0 ? $previousTotal / $previousInvoices : 0.0,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'basket_delta' => $previousInvoices > 0 ? (((($invoiceCount > 0 ? $currentTotal / $invoiceCount : 0.0) - ($previousTotal / $previousInvoices)) / max(0.01, ($previousTotal / $previousInvoices))) * 100.0) : null,
                'highlights' => $this->fetchBrandHighlights($currentStart, $currentEnd, $previousStart, $previousEnd, $filters, $channelName),
                'hint' => 'CA HT sur le mois en cours',
            ];
        }

        return $channels;
    }

    /**
     * @return array<string, array{channel_name: string, total_ht: float, margin_ht: float, invoice_count: int, line_count: int}>
     */
    private function fetchChannelTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $rows = $this->reportingConnection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                    SELECT
                        COALESCE(NULLIF(r.channel_name, ''), 'Autre') AS channel_name,
                        COALESCE(SUM(r.total_ht), 0) AS total_ht,
                        COALESCE(SUM(r.margin_ht), 0) AS margin_ht,
                        COUNT(DISTINCT r.invoice_number) AS invoice_count,
                        COUNT(*) AS line_count
                    FROM reporting_invoice_line_fact r
                    WHERE %s
                    GROUP BY COALESCE(NULLIF(r.channel_name, ''), 'Autre')
                    ORDER BY total_ht DESC, channel_name ASC
                SQL,
                $whereSql
            ),
            $params
        );

        $indexed = [];
        foreach ($rows as $row) {
            $channelName = (string) ($row['channel_name'] ?? 'Autre');
            $indexed[$channelName] = [
                'channel_name' => $channelName,
                'total_ht' => (float) ($row['total_ht'] ?? 0),
                'margin_ht' => (float) ($row['margin_ht'] ?? 0),
                'invoice_count' => (int) ($row['invoice_count'] ?? 0),
                'line_count' => (int) ($row['line_count'] ?? 0),
            ];
        }

        return $indexed;
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
        array $filters = [],
        ?string $channelName = null
    ): array {
        $currentTotals = $this->fetchBrandTotals($currentStart, $currentEnd, $channelName, false, $filters);
        $currentOccasionTotals = $this->fetchBrandTotals($currentStart, $currentEnd, $channelName, true, $filters);
        $previousTotals = $this->fetchBrandTotals($previousStart, $previousEnd, $channelName, false, $filters);

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
            $currentOccasionTotal = (float) (($currentOccasionTotals[$brandId]['total_ht'] ?? 0));
            $previousTotal = (float) ($previousRow['total_ht'] ?? 0);

            $brands[] = [
                'brand_id' => $brandId,
                'brand_name' => $currentRow['brand_name'],
                'current_total' => $currentTotal,
                'current_occasion_total' => $currentOccasionTotal,
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
                    'brand_id' => $brand['brand_id'],
                    'brand_name' => $brand['brand_name'],
                    'current_total_raw' => $brand['current_total'],
                    'current_occasion_total_raw' => $brand['current_occasion_total'],
                    'previous_total_raw' => $brand['previous_total'],
                    'current_total' => number_format((int) round((float) $brand['current_total']), 0, ',', ' ') . ' €',
                    'current_occasion_total' => number_format((int) round((float) $brand['current_occasion_total']), 0, ',', ' ') . ' €',
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
    private function fetchBrandTotals(DateTimeImmutable $start, DateTimeImmutable $end, ?string $channelName = null, bool $includeOccasion = false, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        if ($channelName !== null) {
            $params['channel_name'] = $channelName;
            $whereSql .= ' AND COALESCE(NULLIF(r.channel_name, \'\'), \'Autre\') = :channel_name';
        }

        $occasionFilterSql = $this->buildOccasionFilterSql('r');
        $whereSql .= $includeOccasion ? ' AND ' . $occasionFilterSql : ' AND NOT ' . $occasionFilterSql;

        $sql = sprintf(
            <<<'SQL'
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
                WHERE %s
                GROUP BY COALESCE(r.brand_id, 0), brand_name
                ORDER BY total_ht DESC, brand_name ASC
            SQL,
            $whereSql
        );

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
    private function fetchTrend(DateTimeImmutable $currentStart, DateTimeImmutable $currentEnd, DateTimeImmutable $previousStart, DateTimeImmutable $previousEnd, array $filters = []): array
    {
        $currentTotals = $this->fetchDailyTotals($currentStart, $currentEnd, $filters);
        $previousTotals = $this->fetchDailyTotals($previousStart, $previousEnd, $filters);

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
    private function fetchDailyTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $rows = $this->reportingConnection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                SELECT
                    r.invoice_date,
                    COALESCE(SUM(r.total_ht), 0) AS total_ht
                FROM reporting_invoice_line_fact r
                WHERE %s
                GROUP BY r.invoice_date
                ORDER BY invoice_date ASC
                SQL,
                $whereSql
            ),
            $params
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
