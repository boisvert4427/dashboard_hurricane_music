# Context de reprise

Ce document sert de mémo pour reprendre le projet vite dans une prochaine discussion.

## Objectif produit

Le dashboard doit servir à piloter l’activité de Hurricane Music avec:

1. **Accueil**
   - global du mois en cours
   - comparaison N-1
   - occasion global + par canal
   - top 5 marques
   - répartition par canal
   - part de chaque sous-ensemble en % du global

2. **Détail**
   - recherche ligne par ligne
   - filtres métier
   - pages de détail à venir

## Architecture

Le projet est une application Symfony unique avec des sous-sections distinctes.

Symfony sert à la fois pour:

- le dashboard principal
- la section dédiée à la veille concurrentielle
- l’orchestration métier et la base de données reporting

La veille concurrentielle est intégrée au même site, mais reste isolée par:

- ses routes
- ses tables
- ses services
- ses workers Python séparés

Flux:

```text
K_LI_FAC + K_ARTICLE + WEB_FABRICANT
        ↓
ETL Symfony
        ↓
reporting_invoice_line_fact
        ↓
Dashboard Symfony
```

Le dashboard ne doit pas interroger les tables métier directement dans les vues.

## Bases de données

Deux connexions Doctrine sont utilisées:

- `DATABASE_URL` = base reporting
- `PRESTASHOP_DATABASE_URL` = base source PrestaShop

Règle:

- le dashboard lit uniquement la base reporting
- l’ETL lit la base PrestaShop puis écrit dans la base reporting

## Sources métiers

### `K_LI_FAC`

Table source principale des lignes de facture.

### `K_ARTICLE`

Table source d’enrichissement produit.

### `WEB_FABRICANT`

Référentiel des marques.

## Table de reporting

La table centrale est:

- `reporting_invoice_line_fact`

## ETL

### Commande CLI

```bash
cd dashboard
php bin/console app:etl:import-invoice-lines
```

### Route web protégée

```text
/etl/import?token=TON_TOKEN
```

Le token est défini dans `dashboard/.env.local` via `ETL_WEB_TOKEN`.

## Veille concurrentielle

La veille concurrentielle repose sur:

- une API interne Symfony
- des jobs Python explicites par concurrent et par tâche
- un orchestrateur Symfony appelé toutes les minutes

### Routes API

```text
GET  /api/competitive/orchestrate
GET  /api/competitive/run-batch
GET  /api/competitive/run-new-urls
GET  /api/competitive/run-retry-urls
GET  /api/competitive/run-all
GET  /api/competitive/run-both
GET  /api/competitive/products/next-batch
GET  /api/competitive/final-prices/next-batch
POST /api/competitive/final-prices
GET  /veille-concurrentielle/validation
GET  /veille-concurrentielle/recherche
GET  /veille-concurrentielle/orchestrateur
GET  /veille-concurrentielle/orchestrateur/log/{filename}
```

### Déclenchement

Point d’entrée normal:

```text
/api/competitive/orchestrate?token=TON_TOKEN
```

Cette URL:

- est faite pour être appelée toutes les minutes
- ne lance pas tout
- choisit au plus une tâche selon:
  - la config admin
  - les locks actifs
  - les intervalles par tâche
  - le backlog disponible

Les anciennes routes restent utiles pour du manuel ou du debug.

### Tâches actuelles

Par concurrent:

- `new_urls`
- `retry_urls`
- `prices`

Concurrents actifs:

- `1` = Woodbrass
- `2` = Stars Music
- `3` = Thomann
- `4` = Michenaud

### Flux

1. Symfony lit les produits ou finals concernés.
2. Symfony choisit une tâche.
3. Python lance le scraper du concurrent.
4. Python renvoie des candidats scorés ou des observations de prix.
5. Symfony stocke les résultats de test et les URLs finales en base.
6. La validation humaine se fait directement sur `competitor_url_test_result`.
7. `competitor_url_candidate` est legacy et n’est plus dans le flux métier actif.
8. `competitor_url_price_history` stocke l’historique append-only des prix finals.
9. La validation se fait par lots de 50 lignes, avec un défaut à `rejected` et un bouton de masse pour tout passer en `valid`.
10. Le script image est revenu en scrape direct, avec un lock fichier et une pause aléatoire.
11. Thomann applique une pause aléatoire entre 2 et 5 secondes avant chaque fetch URL.
12. Le price scraper Thomann applique aussi une pause aléatoire entre 2 et 5 secondes avant chaque requête.
13. L’image n’est gardée que si l’URL finale de la page correspond bien à l’URL candidate.
14. La comparaison image de validation se fait par lots OpenAI de 10 paires, avec compression des images avant envoi et flush à chaque lot.
15. La page de recherche affiche les URLs finales, rejetées et postponed, avec photos produit.
16. La recherche permet aussi d’ajouter une URL à la main par produit et par concurrent.
17. L’ajout manuel tente aussi de scraper immédiatement le prix de la fiche.
18. Les URLs rejetées peuvent être revalidées depuis la recherche.
19. Les URLs postponed peuvent être validées depuis la recherche.
20. L’admin orchestrateur permet de régler chaque tâche sans code et de la lancer manuellement une fois.
21. Les logs des tâches sont consultables depuis l’admin et lisibles dans le navigateur.
22. Le cockpit prix existe maintenant sur `/veille-concurrentielle/prix`.
23. La page `/veille-concurrentielle/prix/ecarts-fiables` isole les écarts Thomann/Michenaud trop importants par rapport à la base Woodbrass + Stars Music.
24. `retry_urls` reprend les plus anciens `not_found` selon `last_tested_at`.
25. `competitor_url_final` garde maintenant l’état HTTP du price scraper.
26. Après 3 `404/410` consécutifs sur une final URL:
    - l’entrée `competitor_url_final` est supprimée
    - le `competitor_url_test_result` lié passe en `competitor_page_status = gone`
27. Le récap home compte aussi `postponed` et `rejected`.

### Worker Python

Le squelette Python est dans `competitive_intelligence_python/`.

Structure actuelle:

- cœur commun:
  - `competitive_intelligence/workers/url_job.py`
  - `competitive_intelligence/workers/price_job.py`
- points d’entrée explicites par concurrent:
  - `jobs/<competitor>/new_urls.py`
  - `jobs/<competitor>/retry_urls.py`
  - `jobs/<competitor>/prices.py`
- compatibilité legacy conservée:
  - `run_batch.py`
  - `run_new_urls.py`
  - `run_retry_urls.py`
  - `run_final_prices.py`

Le worker image séparé a été essayé puis retiré; le flux image actif est revenu au PHP direct.
