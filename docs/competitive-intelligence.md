# Veille Concurrentielle

## Objectif

Suivre les concurrents sur trois axes:

- découverte d’URLs
- validation des correspondances
- suivi des prix

## Architecture

La veille concurrentielle vit dans la même application Symfony, mais reste isolée par:

- ses routes
- ses tables
- ses services
- ses workers Python

## Flux

```text
Symfony
  ↓
Python workers
  ↓
competitor_url_test_result
competitor_url_final
competitor_url_price_history
```

## Mode opératoire

- l’orchestrateur Symfony choisit une tâche à la fois
- les tâches sont `new_urls`, `retry_urls` et `prices`
- les workers Python exécutent le scraping ou la mise à jour de prix
- la validation humaine se fait dans l’interface Symfony
- le dashboard reporting ne lit aucune donnée de ce sous-domaine

## Règles importantes

- `competitor_url_candidate` est legacy
- `competitor_url_test_result` est la source de vérité pour la validation
- `competitor_url_price_history` est append-only
- les pages de prix final ne sont pas relancées en boucle infinie
- les `404/410` répétés sont traités comme une dégradation de santé des URLs

## Pages et routes

- `/api/competitive/orchestrate`
- `/api/competitive/run-new-urls`
- `/api/competitive/run-retry-urls`
- `/api/competitive/final-prices`
- `/veille-concurrentielle/validation`
- `/veille-concurrentielle/recherche`
- `/veille-concurrentielle/prix`
- `/veille-concurrentielle/prix/ecarts-fiables`

## Concurrents

- Woodbrass
- Stars Music
- Thomann
- Michenaud

## Fichiers utiles

- `dashboard/src/Controller/CompetitiveIntelligenceController.php`
- `dashboard/src/Controller/Api/CompetitiveIntelligenceApiController.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveOrchestratorService.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveBatchRunner.php`
- `dashboard/src/Service/CompetitiveIntelligence/FinalPriceBatchRunner.php`
- `competitive_intelligence_python/competitive_intelligence/workers/url_job.py`
- `competitive_intelligence_python/competitive_intelligence/workers/price_job.py`
