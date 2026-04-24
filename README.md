# Hurricane Music Dashboard

Dashboard interne de pilotage pour Hurricane Music.

## Ce que fait le projet

- lecture du mois en cours
- comparaison avec N-1
- récap occasion global et par canal
- top 5 marques
- répartition par canal
- import ETL depuis les tables métier
- part du global sur les sous-ensembles

## Règle principale

Le dashboard ne lit pas les tables PrestaShop directement dans les vues.

- `K_LI_FAC` = source brute
- `K_ARTICLE` = enrichissement produit
- `WEB_FABRICANT` = référentiel marques
- `reporting_invoice_line_fact` = table de travail du dashboard

## Lancement

```bash
cd dashboard
php bin/console app:etl:import-invoice-lines
```

## Déclenchement web de l’ETL

```text
/etl/import?token=TON_TOKEN
```

## Fichiers utiles

- [docs/context.md](docs/context.md)
- [dashboard/src/Controller/DashboardController.php](dashboard/src/Controller/DashboardController.php)
- [dashboard/src/Repository/KpiRepository.php](dashboard/src/Repository/KpiRepository.php)
- [dashboard/src/Service/InvoiceLineImportService.php](dashboard/src/Service/InvoiceLineImportService.php)
- [dashboard/templates/dashboard/home.html.twig](dashboard/templates/dashboard/home.html.twig)

## Tech

- Symfony 7.4
- Doctrine DBAL / Migrations
- Twig

## Ce qu’il ne faut pas refaire

- ne pas lire `K_LI_FAC` directement dans le dashboard
- ne pas réinventer un star schema trop tôt
- ne pas dupliquer toutes les colonnes source si elles ne servent pas au reporting
- ne pas perdre les règles métier déjà validées:
  - canal
  - occasion
  - marques

## Ce qu’il faudra probablement faire ensuite

1. page de détail ligne par ligne
2. recherche et filtres avancés
3. exports CSV
4. sécurisation plus fine du déclenchement web ETL
5. nettoyage / indexation si le volume augmente

## Commandes utiles

### Vérifier la config

```bash
cd dashboard
php bin/console about
```

### Lancer la migration

```bash
cd dashboard
php bin/console doctrine:migrations:migrate
```

### Lancer l’import

```bash
cd dashboard
php bin/console app:etl:import-invoice-lines
```

### Vider le cache

```bash
cd dashboard
php bin/console cache:clear
```

## Mémo pour une prochaine discussion

Si tu reprends ce projet plus tard, le point de départ utile est:

1. lire `README.md`
2. regarder `dashboard/src/Repository/KpiRepository.php`
3. regarder `dashboard/src/Service/InvoiceLineImportService.php`
4. regarder `dashboard/templates/dashboard/home.html.twig`

Le cœur du projet est:

- `K_LI_FAC` comme source des lignes de facture
- `K_ARTICLE` comme enrichissement produit
- `WEB_FABRICANT` comme référentiel des marques
- `reporting_invoice_line_fact` comme table de travail
- le dashboard lit uniquement la table de reporting
