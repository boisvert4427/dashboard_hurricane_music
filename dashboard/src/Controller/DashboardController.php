<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\KpiRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, KpiRepository $kpiRepository): Response
    {
        $filters = $this->parseFilters($request, false);
        $data = $kpiRepository->getHomeData($filters);
        $globalTotal = (float) ($data['current_summary']['total_ht'] ?? 0);
        $channelTotalsByLabel = [];
        foreach ($data['channels'] as $channel) {
            $channelTotalsByLabel[(string) ($channel['label'] ?? '')] = (float) ($channel['current'] ?? 0);
        }
        $rollingSummaries = $data['rolling_summaries'] ?? [];

        return $this->render('dashboard/home.html.twig', [
            'period_label' => $data['current_period']['start']->format('d/m/Y') . ' au ' . $data['current_period']['end']->format('d/m/Y'),
            'previous_period_label' => $data['previous_period']['start']->format('d/m/Y') . ' au ' . $data['previous_period']['end']->format('d/m/Y'),
            'previous_month_label' => $data['previous_month_period']['start']->format('d/m/Y') . ' au ' . $data['previous_month_period']['end']->format('d/m/Y'),
            'active_filters' => $filters,
            'filter_options' => $data['filters'],
            'alerts' => $data['alerts'],
            'objective_summary' => $data['objective_summary'],
            'neuf' => self::sortChannelCards(self::formatNeufSection($data['neuf'], $globalTotal, $channelTotalsByLabel)),
            'occasion' => self::sortChannelCards(self::formatOccasionSection($data['occasion'], $globalTotal, $channelTotalsByLabel)),
            'brand_highlights' => self::formatBrandHighlights($data['brand_highlights'], $globalTotal, true),
            'category_highlights' => self::formatCategoryHighlights($data['category_highlights'], $globalTotal),
            'kpis' => array_map(static function (array $kpi) use ($rollingSummaries): array {
                $deltaClass = match (true) {
                    ($kpi['delta'] ?? null) === null => 'delta-neutral',
                    (float) $kpi['delta'] > 0 => 'delta-up',
                    (float) $kpi['delta'] < 0 => 'delta-down',
                    default => 'delta-neutral',
                };
                $metricKey = match ($kpi['label']) {
                    'Marge période' => 'margin_ht',
                    'Factures' => 'invoice_count',
                    'Panier moyen' => 'basket',
                    default => 'total_ht',
                };
                $metricValue = static function (array $summary) use ($metricKey): float {
                    if ($metricKey === 'basket') {
                        $invoiceCount = (int) ($summary['invoice_count'] ?? 0);
                        return $invoiceCount > 0 ? ((float) ($summary['total_ht'] ?? 0) / $invoiceCount) : 0.0;
                    }

                    return (float) ($summary[$metricKey] ?? 0);
                };
                $trend1y = $rollingSummaries['trend_1y'] ?? null;
                $trend6m = $rollingSummaries['trend_6m'] ?? null;
                $trend3m = $rollingSummaries['trend_3m'] ?? null;
                $trend1yValue = is_array($trend1y) ? $metricValue($trend1y['current'] ?? []) : null;
                $trend1yPrevious = is_array($trend1y) ? $metricValue($trend1y['previous'] ?? []) : null;
                $trend6mValue = is_array($trend6m) ? $metricValue($trend6m['current'] ?? []) : null;
                $trend6mPrevious = is_array($trend6m) ? $metricValue($trend6m['previous'] ?? []) : null;
                $trend3mValue = is_array($trend3m) ? $metricValue($trend3m['current'] ?? []) : null;
                $trend3mPrevious = is_array($trend3m) ? $metricValue($trend3m['previous'] ?? []) : null;
                $trend1yDelta = ($trend1yPrevious ?? 0) > 0 ? ((($trend1yValue ?? 0) - $trend1yPrevious) / $trend1yPrevious) * 100.0 : null;
                $trend6mDelta = ($trend6mPrevious ?? 0) > 0 ? ((($trend6mValue ?? 0) - $trend6mPrevious) / $trend6mPrevious) * 100.0 : null;
                $trend3mDelta = ($trend3mPrevious ?? 0) > 0 ? ((($trend3mValue ?? 0) - $trend3mPrevious) / $trend3mPrevious) * 100.0 : null;

                return [
                    'label' => $kpi['label'],
                    'current' => $kpi['type'] === 'count' ? number_format((int) $kpi['current'], 0, ',', ' ') : self::formatNumber($kpi['current']),
                    'previous' => $kpi['type'] === 'count' ? number_format((int) $kpi['previous'], 0, ',', ' ') : self::formatNumber($kpi['previous']),
                    'delta' => self::formatDelta($kpi['delta']),
                    'delta_class' => $deltaClass,
                    'hint' => $kpi['hint'],
                    'trend_1y_display' => self::formatDeltaShort($trend1yDelta),
                    'trend_6m_display' => self::formatDeltaShort($trend6mDelta),
                    'trend_3m_display' => self::formatDeltaShort($trend3mDelta),
                    'trend_1y_class' => self::deltaClass($trend1yDelta),
                    'trend_6m_class' => self::deltaClass($trend6mDelta),
                    'trend_3m_class' => self::deltaClass($trend3mDelta),
                ];
            }, $data['kpis']),
            'channels' => self::sortChannelCards((static function (array $channels) use ($globalTotal, $filters): array {
                $maxCurrent = 0.0;
                foreach ($channels as $channel) {
                    $maxCurrent = max($maxCurrent, (float) $channel['current']);
                }

                return array_map(static function (array $channel) use ($maxCurrent, $globalTotal): array {
                    $current = (float) $channel['current'];
                    $deltaClass = match (true) {
                        ($channel['delta'] ?? null) === null => 'delta-neutral',
                        (float) $channel['delta'] > 0 => 'delta-up',
                        (float) $channel['delta'] < 0 => 'delta-down',
                        default => 'delta-neutral',
                    };

                    return [
                        'label' => $channel['label'],
                        'value' => self::formatInteger($current),
                        'current_total' => self::formatInteger($current),
                        'previous_total' => self::formatInteger((float) ($channel['previous'] ?? 0)),
                        'share_global' => self::formatPercent($current, $globalTotal),
                        'share' => $maxCurrent > 0 ? ($current / $maxCurrent) * 100.0 : 0.0,
                        'margin' => self::formatInteger((float) $channel['margin']),
                        'margin_percent' => self::formatRatioPercent((float) ($channel['margin'] ?? 0), $current),
                        'invoices' => number_format((int) $channel['invoices'], 0, ',', ' '),
                        'current_invoices' => number_format((int) $channel['invoices'], 0, ',', ' '),
                        'previous_invoices' => number_format((int) ($channel['previous_invoices'] ?? 0), 0, ',', ' '),
                        'lines' => number_format((int) $channel['lines'], 0, ',', ' '),
                        'current_lines' => number_format((int) $channel['lines'], 0, ',', ' '),
                        'previous_lines' => number_format((int) ($channel['previous_lines'] ?? 0), 0, ',', ' '),
                        'average_basket' => self::formatInteger((float) ($channel['average_basket'] ?? 0)),
                        'previous_value' => self::formatInteger((float) ($channel['previous'] ?? 0)),
                        'delta' => self::formatDelta($channel['delta'] ?? null),
                        'delta_short' => self::formatDeltaShort($channel['delta'] ?? null),
                        'delta_class' => $deltaClass,
                        'basket_delta' => $channel['basket_delta'] ?? '--',
                        'trend_1y_display' => self::formatDeltaShort($channel['trend_1y'] ?? null),
                        'trend_6m_display' => self::formatDeltaShort($channel['trend_6m'] ?? null),
                        'trend_3m_display' => self::formatDeltaShort($channel['trend_3m'] ?? null),
                        'trend_1y_class' => self::deltaClass($channel['trend_1y'] ?? null),
                        'trend_6m_class' => self::deltaClass($channel['trend_6m'] ?? null),
                        'trend_3m_class' => self::deltaClass($channel['trend_3m'] ?? null),
                        'hint' => 'CA HT sur le mois en cours',
                        'highlights' => self::formatBrandHighlights($channel['highlights'], $globalTotal, false),
                    ];
                }, $channels);
            })($data['channels'])),
        ]);
    }

    #[Route('/detail', name: 'app_detail')]
    public function detail(Request $request, KpiRepository $kpiRepository): Response
    {
        $filters = $this->parseFilters($request, true);
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = (int) $request->query->get('per_page', 50);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $sort = (string) $request->query->get('sort', 'invoice_date');
        $direction = strtolower((string) $request->query->get('direction', 'desc'));

        $data = $kpiRepository->getDetailData($filters, $page, $perPage, $sort, $direction);

        return $this->render('dashboard/detail.html.twig', [
            'active_filters' => $filters,
            'filter_options' => $data['filters'],
            'summary' => $data['summary'],
            'comparison_previous_year' => $data['comparison_previous_year'],
            'comparison_previous_month' => $data['comparison_previous_month'],
            'objective_summary' => $data['objective'],
            'pagination' => $data['pagination'],
            'rows' => $data['rows'],
            'sort' => $data['sort'],
            'direction' => $data['direction'],
            'alerts' => $data['alerts'],
        ]);
    }

    #[Route('/detail/export.csv', name: 'app_detail_export')]
    public function export(Request $request, KpiRepository $kpiRepository): StreamedResponse
    {
        $filters = $this->parseFilters($request, true);
        $sort = (string) $request->query->get('sort', 'invoice_date');
        $direction = strtolower((string) $request->query->get('direction', 'desc'));
        $filename = 'dashboard-detail-' . (new \DateTimeImmutable('now'))->format('Y-m-d_His') . '.csv';

        $response = new StreamedResponse(function () use ($kpiRepository, $filters, $sort, $direction): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fputcsv($output, ['Date', 'Facture', 'Canal', 'IDART', 'Marque', 'Produit', 'Code', 'Fournisseur', 'Ref four', 'Quantité', 'CA HT', 'Marge HT', 'Occasion']);

            foreach ($kpiRepository->iterateDetailRows($filters, $sort, $direction) as $row) {
                fputcsv($output, [
                    $row['invoice_date'] ?? '',
                    $row['invoice_number'] ?? '',
                    $row['channel_name'] ?? '',
                    $row['idart'] ?? '',
                    $row['brand_name'] ?? '',
                    $row['product_name'] ?? '',
                    $row['product_code'] ?? '',
                    $row['supplier_name'] ?? '',
                    $row['supplier_reference'] ?? '',
                    $row['quantity'] ?? '',
                    $row['total_ht'] ?? '',
                    $row['margin_ht'] ?? '',
                    !empty($row['is_occasion']) ? 'oui' : 'non',
                ]);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    /**
     * @param array<string, mixed> $occasion
     *
     * @return array<string, mixed>
     */
    private static function formatOccasionSection(array $occasion, float $globalTotal, array $channelTotalsByLabel = []): array
    {
        $currentTotal = (float) ($occasion['current_total'] ?? 0);
        return [
            'current_total' => self::formatInteger($currentTotal),
            'previous_total' => self::formatInteger($occasion['previous_total'] ?? 0),
            'share_global' => self::formatPercent($currentTotal, $globalTotal),
            'share_global_value' => self::formatPercentValue($currentTotal, $globalTotal),
            'share_section' => self::formatScopePercent($currentTotal, $currentTotal, "de l'occasion"),
            'share_section_value' => self::formatPercentValue($currentTotal, $currentTotal),
            'delta' => self::formatDelta($occasion['delta'] ?? null),
            'delta_class' => match (true) {
                ($occasion['delta'] ?? null) === null => 'delta-neutral',
                (float) $occasion['delta'] > 0 => 'delta-up',
                (float) $occasion['delta'] < 0 => 'delta-down',
                default => 'delta-neutral',
            },
            'current_lines' => number_format((int) ($occasion['current_lines'] ?? 0), 0, ',', ' '),
            'previous_lines' => number_format((int) ($occasion['previous_lines'] ?? 0), 0, ',', ' '),
            'current_invoices' => number_format((int) ($occasion['current_invoices'] ?? 0), 0, ',', ' '),
            'previous_invoices' => number_format((int) ($occasion['previous_invoices'] ?? 0), 0, ',', ' '),
            'trend_1y_display' => self::formatDeltaShort(is_float($occasion['trend_1y'] ?? null) ? (float) $occasion['trend_1y'] : null),
            'trend_6m_display' => self::formatDeltaShort(is_float($occasion['trend_6m'] ?? null) ? (float) $occasion['trend_6m'] : null),
            'trend_3m_display' => self::formatDeltaShort(is_float($occasion['trend_3m'] ?? null) ? (float) $occasion['trend_3m'] : null),
            'trend_1y_class' => self::deltaClass(is_float($occasion['trend_1y'] ?? null) ? (float) $occasion['trend_1y'] : null),
            'trend_6m_class' => self::deltaClass(is_float($occasion['trend_6m'] ?? null) ? (float) $occasion['trend_6m'] : null),
            'trend_3m_class' => self::deltaClass(is_float($occasion['trend_3m'] ?? null) ? (float) $occasion['trend_3m'] : null),
            'channels' => array_map(
                static function (array $channel) use ($currentTotal, $globalTotal, $channelTotalsByLabel): array {
                    $deltaClass = match (true) {
                        ($channel['delta'] ?? null) === null => 'delta-neutral',
                        (float) $channel['delta'] > 0 => 'delta-up',
                        (float) $channel['delta'] < 0 => 'delta-down',
                        default => 'delta-neutral',
                    };
                    $channelLabel = (string) ($channel['label'] ?? 'Autre');
                    $channelTotal = (float) ($channelTotalsByLabel[$channelLabel] ?? 0);

                    return [
                        'label' => $channel['label'] ?? 'Autre',
                        'current_total' => self::formatInteger($channel['current_total'] ?? 0),
                        'previous_total' => self::formatInteger($channel['previous_total'] ?? 0),
                        'share_global' => self::formatPercent((float) ($channel['current_total'] ?? 0), $globalTotal),
                        'share_global_value' => self::formatPercentValue((float) ($channel['current_total'] ?? 0), $globalTotal),
                        'share_section' => self::formatScopePercent((float) ($channel['current_total'] ?? 0), $currentTotal, "de l'occasion"),
                        'share_section_value' => self::formatPercentValue((float) ($channel['current_total'] ?? 0), $currentTotal),
                        'share_channel' => self::formatScopePercent((float) ($channel['current_total'] ?? 0), $channelTotal, 'du global ' . $channelLabel),
                        'share_channel_value' => self::formatPercentValue((float) ($channel['current_total'] ?? 0), $channelTotal),
                        'delta' => self::formatDelta($channel['delta'] ?? null),
                        'delta_short' => self::formatDeltaShort($channel['delta'] ?? null),
                        'delta_class' => $deltaClass,
                        'current_lines' => number_format((int) ($channel['current_lines'] ?? 0), 0, ',', ' '),
                        'previous_lines' => number_format((int) ($channel['previous_lines'] ?? 0), 0, ',', ' '),
                        'current_invoices' => number_format((int) ($channel['current_invoices'] ?? 0), 0, ',', ' '),
                        'previous_invoices' => number_format((int) ($channel['previous_invoices'] ?? 0), 0, ',', ' '),
                        'trend_1y_display' => self::formatDeltaShort($channel['trend_1y'] ?? null),
                        'trend_6m_display' => self::formatDeltaShort($channel['trend_6m'] ?? null),
                        'trend_3m_display' => self::formatDeltaShort($channel['trend_3m'] ?? null),
                        'trend_1y_class' => self::deltaClass($channel['trend_1y'] ?? null),
                        'trend_6m_class' => self::deltaClass($channel['trend_6m'] ?? null),
                        'trend_3m_class' => self::deltaClass($channel['trend_3m'] ?? null),
                    ];
                },
                $occasion['channels'] ?? []
            ),
        ];
    }

    /**
     * @param array<string, mixed> $neuf
     *
     * @return array<string, mixed>
     */
    private static function formatNeufSection(array $neuf, float $globalTotal, array $channelTotalsByLabel = []): array
    {
        $currentTotal = (float) ($neuf['current_total'] ?? 0);
        return [
            'current_total' => self::formatInteger($currentTotal),
            'previous_total' => self::formatInteger($neuf['previous_total'] ?? 0),
            'share_global' => self::formatPercent($currentTotal, $globalTotal),
            'share_global_value' => self::formatPercentValue($currentTotal, $globalTotal),
            'share_section' => self::formatScopePercent($currentTotal, $currentTotal, 'du neuf'),
            'share_section_value' => self::formatPercentValue($currentTotal, $currentTotal),
            'delta' => self::formatDelta($neuf['delta'] ?? null),
            'delta_class' => match (true) {
                ($neuf['delta'] ?? null) === null => 'delta-neutral',
                (float) $neuf['delta'] > 0 => 'delta-up',
                (float) $neuf['delta'] < 0 => 'delta-down',
                default => 'delta-neutral',
            },
            'current_lines' => number_format((int) ($neuf['current_lines'] ?? 0), 0, ',', ' '),
            'previous_lines' => number_format((int) ($neuf['previous_lines'] ?? 0), 0, ',', ' '),
            'current_invoices' => number_format((int) ($neuf['current_invoices'] ?? 0), 0, ',', ' '),
            'previous_invoices' => number_format((int) ($neuf['previous_invoices'] ?? 0), 0, ',', ' '),
            'trend_1y_display' => self::formatDeltaShort(is_float($neuf['trend_1y'] ?? null) ? (float) $neuf['trend_1y'] : null),
            'trend_6m_display' => self::formatDeltaShort(is_float($neuf['trend_6m'] ?? null) ? (float) $neuf['trend_6m'] : null),
            'trend_3m_display' => self::formatDeltaShort(is_float($neuf['trend_3m'] ?? null) ? (float) $neuf['trend_3m'] : null),
            'trend_1y_class' => self::deltaClass(is_float($neuf['trend_1y'] ?? null) ? (float) $neuf['trend_1y'] : null),
            'trend_6m_class' => self::deltaClass(is_float($neuf['trend_6m'] ?? null) ? (float) $neuf['trend_6m'] : null),
            'trend_3m_class' => self::deltaClass(is_float($neuf['trend_3m'] ?? null) ? (float) $neuf['trend_3m'] : null),
            'channels' => array_map(
                static function (array $channel) use ($currentTotal, $globalTotal, $channelTotalsByLabel): array {
                    $deltaClass = match (true) {
                        ($channel['delta'] ?? null) === null => 'delta-neutral',
                        (float) $channel['delta'] > 0 => 'delta-up',
                        (float) $channel['delta'] < 0 => 'delta-down',
                        default => 'delta-neutral',
                    };
                    $channelLabel = (string) ($channel['label'] ?? 'Autre');
                    $channelTotal = (float) ($channelTotalsByLabel[$channelLabel] ?? 0);

                    return [
                        'label' => $channel['label'] ?? 'Autre',
                        'current_total' => self::formatInteger($channel['current_total'] ?? 0),
                        'previous_total' => self::formatInteger($channel['previous_total'] ?? 0),
                        'share_global' => self::formatPercent((float) ($channel['current_total'] ?? 0), $globalTotal),
                        'share_global_value' => self::formatPercentValue((float) ($channel['current_total'] ?? 0), $globalTotal),
                        'share_section' => self::formatScopePercent((float) ($channel['current_total'] ?? 0), $currentTotal, 'du neuf'),
                        'share_section_value' => self::formatPercentValue((float) ($channel['current_total'] ?? 0), $currentTotal),
                        'share_channel' => self::formatScopePercent((float) ($channel['current_total'] ?? 0), $channelTotal, 'du global ' . $channelLabel),
                        'share_channel_value' => self::formatPercentValue((float) ($channel['current_total'] ?? 0), $channelTotal),
                        'delta' => self::formatDelta($channel['delta'] ?? null),
                        'delta_short' => self::formatDeltaShort($channel['delta'] ?? null),
                        'delta_class' => $deltaClass,
                        'current_lines' => number_format((int) ($channel['current_lines'] ?? 0), 0, ',', ' '),
                        'previous_lines' => number_format((int) ($channel['previous_lines'] ?? 0), 0, ',', ' '),
                        'current_invoices' => number_format((int) ($channel['current_invoices'] ?? 0), 0, ',', ' '),
                        'previous_invoices' => number_format((int) ($channel['previous_invoices'] ?? 0), 0, ',', ' '),
                        'trend_1y_display' => self::formatDeltaShort($channel['trend_1y'] ?? null),
                        'trend_6m_display' => self::formatDeltaShort($channel['trend_6m'] ?? null),
                        'trend_3m_display' => self::formatDeltaShort($channel['trend_3m'] ?? null),
                        'trend_1y_class' => self::deltaClass($channel['trend_1y'] ?? null),
                        'trend_6m_class' => self::deltaClass($channel['trend_6m'] ?? null),
                        'trend_3m_class' => self::deltaClass($channel['trend_3m'] ?? null),
                    ];
                },
                $neuf['channels'] ?? []
            ),
        ];
    }

    /**
     * @param array<string, mixed> $brandHighlights
     *
     * @return array<string, mixed>
     */
    private static function formatBrandHighlights(array $brandHighlights, float $globalTotal, bool $excludeHm = false): array
    {
        $topBrands = $brandHighlights['top_brands'] ?? [];
        if ($excludeHm) {
            $topBrands = array_values(array_filter(
                $topBrands,
                static fn (array $brand): bool => strtoupper((string) ($brand['brand_name'] ?? '')) !== 'HM'
            ));
        }

        $brandHighlights['top_brands'] = array_map(
            static function (array $brand) use ($globalTotal): array {
                $currentTotal = (float) ($brand['current_total_raw'] ?? 0);
                $currentOccasionTotal = (float) ($brand['current_occasion_total_raw'] ?? 0);
                $currentGlobalTotal = (float) ($brand['current_global_total_raw'] ?? ($currentTotal + $currentOccasionTotal));
                $previousTotal = (float) ($brand['previous_total_raw'] ?? 0);
                $previousOccasionTotal = (float) ($brand['previous_occasion_total_raw'] ?? 0);
                $previousGlobalTotal = (float) ($brand['previous_global_total_raw'] ?? ($previousTotal + $previousOccasionTotal));
                $globalDelta = $previousGlobalTotal > 0
                    ? (($currentGlobalTotal - $previousGlobalTotal) / $previousGlobalTotal) * 100.0
                    : null;
                $brandDelta = $brand['delta_raw'] ?? null;
                $occasionDelta = $brand['occasion_delta'] ?? null;
                $trend1y = $brand['trend_1y_raw'] ?? null;
                $trend6m = $brand['trend_6m_raw'] ?? null;
                $trend3m = $brand['trend_3m_raw'] ?? null;
                $hasBrandValue = $currentGlobalTotal > 0 || $previousGlobalTotal > 0;
                $hasNeufValue = $currentTotal > 0 || $previousTotal > 0;
                $hasOccasionValue = $currentOccasionTotal > 0 || $previousOccasionTotal > 0;

                return [
                    'brand_id' => $brand['brand_id'] ?? null,
                    'brand_name' => $brand['brand_name'] ?? 'Marque',
                    'current_total' => $hasNeufValue ? self::formatInteger($currentTotal) : '-- €',
                    'current_occasion_total' => $hasOccasionValue ? self::formatInteger($currentOccasionTotal) : '-- €',
                    'current_global_total' => $hasBrandValue ? self::formatInteger($currentGlobalTotal) : '-- €',
                    'previous_total' => $hasNeufValue ? self::formatInteger($previousTotal) : '-- €',
                    'previous_occasion_total' => $hasOccasionValue ? self::formatInteger($previousOccasionTotal) : '-- €',
                    'previous_global_total' => $hasBrandValue ? self::formatInteger($previousGlobalTotal) : '-- €',
                    'global_delta' => $globalDelta,
                    'global_delta_display' => $globalDelta === null ? '--' : (($globalDelta > 0 ? '+' : '') . number_format($globalDelta, 1, ',', ' ') . ' %'),
                    'global_delta_class' => $globalDelta === null
                        ? 'delta-neutral'
                        : ($globalDelta > 0 ? 'delta-up' : ($globalDelta < 0 ? 'delta-down' : 'delta-neutral')),
                    'share_global' => self::formatPercent($currentGlobalTotal, $globalTotal),
                    'occasion_delta' => $occasionDelta,
                    'occasion_delta_display' => $occasionDelta === null ? '--' : (($occasionDelta > 0 ? '+' : '') . number_format($occasionDelta, 1, ',', ' ') . ' %'),
                    'delta_display' => $brandDelta === null ? '--' : (($brandDelta > 0 ? '+' : '') . number_format((float) $brandDelta, 1, ',', ' ') . ' %'),
                    'delta' => $brandDelta === null ? '--' : (($brandDelta > 0 ? '+' : '') . number_format((float) $brandDelta, 1, ',', ' ') . ' %'),
                    'delta_class' => $brandDelta === null ? 'delta-neutral' : ((float) $brandDelta > 0 ? 'delta-up' : ((float) $brandDelta < 0 ? 'delta-down' : 'delta-neutral')),
                    'occasion_delta_class' => $occasionDelta === null ? 'delta-neutral' : ($occasionDelta > 0 ? 'delta-up' : ($occasionDelta < 0 ? 'delta-down' : 'delta-neutral')),
                    'trend_1y_display' => self::formatDeltaShort(is_float($trend1y) ? $trend1y : null),
                    'trend_6m_display' => self::formatDeltaShort(is_float($trend6m) ? $trend6m : null),
                    'trend_3m_display' => self::formatDeltaShort(is_float($trend3m) ? $trend3m : null),
                    'trend_1y_class' => self::deltaClass(is_float($trend1y) ? $trend1y : null),
                    'trend_6m_class' => self::deltaClass(is_float($trend6m) ? $trend6m : null),
                    'trend_3m_class' => self::deltaClass(is_float($trend3m) ? $trend3m : null),
                    'channels' => array_map(
                        static function (array $channel) use ($brand): array {
                            $channelTotal = (float) ($channel['current_total_raw'] ?? 0);

                            return [
                                'label' => $channel['label'] ?? 'Canal',
                                'current_total' => self::formatInteger($channelTotal),
                                'previous_total' => self::formatInteger((float) ($channel['previous_total_raw'] ?? 0)),
                                'delta' => $channel['delta'] === null ? '--' : (($channel['delta'] > 0 ? '+' : '') . number_format((float) $channel['delta'], 1, ',', ' ') . ' %'),
                                'delta_class' => $channel['delta_class'] ?? 'delta-neutral',
                            ];
                        },
                        $brand['channels'] ?? []
                    ),
                ];
            },
            $topBrands
        );

        return $brandHighlights;
    }

    private static function formatNumber(float|int $value): string
    {
        return number_format((int) round((float) $value), 0, ',', ' ') . ' €';
    }

    private static function formatInteger(float|int $value): string
    {
        return number_format((int) round((float) $value), 0, ',', ' ') . ' €';
    }

    private static function formatPercent(float $value, float $base): string
    {
        if ($base <= 0) {
            return '0,0 % du global société';
        }

        return number_format(($value / $base) * 100.0, 1, ',', ' ') . ' % du global société';
    }

    private static function formatPercentValue(float $value, float $base): string
    {
        if ($base <= 0) {
            return '0,0 %';
        }

        return number_format(($value / $base) * 100.0, 1, ',', ' ') . ' %';
    }

    private static function formatRatioPercent(float $value, float $base): string
    {
        if ($base <= 0) {
            return '--';
        }

        return number_format(($value / $base) * 100.0, 1, ',', ' ') . ' %';
    }

    private static function formatScopePercent(float $value, float $base, string $scopeLabel): string
    {
        if ($base <= 0) {
            return '0,0 % ' . $scopeLabel;
        }

        return number_format(($value / $base) * 100.0, 1, ',', ' ') . ' % ' . $scopeLabel;
    }

    private static function formatDelta(null|float $value): string
    {
        if ($value === null) {
            return '--';
        }

        $prefix = $value > 0 ? '+' : '';

        return $prefix . number_format($value, 1, ',', ' ') . ' %';
    }

    private static function formatDeltaShort(null|float $value): string
    {
        if ($value === null) {
            return '--';
        }

        $prefix = $value > 0 ? '+' : '';

        return $prefix . number_format($value, 1, ',', ' ') . ' %';
    }

    private static function deltaClass(null|float $value): string
    {
        return match (true) {
            $value === null => 'delta-neutral',
            $value > 0 => 'delta-up',
            $value < 0 => 'delta-down',
            default => 'delta-neutral',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $channels
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sortChannelCards(array $channels): array
    {
        if (array_key_exists('channels', $channels) && is_array($channels['channels'])) {
            $channels['channels'] = self::sortChannelCards($channels['channels']);

            return $channels;
        }

        $preferredOrder = [
            'Nantes' => 0,
            'Bordeaux' => 1,
            'Web' => 2,
            'Ecole' => 3,
            'École' => 3,
            'Autre' => 4,
        ];

        usort(
            $channels,
            static function (array $a, array $b) use ($preferredOrder): int {
                $aLabel = (string) ($a['label'] ?? '');
                $bLabel = (string) ($b['label'] ?? '');
                $aRank = $preferredOrder[$aLabel] ?? 99;
                $bRank = $preferredOrder[$bLabel] ?? 99;

                return $aRank <=> $bRank ?: strcasecmp($aLabel, $bLabel);
            }
        );

        return $channels;
    }

    /**
     * @param array<string, mixed> $categoryHighlights
     *
     * @return array<string, mixed>
     */
    private static function formatCategoryHighlights(array $categoryHighlights, float $globalTotal): array
    {
        $categoryHighlights['top_categories'] = array_map(
            static function (array $category) use ($globalTotal): array {
                $currentTotal = (float) ($category['current_total_raw'] ?? 0);
                $currentOccasionTotal = (float) ($category['current_occasion_total_raw'] ?? 0);
                $currentGlobalTotal = (float) ($category['current_global_total_raw'] ?? ($currentTotal + $currentOccasionTotal));
                $previousTotal = (float) ($category['previous_total_raw'] ?? 0);
                $previousOccasionTotal = (float) ($category['previous_occasion_total_raw'] ?? 0);
                $previousGlobalTotal = (float) ($category['previous_global_total_raw'] ?? ($previousTotal + $previousOccasionTotal));
                $globalDelta = $previousGlobalTotal > 0
                    ? (($currentGlobalTotal - $previousGlobalTotal) / $previousGlobalTotal) * 100.0
                    : null;
                $categoryDelta = $category['delta_raw'] ?? null;
                $occasionDelta = ($previousOccasionTotal > 0)
                    ? (($currentOccasionTotal - $previousOccasionTotal) / $previousOccasionTotal) * 100.0
                    : null;
                $trend1y = $category['trend_1y_raw'] ?? null;
                $trend6m = $category['trend_6m_raw'] ?? null;
                $trend3m = $category['trend_3m_raw'] ?? null;
                $hasCategoryValue = $currentGlobalTotal > 0 || $previousGlobalTotal > 0;
                $hasNeufValue = $currentTotal > 0 || $previousTotal > 0;
                $hasOccasionValue = $currentOccasionTotal > 0 || $previousOccasionTotal > 0;

                return [
                    'category_name' => $category['category_name'] ?? 'Catégorie',
                    'current_total' => $hasNeufValue ? self::formatInteger($currentTotal) : '-- €',
                    'current_occasion_total' => $hasOccasionValue ? self::formatInteger($currentOccasionTotal) : '-- €',
                    'current_global_total' => $hasCategoryValue ? self::formatInteger($currentGlobalTotal) : '-- €',
                    'previous_total' => $hasNeufValue ? self::formatInteger($previousTotal) : '-- €',
                    'previous_occasion_total' => $hasOccasionValue ? self::formatInteger($previousOccasionTotal) : '-- €',
                    'previous_global_total' => $hasCategoryValue ? self::formatInteger($previousGlobalTotal) : '-- €',
                    'global_delta' => $globalDelta,
                    'global_delta_display' => $globalDelta === null ? '--' : (($globalDelta > 0 ? '+' : '') . number_format($globalDelta, 1, ',', ' ') . ' %'),
                    'global_delta_class' => $globalDelta === null
                        ? 'delta-neutral'
                        : ($globalDelta > 0 ? 'delta-up' : ($globalDelta < 0 ? 'delta-down' : 'delta-neutral')),
                    'share_global' => self::formatPercent($currentGlobalTotal, $globalTotal),
                    'delta_raw' => $categoryDelta,
                    'delta_display' => $categoryDelta === null ? '--' : (($categoryDelta > 0 ? '+' : '') . number_format((float) $categoryDelta, 1, ',', ' ') . ' %'),
                    'delta' => $categoryDelta === null ? '--' : (($categoryDelta > 0 ? '+' : '') . number_format((float) $categoryDelta, 1, ',', ' ') . ' %'),
                    'delta_class' => $categoryDelta === null ? 'delta-neutral' : ((float) $categoryDelta > 0 ? 'delta-up' : ((float) $categoryDelta < 0 ? 'delta-down' : 'delta-neutral')),
                    'occasion_delta' => $occasionDelta,
                    'occasion_delta_display' => $occasionDelta === null ? '--' : (($occasionDelta > 0 ? '+' : '') . number_format($occasionDelta, 1, ',', ' ') . ' %'),
                    'occasion_delta_class' => $occasionDelta === null ? 'delta-neutral' : ($occasionDelta > 0 ? 'delta-up' : ($occasionDelta < 0 ? 'delta-down' : 'delta-neutral')),
                    'trend_1y_display' => self::formatDeltaShort(is_float($trend1y) ? $trend1y : null),
                    'trend_6m_display' => self::formatDeltaShort(is_float($trend6m) ? $trend6m : null),
                    'trend_3m_display' => self::formatDeltaShort(is_float($trend3m) ? $trend3m : null),
                    'trend_1y_class' => self::deltaClass(is_float($trend1y) ? $trend1y : null),
                    'trend_6m_class' => self::deltaClass(is_float($trend6m) ? $trend6m : null),
                    'trend_3m_class' => self::deltaClass(is_float($trend3m) ? $trend3m : null),
                    'channels' => array_map(
                        static function (array $channel): array {
                            $currentTotal = (float) ($channel['current_total_raw'] ?? 0);
                            $currentOccasionTotal = (float) ($channel['current_occasion_total_raw'] ?? 0);
                            $currentGlobalTotal = (float) ($channel['current_global_total_raw'] ?? ($currentTotal + $currentOccasionTotal));
                            $previousTotal = (float) ($channel['previous_total_raw'] ?? 0);
                            $previousOccasionTotal = (float) ($channel['previous_occasion_total_raw'] ?? 0);
                            $previousGlobalTotal = (float) ($channel['previous_global_total_raw'] ?? ($previousTotal + $previousOccasionTotal));
                            $globalDelta = $channel['global_delta'] ?? null;
                            $occasionDelta = $channel['occasion_delta'] ?? null;
                            $delta = $channel['delta'] ?? null;

                            return [
                                'label' => $channel['label'] ?? 'Canal',
                                'current_total' => self::formatInteger($currentTotal),
                                'current_occasion_total' => self::formatInteger($currentOccasionTotal),
                                'current_global_total' => self::formatInteger($currentGlobalTotal),
                                'previous_total' => self::formatInteger($previousTotal),
                                'previous_occasion_total' => self::formatInteger($previousOccasionTotal),
                                'previous_global_total' => self::formatInteger($previousGlobalTotal),
                                'delta' => $delta === null ? '--' : (($delta > 0 ? '+' : '') . number_format((float) $delta, 1, ',', ' ') . ' %'),
                                'delta_display' => $delta === null ? '--' : (($delta > 0 ? '+' : '') . number_format((float) $delta, 1, ',', ' ') . ' %'),
                                'delta_class' => $channel['delta_class'] ?? 'delta-neutral',
                                'global_delta_display' => $globalDelta === null ? '--' : (($globalDelta > 0 ? '+' : '') . number_format((float) $globalDelta, 1, ',', ' ') . ' %'),
                                'global_delta_class' => $channel['global_delta_class'] ?? 'delta-neutral',
                                'occasion_delta_display' => $occasionDelta === null ? '--' : (($occasionDelta > 0 ? '+' : '') . number_format((float) $occasionDelta, 1, ',', ' ') . ' %'),
                                'occasion_delta_class' => $channel['occasion_delta_class'] ?? 'delta-neutral',
                            ];
                        },
                        $category['channels'] ?? []
                    ),
                ];
            },
            $categoryHighlights['top_categories'] ?? []
        );

        return $categoryHighlights;
    }

    /**
     * @return array{start:?string,end:?string,channel:?string,brand_id:?string,category:?string,occasion:?string,q:?string}
     */
    private function parseFilters(Request $request, bool $includeSearch): array
    {
        [$defaultStart, $defaultEnd] = $this->getDefaultDateRange();
        $occasion = (string) $request->query->get('occasion', '');
        if (!in_array($occasion, ['', 'occasion', 'hors_occasion'], true)) {
            $occasion = '';
        }
        $view = (string) $request->query->get('view', $includeSearch ? 'table' : 'cards');
        if (!in_array($view, ['cards', 'table'], true)) {
            $view = $includeSearch ? 'table' : 'cards';
        }
        $groupBy = (string) $request->query->get('group_by', 'idart');
        if (!in_array($groupBy, ['idart', 'brand', 'channel', 'category'], true)) {
            $groupBy = 'idart';
        }

        $filters = [
            'start' => $this->sanitizeDate($request->query->getString('start')) ?? $defaultStart,
            'end' => $this->sanitizeDate($request->query->getString('end')) ?? $defaultEnd,
            'channel' => $request->query->getString('channel') !== '' ? $request->query->getString('channel') : null,
            'brand_id' => $request->query->getString('brand_id') !== '' ? $request->query->getString('brand_id') : null,
            'category' => $request->query->getString('category') !== '' ? $request->query->getString('category') : null,
            'occasion' => $occasion !== '' ? $occasion : null,
            'view' => $view,
            'group_by' => $groupBy,
            'q' => $includeSearch ? trim($request->query->getString('q')) : null,
        ];

        return array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function getDefaultDateRange(): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            $today->modify('first day of this month')->format('Y-m-d'),
            $today->format('Y-m-d'),
        ];
    }

    private function sanitizeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof \DateTimeImmutable ? $date->format('Y-m-d') : null;
    }
}
