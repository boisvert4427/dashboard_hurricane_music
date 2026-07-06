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
     *     previous_six_month_period: array<string, mixed>,
     *     previous_three_month_period: array<string, mixed>,
     *     current_summary: array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float},
     *     previous_summary: array{total_ht: float, margin_ht: float, invoice_count: int, line_count: int, quantity: float},
     *     kpis: array<int, array<string, mixed>>,
     *     occasion: array<string, mixed>,
     *     brand_highlights: array<string, mixed>,
     *     category_highlights: array<string, mixed>,
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
        $trendPeriods = [
            'trend_1y' => $this->buildRollingComparisonPeriod($period['current_end'], 12),
            'trend_6m' => $this->buildRollingComparisonPeriod($period['current_end'], 6),
            'trend_3m' => $this->buildRollingComparisonPeriod($period['current_end'], 3),
        ];
        $rollingSummaries = [];
        foreach ($trendPeriods as $key => $trendPeriod) {
            $rollingSummaries[$key] = [
                'current' => $this->fetchPeriodSummary($trendPeriod['current_start'], $trendPeriod['current_end'], $filters),
                'previous' => $this->fetchPeriodSummary($trendPeriod['previous_start'], $trendPeriod['previous_end'], $filters),
            ];
        }

        return [
            'current_period' => [
                'start' => $period['current_start'],
                'end' => $period['current_end'],
            ],
            'previous_period' => [
                'start' => $period['previous_year_start'],
                'end' => $period['previous_year_end'],
            ],
            'previous_six_month_period' => [
                'start' => $period['previous_six_month_start'],
                'end' => $period['previous_six_month_end'],
            ],
            'previous_three_month_period' => [
                'start' => $period['previous_three_month_start'],
                'end' => $period['previous_three_month_end'],
            ],
            'previous_month_period' => [
                'start' => $period['previous_month_start'],
                'end' => $period['previous_month_end'],
            ],
            'current_summary' => $current,
            'previous_summary' => $previousYear,
            'previous_month_summary' => $previousMonth,
            'objective_summary' => $objective,
            'rolling_summaries' => $rollingSummaries,
            'kpis' => $this->buildKpis($current, $previousYear, $previousMonth, $objective),
            'neuf' => $this->fetchNeufSummary($period['current_start'], $period['current_end'], $period['previous_year_start'], $period['previous_year_end'], $filters),
            'occasion' => $this->fetchOccasionSummary($period['current_start'], $period['current_end'], $period['previous_year_start'], $period['previous_year_end'], $filters),
            'brand_highlights' => $this->fetchBrandHighlights(
                $period['current_start'],
                $period['current_end'],
                $period['previous_year_start'],
                $period['previous_year_end'],
                $period['previous_six_month_start'],
                $period['previous_six_month_end'],
                $period['previous_three_month_start'],
                $period['previous_three_month_end'],
                $filters
            ),
            'category_highlights' => $this->fetchCategoryHighlights(
                $period['current_start'],
                $period['current_end'],
                $period['previous_year_start'],
                $period['previous_year_end'],
                $period['previous_six_month_start'],
                $period['previous_six_month_end'],
                $period['previous_three_month_start'],
                $period['previous_three_month_end'],
                $filters
            ),
            'channels' => $this->fetchChannelSummaries(
                $period['current_start'],
                $period['current_end'],
                $period['previous_year_start'],
                $period['previous_year_end'],
                $period['previous_six_month_start'],
                $period['previous_six_month_end'],
                $period['previous_three_month_start'],
                $period['previous_three_month_end'],
                $filters
            ),
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
            $this->buildKpiCard('CA période', $current['total_ht'], $previous['total_ht'], 'Somme des lignes de facture HT sur la période', 'money'),
            $this->buildKpiCard('Marge période', $current['margin_ht'], $previous['margin_ht'], 'Marge brute sur la même période', 'money'),
            $this->buildKpiCard('Factures', $current['invoice_count'], $previous['invoice_count'], 'Nombre de factures distinctes', 'count'),
            $this->buildKpiCard('Panier moyen', $currentAverageBasket, $previousAverageBasket, 'CA moyen par facture', 'money'),
            $this->buildKpiCard('CA vs mois précédent', $current['total_ht'], $previousMonth['total_ht'], 'Lecture rapide vs mois précédent', 'money'),
            $this->buildKpiCard('Objectif période', $current['total_ht'], $objective['objective_ht'] > 0 ? $objective['objective_ht'] : $current['total_ht'], $objective['objective_ht'] > 0 ? 'Cumul vs objectif' : 'Objectif non configuré', 'money'),
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
     *     previous_six_month_start: DateTimeImmutable,
     *     previous_six_month_end: DateTimeImmutable,
     *     previous_three_month_start: DateTimeImmutable,
     *     previous_three_month_end: DateTimeImmutable,
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
            'previous_six_month_start' => $currentStart->modify('-6 months'),
            'previous_six_month_end' => $currentEnd->modify('-6 months'),
            'previous_three_month_start' => $currentStart->modify('-3 months'),
            'previous_three_month_end' => $currentEnd->modify('-3 months'),
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
     * @return array{channels: array<int, array{id:int, label:string}>, brands: array<int, array{id:int, label:string}>, categories: array<int, array{id:string, label:string}>}
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

        $categories = $this->reportingConnection->fetchAllAssociative(
            <<<SQL
                SELECT DISTINCT
                    CASE
                        WHEN COALESCE(NULLIF(subfamily_name, ''), '') = '' THEN CONCAT_WS(' > ',
                            COALESCE(NULLIF(rayon_name, ''), 'Sans rayon'),
                            COALESCE(NULLIF(family_name, ''), 'Sans famille')
                        )
                        ELSE CONCAT_WS(' > ',
                            COALESCE(NULLIF(rayon_name, ''), 'Sans rayon'),
                            COALESCE(NULLIF(family_name, ''), 'Sans famille'),
                            subfamily_name
                        )
                    END AS label
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
            'categories' => array_map(static fn (array $row): array => [
                'value' => (string) ($row['label'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
            ], $categories),
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
                __CATEGORY_EXPR__ AS category_name,
                r.product_code,
                r.product_name,
                r.supplier_name,
                r.supplier_reference,
                r.quantity,
                r.total_ht,
                r.margin_ht,
                r.customer_id,
                CASE
                    WHEN LOWER(COALESCE(r.product_code, '')) LIKE 'b-%%'
                        OR LOWER(COALESCE(r.product_code, '')) LIKE '%%occas%%'
                        OR UPPER(COALESCE(r.product_code, '')) LIKE 'DEPV%%'
                        OR LOWER(COALESCE(r.product_name, '')) LIKE '%%occas%%'
                    THEN 1
                    ELSE 0
                END AS is_occasion
            FROM reporting_invoice_line_fact r
            WHERE {$whereSql}
            ORDER BY {$orderSql}
            LIMIT {$perPage} OFFSET {$offset}
        SQL;
        $rowsSql = str_replace('__CATEGORY_EXPR__', $this->buildCategoryExpression('r'), $rowsSql);

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
        [$previousWhereSql, $previousParams] = $this->buildWhereClause($period['previous_year_start'], $period['previous_year_end'], $filters, 'p');
        $orderSql = $this->buildOrderByClause($sort, $direction);

        $previousWhereSql = str_replace(
            [':start_date', ':end_date', ':channel_name', ':brand_id', ':category_name', ':search'],
            [':previous_start_date', ':previous_end_date', ':previous_channel_name', ':previous_brand_id', ':previous_category_name', ':previous_search'],
            $previousWhereSql
        );
        $previousParams = [
            'previous_start_date' => $previousParams['start_date'] ?? null,
            'previous_end_date' => $previousParams['end_date'] ?? null,
            'previous_channel_name' => $previousParams['channel_name'] ?? null,
            'previous_brand_id' => $previousParams['brand_id'] ?? null,
            'previous_category_name' => $previousParams['category_name'] ?? null,
            'previous_search' => $previousParams['search'] ?? null,
        ];

        $sql = <<<SQL
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
                COALESCE(prev.previous_quantity, 0) AS previous_quantity,
                r.total_ht,
                COALESCE(prev.previous_total_ht, 0) AS previous_total_ht,
                r.margin_ht,
                r.customer_id,
                CASE
                    WHEN LOWER(COALESCE(r.product_code, '')) LIKE 'b-%%'
                        OR LOWER(COALESCE(r.product_code, '')) LIKE '%%occas%%'
                        OR UPPER(COALESCE(r.product_code, '')) LIKE 'DEPV%%'
                        OR LOWER(COALESCE(r.product_name, '')) LIKE '%%occas%%'
                    THEN 1
                    ELSE 0
                END AS is_occasion
            FROM reporting_invoice_line_fact r
            LEFT JOIN (
                SELECT
                    p.product_id AS idart,
                    COALESCE(NULLIF(p.channel_name, ''), 'Autre') AS channel_name,
                    COALESCE(p.brand_id, 0) AS brand_id,
                    COALESCE(NULLIF(p.brand_name, ''), CASE
                        WHEN COALESCE(p.brand_id, 0) = 0 THEN 'Sans marque'
                        ELSE CONCAT('IDFAB ', COALESCE(p.brand_id, 0))
                    END) AS brand_name,
                    p.product_code,
                    p.supplier_reference,
                    __PREVIOUS_CATEGORY_EXPR__ AS category_name,
                    CASE
                        WHEN LOWER(COALESCE(p.product_code, '')) LIKE 'b-%%'
                            OR LOWER(COALESCE(p.product_code, '')) LIKE '%%occas%%'
                            OR UPPER(COALESCE(p.product_code, '')) LIKE 'DEPV%%'
                            OR LOWER(COALESCE(p.product_name, '')) LIKE '%%occas%%'
                        THEN 1
                        ELSE 0
                    END AS is_occasion,
                    COALESCE(SUM(p.quantity), 0) AS previous_quantity,
                    COALESCE(SUM(p.total_ht), 0) AS previous_total_ht
                FROM reporting_invoice_line_fact p
                WHERE {$previousWhereSql}
                GROUP BY
                    p.product_id,
                    channel_name,
                    brand_id,
                    brand_name,
                    p.product_code,
                    p.supplier_reference,
                    category_name,
                    is_occasion
            ) prev ON prev.idart = r.product_id
                AND prev.channel_name = COALESCE(NULLIF(r.channel_name, ''), 'Autre')
                AND prev.brand_id = COALESCE(r.brand_id, 0)
                AND prev.product_code = r.product_code
                AND prev.supplier_reference = r.supplier_reference
                AND prev.category_name = __CATEGORY_EXPR__
                AND prev.is_occasion = CASE
                    WHEN LOWER(COALESCE(r.product_code, '')) LIKE 'b-%%'
                        OR LOWER(COALESCE(r.product_code, '')) LIKE '%%occas%%'
                        OR UPPER(COALESCE(r.product_code, '')) LIKE 'DEPV%%'
                        OR LOWER(COALESCE(r.product_name, '')) LIKE '%%occas%%'
                    THEN 1
                    ELSE 0
                END
            WHERE {$whereSql}
            ORDER BY {$orderSql}
        SQL;
        $sql = str_replace('__CATEGORY_EXPR__', $this->buildCategoryExpression('r'), $sql);
        $sql = str_replace('__PREVIOUS_CATEGORY_EXPR__', $this->buildCategoryExpression('p'), $sql);

        return $this->reportingConnection->iterateAssociative($sql, array_merge($params, $previousParams));
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

        if (!empty($filters['category'])) {
                $categoryExpr = $this->buildCategoryExpression($alias);
            $conditions[] = $categoryExpr . ' = :category_name';
            $params['category_name'] = (string) $filters['category'];
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
            'category_name' => 'category_name',
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

        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => max((float) ($row['current_total'] ?? 0), (float) ($row['previous_total'] ?? 0)) >= 500.0
        ));

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
            'delta' => $row['delta'] === null ? '--' : (($row['delta'] > 0 ? '+' : '') . number_format((float) $row['delta'], 1, ',', ' ') . ' %'),
            'basket_delta' => $row['basket_delta'] === null ? '--' : (($row['basket_delta'] > 0 ? '+' : '') . number_format((float) $row['basket_delta'], 1, ',', ' ') . ' %'),
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
            'basket_delta' => $row['basket_delta'] === null ? '--' : (($row['basket_delta'] > 0 ? '+' : '') . number_format((float) $row['basket_delta'], 1, ',', ' ') . ' %'),
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
            'delta' => $row['delta'] === null ? '--' : (($row['delta'] > 0 ? '+' : '') . number_format((float) $row['delta'], 1, ',', ' ') . ' %'),
            'delta_class' => $row['delta'] === null ? 'delta-neutral' : ((float) $row['delta'] > 0 ? 'delta-up' : 'delta-down'),
            'absolute_delta' => number_format((int) round((float) $row['absolute_delta']), 0, ',', ' ') . ' €',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchChannelSummaries(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd,
        DateTimeImmutable $previousSixMonthStart,
        DateTimeImmutable $previousSixMonthEnd,
        DateTimeImmutable $previousThreeMonthStart,
        DateTimeImmutable $previousThreeMonthEnd,
        array $filters = []
    ): array
    {
        $rows = $this->fetchChannelTotals($currentStart, $currentEnd, $filters);
        $previousRows = $this->fetchChannelTotals($previousStart, $previousEnd, $filters);
        $trend1y = $this->buildRollingComparisonPeriod($currentEnd, 12);
        $trend6m = $this->buildRollingComparisonPeriod($currentEnd, 6);
        $trend3m = $this->buildRollingComparisonPeriod($currentEnd, 3);
        $trend1yCurrentRows = $this->fetchChannelTotals($trend1y['current_start'], $trend1y['current_end'], $filters);
        $trend1yPreviousRows = $this->fetchChannelTotals($trend1y['previous_start'], $trend1y['previous_end'], $filters);
        $trend6mCurrentRows = $this->fetchChannelTotals($trend6m['current_start'], $trend6m['current_end'], $filters);
        $trend6mPreviousRows = $this->fetchChannelTotals($trend6m['previous_start'], $trend6m['previous_end'], $filters);
        $trend3mCurrentRows = $this->fetchChannelTotals($trend3m['current_start'], $trend3m['current_end'], $filters);
        $trend3mPreviousRows = $this->fetchChannelTotals($trend3m['previous_start'], $trend3m['previous_end'], $filters);

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
            $trend1yCurrent = (float) ($trend1yCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend1yPrevious = (float) ($trend1yPreviousRows[$channelName]['total_ht'] ?? 0);
            $trend6mCurrent = (float) ($trend6mCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend6mPrevious = (float) ($trend6mPreviousRows[$channelName]['total_ht'] ?? 0);
            $trend3mCurrent = (float) ($trend3mCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend3mPrevious = (float) ($trend3mPreviousRows[$channelName]['total_ht'] ?? 0);
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
                'trend_1y' => $trend1yPrevious > 0 ? (($trend1yCurrent - $trend1yPrevious) / $trend1yPrevious) * 100.0 : null,
                'trend_6m' => $trend6mPrevious > 0 ? (($trend6mCurrent - $trend6mPrevious) / $trend6mPrevious) * 100.0 : null,
                'trend_3m' => $trend3mPrevious > 0 ? (($trend3mCurrent - $trend3mPrevious) / $trend3mPrevious) * 100.0 : null,
                'highlights' => $this->fetchBrandHighlights(
                    $currentStart,
                    $currentEnd,
                    $previousStart,
                    $previousEnd,
                    $previousSixMonthStart,
                    $previousSixMonthEnd,
                    $previousThreeMonthStart,
                    $previousThreeMonthEnd,
                    $filters,
                    $channelName
                ),
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
    private function fetchOccasionSummary(DateTimeImmutable $currentStart, DateTimeImmutable $currentEnd, DateTimeImmutable $previousStart, DateTimeImmutable $previousEnd, array $filters = []): array
    {
        $current = $this->fetchOccasionTotals($currentStart, $currentEnd, $filters);
        $previous = $this->fetchOccasionTotals($previousStart, $previousEnd, $filters);
        $trend1y = $this->buildRollingComparisonPeriod($currentEnd, 12);
        $trend6m = $this->buildRollingComparisonPeriod($currentEnd, 6);
        $trend3m = $this->buildRollingComparisonPeriod($currentEnd, 3);
        $trend1yCurrent = $this->fetchOccasionTotals($trend1y['current_start'], $trend1y['current_end'], $filters);
        $trend1yPrevious = $this->fetchOccasionTotals($trend1y['previous_start'], $trend1y['previous_end'], $filters);
        $trend6mCurrent = $this->fetchOccasionTotals($trend6m['current_start'], $trend6m['current_end'], $filters);
        $trend6mPrevious = $this->fetchOccasionTotals($trend6m['previous_start'], $trend6m['previous_end'], $filters);
        $trend3mCurrent = $this->fetchOccasionTotals($trend3m['current_start'], $trend3m['current_end'], $filters);
        $trend3mPrevious = $this->fetchOccasionTotals($trend3m['previous_start'], $trend3m['previous_end'], $filters);

        return [
            'current_total' => $current['total_ht'],
            'previous_total' => $previous['total_ht'],
            'delta' => $previous['total_ht'] > 0
                ? (($current['total_ht'] - $previous['total_ht']) / $previous['total_ht']) * 100.0
                : null,
            'trend_1y' => $trend1yPrevious['total_ht'] > 0 ? (($trend1yCurrent['total_ht'] - $trend1yPrevious['total_ht']) / $trend1yPrevious['total_ht']) * 100.0 : null,
            'trend_6m' => $trend6mPrevious['total_ht'] > 0 ? (($trend6mCurrent['total_ht'] - $trend6mPrevious['total_ht']) / $trend6mPrevious['total_ht']) * 100.0 : null,
            'trend_3m' => $trend3mPrevious['total_ht'] > 0 ? (($trend3mCurrent['total_ht'] - $trend3mPrevious['total_ht']) / $trend3mPrevious['total_ht']) * 100.0 : null,
            'current_lines' => $current['line_count'],
            'previous_lines' => $previous['line_count'],
            'current_invoices' => $current['invoice_count'],
            'previous_invoices' => $previous['invoice_count'],
            'channels' => $this->fetchOccasionChannelSummaries($currentStart, $currentEnd, $previousStart, $previousEnd, $filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchNeufSummary(DateTimeImmutable $currentStart, DateTimeImmutable $currentEnd, DateTimeImmutable $previousStart, DateTimeImmutable $previousEnd, array $filters = []): array
    {
        $current = $this->fetchNeufTotals($currentStart, $currentEnd, $filters);
        $previous = $this->fetchNeufTotals($previousStart, $previousEnd, $filters);
        $trend1y = $this->buildRollingComparisonPeriod($currentEnd, 12);
        $trend6m = $this->buildRollingComparisonPeriod($currentEnd, 6);
        $trend3m = $this->buildRollingComparisonPeriod($currentEnd, 3);
        $trend1yCurrent = $this->fetchNeufTotals($trend1y['current_start'], $trend1y['current_end'], $filters);
        $trend1yPrevious = $this->fetchNeufTotals($trend1y['previous_start'], $trend1y['previous_end'], $filters);
        $trend6mCurrent = $this->fetchNeufTotals($trend6m['current_start'], $trend6m['current_end'], $filters);
        $trend6mPrevious = $this->fetchNeufTotals($trend6m['previous_start'], $trend6m['previous_end'], $filters);
        $trend3mCurrent = $this->fetchNeufTotals($trend3m['current_start'], $trend3m['current_end'], $filters);
        $trend3mPrevious = $this->fetchNeufTotals($trend3m['previous_start'], $trend3m['previous_end'], $filters);

        return [
            'current_total' => $current['total_ht'],
            'previous_total' => $previous['total_ht'],
            'delta' => $previous['total_ht'] > 0
                ? (($current['total_ht'] - $previous['total_ht']) / $previous['total_ht']) * 100.0
                : null,
            'trend_1y' => $trend1yPrevious['total_ht'] > 0 ? (($trend1yCurrent['total_ht'] - $trend1yPrevious['total_ht']) / $trend1yPrevious['total_ht']) * 100.0 : null,
            'trend_6m' => $trend6mPrevious['total_ht'] > 0 ? (($trend6mCurrent['total_ht'] - $trend6mPrevious['total_ht']) / $trend6mPrevious['total_ht']) * 100.0 : null,
            'trend_3m' => $trend3mPrevious['total_ht'] > 0 ? (($trend3mCurrent['total_ht'] - $trend3mPrevious['total_ht']) / $trend3mPrevious['total_ht']) * 100.0 : null,
            'current_lines' => $current['line_count'],
            'previous_lines' => $previous['line_count'],
            'current_invoices' => $current['invoice_count'],
            'previous_invoices' => $previous['invoice_count'],
            'channels' => $this->fetchNeufChannelSummaries($currentStart, $currentEnd, $previousStart, $previousEnd, $filters),
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
        DateTimeImmutable $previousSixMonthStart,
        DateTimeImmutable $previousSixMonthEnd,
        DateTimeImmutable $previousThreeMonthStart,
        DateTimeImmutable $previousThreeMonthEnd,
        array $filters = [],
        ?string $channelName = null
    ): array {
        $currentTotals = $this->fetchBrandTotals($currentStart, $currentEnd, $channelName, false, $filters);
        $currentOccasionTotals = $this->fetchBrandTotals($currentStart, $currentEnd, $channelName, true, $filters);
        $previousTotals = $this->fetchBrandTotals($previousStart, $previousEnd, $channelName, false, $filters);
        $previousOccasionTotals = $this->fetchBrandTotals($previousStart, $previousEnd, $channelName, true, $filters);
        $currentChannelTotals = $this->fetchBrandChannelTotals($currentStart, $currentEnd, $filters);
        $previousChannelTotals = $this->fetchBrandChannelTotals($previousStart, $previousEnd, $filters);
        $trend1yPeriod = $this->buildRollingComparisonPeriod($currentEnd, 12);
        $trend6mPeriod = $this->buildRollingComparisonPeriod($currentEnd, 6);
        $trend3mPeriod = $this->buildRollingComparisonPeriod($currentEnd, 3);
        $trend1yTotals = $this->fetchBrandTotals($trend1yPeriod['current_start'], $trend1yPeriod['current_end'], $channelName, false, $filters);
        $trend1yOccasionTotals = $this->fetchBrandTotals($trend1yPeriod['current_start'], $trend1yPeriod['current_end'], $channelName, true, $filters);
        $trend1yPreviousTotals = $this->fetchBrandTotals($trend1yPeriod['previous_start'], $trend1yPeriod['previous_end'], $channelName, false, $filters);
        $trend1yPreviousOccasionTotals = $this->fetchBrandTotals($trend1yPeriod['previous_start'], $trend1yPeriod['previous_end'], $channelName, true, $filters);
        $trend6mTotals = $this->fetchBrandTotals($trend6mPeriod['current_start'], $trend6mPeriod['current_end'], $channelName, false, $filters);
        $trend6mOccasionTotals = $this->fetchBrandTotals($trend6mPeriod['current_start'], $trend6mPeriod['current_end'], $channelName, true, $filters);
        $trend6mPreviousTotals = $this->fetchBrandTotals($trend6mPeriod['previous_start'], $trend6mPeriod['previous_end'], $channelName, false, $filters);
        $trend6mPreviousOccasionTotals = $this->fetchBrandTotals($trend6mPeriod['previous_start'], $trend6mPeriod['previous_end'], $channelName, true, $filters);
        $trend3mTotals = $this->fetchBrandTotals($trend3mPeriod['current_start'], $trend3mPeriod['current_end'], $channelName, false, $filters);
        $trend3mOccasionTotals = $this->fetchBrandTotals($trend3mPeriod['current_start'], $trend3mPeriod['current_end'], $channelName, true, $filters);
        $trend3mPreviousTotals = $this->fetchBrandTotals($trend3mPeriod['previous_start'], $trend3mPeriod['previous_end'], $channelName, false, $filters);
        $trend3mPreviousOccasionTotals = $this->fetchBrandTotals($trend3mPeriod['previous_start'], $trend3mPeriod['previous_end'], $channelName, true, $filters);

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
            $previousOccasionRow = $previousOccasionTotals[$brandId] ?? [
                'brand_id' => $brandId,
                'brand_name' => $currentRow['brand_name'],
                'total_ht' => 0.0,
                'margin_ht' => 0.0,
                'invoice_count' => 0,
            ];

            $currentTotal = (float) ($currentRow['total_ht'] ?? 0);
            $currentOccasionTotal = (float) (($currentOccasionTotals[$brandId]['total_ht'] ?? 0));
            $previousTotal = (float) ($previousRow['total_ht'] ?? 0);
            $previousOccasionTotal = (float) ($previousOccasionRow['total_ht'] ?? 0);
            $currentGlobalTotal = $currentTotal + $currentOccasionTotal;
            $previousGlobalTotal = $previousTotal + $previousOccasionTotal;
            $trend1yCurrentGlobalTotal = (float) (($trend1yTotals[$brandId]['total_ht'] ?? 0) + ($trend1yOccasionTotals[$brandId]['total_ht'] ?? 0));
            $trend1yPreviousGlobalTotal = (float) (($trend1yPreviousTotals[$brandId]['total_ht'] ?? 0) + ($trend1yPreviousOccasionTotals[$brandId]['total_ht'] ?? 0));
            $trend6mCurrentGlobalTotal = (float) (($trend6mTotals[$brandId]['total_ht'] ?? 0) + ($trend6mOccasionTotals[$brandId]['total_ht'] ?? 0));
            $trend6mPreviousGlobalTotal = (float) (($trend6mPreviousTotals[$brandId]['total_ht'] ?? 0) + ($trend6mPreviousOccasionTotals[$brandId]['total_ht'] ?? 0));
            $trend3mCurrentGlobalTotal = (float) (($trend3mTotals[$brandId]['total_ht'] ?? 0) + ($trend3mOccasionTotals[$brandId]['total_ht'] ?? 0));
            $trend3mPreviousGlobalTotal = (float) (($trend3mPreviousTotals[$brandId]['total_ht'] ?? 0) + ($trend3mPreviousOccasionTotals[$brandId]['total_ht'] ?? 0));

            $brands[] = [
                'brand_id' => $brandId,
                'brand_name' => $currentRow['brand_name'],
                'current_total' => $currentTotal,
                'current_occasion_total' => $currentOccasionTotal,
                'previous_total' => $previousTotal,
                'previous_occasion_total' => $previousOccasionTotal,
                'current_global_total' => $currentGlobalTotal,
                'previous_global_total' => $previousGlobalTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'absolute_delta' => $currentTotal - $previousTotal,
                'trend_1y' => $trend1yPreviousGlobalTotal > 0 ? (($trend1yCurrentGlobalTotal - $trend1yPreviousGlobalTotal) / $trend1yPreviousGlobalTotal) * 100.0 : null,
                'trend_6m' => $trend6mPreviousGlobalTotal > 0 ? (($trend6mCurrentGlobalTotal - $trend6mPreviousGlobalTotal) / $trend6mPreviousGlobalTotal) * 100.0 : null,
                'trend_3m' => $trend3mPreviousGlobalTotal > 0 ? (($trend3mCurrentGlobalTotal - $trend3mPreviousGlobalTotal) / $trend3mPreviousGlobalTotal) * 100.0 : null,
                'channels' => $this->buildBrandChannelBreakdown(
                    $brandId,
                    $currentChannelTotals,
                    $previousChannelTotals
                ),
            ];
        }

        usort($brands, static fn (array $a, array $b): int => $b['current_global_total'] <=> $a['current_global_total']);

        return [
            'scope_label' => $channelName ?? 'global',
            'top_brands' => array_map(
                static fn (array $brand): array => [
                    'brand_id' => $brand['brand_id'],
                    'brand_name' => $brand['brand_name'],
                    'current_total_raw' => $brand['current_total'],
                    'current_occasion_total_raw' => $brand['current_occasion_total'],
                    'previous_total_raw' => $brand['previous_total'],
                    'previous_occasion_total_raw' => $brand['previous_occasion_total'],
                    'current_global_total_raw' => $brand['current_global_total'],
                    'previous_global_total_raw' => $brand['previous_global_total'],
                    'delta_raw' => $brand['delta'],
                    'trend_1y_raw' => $brand['trend_1y'],
                    'trend_6m_raw' => $brand['trend_6m'],
                    'trend_3m_raw' => $brand['trend_3m'],
                    'channels' => $brand['channels'],
                    'current_total' => number_format((int) round((float) $brand['current_total']), 0, ',', ' ') . ' €',
                    'current_occasion_total' => number_format((int) round((float) $brand['current_occasion_total']), 0, ',', ' ') . ' €',
                    'previous_total' => number_format((int) round((float) $brand['previous_total']), 0, ',', ' ') . ' €',
                    'previous_occasion_total' => number_format((int) round((float) $brand['previous_occasion_total']), 0, ',', ' ') . ' €',
                    'delta' => $brand['delta'] === null ? '--' : (($brand['delta'] > 0 ? '+' : '') . number_format((float) $brand['delta'], 1, ',', ' ') . ' %'),
                    'delta_class' => $brand['delta'] === null ? 'delta-neutral' : ((float) $brand['delta'] > 0 ? 'delta-up' : ((float) $brand['delta'] < 0 ? 'delta-down' : 'delta-neutral')),
                ],
                array_slice($brands, 0, 8)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCategoryHighlights(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd,
        DateTimeImmutable $previousSixMonthStart,
        DateTimeImmutable $previousSixMonthEnd,
        DateTimeImmutable $previousThreeMonthStart,
        DateTimeImmutable $previousThreeMonthEnd,
        array $filters = []
    ): array {
        $currentTotals = $this->fetchCategoryTotals($currentStart, $currentEnd, $filters, false);
        $currentOccasionTotals = $this->fetchCategoryTotals($currentStart, $currentEnd, $filters, true);
        $previousTotals = $this->fetchCategoryTotals($previousStart, $previousEnd, $filters, false);
        $previousOccasionTotals = $this->fetchCategoryTotals($previousStart, $previousEnd, $filters, true);
        $currentChannelTotals = $this->fetchCategoryChannelTotals($currentStart, $currentEnd, $filters, false);
        $currentOccasionChannelTotals = $this->fetchCategoryChannelTotals($currentStart, $currentEnd, $filters, true);
        $previousChannelTotals = $this->fetchCategoryChannelTotals($previousStart, $previousEnd, $filters, false);
        $previousOccasionChannelTotals = $this->fetchCategoryChannelTotals($previousStart, $previousEnd, $filters, true);
        $currentPeriodTotal = $this->fetchPeriodSummary($currentStart, $currentEnd, $filters)['total_ht'];
        $trend1yPeriod = $this->buildRollingComparisonPeriod($currentEnd, 12);
        $trend6mPeriod = $this->buildRollingComparisonPeriod($currentEnd, 6);
        $trend3mPeriod = $this->buildRollingComparisonPeriod($currentEnd, 3);
        $trend1yCurrentTotals = $this->fetchCategoryTotals($trend1yPeriod['current_start'], $trend1yPeriod['current_end'], $filters, false);
        $trend1yCurrentOccasionTotals = $this->fetchCategoryTotals($trend1yPeriod['current_start'], $trend1yPeriod['current_end'], $filters, true);
        $trend1yPreviousTotals = $this->fetchCategoryTotals($trend1yPeriod['previous_start'], $trend1yPeriod['previous_end'], $filters, false);
        $trend1yPreviousOccasionTotals = $this->fetchCategoryTotals($trend1yPeriod['previous_start'], $trend1yPeriod['previous_end'], $filters, true);
        $trend6mCurrentTotals = $this->fetchCategoryTotals($trend6mPeriod['current_start'], $trend6mPeriod['current_end'], $filters, false);
        $trend6mCurrentOccasionTotals = $this->fetchCategoryTotals($trend6mPeriod['current_start'], $trend6mPeriod['current_end'], $filters, true);
        $trend6mPreviousTotals = $this->fetchCategoryTotals($trend6mPeriod['previous_start'], $trend6mPeriod['previous_end'], $filters, false);
        $trend6mPreviousOccasionTotals = $this->fetchCategoryTotals($trend6mPeriod['previous_start'], $trend6mPeriod['previous_end'], $filters, true);
        $trend3mCurrentTotals = $this->fetchCategoryTotals($trend3mPeriod['current_start'], $trend3mPeriod['current_end'], $filters, false);
        $trend3mCurrentOccasionTotals = $this->fetchCategoryTotals($trend3mPeriod['current_start'], $trend3mPeriod['current_end'], $filters, true);
        $trend3mPreviousTotals = $this->fetchCategoryTotals($trend3mPeriod['previous_start'], $trend3mPeriod['previous_end'], $filters, false);
        $trend3mPreviousOccasionTotals = $this->fetchCategoryTotals($trend3mPeriod['previous_start'], $trend3mPeriod['previous_end'], $filters, true);

        $categoryNames = array_unique(array_merge(array_keys($currentTotals), array_keys($currentOccasionTotals), array_keys($previousTotals), array_keys($previousOccasionTotals)));
        $categories = [];
        foreach ($categoryNames as $categoryName) {
            $currentRow = $currentTotals[$categoryName] ?? ['total_ht' => 0.0];
            $currentOccasionRow = $currentOccasionTotals[$categoryName] ?? ['total_ht' => 0.0];
            $previousRow = $previousTotals[$categoryName] ?? ['total_ht' => 0.0];
            $previousOccasionRow = $previousOccasionTotals[$categoryName] ?? ['total_ht' => 0.0];
            $currentTotal = (float) ($currentRow['total_ht'] ?? 0);
            $currentOccasionTotal = (float) ($currentOccasionRow['total_ht'] ?? 0);
            $previousTotal = (float) ($previousRow['total_ht'] ?? 0);
            $previousOccasionTotal = (float) ($previousOccasionRow['total_ht'] ?? 0);
            $currentGlobalTotal = $currentTotal + $currentOccasionTotal;
            $previousGlobalTotal = $previousTotal + $previousOccasionTotal;
            $trend1yCurrentGlobalTotal = (float) (($trend1yCurrentTotals[$categoryName]['total_ht'] ?? 0) + ($trend1yCurrentOccasionTotals[$categoryName]['total_ht'] ?? 0));
            $trend1yPreviousGlobalTotal = (float) (($trend1yPreviousTotals[$categoryName]['total_ht'] ?? 0) + ($trend1yPreviousOccasionTotals[$categoryName]['total_ht'] ?? 0));
            $trend6mCurrentGlobalTotal = (float) (($trend6mCurrentTotals[$categoryName]['total_ht'] ?? 0) + ($trend6mCurrentOccasionTotals[$categoryName]['total_ht'] ?? 0));
            $trend6mPreviousGlobalTotal = (float) (($trend6mPreviousTotals[$categoryName]['total_ht'] ?? 0) + ($trend6mPreviousOccasionTotals[$categoryName]['total_ht'] ?? 0));
            $trend3mCurrentGlobalTotal = (float) (($trend3mCurrentTotals[$categoryName]['total_ht'] ?? 0) + ($trend3mCurrentOccasionTotals[$categoryName]['total_ht'] ?? 0));
            $trend3mPreviousGlobalTotal = (float) (($trend3mPreviousTotals[$categoryName]['total_ht'] ?? 0) + ($trend3mPreviousOccasionTotals[$categoryName]['total_ht'] ?? 0));

            $categories[] = [
                'category_name' => $categoryName,
                'current_total' => $currentTotal,
                'current_occasion_total' => $currentOccasionTotal,
                'previous_total' => $previousTotal,
                'previous_occasion_total' => $previousOccasionTotal,
                'current_global_total' => $currentGlobalTotal,
                'previous_global_total' => $previousGlobalTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'trend_1y' => $trend1yPreviousGlobalTotal > 0 ? (($trend1yCurrentGlobalTotal - $trend1yPreviousGlobalTotal) / $trend1yPreviousGlobalTotal) * 100.0 : null,
                'trend_6m' => $trend6mPreviousGlobalTotal > 0 ? (($trend6mCurrentGlobalTotal - $trend6mPreviousGlobalTotal) / $trend6mPreviousGlobalTotal) * 100.0 : null,
                'trend_3m' => $trend3mPreviousGlobalTotal > 0 ? (($trend3mCurrentGlobalTotal - $trend3mPreviousGlobalTotal) / $trend3mPreviousGlobalTotal) * 100.0 : null,
                'channels' => $this->buildCategoryChannelBreakdown(
                    $categoryName,
                    $currentChannelTotals,
                    $currentOccasionChannelTotals,
                    $previousChannelTotals,
                    $previousOccasionChannelTotals
                ),
            ];
        }

        usort($categories, static fn (array $a, array $b): int => $b['current_global_total'] <=> $a['current_global_total']);

        return [
            'scope_label' => 'global',
            'top_categories' => array_map(
                static fn (array $category): array => [
                    'category_name' => $category['category_name'],
                    'current_total_raw' => $category['current_total'],
                    'current_occasion_total_raw' => $category['current_occasion_total'],
                    'previous_total_raw' => $category['previous_total'],
                    'previous_occasion_total_raw' => $category['previous_occasion_total'],
                    'current_global_total_raw' => $category['current_global_total'],
                    'previous_global_total_raw' => $category['previous_global_total'],
                    'delta_raw' => $category['delta'],
                    'trend_1y_raw' => $category['trend_1y'],
                    'trend_6m_raw' => $category['trend_6m'],
                    'trend_3m_raw' => $category['trend_3m'],
                    'channels' => $category['channels'],
                    'current_total' => number_format((int) round((float) $category['current_total']), 0, ',', ' ') . ' €',
                    'current_occasion_total' => number_format((int) round((float) $category['current_occasion_total']), 0, ',', ' ') . ' €',
                    'previous_total' => number_format((int) round((float) $category['previous_total']), 0, ',', ' ') . ' €',
                    'previous_occasion_total' => number_format((int) round((float) $category['previous_occasion_total']), 0, ',', ' ') . ' €',
                    'current_global_total' => number_format((int) round((float) $category['current_global_total']), 0, ',', ' ') . ' €',
                    'previous_global_total' => number_format((int) round((float) $category['previous_global_total']), 0, ',', ' ') . ' €',
                    'global_delta' => $category['previous_global_total'] > 0 ? (($category['current_global_total'] - $category['previous_global_total']) / $category['previous_global_total']) * 100.0 : null,
                    'global_delta_display' => $category['previous_global_total'] > 0 ? (($category['current_global_total'] - $category['previous_global_total']) / $category['previous_global_total'] * 100.0) : null,
                    'delta' => $category['delta'] === null ? '--' : (($category['delta'] > 0 ? '+' : '') . number_format((float) $category['delta'], 1, ',', ' ') . ' %'),
                    'delta_class' => $category['delta'] === null ? 'delta-neutral' : ((float) $category['delta'] > 0 ? 'delta-up' : ((float) $category['delta'] < 0 ? 'delta-down' : 'delta-neutral')),
                ],
                array_slice($categories, 0, 8)
            ),
        ];
    }

    /**
     * @return array<string, array{total_ht: float}>
     */
    private function fetchCategoryTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = [], bool $includeOccasion = false): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $categoryExpr = $this->buildCategoryExpression('r');
        $occasionFilterSql = $this->buildOccasionFilterSql('r');
        $whereSql .= $includeOccasion ? ' AND ' . $occasionFilterSql : ' AND NOT ' . $occasionFilterSql;

        $rows = $this->reportingConnection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                    SELECT
                        %s AS category_name,
                        COALESCE(SUM(r.total_ht), 0) AS total_ht
                    FROM reporting_invoice_line_fact r
                    WHERE %s
                    GROUP BY category_name
                    ORDER BY total_ht DESC, category_name ASC
                SQL,
                $categoryExpr,
                $whereSql
            ),
            $params
        );

        $indexed = [];
        foreach ($rows as $row) {
            $categoryName = (string) ($row['category_name'] ?? '');
            $indexed[$categoryName] = [
                'total_ht' => (float) ($row['total_ht'] ?? 0),
            ];
        }

        return $indexed;
    }

    /**
     * @return array<string, array<string, array{total_ht: float}>>
     */
    private function fetchCategoryChannelTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = [], bool $includeOccasion = false): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $categoryExpr = $this->buildCategoryExpression('r');
        $occasionFilterSql = $this->buildOccasionFilterSql('r');
        $whereSql .= $includeOccasion ? ' AND ' . $occasionFilterSql : ' AND NOT ' . $occasionFilterSql;

        $rows = $this->reportingConnection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                    SELECT
                        %s AS category_name,
                        COALESCE(NULLIF(r.channel_name, ''), 'Autre') AS channel_name,
                        COALESCE(SUM(r.total_ht), 0) AS total_ht
                    FROM reporting_invoice_line_fact r
                    WHERE %s
                    GROUP BY category_name, channel_name
                    ORDER BY total_ht DESC, channel_name ASC
                SQL,
                $categoryExpr,
                $whereSql
            ),
            $params
        );

        $indexed = [];
        foreach ($rows as $row) {
            $categoryName = (string) ($row['category_name'] ?? '');
            $channelName = (string) ($row['channel_name'] ?? 'Autre');
            $indexed[$categoryName][$channelName] = [
                'total_ht' => (float) ($row['total_ht'] ?? 0),
            ];
        }

        return $indexed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBrandChannelBreakdown(int|string $brandId, array $currentChannelTotals, array $previousChannelTotals): array
    {
        $preferredOrder = [
            'Nantes' => 0,
            'Bordeaux' => 1,
            'Web' => 2,
            'Ecole' => 3,
            'École' => 3,
            'Autre' => 4,
        ];

        $currentRows = $currentChannelTotals[$brandId] ?? [];
        $previousRows = $previousChannelTotals[$brandId] ?? [];
        $channelNames = array_unique(array_merge(array_keys($currentRows), array_keys($previousRows)));
        usort($channelNames, static function (string $a, string $b) use ($preferredOrder): int {
            $aRank = $preferredOrder[$a] ?? 99;
            $bRank = $preferredOrder[$b] ?? 99;

            return $aRank <=> $bRank ?: strcasecmp($a, $b);
        });

        $channels = [];
        foreach (array_slice($channelNames, 0, 3) as $channelName) {
            $currentRow = $currentRows[$channelName] ?? ['total_ht' => 0.0];
            $previousRow = $previousRows[$channelName] ?? ['total_ht' => 0.0];
            $currentTotal = (float) ($currentRow['total_ht'] ?? 0);
            $previousTotal = (float) ($previousRow['total_ht'] ?? 0);

            $channels[] = [
                'label' => $channelName,
                'current_total_raw' => $currentTotal,
                'previous_total_raw' => $previousTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'delta_class' => $previousTotal > 0
                    ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 > 0
                        ? 'delta-up'
                        : ((($currentTotal - $previousTotal) / $previousTotal) * 100.0 < 0 ? 'delta-down' : 'delta-neutral')
                    : 'delta-neutral',
            ];
        }

        return $channels;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCategoryChannelBreakdown(
        string $categoryName,
        array $currentChannelTotals,
        array $currentOccasionChannelTotals,
        array $previousChannelTotals,
        array $previousOccasionChannelTotals
    ): array
    {
        $preferredOrder = [
            'Nantes' => 0,
            'Bordeaux' => 1,
            'Web' => 2,
            'Ecole' => 3,
            'École' => 3,
            'Autre' => 4,
        ];

        $currentRows = $currentChannelTotals[$categoryName] ?? [];
        $currentOccasionRows = $currentOccasionChannelTotals[$categoryName] ?? [];
        $previousRows = $previousChannelTotals[$categoryName] ?? [];
        $previousOccasionRows = $previousOccasionChannelTotals[$categoryName] ?? [];
        $channelNames = array_unique(array_merge(array_keys($currentRows), array_keys($currentOccasionRows), array_keys($previousRows), array_keys($previousOccasionRows)));
        usort($channelNames, static function (string $a, string $b) use ($preferredOrder): int {
            $aRank = $preferredOrder[$a] ?? 99;
            $bRank = $preferredOrder[$b] ?? 99;

            return $aRank <=> $bRank ?: strcasecmp($a, $b);
        });

        $channels = [];
        foreach (array_slice($channelNames, 0, 3) as $channelName) {
            $currentRow = $currentRows[$channelName] ?? ['total_ht' => 0.0];
            $currentOccasionRow = $currentOccasionRows[$channelName] ?? ['total_ht' => 0.0];
            $previousRow = $previousRows[$channelName] ?? ['total_ht' => 0.0];
            $previousOccasionRow = $previousOccasionRows[$channelName] ?? ['total_ht' => 0.0];
            $currentTotal = (float) ($currentRow['total_ht'] ?? 0);
            $currentOccasionTotal = (float) ($currentOccasionRow['total_ht'] ?? 0);
            $previousTotal = (float) ($previousRow['total_ht'] ?? 0);
            $previousOccasionTotal = (float) ($previousOccasionRow['total_ht'] ?? 0);
            $currentGlobalTotal = $currentTotal + $currentOccasionTotal;
            $previousGlobalTotal = $previousTotal + $previousOccasionTotal;

            $channels[] = [
                'label' => $channelName,
                'current_total_raw' => $currentTotal,
                'current_occasion_total_raw' => $currentOccasionTotal,
                'previous_total_raw' => $previousTotal,
                'previous_occasion_total_raw' => $previousOccasionTotal,
                'current_global_total_raw' => $currentGlobalTotal,
                'previous_global_total_raw' => $previousGlobalTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'global_delta' => $previousGlobalTotal > 0 ? (($currentGlobalTotal - $previousGlobalTotal) / $previousGlobalTotal) * 100.0 : null,
                'occasion_delta' => $previousOccasionTotal > 0 ? (($currentOccasionTotal - $previousOccasionTotal) / $previousOccasionTotal) * 100.0 : null,
                'delta_class' => $previousTotal > 0
                    ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 > 0
                        ? 'delta-up'
                        : ((($currentTotal - $previousTotal) / $previousTotal) * 100.0 < 0 ? 'delta-down' : 'delta-neutral')
                    : 'delta-neutral',
                'global_delta_class' => $previousGlobalTotal > 0
                    ? (($currentGlobalTotal - $previousGlobalTotal) / $previousGlobalTotal) * 100.0 > 0
                        ? 'delta-up'
                        : ((($currentGlobalTotal - $previousGlobalTotal) / $previousGlobalTotal) * 100.0 < 0 ? 'delta-down' : 'delta-neutral')
                    : 'delta-neutral',
                'occasion_delta_class' => $previousOccasionTotal > 0
                    ? (($currentOccasionTotal - $previousOccasionTotal) / $previousOccasionTotal) * 100.0 > 0
                        ? 'delta-up'
                        : ((($currentOccasionTotal - $previousOccasionTotal) / $previousOccasionTotal) * 100.0 < 0 ? 'delta-down' : 'delta-neutral')
                    : 'delta-neutral',
            ];
        }

        return $channels;
    }

    private function buildCategoryExpression(string $alias = 'r'): string
    {
        return sprintf(
            "CASE WHEN COALESCE(NULLIF(%1\$s.subfamily_name, ''), '') = '' THEN CONCAT_WS(' > ', COALESCE(NULLIF(%1\$s.rayon_name, ''), 'Sans rayon'), COALESCE(NULLIF(%1\$s.family_name, ''), 'Sans famille')) ELSE CONCAT_WS(' > ', COALESCE(NULLIF(%1\$s.rayon_name, ''), 'Sans rayon'), COALESCE(NULLIF(%1\$s.family_name, ''), 'Sans famille'), %1\$s.subfamily_name) END",
            $alias
        );
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
    private function fetchOccasionTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $whereSql .= ' AND NOT (r.product_id = 18823 OR UPPER(COALESCE(r.supplier_reference, \'\')) LIKE \'REPRISE%\')';
        $whereSql .= ' AND ' . $this->buildOccasionFilterSql('r');

        $row = $this->reportingConnection->fetchAssociative(
            sprintf(
                <<<'SQL'
                SELECT
                    COALESCE(SUM(r.total_ht), 0) AS total_ht,
                    COUNT(*) AS line_count,
                    COUNT(DISTINCT r.invoice_number) AS invoice_count
                FROM reporting_invoice_line_fact r
                WHERE %s
            SQL,
                $whereSql
            ),
            $params
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
        DateTimeImmutable $previousEnd,
        array $filters = []
    ): array {
        $currentRows = $this->fetchOccasionChannelTotals($currentStart, $currentEnd, $filters);
        $previousRows = $this->fetchOccasionChannelTotals($previousStart, $previousEnd, $filters);
        $trend1y = $this->buildRollingComparisonPeriod($currentEnd, 12);
        $trend6m = $this->buildRollingComparisonPeriod($currentEnd, 6);
        $trend3m = $this->buildRollingComparisonPeriod($currentEnd, 3);
        $trend1yCurrentRows = $this->fetchOccasionChannelTotals($trend1y['current_start'], $trend1y['current_end'], $filters);
        $trend1yPreviousRows = $this->fetchOccasionChannelTotals($trend1y['previous_start'], $trend1y['previous_end'], $filters);
        $trend6mCurrentRows = $this->fetchOccasionChannelTotals($trend6m['current_start'], $trend6m['current_end'], $filters);
        $trend6mPreviousRows = $this->fetchOccasionChannelTotals($trend6m['previous_start'], $trend6m['previous_end'], $filters);
        $trend3mCurrentRows = $this->fetchOccasionChannelTotals($trend3m['current_start'], $trend3m['current_end'], $filters);
        $trend3mPreviousRows = $this->fetchOccasionChannelTotals($trend3m['previous_start'], $trend3m['previous_end'], $filters);

        $channelNames = array_unique(array_merge(array_keys($currentRows), array_keys($previousRows)));
        $channels = [];

        foreach ($channelNames as $channelName) {
            $current = $currentRows[$channelName] ?? ['total_ht' => 0.0, 'line_count' => 0, 'invoice_count' => 0];
            $previous = $previousRows[$channelName] ?? ['total_ht' => 0.0, 'line_count' => 0, 'invoice_count' => 0];
            $trend1yCurrent = (float) ($trend1yCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend1yPrevious = (float) ($trend1yPreviousRows[$channelName]['total_ht'] ?? 0);
            $trend6mCurrent = (float) ($trend6mCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend6mPrevious = (float) ($trend6mPreviousRows[$channelName]['total_ht'] ?? 0);
            $trend3mCurrent = (float) ($trend3mCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend3mPrevious = (float) ($trend3mPreviousRows[$channelName]['total_ht'] ?? 0);

            $currentTotal = (float) $current['total_ht'];
            $previousTotal = (float) $previous['total_ht'];

            $channels[] = [
                'label' => $channelName,
                'current_total' => $currentTotal,
                'previous_total' => $previousTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'trend_1y' => $trend1yPrevious > 0 ? (($trend1yCurrent - $trend1yPrevious) / $trend1yPrevious) * 100.0 : null,
                'trend_6m' => $trend6mPrevious > 0 ? (($trend6mCurrent - $trend6mPrevious) / $trend6mPrevious) * 100.0 : null,
                'trend_3m' => $trend3mPrevious > 0 ? (($trend3mCurrent - $trend3mPrevious) / $trend3mPrevious) * 100.0 : null,
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
    private function fetchOccasionChannelTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $whereSql .= ' AND NOT (r.product_id = 18823 OR UPPER(COALESCE(r.supplier_reference, \'\')) LIKE \'REPRISE%\')';
        $whereSql .= ' AND ' . $this->buildOccasionFilterSql('r');

        $rows = $this->reportingConnection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                SELECT
                    COALESCE(NULLIF(r.channel_name, ''), 'Autre') AS channel_name,
                    COALESCE(SUM(r.total_ht), 0) AS total_ht,
                    COUNT(*) AS line_count,
                    COUNT(DISTINCT r.invoice_number) AS invoice_count
                FROM reporting_invoice_line_fact r
                WHERE %s
                GROUP BY COALESCE(NULLIF(r.channel_name, ''), 'Autre')
            SQL,
                $whereSql
            ),
            $params
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
     * @return array{current_start: DateTimeImmutable, current_end: DateTimeImmutable, previous_start: DateTimeImmutable, previous_end: DateTimeImmutable}
     */
    private function buildRollingComparisonPeriod(DateTimeImmutable $end, int $months): array
    {
        $currentEnd = $end;
        $currentStart = $end->modify(sprintf('-%d months', $months))->modify('+1 day');
        $previousEnd = $currentEnd->modify('-1 year');
        $previousStart = $currentStart->modify('-1 year');

        return [
            'current_start' => $currentStart,
            'current_end' => $currentEnd,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
        ];
    }

    /**
     * @return array<int, array<string, array<string, array<string, mixed>>>>
     */
    private function fetchBrandChannelTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $rows = $this->reportingConnection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                    SELECT
                        COALESCE(r.brand_id, 0) AS brand_id,
                        COALESCE(NULLIF(r.brand_name, ''), CASE
                            WHEN COALESCE(r.brand_id, 0) = 0 THEN 'Sans marque'
                            ELSE CONCAT('IDFAB ', COALESCE(r.brand_id, 0))
                        END) AS brand_name,
                        COALESCE(NULLIF(r.channel_name, ''), 'Autre') AS channel_name,
                        COALESCE(SUM(r.total_ht), 0) AS total_ht,
                        COUNT(DISTINCT r.invoice_number) AS invoice_count,
                        COUNT(*) AS line_count
                    FROM reporting_invoice_line_fact r
                    WHERE %s
                    GROUP BY COALESCE(r.brand_id, 0), brand_name, COALESCE(NULLIF(r.channel_name, ''), 'Autre')
                    ORDER BY total_ht DESC, channel_name ASC
                SQL,
                $whereSql
            ),
            $params
        );

        $indexed = [];
        foreach ($rows as $row) {
            $brandId = (int) ($row['brand_id'] ?? 0);
            $channelName = (string) ($row['channel_name'] ?? 'Autre');
            $indexed[$brandId][$channelName] = [
                'brand_id' => $brandId,
                'brand_name' => (string) ($row['brand_name'] ?? 'Marque'),
                'channel_name' => $channelName,
                'total_ht' => (float) ($row['total_ht'] ?? 0),
                'invoice_count' => (int) ($row['invoice_count'] ?? 0),
                'line_count' => (int) ($row['line_count'] ?? 0),
            ];
        }

        return $indexed;
    }

    /**
     * @return array{total_ht: float, line_count: int, invoice_count: int}
     */
    private function fetchNeufTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $whereSql .= ' AND NOT (r.product_id = 18823 OR UPPER(COALESCE(r.supplier_reference, \'\')) LIKE \'REPRISE%\')';
        $whereSql .= ' AND NOT ' . $this->buildOccasionFilterSql('r');

        $row = $this->reportingConnection->fetchAssociative(
            sprintf(
                <<<'SQL'
                SELECT
                    COALESCE(SUM(r.total_ht), 0) AS total_ht,
                    COUNT(*) AS line_count,
                    COUNT(DISTINCT r.invoice_number) AS invoice_count
                FROM reporting_invoice_line_fact r
                WHERE %s
            SQL,
                $whereSql
            ),
            $params
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
    private function fetchNeufChannelSummaries(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd,
        array $filters = []
    ): array {
        $currentRows = $this->fetchNeufChannelTotals($currentStart, $currentEnd, $filters);
        $previousRows = $this->fetchNeufChannelTotals($previousStart, $previousEnd, $filters);
        $trend1y = $this->buildRollingComparisonPeriod($currentEnd, 12);
        $trend6m = $this->buildRollingComparisonPeriod($currentEnd, 6);
        $trend3m = $this->buildRollingComparisonPeriod($currentEnd, 3);
        $trend1yCurrentRows = $this->fetchNeufChannelTotals($trend1y['current_start'], $trend1y['current_end'], $filters);
        $trend1yPreviousRows = $this->fetchNeufChannelTotals($trend1y['previous_start'], $trend1y['previous_end'], $filters);
        $trend6mCurrentRows = $this->fetchNeufChannelTotals($trend6m['current_start'], $trend6m['current_end'], $filters);
        $trend6mPreviousRows = $this->fetchNeufChannelTotals($trend6m['previous_start'], $trend6m['previous_end'], $filters);
        $trend3mCurrentRows = $this->fetchNeufChannelTotals($trend3m['current_start'], $trend3m['current_end'], $filters);
        $trend3mPreviousRows = $this->fetchNeufChannelTotals($trend3m['previous_start'], $trend3m['previous_end'], $filters);

        $channelNames = array_unique(array_merge(array_keys($currentRows), array_keys($previousRows)));
        $channels = [];

        foreach ($channelNames as $channelName) {
            $current = $currentRows[$channelName] ?? ['total_ht' => 0.0, 'line_count' => 0, 'invoice_count' => 0];
            $previous = $previousRows[$channelName] ?? ['total_ht' => 0.0, 'line_count' => 0, 'invoice_count' => 0];
            $trend1yCurrent = (float) ($trend1yCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend1yPrevious = (float) ($trend1yPreviousRows[$channelName]['total_ht'] ?? 0);
            $trend6mCurrent = (float) ($trend6mCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend6mPrevious = (float) ($trend6mPreviousRows[$channelName]['total_ht'] ?? 0);
            $trend3mCurrent = (float) ($trend3mCurrentRows[$channelName]['total_ht'] ?? 0);
            $trend3mPrevious = (float) ($trend3mPreviousRows[$channelName]['total_ht'] ?? 0);

            $currentTotal = (float) $current['total_ht'];
            $previousTotal = (float) $previous['total_ht'];

            $channels[] = [
                'label' => $channelName,
                'current_total' => $currentTotal,
                'previous_total' => $previousTotal,
                'delta' => $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100.0 : null,
                'trend_1y' => $trend1yPrevious > 0 ? (($trend1yCurrent - $trend1yPrevious) / $trend1yPrevious) * 100.0 : null,
                'trend_6m' => $trend6mPrevious > 0 ? (($trend6mCurrent - $trend6mPrevious) / $trend6mPrevious) * 100.0 : null,
                'trend_3m' => $trend3mPrevious > 0 ? (($trend3mCurrent - $trend3mPrevious) / $trend3mPrevious) * 100.0 : null,
                'current_lines' => (int) $current['line_count'],
                'previous_lines' => (int) $previous['line_count'],
                'current_invoices' => (int) $current['invoice_count'],
                'previous_invoices' => (int) $previous['invoice_count'],
            ];
        }

        usort($channels, static fn (array $a, array $b): int => $b['current_total'] <=> $a['current_total']);

        return $channels;
    }

    /**
     * @return array<string, array{total_ht: float, line_count: int, invoice_count: int}>
     */
    private function fetchNeufChannelTotals(DateTimeImmutable $start, DateTimeImmutable $end, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildWhereClause($start, $end, $filters, 'r');
        $whereSql .= ' AND NOT (r.product_id = 18823 OR UPPER(COALESCE(r.supplier_reference, \'\')) LIKE \'REPRISE%\')';
        $whereSql .= ' AND NOT ' . $this->buildOccasionFilterSql('r');

        $rows = $this->reportingConnection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                SELECT
                    COALESCE(NULLIF(r.channel_name, ''), 'Autre') AS channel_name,
                    COALESCE(SUM(r.total_ht), 0) AS total_ht,
                    COUNT(*) AS line_count,
                    COUNT(DISTINCT r.invoice_number) AS invoice_count
                FROM reporting_invoice_line_fact r
                WHERE %s
                GROUP BY COALESCE(NULLIF(r.channel_name, ''), 'Autre')
            SQL,
                $whereSql
            ),
            $params
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
