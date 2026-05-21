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
- module de veille concurrentielle avec orchestration, matching URL, prix et validation image

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

La veille concurrentielle est maintenant pilotée par un orchestrateur unique et découpée en tâches explicites:

- `new_urls`
- `retry_urls`
- `prices`

Chaque tâche existe pour:

- Woodbrass
- Stars Music
- Thomann
- Michenaud

### Orchestrateur

Point d’entrée normal:

```text
/api/competitive/orchestrate?token=TON_TOKEN
```

Usage prévu:

- appelé toutes les minutes
- lit la configuration définie dans l’admin
- regarde les locks actifs
- regarde le backlog disponible
- choisit au plus une tâche à lancer

Réglages admin:

- page: `/veille-concurrentielle/orchestrateur`
- paramètres par tâche:
  - actif
  - batch
  - intervalle en heures + minutes
  - priorité
- action manuelle:
  - `Lancer 1 fois`

Valeurs par défaut:

- `new_urls` = 12h
- `retry_urls` = 12h
- `prices` = 1 minute

### Matching URL

- Symfony orchestre le lot de produits
- Python cherche les URLs chez les concurrents
- Thomann et Michenaud utilisent OpenAI pour arbitrer les meilleurs candidats
- l’API reçoit un seul appel OpenAI par batch avec jusqu’à 3 candidats utiles par produit
- les candidats Thomann / Michenaud sont filtrés par marque avant appel API
- Thomann rejette d’office les titres `b-stock`, `b stock`, `bstock` et `bundle`
- Symfony stocke les résultats de test et les URLs finales
- `competitor_url_candidate` n’est plus dans le flux métier actif
- les tests gardent `competitor_title`, `competitor_brand`, `competitor_breadcrumb` et `competitor_price` quand ils sont disponibles
- la validation humaine travaille directement sur `competitor_url_test_result`
- les statuts métier sont `pending`, `valid`, `rejected`, `postponed`, `ignored`
- `score < 30` est traité comme `not_found`
- `retry_url` reprend maintenant les plus anciens `not_found` sans `final`, triés par `last_tested_at`

### Passe prix

- Symfony ne reprend que les `competitor_url_final`
- l’historique est append-only dans `competitor_url_price_history`
- `competitor_url_final` garde le prix courant
- `Stars Music` remonte maintenant aussi le prix pendant le matching URL quand la fiche produit est validée
- la sélection priorise les finals absents de l’historique, puis les plus anciens derniers scrapes de prix
- `competitor_url_final` stocke aussi maintenant l’état HTTP:
  - `last_http_status`
  - `consecutive_http_failures`
  - `last_http_error_at`
  - `last_http_error_message`
- après 3 `404/410` consécutifs:
  - l’entrée est supprimée de `competitor_url_final`
  - le `competitor_url_test_result` lié passe en `competitor_page_status = gone`

### Images et validation

- le scrape image reste en mode direct sur le script PHP `fix-pending-image-urls`
- ce batch est locké pour éviter deux lancements simultanés
- Thomann applique une pause aléatoire de 2 à 5 secondes avant chaque fetch URL
- le price scraper Thomann applique aussi une pause de 2 à 5 secondes avant chaque requête
- l’image n’est gardée que si l’URL finale de la page correspond bien à l’URL candidate
- le worker Python image séparé a été tenté puis abandonné
- la comparaison image OpenAI se fait par lots de 10 paires
- les images sont redimensionnées/compressées avant envoi
- Symfony flush la persistence par lot de 10
- la route image-review est protégée par un lock fichier

### Recherche et validation

- `/veille-concurrentielle/validation` liste les `pending`
- `/veille-concurrentielle/recherche` affiche:
  - URLs finales
  - URLs rejetées
  - URLs postponed
  - photo source PrestaShop
  - une référence prix Algam sans URL ni titre concurrent
- la recherche permet aussi d’ajouter une URL manuellement par produit et par concurrent
- l’ajout manuel tente immédiatement de scraper le prix de la fiche et alimente:
  - `competitor_url_test_result`
  - `competitor_url_final`
  - `competitor_url_price_history`
- les URLs rejetées peuvent être revalidées depuis la recherche
- les URLs postponed peuvent être validées depuis la recherche

### Cockpit prix

- `/veille-concurrentielle/prix` expose un cockpit prix avec:
  - KPI globaux
  - histogramme des écarts
  - filtre concurrent
  - drawer latéral de détail produit
- `/veille-concurrentielle/prix/ecarts-fiables` isole les produits où Thomann et/ou Michenaud sont trop loin de la base de confiance Woodbrass + Stars Music
- les deux pages ouvrent le détail produit dans un panneau latéral et réutilisent la page recherche en mode embarqué
- Algam est affiché comme référence prix séparée depuis `tm2dn_site_v3.leo_algamwebstoreprice`

### Récap home

- le tableau home compte maintenant aussi:
  - `postponed`
  - `rejected`
- `Total` reflète donc mieux le vrai stock de `competitor_url_test_result`

### Logs

L’admin orchestrateur expose:

- le dernier log par tâche
- une liste de logs récents
- un aperçu des dernières lignes
- un log complet lisible dans le navigateur

Route de lecture:

```text
/veille-concurrentielle/orchestrateur/log/<nom-du-fichier>.log
```

Noms de logs actuels:

- URL: `url-<timestamp>-c<id>-new_url.log`
- retry URL: `url-<timestamp>-c<id>-retry_url.log`
- prix: `prices-<timestamp>-c<id>.log`

### URLs de lancement utiles

Orchestrateur:

```text
/api/competitive/orchestrate?token=TON_TOKEN
```

Batch URL legacy:

```text
/api/competitive/run-batch?competitor_id=1&limit=10&after_id=0&lang_id=1&shop_id=1&token=TON_TOKEN
```

Nouvelles routes explicites:

```text
/api/competitive/run-new-urls?competitor_id=1&limit=10&lang_id=1&shop_id=1&token=TON_TOKEN
/api/competitive/run-retry-urls?competitor_id=1&limit=10&lang_id=1&shop_id=1&token=TON_TOKEN
```

Lancer global:

```text
/api/competitive/run-all?limit=5&price_limit=10&lang_id=1&shop_id=1&max_parallel=2&token=TON_TOKEN
```

`run-all` reste utile pour du rattrapage, mais le flux normal est l’orchestrateur.

### Workers Python

Cœur commun:

```text
competitive_intelligence_python/competitive_intelligence/workers/url_job.py
competitive_intelligence_python/competitive_intelligence/workers/price_job.py
```

Entrées explicites par concurrent:

```text
competitive_intelligence_python/jobs/<competitor>/new_urls.py
competitive_intelligence_python/jobs/<competitor>/retry_urls.py
competitive_intelligence_python/jobs/<competitor>/prices.py
```

Compatibilité legacy conservée:

```text
competitive_intelligence_python/run_batch.py
competitive_intelligence_python/run_new_urls.py
competitive_intelligence_python/run_retry_urls.py
competitive_intelligence_python/run_final_prices.py
```

## Fichiers utiles

- [docs/context.md](docs/context.md)
- [dashboard/src/Controller/DashboardController.php](dashboard/src/Controller/DashboardController.php)
- [dashboard/src/Repository/KpiRepository.php](dashboard/src/Repository/KpiRepository.php)
- [dashboard/src/Service/InvoiceLineImportService.php](dashboard/src/Service/InvoiceLineImportService.php)
- [dashboard/src/Controller/CompetitiveIntelligenceController.php](dashboard/src/Controller/CompetitiveIntelligenceController.php)
- [dashboard/src/Controller/Api/CompetitiveIntelligenceApiController.php](dashboard/src/Controller/Api/CompetitiveIntelligenceApiController.php)

## Tech

- Symfony 7.4
- Doctrine DBAL / Migrations
- Twig
- Python workers séparés

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

1. exécuter la migration qui ajoute le suivi HTTP des `competitor_url_final`
2. continuer l’affinage des règles d’orchestrateur
3. ajouter si besoin un résumé final homogène par tâche dans les logs
4. compléter les pages de détail et exports

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

### Lancer l’orchestrateur

```bash
curl -fsS \
'https://dashboard.hurricanemusic.fr/api/competitive/orchestrate?token=TON_TOKEN'
```

### Lancer un batch concurrentiel legacy

```bash
curl -H 'X-COMPETITIVE-TOKEN: TON_TOKEN' \
'https://dashboard.hurricanemusic.fr/api/competitive/run-batch?competitor_id=1&limit=10&after_id=0&lang_id=1&shop_id=1'
```

### Vider le cache

```bash
cd dashboard
php bin/console cache:clear
```
