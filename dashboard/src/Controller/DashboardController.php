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

        return $this->render('dashboard/home.html.twig', [
            'period_label' => $data['current_period']['start']->format('d/m/Y') . ' au ' . $data['current_period']['end']->format('d/m/Y'),
            'previous_period_label' => $data['previous_period']['start']->format('d/m/Y') . ' au ' . $data['previous_period']['end']->format('d/m/Y'),
            'previous_month_label' => $data['previous_month_period']['start']->format('d/m/Y') . ' au ' . $data['previous_month_period']['end']->format('d/m/Y'),
            'active_filters' => $filters,
            'filter_options' => $data['filters'],
            'alerts' => $data['alerts'],
            'objective_summary' => $data['objective_summary'],
            'occasion' => self::formatOccasionSection($data['occasion'], $globalTotal),
            'brand_highlights' => self::formatBrandHighlights($data['brand_highlights'], $globalTotal, true),
            'kpis' => array_map(static function (array $kpi): array {
                $deltaClass = match (true) {
                    ($kpi['delta'] ?? null) === null => 'delta-neutral',
                    (float) $kpi['delta'] > 0 => 'delta-up',
                    (float) $kpi['delta'] < 0 => 'delta-down',
                    default => 'delta-neutral',
                };

                return [
                    'label' => $kpi['label'],
                    'current' => $kpi['type'] === 'count' ? number_format((int) $kpi['current'], 0, ',', ' ') : self::formatNumber($kpi['current']),
                    'previous' => $kpi['type'] === 'count' ? number_format((int) $kpi['previous'], 0, ',', ' ') : self::formatNumber($kpi['previous']),
                    'delta' => self::formatDelta($kpi['delta']),
                    'delta_class' => $deltaClass,
                    'hint' => $kpi['hint'],
                ];
            }, $data['kpis']),
            'channels' => (static function (array $channels) use ($globalTotal, $filters): array {
                $maxCurrent = 0.0;
                foreach ($channels as $channel) {
                    $maxCurrent = max($maxCurrent, (float) $channel['current']);
                }

                return array_map(static function (array $channel) use ($maxCurrent, $globalTotal): array {
                    $current = (float) $channel['current'];

                    return [
                        'label' => $channel['label'],
                        'value' => self::formatInteger($current),
                        'share_global' => self::formatPercent($current, $globalTotal),
                        'share' => $maxCurrent > 0 ? ($current / $maxCurrent) * 100.0 : 0.0,
                        'margin' => self::formatInteger((float) $channel['margin']),
                        'invoices' => number_format((int) $channel['invoices'], 0, ',', ' '),
                        'lines' => number_format((int) $channel['lines'], 0, ',', ' '),
                        'average_basket' => self::formatInteger((float) ($channel['average_basket'] ?? 0)),
                        'previous_value' => self::formatInteger((float) ($channel['previous'] ?? 0)),
                        'delta' => self::formatDelta($channel['delta'] ?? null),
                        'basket_delta' => $channel['basket_delta'] ?? 'n/a',
                        'hint' => 'CA HT sur le mois en cours',
                        'highlights' => self::formatBrandHighlights($channel['highlights'], $globalTotal, false),
                    ];
                }, $channels);
            })($data['channels']),
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
    private static function formatOccasionSection(array $occasion, float $globalTotal): array
    {
        return [
            'current_total' => self::formatInteger($occasion['current_total'] ?? 0),
            'previous_total' => self::formatInteger($occasion['previous_total'] ?? 0),
            'share_global' => self::formatPercent((float) ($occasion['current_total'] ?? 0), $globalTotal),
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
            'channels' => array_map(
                static function (array $channel) use ($globalTotal): array {
                    $deltaClass = match (true) {
                        ($channel['delta'] ?? null) === null => 'delta-neutral',
                        (float) $channel['delta'] > 0 => 'delta-up',
                        (float) $channel['delta'] < 0 => 'delta-down',
                        default => 'delta-neutral',
                    };

                    return [
                        'label' => $channel['label'] ?? 'Autre',
                        'current_total' => self::formatInteger($channel['current_total'] ?? 0),
                        'previous_total' => self::formatInteger($channel['previous_total'] ?? 0),
                        'share_global' => self::formatPercent((float) ($channel['current_total'] ?? 0), $globalTotal),
                        'delta' => self::formatDelta($channel['delta'] ?? null),
                        'delta_class' => $deltaClass,
                        'current_lines' => number_format((int) ($channel['current_lines'] ?? 0), 0, ',', ' '),
                        'previous_lines' => number_format((int) ($channel['previous_lines'] ?? 0), 0, ',', ' '),
                    ];
                },
                $occasion['channels'] ?? []
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

                return [
                    'brand_id' => $brand['brand_id'] ?? null,
                    'brand_name' => $brand['brand_name'] ?? 'Marque',
                    'current_total' => self::formatInteger($currentTotal),
                    'current_occasion_total' => self::formatInteger((float) ($brand['current_occasion_total_raw'] ?? 0)),
                    'previous_total' => self::formatInteger((float) ($brand['previous_total_raw'] ?? 0)),
                    'share_global' => self::formatPercent($currentTotal, $globalTotal),
                    'delta' => $brand['delta'] === null ? 'n/a' : (($brand['delta'] > 0 ? '+' : '') . number_format((float) $brand['delta'], 1, ',', ' ') . ' % vs N-1'),
                    'delta_class' => $brand['delta'] === null ? 'delta-neutral' : ((float) $brand['delta'] > 0 ? 'delta-up' : ((float) $brand['delta'] < 0 ? 'delta-down' : 'delta-neutral')),
                ];
            },
            $topBrands
        );

        return $brandHighlights;
    }

    private static function formatNumber(float|int $value): string
    {
        return number_format((float) $value, 2, ',', ' ') . ' €';
    }

    private static function formatInteger(float|int $value): string
    {
        return number_format((int) round((float) $value), 0, ',', ' ') . ' €';
    }

    private static function formatPercent(float $value, float $base): string
    {
        if ($base <= 0) {
            return '0,0 % du global';
        }

        return number_format(($value / $base) * 100.0, 1, ',', ' ') . ' % du global';
    }

    private static function formatDelta(null|float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        $prefix = $value > 0 ? '+' : '';

        return $prefix . number_format($value, 1, ',', ' ') . ' % vs N-1';
    }

    /**
     * @return array{start:?string,end:?string,channel:?string,brand_id:?string,occasion:?string,q:?string}
     */
    private function parseFilters(Request $request, bool $includeSearch): array
    {
        $occasion = (string) $request->query->get('occasion', '');
        if (!in_array($occasion, ['', 'occasion', 'hors_occasion'], true)) {
            $occasion = '';
        }

        $filters = [
            'start' => $this->sanitizeDate($request->query->getString('start')),
            'end' => $this->sanitizeDate($request->query->getString('end')),
            'channel' => $request->query->getString('channel') !== '' ? $request->query->getString('channel') : null,
            'brand_id' => $request->query->getString('brand_id') !== '' ? $request->query->getString('brand_id') : null,
            'occasion' => $occasion !== '' ? $occasion : null,
            'q' => $includeSearch ? trim($request->query->getString('q')) : null,
        ];

        return array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
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
