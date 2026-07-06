# Dashboard Reporting

## Objectif

Piloter l’activité Hurricane Music avec un dashboard de reporting centré sur:

- l’objectif de période
- le cumul depuis le début de période
- le CA par canal
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
K_LI_FAC + K_ARTICLE + WEB_FABRICANT
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
php bin/console app:etl:import-invoice-lines --since=2026-06-01
```

### Comportement

- import incrémental par défaut
- reprise depuis le dernier `IDLigneFac` déjà présent
- rattrapage possible par date avec `--since`
- mise à jour des doublons via `source_line_id`
- aucune écriture dans `tm3dn_site_v3`
- les montants affichés dans l’interface sont en HT

### Règles métier d’import

- ignorer les lignes à quantité nulle
- ignorer le produit `18823`
- ignorer les références fournisseur commençant par `REPRISE`

## Accueil

La page d’accueil du dashboard met en avant:

- le global
- le CA par canal
- le neuf
- l’occasion
- les marques
- les catégories
- les filtres de période, canal, marque, catégorie et occasion

Les cartes de la home sont cliquables et le détail reprend la même période sélectionnée.

## Configuration

Les secrets ne doivent pas être stockés dans le webroot.

- variables d’environnement
- ou fichier privé hors racine publique

## Fichiers utiles

- `dashboard/src/Controller/DashboardController.php`
- `dashboard/src/Repository/KpiRepository.php`
- `dashboard/src/Service/InvoiceLineImportService.php`
- `dashboard/templates/dashboard/home.html.twig`
- `dashboard/templates/dashboard/detail.html.twig`
