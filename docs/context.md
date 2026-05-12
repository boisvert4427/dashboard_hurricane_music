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
- son worker Python séparé

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

Champs importants:

- `IDLigneFac`
- `IDFAC`
- `DateFacture`
- `DH_Facture`
- `IDART`
- `DESIGNATION_PRODUIT`
- `CODE`
- `TotalHT`
- `TotalTTC`
- `MARGE`
- `WEB`
- `SITE`
- `MODE_VENTE`
- `IDCLI`
- `IDPRESTATAIRE`

### `K_ARTICLE`

Table source d’enrichissement produit.

Champs importants:

- `IDART`
- `DESIGNATION`
- `CODE`
- `IDRAY`
- `IDFAM`
- `IDSSFAM`
- `ID_FAB`
- `supplier`
- `REF_FOU`

### `WEB_FABRICANT`

Référentiel des marques.

Champs importants:

- `IDFAB`
- `NOM_FAB`

Jointure:

- `K_ARTICLE.ID_FAB = WEB_FABRICANT.IDFAB`

## Table de reporting

La table centrale est:

- `reporting_invoice_line_fact`

Elle contient une ligne de facture enrichie.

Champs fonctionnels principaux:

- `invoice_number`
- `invoice_date`
- `product_id`
- `product_code`
- `product_name`
- `brand_id`
- `brand_name`
- `ray_id`
- `family_id`
- `subfamily_id`
- `supplier_name`
- `channel_name`
- `quantity`
- `total_ht`
- `margin_ht`

## Sous-ensembles affichés

Les cartes de la home affichent maintenant:

- la valeur courante
- la valeur N-1
- la variation vs N-1
- la part du global

Cette logique s’applique à:

- l’occasion
- les canaux
- les marques
- les marques dans chaque canal

## ETL

L’ETL est dans Symfony.

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

La veille concurrentielle repose sur une API interne Symfony et un worker Python séparé.

### Routes API

```text
GET  /api/competitive/run-batch
GET  /api/competitive/run-all
GET  /api/competitive/run-both
GET  /api/competitive/products/next-batch
GET  /api/competitive/final-prices/next-batch
POST /api/competitive/final-prices
GET  /veille-concurrentielle/validation
GET  /veille-concurrentielle/recherche
```

### Déclenchement

Le navigateur ou un cron doit appeler:

```text
/api/competitive/run-batch?competitor_id=1&limit=10&after_id=0&lang_id=1&shop_id=1&token=TON_TOKEN
```

Cette route lance `competitive_intelligence_python/run_batch.py` en arrière-plan.
Le batch runner bloque une deuxième exécution simultanée pour le même `competitor_id` / `lang_id` / `shop_id`.

Orchestrateur parallèle:

```text
/api/competitive/run-all?limit=5&price_limit=10&lang_id=1&shop_id=1&max_parallel=2&token=TON_TOKEN
```

Il lance Woodbrass, Stars Music, Thomann et Michenaud.
`limit` pilote le batch de matching URL.
`price_limit` pilote le batch de scraping prix sur les URLs finales.

### Authentification

Le token est défini dans `dashboard/.env.local` via `COMPETITIVE_INTELLIGENCE_API_TOKEN`.

### Flux

1. Symfony lit les produits dans `leo_netrivals_send_feed` dans la base PrestaShop.
2. Symfony fournit un lot à Python.
3. Python lance le scraper du concurrent.
4. Python renvoie des candidats scorés.
5. Symfony stocke les résultats de test et les URLs finales en base.
6. La validation humaine se fait directement sur `competitor_url_test_result`.
7. `competitor_url_candidate` est legacy et n’est plus dans le flux métier actif.
8. `competitor_url_price_history` stocke l'historique append-only des prix finals.
9. La validation se fait désormais par lots de 50 lignes, avec un défaut à `rejected` et un bouton de masse pour tout passer en `valid` avant envoi.
10. Le script image est revenu en scrape direct, avec un lock fichier et une pause aléatoire pour éviter les lancements simultanés.
11. Thomann est traité plus lentement qu’avant, avec une pause aléatoire entre 2 et 5 secondes avant chaque fetch.
12. L’image n’est gardée que si l’URL finale de la page correspond bien à l’URL candidate.
13. La comparaison image de validation se fait par lots OpenAI de 10 paires, avec compression des images avant envoi et flush de persistence à chaque lot.
14. La page de recherche affiche les URLs finales, rejetées et postponed, avec photos produit dans la vue.
15. Les URLs rejetées peuvent être revalidées depuis la recherche.

Concurrents actifs:

- `1` = Woodbrass
- `2` = Stars Music
- `3` = Thomann
- `4` = Michenaud

### Worker Python

Le squelette Python est dans `competitive_intelligence_python/`.
Le worker image séparé a été essayé puis retiré; le flux image actif est revenu au PHP direct.

### Statuts de test

La table `competitor_url_test_result` enregistre:

- `matched`
- `not_found`
- `cloudflare`
- `search_input_not_found`
- `error`
- `pending`
- `valid`
- `rejected`
- `postponed`
- `ignored`

Les lots suivants ignorent les produits déjà testés pour ce concurrent, afin de ne pas recycler le même `id_product`.
Les produits rejetés ne sont plus renvoyés par le batch provider.
La page de validation est paginée par blocs de 50 lignes.

### Règles de matching

- Thomann et Michenaud peuvent utiliser OpenAI pour classer les meilleurs candidats.
- L'API reçoit un seul appel par batch, avec jusqu'à 3 candidats utiles par produit.
- Les candidats Thomann / Michenaud sont filtrés par marque avant appel API.
- Thomann rejette d'office les titres qui contiennent `b-stock`, `b stock`, `bstock` ou `bundle`.
- `score < 30` devient `not_found`.
- Les résultats Thomann / Michenaud restent `pending` par défaut et peuvent passer à `matched` seulement avec une confiance suffisante.

### Passe prix

- La passe prix ne traite que les `competitor_url_final`.
- Le prix courant est stocké sur `competitor_url_final`.
- L'historique de prix est append-only dans `competitor_url_price_history`.
- La sélection priorise les finals absents de l'historique, puis les plus vieux derniers scrapes de prix.

### Champs de test

Les résultats de test stockent:

- `competitor_title`
- `competitor_brand`
- `competitor_breadcrumb`
- `competitor_price`
- `score`
- `result`
- `matched_query`
- `validation_status`

La colonne `title` a été supprimée de `competitor_url_test_result`.
`score < 30` est traité comme `not_found`.
Les flux heuristiques peuvent encore passer en `matched` au-dessus de 90.
Pour Thomann et Michenaud, l'auto-match API se déclenche seulement très haut, autour de 95.
Quand un test passe en `valid`, Symfony écrit aussi dans `competitor_url_final`.

### Tables métier

- `competitor`
- `competitor_url_final`
- `competitor_url_test_result`
- `competitor_url_rejected_url`
- `leo_netrivals_send_feed`

### Rôle

1. lire les produits PrestaShop par lot
2. lancer le scraper adapté au concurrent
3. scorer les URLs candidates
4. pousser les candidats et les tests dans Symfony
5. laisser la validation humaine décider du statut final
6. limiter la concurrence globale via `max_parallel` si nécessaire
7. éviter de relancer plusieurs `run-all` en parallèle

### Volume

On part d’environ 228k lignes, donc:

- import complet acceptable
- batch interne utilisé

## Règles métier

### Canal de vente

Règle actuelle:

- si `WEB = 1` -> `Web`
- sinon si `IDRAY = 2` -> `École`
- sinon si `SITE = 0` -> `Nantes`
- sinon si `SITE = 1` -> `Bordeaux`
- sinon -> `Autre`

Le canal `École` sert à isoler les lignes liées aux produits pédagogiques.

### Occasion

Les lignes occasion sont détectées par:

- `product_code` commence par `B-`
- ou `product_code` contient `occas`
- ou `product_code` commence par `DEPV`
- ou `product_name` contient `occas`

Conséquence:

- occasion incluse dans le global
- occasion incluse dans les canaux
- occasion exclue du top marques

### Exclusion métier

Une ligne est exclue de tous les calculs si:

- `IDART = 18823`
- ou `REF_FOU` commence par `REPRISE`
- ou `Q_FAC = 0`

L’objectif est de retirer les reprises / lignes parasites du reporting.

### Numéro de facture

Le numéro de facture de référence est `IDFAC`.

`NumFacPoste` peut servir de fallback technique, mais le reporting s’appuie désormais sur `IDFAC` pour compter les factures.

## Règles projet à garder

- ne pas mélanger le code Python dans Symfony
- ne pas mélanger le scraping prix dans le matching URL
- ne pas lancer les 8 500 produits d’un coup
- privilégier des lots progressifs
- garder les statuts de test explicites

### Marques

Le top global des marques exclut `HM` par défaut.

Cette exclusion ne s’applique pas aux vues de détail ni aux highlights par canal.

## Dashboard actuel

La home affiche:

- KPI globaux du mois
- top 5 marques
- répartition par canal
- récap occasion N vs N-1
- bloc d’alertes métier
- filtres de lecture
- comparaisons N-1 et mois précédent

La veille concurrentielle affiche:

- un récap global par concurrent
- un compteur de `pending` aligné avec la validation
- une page de validation paginée
- une page de recherche par id, nom, marque, ref, ean
- une page de concurrents

Le détail affiche:

- recherche ligne par ligne
- filtres
- tri
- pagination
- export CSV

Affichage:

- montants sans décimales visibles
- delta vert si hausse
- delta rouge si baisse
- delta gris si neutre
- part du global affichée sur les sous-ensembles

## Bonnes habitudes

- ne pas modifier les chiffres dans les vues
- faire les exclusions métier dans l’ETL et dans les agrégats SQL
- garder `README.md` court
- utiliser ce fichier comme mémoire métier détaillée

## Fichiers clés

- [dashboard/src/Controller/DashboardController.php](../dashboard/src/Controller/DashboardController.php)
- [dashboard/src/Repository/KpiRepository.php](../dashboard/src/Repository/KpiRepository.php)
- [dashboard/src/Service/InvoiceLineImportService.php](../dashboard/src/Service/InvoiceLineImportService.php)
- [dashboard/src/Command/ImportInvoiceLinesCommand.php](../dashboard/src/Command/ImportInvoiceLinesCommand.php)
- [dashboard/templates/dashboard/home.html.twig](../dashboard/templates/dashboard/home.html.twig)
- [dashboard/assets/styles/app.css](../dashboard/assets/styles/app.css)
- [dashboard/migrations/Version20260424130000.php](../dashboard/migrations/Version20260424130000.php)

## Ce qu’il faut retenir pour la prochaine reprise

Le point de départ utile est:

1. `README.md`
2. `docs/context.md`
3. `dashboard/src/Repository/KpiRepository.php`
4. `dashboard/src/Service/InvoiceLineImportService.php`
5. `dashboard/templates/dashboard/home.html.twig`

Si tu dois reprendre vite le projet, lis d’abord ce document.
