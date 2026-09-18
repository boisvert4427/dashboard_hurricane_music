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

## Relances des relevés de prix

Le suivi s'applique à Woodbrass, Stars Music, Thomann et Michenaud. Chaque tentative
met à jour `competitor_url_final` : `last_price_attempt_at`, `last_price_result`,
`consecutive_price_not_found`, `next_price_check_at` et `price_check_requested_at`.
L'historique conserve uniquement les prix effectivement relevés ; un échec ne
remplace pas le dernier prix connu.

- `price_not_found` : nouvelle tentative après 24 heures, puis 7 jours, puis 30 jours
  pour le troisième échec et les suivants.
- `temporary_error` (réseau, HTTP 403/429/5xx, challenge anti-bot détecté) : après 3 heures.
  Ces erreurs ne font pas progresser le compteur de prix introuvables.
- `price_found` : compteur remis à zéro et retour à la rotation habituelle.
- `http_gone` (404/410) : après 24 heures ; la règle existante de suppression après
  trois réponses 404/410 consécutives reste active.

Le fournisseur de lots et `hasPendingWork` excluent les URLs dont la prochaine
vérification est dans le futur. Les demandes manuelles sont prioritaires, puis
la rotation utilise la dernière tentative (ou le dernier relevé pour les URLs
encore sans tentative enregistrée). Le curseur `after_id` reste compatible.

La page Prix affiche la date du dernier relevé, le dernier échec et la prochaine
vérification. Dans la fiche produit, « Revérifier maintenant » déclenche un relevé
ciblé ; si le robot du concurrent est occupé, la demande reste prioritaire dans
la file. Cette action est en POST avec protection CSRF et conserve le compteur
d'échecs jusqu'au prochain succès. Un changement d'URL réinitialise les délais ;
les réponses reçues pour une ancienne URL sont ignorées.

Migration : `Version20260917193000` (base de veille uniquement).
Tests : `php bin/phpunit tests/CompetitiveIntelligence/PriceRetryTest.php` et,
depuis `competitive_intelligence_python`, `python3 -m unittest discover -s tests`.

### Redirections Woodbrass

Après un relevé réussi sur une redirection HTTP vers une fiche Woodbrass,
`resolved_url` permet de mettre à jour `competitor_url_final.url` et l'URL de
validation correspondante. L'URL demandée doit encore correspondre à l'URL finale
courante, la destination doit être une fiche Woodbrass et ne pas appartenir à un
autre produit final. Les anciens relevés conservent leur URL d'origine ; le nouveau
relevé est enregistré avec la nouvelle URL. Les redirections vers un accueil,
une catégorie ou un domaine externe ne remplacent pas les URLs finales.

### Recherche d'URLs Woodbrass par EAN

La recherche utilise désormais le moteur de la boutique actuelle
(`/search/suggest.json`) avec l'EAN source. Chaque résultat est vérifié dans les
données de la fiche (`/products/<handle>.js`) : le code-barres de la variante doit
correspondre exactement, avec équivalence UPC/EAN à zéro initial. Le prix et la
variante proviennent de ces mêmes données. Sans EAN ou si l'EAN diffère, aucune
correspondance automatique n'est créée. L'ancien index Algolia n'est plus utilisé.
