# Veille Concurrentielle

## Fonctionnement complet : de l'URL au scraping du prix

```text
leo_netrivals_send_feed (produits actuellement suivis)
                         │
                         ▼
             tâche new_urls / retry_urls
                         │
                         ▼
       worker Python : recherche chez le concurrent
                         │
                         ▼
 competitor_url_test_result (résultat et validation)
                         │
              validation automatique ou humaine
                         │
                         ▼
       competitor_url_final (URL retenue par produit)
                         │
         filtre obligatoire : produit encore dans le feed
                         │
                         ▼
               tâche prices / lot planifié
                         │
                         ▼
       worker Python : ouverture de l'URL et extraction
                         │
             ┌───────────┴───────────┐
             ▼                       ▼
       prix trouvé                 échec
             │           (HTTP, anti-bot, prix absent)
             ▼                       │
 mise à jour du prix                 ▼
 + ajout dans l'historique    délai avant nouvelle tentative
 competitor_url_price_history  sans effacer le dernier prix
```

Le feed détermine en permanence les produits éligibles. Une URL peut rester
archivée dans les tables de veille après la sortie d'un produit du feed, mais
elle n'est plus envoyée au worker de prix et ne maintient pas artificiellement
une tâche active.

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

### Correctif du crawl d'URLs Michenaud — 1er octobre 2026

Le scraper convertit les tuples issus de `parse_results` en objets
`MichenaudResult`. La boucle d'enrichissement doit parcourir `results` (ces objets)
et non `parsed_results` (les tuples). Sinon, l'accès à `result.url` provoque
`'tuple' object has no attribute 'url'` et les recherches échouent.

Le correctif porte uniquement sur cette boucle. Le test de régression
`tests/test_michenaud_search.py` simule une recherche et une fiche produit,
puis vérifie l'URL, la marque, le prix, l'image et le fil d'Ariane du candidat.
Depuis `competitive_intelligence_python`, lancer
`python3 -m unittest discover -s tests` pour vérifier l'ensemble des tests.

### Code

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
vérification est dans le futur ainsi que les produits absents de
`leo_netrivals_send_feed`. Les demandes manuelles sont prioritaires, puis la
rotation utilise la dernière tentative (ou le dernier relevé pour les URLs encore
sans tentative enregistrée). Le curseur `after_id` reste compatible. Le filtre
du feed est non destructif : les anciennes associations restent consultables,
mais ne sont plus scrapées.

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
courante et la destination doit être une fiche Woodbrass. Les anciens relevés
conservent leur URL d'origine ; le nouveau relevé est enregistré avec la nouvelle
URL. Les redirections vers un accueil, une catégorie ou un domaine externe ne
remplacent pas les URLs finales.

Si la destination appartient déjà à un autre produit final, les deux IDs sont
comparés au feed :

- le produit en cours doit être présent dans `leo_netrivals_send_feed` ;
- si le détenteur de la destination est également dans le feed, le conflit est
  refusé et doit être contrôlé manuellement ;
- si le détenteur de la destination est absent du feed, son association finale
  obsolète est supprimée et l'URL est réattribuée au produit actif ;
- l'historique des prix existant reste intact.

### Basculement d'IP pour les prix Woodbrass

Après trois réponses HTTP 429 consécutives reçues directement pendant les relevés
de prix Woodbrass, le worker active un tunnel SOCKS SSH vers le VPS secondaire.
Le basculement ne concerne que les requêtes de scraping des prix Woodbrass : les
appels à l'API Symfony et les autres concurrents restent en accès direct. Si le
tunnel n'est plus actif, le worker le recrée automatiquement. Après sept jours,
le prochain relevé teste de nouveau l'accès direct. Une réponse HTTP réussie
désactive le proxy ; un nouvel échec conserve le proxy et reporte le test direct
de sept jours.

La protection contre les limitations reste pilotée par l'orchestrateur. Un HTTP
429 signalé par le worker place `woodbrass.prices` en pause pendant 24 heures et
arrête immédiatement le lot courant. L'état, le motif et l'heure de reprise sont
affichés sur la page Orchestrateur ; les lancements automatiques et manuels sont
refusés pendant cette pause. Si la tâche est décochée manuellement, l'interface
indique explicitement qu'aucun lancement automatique n'aura lieu. Le réglage
prudent par défaut est un produit toutes les quatre heures.

La configuration repose sur `CI_WOODBRASS_PROXY_SSH_HOST`,
`CI_WOODBRASS_PROXY_SSH_USER`, `CI_WOODBRASS_PROXY_SSH_KEY`,
`CI_WOODBRASS_PROXY_LOCAL_PORT`, `CI_WOODBRASS_PROXY_AFTER_429` et
`CI_WOODBRASS_PROXY_RETRY_AFTER_SECONDS`. L'état du circuit est conservé dans
`competitive_intelligence_python/var/state/`.

### Stratégie prudente des relevés Woodbrass

La tâche `woodbrass.prices` est configurée dans l'orchestrateur avec un lot d'un
seul produit et un intervalle de quatre heures. Elle reste désactivée tant qu'un
opérateur ne la réactive pas depuis la page Orchestrateur. Une désactivation
manuelle n'est jamais annulée automatiquement.

Une fois réactivée, le worker ne télécharge plus la page HTML complète. Pour les
URLs Shopify canoniques `/products/<handle>`, il appelle exclusivement la
ressource légère `/products/<handle>.js`, sélectionne la variante indiquée dans
le paramètre `variant` lorsqu'il existe, puis convertit le prix Shopify exprimé
en centimes. Les anciennes URLs Woodbrass ne sont pas placées dans les lots de
prix afin d'éviter un accès HTML ou une redirection supplémentaire.

L'URL technique JSON terminée par `.js` ne doit jamais remplacer l'URL produit
canonique dans `competitor_url_final` ou `competitor_url_test_result`. Elle est
également exclue des lots de prix afin d'éviter une requête invalide `.js.js`.

L'orchestrateur conserve la décision opérationnelle et son état visible : tâche
active ou désactivée, taille du lot, intervalle, route réseau utilisée, dernier
statut HTTP, motif de pause et heure de reprise. Au premier HTTP 429, le worker
arrête immédiatement le lot et le signale à l'API ; l'orchestrateur impose alors
une pause de 24 heures aux lancements automatiques comme aux lancements manuels.

### Recherche d'URLs Woodbrass par EAN

La recherche utilise désormais le moteur de la boutique actuelle
(`/search/suggest.json`) avec l'EAN source. Chaque résultat est vérifié dans les
données de la fiche (`/products/<handle>.js`) : le code-barres de la variante doit
correspondre exactement, avec équivalence UPC/EAN à zéro initial. Le prix et la
variante proviennent de ces mêmes données. Sans EAN ou si l'EAN diffère, aucune
correspondance automatique n'est créée. L'ancien index Algolia n'est plus utilisé.

Les deux tâches orchestrées `woodbrass.new_urls` et `woodbrass.retry_urls` restent
indépendantes (cadence, taille de lot, priorité, état et journal propres), mais
leurs appels Woodbrass utilisent Chromium direct comme `woodbrass.prices`. Le
navigateur est ouvert une fois par exécution et réutilisé pour
`/search/suggest.json` puis `/products/<handle>.js`. Les appels à l'API Symfony
restent en HTTP direct. Aucun verrou supplémentaire n'est ajouté : la sélection
et le parallélisme restent sous le contrôle de l'orchestrateur. Un HTTP 429 arrête
immédiatement le lot Woodbrass en cours.
