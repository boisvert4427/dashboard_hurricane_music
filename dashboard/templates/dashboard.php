<?php

declare(strict_types=1);

/** @var array<string, mixed> $kpis */
/** @var array<string, mixed> $previousKpis */
/** @var array<string, mixed> $monthToDateKpis */
/** @var array<string, mixed> $config */
/** @var string $monthStart */

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

$currentRevenue = $kpis['total_revenue'] ?? 0;
$previousRevenue = $previousKpis['total_revenue'] ?? 0;
$currentMargin = $kpis['gross_margin'] ?? 0;
$previousMargin = $previousKpis['gross_margin'] ?? 0;
$currentOrders = $kpis['orders_count'] ?? 0;
$previousOrders = $previousKpis['orders_count'] ?? 0;
$currentBasket = $kpis['average_basket'] ?? 0;
$previousBasket = $previousKpis['average_basket'] ?? 0;
$currentTraffic = $kpis['traffic'] ?? 0;
$previousTraffic = $previousKpis['traffic'] ?? 0;
$currentConversion = $kpis['conversion_rate'] ?? 0;
$previousConversion = $previousKpis['conversion_rate'] ?? 0;

$monthlyRevenueTarget = (float) ($config['targets']['monthly_revenue'] ?? 0);
$monthlyOrdersTarget = (int) ($config['targets']['monthly_orders'] ?? 0);
$monthRevenue = (float) ($monthToDateKpis['total_revenue'] ?? 0);
$monthMargin = (float) ($monthToDateKpis['gross_margin'] ?? 0);
$monthOrders = (int) ($monthToDateKpis['orders_count'] ?? 0);
$monthTraffic = (int) ($monthToDateKpis['traffic'] ?? 0);
$monthConversion = (float) ($monthToDateKpis['conversion_rate'] ?? 0);
$monthStartLabel = date('d/m/Y', strtotime((string) $monthStart));
$revenueProgress = $monthlyRevenueTarget > 0 ? min(100, ($monthRevenue / $monthlyRevenueTarget) * 100) : 0;
$revenueRemaining = $monthlyRevenueTarget > 0 ? max(0, $monthlyRevenueTarget - $monthRevenue) : 0;

$channelCards = [
    [
        'label' => 'Web',
        'note' => 'CA e-commerce et conversion',
        'value' => 'À connecter',
        'hint' => 'Premier canal à isoler dans `reporting_sales_daily`',
    ],
    [
        'label' => 'Boutique',
        'note' => 'Ventes magasin et trafic physique',
        'value' => 'À connecter',
        'hint' => 'Suivi du point de vente et du panier moyen',
    ],
    [
        'label' => 'Marketplace',
        'note' => 'Flux tiers et commissions',
        'value' => 'À connecter',
        'hint' => 'Pilotage des canaux externes',
    ],
    [
        'label' => 'B2B',
        'note' => 'Commandes pros et comptes clés',
        'value' => 'À connecter',
        'hint' => 'Segment à part dans le pilotage',
    ],
];

ob_start();
?>
<section class="hero objective" id="overview">
    <div class="hero-copy">
        <h1>Dashboard Hurricane Music</h1>
        <p>Lecture d’abord sur l’objectif global du mois, puis sur la contribution de chaque canal de vente.</p>
    </div>
    <div>
        <div class="badge">Dernière date: <?= htmlspecialchars((string) ($kpis['kpi_date'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="badge badge--subtle" style="margin-top: 10px;">Depuis le <?= htmlspecialchars($monthStartLabel, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2 class="section-title">Objectif du mois</h2>
        <p class="muted">Le pilotage commence ici: où on en est depuis le 1er du mois, et combien il reste à aller chercher.</p>
    </div>
    <div class="objective-layout">
        <div class="objective-panel">
            <div class="label">CA mensuel</div>
            <div class="value"><?= formatMoney($monthRevenue) ?></div>
            <p>Montant cumulé depuis le début du mois.</p>
            <div class="progress">
                <div class="progress-track">
                    <div class="progress-fill" style="width: <?= htmlspecialchars((string) $revenueProgress, ENT_QUOTES, 'UTF-8') ?>%;"></div>
                </div>
                <div class="progress-meta">
                    <span><?= htmlspecialchars($monthlyRevenueTarget > 0 ? formatMoney($monthlyRevenueTarget) : 'Objectif non défini', ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars($monthlyRevenueTarget > 0 ? formatMoney($revenueRemaining) . ' restant' : 'À renseigner', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
            <div class="objective-kpis">
                <div class="objective-kpi">
                    <span class="muted">Commandes</span>
                    <strong><?= formatNumber($monthOrders) ?></strong>
                    <div class="muted">Objectif: <?= $monthlyOrdersTarget > 0 ? formatNumber($monthlyOrdersTarget) : 'non défini' ?></div>
                </div>
                <div class="objective-kpi">
                    <span class="muted">Marge</span>
                    <strong><?= formatMoney($monthMargin) ?></strong>
                    <div class="muted">Lecture de la rentabilité du mois</div>
                </div>
                <div class="objective-kpi">
                    <span class="muted">Trafic</span>
                    <strong><?= formatNumber($monthTraffic) ?></strong>
                    <div class="muted">Audience depuis le 1er</div>
                </div>
            </div>
        </div>
        <div class="objective-panel">
            <div class="label">Taux de conversion mensuel</div>
            <div class="value"><?= formatRate($monthConversion) ?></div>
            <p>Vue rapide pour mesurer l’efficacité du trafic et des ventes sur le mois.</p>
            <div class="list">
                <div class="list-item">
                    <div>
                        <strong>CA vs objectif</strong>
                        <span>Le premier indicateur à suivre chaque jour.</span>
                    </div>
                    <span><?= $monthlyRevenueTarget > 0 ? formatRate(($monthRevenue / $monthlyRevenueTarget) * 100) : 'à définir' ?></span>
                </div>
                <div class="list-item">
                    <div>
                        <strong>Reste à faire</strong>
                        <span>Montant restant pour atteindre le budget mensuel.</span>
                    </div>
                    <span><?= $monthlyRevenueTarget > 0 ? formatMoney($revenueRemaining) : 'à définir' ?></span>
                </div>
                <div class="list-item">
                    <div>
                        <strong>Lecture prioritaire</strong>
                        <span>Objectif global puis canal de vente.</span>
                    </div>
                    <span>Accueil</span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="sales">
    <div class="section-head">
        <h2 class="section-title">Canaux de vente</h2>
        <p class="muted">La page d’accueil doit montrer en premier la répartition par canal, avant les blocs support.</p>
    </div>
    <div class="channel-grid">
        <?php foreach ($channelCards as $channel): ?>
            <article class="channel-card">
                <div class="label"><?= htmlspecialchars($channel['label'], ENT_QUOTES, 'UTF-8') ?></div>
                <strong><?= htmlspecialchars($channel['note'], ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="big"><?= htmlspecialchars($channel['value'], ENT_QUOTES, 'UTF-8') ?></div>
                <p><?= htmlspecialchars($channel['hint'], ENT_QUOTES, 'UTF-8') ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="section columns">
    <div class="panel">
        <div class="section-head">
            <h2 class="section-title">Synthèse du jour</h2>
            <span class="badge badge--subtle">Lecture opérationnelle complémentaire</span>
        </div>
        <p>Les KPI journaliers restent utiles, mais ils passent après l’objectif mensuel et la vue par canal.</p>
        <section class="grid" style="margin-top: 14px;">
            <?php metricCard(
                'CA du jour',
                formatMoney($currentRevenue),
                "Chiffre d'affaires du dernier jour chargé.",
                formatDelta($currentRevenue, $previousRevenue),
                deltaClass($currentRevenue, $previousRevenue)
            ); ?>
            <?php metricCard(
                'Commandes',
                formatNumber($currentOrders),
                "Volume de commandes journalier.",
                formatDelta($currentOrders, $previousOrders),
                deltaClass($currentOrders, $previousOrders)
            ); ?>
            <?php metricCard(
                'Panier moyen',
                formatMoney($currentBasket),
                "Ticket moyen par commande.",
                formatDelta($currentBasket, $previousBasket),
                deltaClass($currentBasket, $previousBasket)
            ); ?>
        </section>
    </div>
    <div class="panel" id="marketing">
        <div class="section-head">
            <h2 class="section-title">Marketing</h2>
            <span class="badge badge--subtle">Analytics et conversion</span>
        </div>
        <p>Cette zone doit agréger le trafic et la conversion par source, avec un focus sur les pages d’entrée.</p>
        <div class="list">
            <div class="list-item">
                <div>
                    <strong>Trafic total</strong>
                    <span>Sessions ou visites consolidées.</span>
                </div>
                <span><code>reporting_analytics_daily</code></span>
            </div>
            <div class="list-item">
                <div>
                    <strong>Sources de trafic</strong>
                    <span>Organic, paid, social, direct, referral.</span>
                </div>
                <span>À détailler par canal</span>
            </div>
            <div class="list-item">
                <div>
                    <strong>Taux de conversion</strong>
                    <span>Vue simple et drill-down par source.</span>
                </div>
                <span>Conversion par source</span>
            </div>
        </div>
    </div>
</section>

<section class="section columns" id="stock">
    <div class="panel">
        <div class="section-head">
            <h2 class="section-title">Stock</h2>
            <span class="badge badge--subtle">Achats et couverture</span>
        </div>
        <p>Le dashboard doit rapidement montrer ce qui bloque le business: ruptures, couverture et valorisation.</p>
        <div class="list">
            <div class="list-item">
                <div>
                    <strong>État du stock</strong>
                    <span>Quantités disponibles et seuils d'alerte.</span>
                </div>
                <span><code>reporting_stock_snapshot</code></span>
            </div>
            <div class="list-item">
                <div>
                    <strong>Produits en rupture</strong>
                    <span>Alertes actionnables par catégorie.</span>
                </div>
                <span>Priorité opérationnelle</span>
            </div>
            <div class="list-item">
                <div>
                    <strong>Achats fournisseurs</strong>
                    <span>Flux d'achats et couverture.</span>
                </div>
                <span>À consolider via ETL</span>
            </div>
        </div>
    </div>
    <div class="panel" id="performance">
        <div class="section-head">
            <h2 class="section-title">Performance avancée</h2>
            <span class="badge badge--subtle">Lecture croisée</span>
        </div>
        <p>Cette partie doit permettre de relier trafic, marge, canal et rentabilité produit pour aider à décider vite.</p>
        <div class="list">
            <div class="list-item">
                <div>
                    <strong>CA vs trafic</strong>
                    <span>Mesurer l'efficacité commerciale réelle.</span>
                </div>
                <span>Comparatif multi-séries</span>
            </div>
            <div class="list-item">
                <div>
                    <strong>Marge par canal</strong>
                    <span>Repérer les canaux les plus rentables.</span>
                </div>
                <span>Drill-down canal</span>
            </div>
            <div class="list-item">
                <div>
                    <strong>Rentabilité produit</strong>
                    <span>Arbitrer sur l'assortiment et les prix.</span>
                </div>
                <span>Marque + catégorie + fournisseur</span>
            </div>
        </div>
    </div>
</section>

<p class="foot" id="roadmap">
    Prochaine étape: connecter la base MariaDB, remplir les tables de reporting via l'ETL, puis ajouter les vues
    <code>/ventes</code>, <code>/marketing</code>, <code>/stock</code> et <code>/performance</code> en mode drill-down.
</p>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
