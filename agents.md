# Agent Guide

## Lire en premier

1. [docs/dashboard.md](docs/dashboard.md)
2. [docs/competitive-intelligence.md](docs/competitive-intelligence.md)
3. [docs/context.md](docs/context.md)

## Règles

- Symfony est l’application principale.
- Python sert aux workers de scraping et de prix.
- Ne pas mélanger dashboard et veille concurrentielle.
- Le dashboard lit la base de reporting, jamais la base source métier.
- Ne jamais écrire dans `tm3dn_site_v3`.

## Dashboard

- Source métier: `K_LI_FAC`, enrichie par `K_ARTICLE`, `WEB_FABRICANT`, `WEB_RAYON`, `WEB_FAMILLE`, `WEB_SSFAMILLE`.
- Table de sortie: `reporting_invoice_line_fact`.
- Import par défaut: incrémental.
- Rattrapage: `php bin/console app:etl:import-invoice-lines --since=YYYY-MM-DD`.
- La home met en avant le global, les canaux, le neuf, l’occasion, les marques et les catégories.
- Le détail sert à filtrer, trier et exporter les lignes.
- Les montants affichés sont en HT.

## Veille concurrentielle

- Source de vérité validation: `competitor_url_test_result`.
- URLs finales: `competitor_url_final`.
- Historique prix: `competitor_url_price_history`.
- `competitor_url_candidate` est legacy.
- Tâches principales: `new_urls`, `retry_urls`, `prices`.

## Avant d’agir

- Si un KPI semble faux, vérifier d’abord le reporting alimenté.
- Si une modification touche les données, vérifier le périmètre dashboard vs veille concurrentielle.
- Si un flux n’est pas clair, ouvrir le doc dédié avant de changer le code.
