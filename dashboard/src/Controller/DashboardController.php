<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\KpiRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(KpiRepository $kpiRepository): Response
    {
        $data = $kpiRepository->getHomeData();
        $globalTotal = (float) ($data['current_summary']['total_ht'] ?? 0);

        return $this->render('dashboard/home.html.twig', [
            'period_label' => $data['current_period']['start']->format('d/m/Y') . ' au ' . $data['current_period']['end']->format('d/m/Y'),
            'previous_period_label' => $data['previous_period']['start']->format('d/m/Y') . ' au ' . $data['previous_period']['end']->format('d/m/Y'),
            'occasion' => self::formatOccasionSection($data['occasion'], $globalTotal),
            'brand_highlights' => self::formatBrandHighlights($data['brand_highlights'], $globalTotal),
            'kpis' => array_map(static function (array $kpi): array {
                $deltaClass = match (true) {
                    ($kpi['delta'] ?? null) === null => 'delta-neutral',
                    (float) $kpi['delta'] > 0 => 'delta-up',
                    (float) $kpi['delta'] < 0 => 'delta-down',
                    default => 'delta-neutral',
                };

                return [
                    'label' => $kpi['label'],
                    'current' => $kpi['type'] === 'count' ? number_format((int) $kpi['current'], 0, ',', ' ') . ' €' : self::formatNumber($kpi['current']),
                    'previous' => $kpi['type'] === 'count' ? number_format((int) $kpi['previous'], 0, ',', ' ') . ' €' : self::formatNumber($kpi['previous']),
                    'delta' => self::formatDelta($kpi['delta']),
                    'delta_class' => $deltaClass,
                    'hint' => $kpi['hint'],
                ];
            }, $data['kpis']),
            'channels' => (static function (array $channels) use ($globalTotal): array {
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
                        'hint' => 'Marge: ' . self::formatInteger($channel['margin']) . ' | Factures: ' . number_format((int) $channel['invoices'], 0, ',', ' ') . ' | Lignes: ' . number_format((int) $channel['lines'], 0, ',', ' '),
                        'highlights' => self::formatBrandHighlights($channel['highlights'], $globalTotal),
                    ];
                }, $channels);
            })($data['channels']),
        ]);
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
    private static function formatBrandHighlights(array $brandHighlights, float $globalTotal): array
    {
        $brandHighlights['top_brands'] = array_map(
            static function (array $brand) use ($globalTotal): array {
                $currentTotal = (float) ($brand['current_total_raw'] ?? 0);

                return [
                    'brand_name' => $brand['brand_name'] ?? 'Marque',
                    'current_total' => self::formatInteger($currentTotal),
                    'previous_total' => self::formatInteger((float) ($brand['previous_total_raw'] ?? 0)),
                    'share_global' => self::formatPercent($currentTotal, $globalTotal),
                    'delta' => $brand['delta'] === null ? 'n/a' : (($brand['delta'] > 0 ? '+' : '') . number_format((float) $brand['delta'], 1, ',', ' ') . ' % vs N-1'),
                    'delta_class' => $brand['delta'] === null ? 'delta-neutral' : ((float) $brand['delta'] > 0 ? 'delta-up' : ((float) $brand['delta'] < 0 ? 'delta-down' : 'delta-neutral')),
                ];
            },
            $brandHighlights['top_brands'] ?? []
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
}
