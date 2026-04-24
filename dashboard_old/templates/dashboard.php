<?php

declare(strict_types=1);

/** @var array<string, mixed> $monthToDateKpis */
/** @var array<string, mixed> $previousYearMonthToDateKpis */
/** @var array<int, array<string, mixed>> $salesByChannel */
/** @var array<string, mixed> $config */
/** @var DateTimeImmutable $monthStart */
/** @var DateTimeImmutable $monthEnd */
/** @var DateTimeImmutable $previousYearStart */
/** @var DateTimeImmutable $previousYearEnd */

function formatMoney(mixed $value): string
{
    return number_format((float) $value, 2, ',', ' ') . ' €';
}

function formatNumber(mixed $value): string
{
    return number_format((float) $value, 0, ',', ' ');
}

function formatRate(mixed $value): string
{
    return number_format((float) $value, 2, ',', ' ') . ' %';
}

function formatDelta(mixed $current, mixed $previous, string $suffix = ''): string
{
    $currentValue = (float) $current;
    $previousValue = (float) $previous;

    if ($previousValue === 0.0) {
        return 'N/A';
    }

    $delta = (($currentValue - $previousValue) / abs($previousValue)) * 100;
    $sign = $delta > 0 ? '+' : '';

    return $sign . number_format($delta, 1, ',', ' ') . ' %' . $suffix;
}

function deltaClass(mixed $current, mixed $previous): string
{
    return (float) $current >= (float) $previous ? '' : 'is-down';
}

function metricCard(string $label, string $value, string $description, string $delta = '', string $class = ''): void
{
    ?>
    <article class="card">
        <div class="label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></div>
        <p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($delta !== ''): ?>
            <div class="delta <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
                <span>vs période précédente</span>
                <strong><?= htmlspecialchars($delta, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        <?php endif; ?>
    </article>
    <?php
}

$monthRevenue = (float) ($monthToDateKpis['total_revenue'] ?? 0);
$monthMargin = (float) ($monthToDateKpis['gross_margin'] ?? 0);
$monthOrders = (int) ($monthToDateKpis['orders_count'] ?? 0);
$monthBasket = (float) ($monthToDateKpis['average_basket'] ?? 0);

$previousMonthRevenue = (float) ($previousYearMonthToDateKpis['total_revenue'] ?? 0);
$previousMonthMargin = (float) ($previousYearMonthToDateKpis['gross_margin'] ?? 0);
$previousMonthOrders = (int) ($previousYearMonthToDateKpis['orders_count'] ?? 0);
$previousMonthBasket = (float) ($previousYearMonthToDateKpis['average_basket'] ?? 0);

$monthStartLabel = $monthStart->format('d/m/Y');
$monthEndLabel = $monthEnd->format('d/m/Y');
$previousYearStartLabel = $previousYearStart->format('d/m/Y');
$previousYearEndLabel = $previousYearEnd->format('d/m/Y');
$monthPeriodLabel = $monthStartLabel . ' - ' . $monthEndLabel;
$previousPeriodLabel = $previousYearStartLabel . ' - ' . $previousYearEndLabel;
$totalChannelRevenue = array_sum(array_map(static fn(array $row): float => (float) ($row['current_revenue'] ?? 0), $salesByChannel));
$channelCount = count($salesByChannel);

ob_start();
?>
<section class="hero objective" id="overview">
    <div class="hero-copy">
        <h1>Vue mensuelle</h1>
        <p>Chiffres depuis le début du mois, comparés à la même période de l'année dernière, puis découpés par canal de vente.</p>
    </div>
    <div>
        <div class="badge">Période: <?= htmlspecialchars($monthPeriodLabel, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="badge badge--subtle" style="margin-top: 10px;">N-1: <?= htmlspecialchars($previousPeriodLabel, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2 class="section-title">Global depuis le 1er</h2>
        <p class="muted">Lecture globale du mois en cours, avec comparaison à la même période de l'année précédente.</p>
    </div>
    <div class="grid">
        <?php metricCard(
            'CA mensuel',
            formatMoney($monthRevenue),
            'N-1 sur la même période: ' . formatMoney($previousMonthRevenue),
            formatDelta($monthRevenue, $previousMonthRevenue),
            deltaClass($monthRevenue, $previousMonthRevenue)
        ); ?>
        <?php metricCard(
            'Marge mensuelle',
            formatMoney($monthMargin),
            'N-1 sur la même période: ' . formatMoney($previousMonthMargin),
            formatDelta($monthMargin, $previousMonthMargin),
            deltaClass($monthMargin, $previousMonthMargin)
        ); ?>
        <?php metricCard(
            'Factures',
            formatNumber($monthOrders),
            'N-1 sur la même période: ' . formatNumber($previousMonthOrders),
            formatDelta($monthOrders, $previousMonthOrders),
            deltaClass($monthOrders, $previousMonthOrders)
        ); ?>
        <?php metricCard(
            'Panier moyen',
            formatMoney($monthBasket),
            'N-1 sur la même période: ' . formatMoney($previousMonthBasket),
            formatDelta($monthBasket, $previousMonthBasket),
            deltaClass($monthBasket, $previousMonthBasket)
        ); ?>
    </div>
</section>

<section class="section" id="sales">
    <div class="section-head">
        <h2 class="section-title">Canaux de vente</h2>
        <p class="muted">Répartition du mois en cours par canal de vente.</p>
    </div>
    <div class="badge badge--subtle" style="margin-bottom: 14px;">
        <?= htmlspecialchars((string) $channelCount, ENT_QUOTES, 'UTF-8') ?> canaux détectés - CA total: <?= htmlspecialchars(formatMoney($totalChannelRevenue), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="channel-grid">
        <?php if ($salesByChannel === []): ?>
            <article class="channel-card">
                <div class="label">Canaux</div>
                <strong>Aucune donnée détectée</strong>
                <div class="big">À brancher</div>
                <p>Le schéma de `reporting_sales_daily` doit exposer un champ canal et un champ chiffre d'affaires.</p>
            </article>
        <?php else: ?>
            <?php foreach ($salesByChannel as $channel): ?>
                <?php
                    $currentRevenue = (float) ($channel['current_revenue'] ?? 0);
                    $previousRevenue = (float) ($channel['previous_revenue'] ?? 0);
                    $currentOrders = (int) ($channel['current_orders'] ?? 0);
                    $previousOrders = (int) ($channel['previous_orders'] ?? 0);
                    $share = $totalChannelRevenue > 0 ? ($currentRevenue / $totalChannelRevenue) * 100 : 0;
                ?>
                <article class="channel-card">
                    <div class="label"><?= htmlspecialchars((string) ($channel['channel'] ?? 'Autre'), ENT_QUOTES, 'UTF-8') ?></div>
                    <strong>Part du mois: <?= htmlspecialchars(formatRate($share), ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="big"><?= htmlspecialchars(formatMoney($currentRevenue), ENT_QUOTES, 'UTF-8') ?></div>
                    <p>N-1: <?= htmlspecialchars(formatMoney($previousRevenue), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="delta <?= htmlspecialchars(deltaClass($currentRevenue, $previousRevenue), ENT_QUOTES, 'UTF-8') ?>">
                        <span>vs N-1</span>
                        <strong><?= htmlspecialchars(formatDelta($currentRevenue, $previousRevenue), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <p style="margin-top: 10px;">Factures: <?= htmlspecialchars(formatNumber($currentOrders), ENT_QUOTES, 'UTF-8') ?> | N-1: <?= htmlspecialchars(formatNumber($previousOrders), ENT_QUOTES, 'UTF-8') ?></p>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
