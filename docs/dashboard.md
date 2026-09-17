# Dashboard Reporting

## Objectif

Piloter l’activité Hurricane Music avec un dashboard de reporting centré sur:

- l’objectif de période
- le cumul depuis le début de période
- le CA par canal
- le bloc `Neuf / Occasion` par canal
- les marges
- le neuf et l’occasion
- les marques
- les catégories
- le détail ligne par ligne

## Sources de données

Le dashboard ne lit pas les tables métier directement dans les vues.

### Source

- `K_LI_FAC`

### Enrichissement

- `K_ARTICLE`
- `WEB_FABRICANT`
- `WEB_RAYON`
- `WEB_FAMILLE`
- `WEB_SSFAMILLE`

### Reporting

- `reporting_invoice_line_fact`

## Flux

```text
K_LI_FAC + K_ARTICLE + WEB_FABRICANT + WEB_RAYON + WEB_FAMILLE + WEB_SSFAMILLE
        ↓
ETL Symfony
        ↓
reporting_invoice_line_fact
        ↓
KPI, canaux, marques, détail
```

## Import ETL

Commande:

```bash
cd dashboard
php bin/console app:etl:import-invoice-lines
```

Mode de rattrapage:

```bash
php bin/console app:etl:import-invoice-lines --since=2026-07-01
```

Route web sécurisée:

```text
/etl/import?token=VOTRE_TOKEN&since=2026-07-01
```

### Comportement

- import incrémental par défaut
- reprise depuis le dernier `IDLigneFac` déjà présent
- rattrapage possible par date avec `--since`
- mise à jour des doublons via `source_line_id`
- route web d’import protégée par `ETL_WEB_TOKEN`
- aucune écriture dans `tm3dn_site_v3`
- les montants affichés dans l’interface sont en HT
- les KPI de répartition et les cartes utilisent `Chart.js` pour les camemberts
- la home reste en vue cartes
- la page détail affiche le tableau ligne par ligne

### Règles métier d’import

- ignorer les lignes à quantité nulle
- ignorer le produit `18823`
- ignorer les références fournisseur commençant par `REPRISE`

## Accueil

La page d’accueil du dashboard met en avant:

- le global
- le CA par canal
- le bloc `Neuf / Occasion` réorganisé par canal
- le neuf
- l’occasion
- les marques
- les catégories
- les filtres de période, canal, marque, catégorie et occasion

### Sections visibles

- `Global`
- `Répartition par canal`
- `Neuf / Occasion`
- `Neuf`
- `Occasion`
- `Top 8 marques`
- `Catégories`

### Comportement interface

- la période par défaut va du premier jour du mois courant à aujourd’hui
- les dates sont modifiables à la main ou via le calendrier
- les cartes sont cliquables
- le détail reprend les filtres actifs
- les montants sont affichés en HT
- les graphiques de répartition sont construits avec `Chart.js`

Les cartes de la home sont cliquables et le détail reprend la même période sélectionnée.

## Configuration

Les secrets ne doivent pas être stockés dans le webroot.

- variables d’environnement
- ou fichier privé hors racine publique

## Fichiers utiles

- `dashboard/src/Controller/DashboardController.php`
- `dashboard/src/Controller/EtlController.php`
- `dashboard/src/Repository/KpiRepository.php`
- `dashboard/src/Service/InvoiceLineImportService.php`
- `dashboard/templates/dashboard/home.html.twig`
- `dashboard/templates/dashboard/detail.html.twig`
