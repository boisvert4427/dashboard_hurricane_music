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
- module de veille concurrentielle URL Finder

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

## Veille concurrentielle

La phase 1 est un URL Finder:

- Symfony orchestre le lot de produits
- Python cherche les URLs chez les concurrents
- Symfony stocke les résultats de test et les URLs finales
- `competitor_url_candidate` n’est plus dans le flux métier actif
- les tests gardent `competitor_title` et `competitor_price` quand ils sont disponibles
- la validation humaine travaille directement sur `competitor_url_test_result`
- les statuts métier sont `pending`, `valid`, `rejected`, `postponed`, `ignored`
- `score < 30` est traité comme `not_found`
- `score >= 90` et `matched` passe directement en `valid` et écrit aussi dans `competitor_url_final`

### URL de lancement

```text
/api/competitive/run-batch?competitor_id=1&limit=10&after_id=0&lang_id=1&shop_id=1&token=TON_TOKEN
```

Le batch runner refuse désormais de lancer deux exécutions concurrentes pour le même `competitor_id` / `lang_id` / `shop_id`.
Le lancer global `/api/competitive/run-all` possède aussi un verrou global, pour éviter deux runs complets en parallèle.

### URL de lecture de lot

```text
/api/competitive/products/next-batch?competitor_id=1&limit=10&after_id=0&lang_id=1&shop_id=1&token=TON_TOKEN
```

Les produits déjà testés pour ce concurrent sont exclus du prochain lot, afin d’éviter de recycler le même `id_product`.
Les produits `rejected` ne sont plus repris par le batch provider.

### Validation concurrentielle

- la page `/veille-concurrentielle/validation` liste uniquement les `pending` ouverts
- elle est paginée
- elle affiche le total des `pending`
- `Valider` pousse la ligne en `valid` et écrit l’URL finale
- `Rejeter` sort la ligne du flux et enregistre l’URL rejetée
- `Remettre à plus tard` passe en `postponed` sans la faire remonter dans la liste
- `competitor_url_test_result` reste la source de vérité pour la validation humaine

### Worker Python

Le worker est dans:

```text
competitive_intelligence_python/run_batch.py
```

Le worker enregistre les statuts de test dans `competitor_url_test_result`, y compris les cas `cloudflare` et `search_input_not_found`.
Les images peuvent être récupérées côté validation pour comparaison humaine, mais elles ne sont pas encore utilisées dans le scoring.

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
- ne pas mélanger le scraping Python dans Symfony

## Ce qu’il faudra probablement faire ensuite

1. page de détail ligne par ligne
2. recherche et filtres avancés
3. exports CSV
4. sécurisation plus fine du déclenchement web ETL
5. nettoyage / indexation si le volume augmente
6. affichage back-office de la veille concurrentielle

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

### Lancer un batch concurrentiel

```bash
curl -H 'X-COMPETITIVE-TOKEN: TON_TOKEN' \
'https://dashboard.hurricanemusic.fr/api/competitive/run-batch?competitor_id=1&limit=10&after_id=0&lang_id=1&shop_id=1'
```

### Lancer tous les concurrents

```text
/api/competitive/run-all?limit=5&lang_id=1&shop_id=1&max_parallel=2&token=TON_TOKEN
```

Cela lance Woodbrass, Stars Music, Thomann et Michenaud.
`run-both` reste un alias legacy.

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
- la veille concurrentielle est une sous-section du même site, avec un worker Python séparé
