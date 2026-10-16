# Dashboard Reporting

## Objectif

Piloter l’activité Hurricane Music avec un dashboard de reporting centré sur:

- l’objectif de période
- le cumul depuis le début de période
- le CA par canal
- le bloc `Neuf / Occasion` par canal
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
K_LI_FAC + K_ARTICLE + WEB_FABRICANT + WEB_RAYON + WEB_FAMILLE + WEB_SSFAMILLE
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
php bin/console app:etl:import-invoice-lines --since=2026-07-01
```

## Coûts de stock FIFO et PAMP

Le calcul des coûts est indépendant du `PMAP` présent dans `K_HISTO_STOCK`.
Il lit les mouvements métier et écrit uniquement dans la base de reporting.

Commande incrémentale:

```bash
cd dashboard
php bin/console app:etl:calculate-stock-costs --pair-limit=100 --discovery-limit=5000
```

Les limites découpent le travail en lots de 100 couples produit/site et de 5 000
mouvements source. Une exécution enchaîne désormais tous les lots jusqu'à ce
qu'il ne reste plus aucun mouvement ni couple traitable en attente. Un couple en
erreur est tenté trois fois puis conservé dans la file avec son erreur.

Le cron autonome ci-dessous est actuellement désactivé; le calcul est déclenché
par `/etl/import` après l'import des ventes:

```cron
2-59/5 * * * * cd dashboard && php bin/console app:etl:calculate-stock-costs --pair-limit=100 --discovery-limit=5000
```

La sortie est conservée dans `dashboard/var/log/stock-cost-etl.log`.

### Sources

- `K_HISTO_STOCK`: chronologie, quantité, stock après mouvement, site et pièce source
- `K_LI_RECEPT`: prix d'achat et remises des réceptions
- `IDPIECE = IDFAC` pour une vente
- `IDPIECE = IDRECEPTION` pour une réception

### Tables de reporting

- `reporting_stock_movement_cost`: résultat FIFO et PAMP pour chaque `IDHISTO_STOCK`
- `reporting_stock_current_cost`: dernier état calculé par article et site
- `reporting_stock_recalc_queue`: références/sites à rejouer
- `reporting_etl_checkpoint`: points de reprise séparés par site

Chaque référence/site modifiée est rejouée chronologiquement depuis son premier
mouvement. Les coûts d'ouverture absents et les incohérences de stock sont conservés
dans `calculation_status`; ils ne sont pas masqués par le PMAP source.

Pour une entrée de stock sans ligne de réception correspondante, le coût utilisé est
`K_HISTO_STOCK.DER_PA`. Le PAMP courant ne sert de dernier recours que si `DER_PA`
est également nul.

La commande prend le verrou exclusif `var/lock/heavy-processing.lock`. L'orchestrateur
de veille ne démarre pas de nouveau worker pendant ce calcul, et la commande s'abstient
si un worker de veille est encore actif.

### Recalcul manuel d'un article

Le bloc « Maintenance des coûts de stock » en bas de l'accueil permet de saisir un
`IDART` après la correction ou la suppression d'une réception. L'action POST protégée
par CSRF place tous les couples `IDART + SITE` trouvés dans `K_HISTO_STOCK` dans
`reporting_stock_recalc_queue`. Elle ne calcule rien dans la requête web : le prochain
cron rejoue l'historique complet de chaque site, en relisant l'état courant de
`K_LI_RECEPT`, puis remplace le cache FIFO/PAMP de l'article.

Route web sécurisée:

```text
/etl/import?token=VOTRE_TOKEN&since=2026-07-01
```

Après l'import des ventes, cette route lance également un lot incrémental de calcul
FIFO/PAMP (100 couples article/site, découverte de 5 000 mouvements). Le même verrou
que la commande protège le traitement : si une autre tâche lourde ou un worker de
veille est actif, le calcul des coûts est reporté et la réponse JSON l'indique dans
`stock_costs`, sans faire échouer l'import des ventes.

Les marges du dashboard sont ensuite reliées au PAMP calculé avant la sortie de
stock. La correspondance utilise `IDFAC + IDART + SITE`. Lorsque plusieurs lignes
de facture correspondent à plusieurs sorties du même article, le coût PAMP total
est regroupé puis réparti au prorata des quantités afin de ne jamais le compter
plusieurs fois. La marge métier d'origine reste conservée dans `source_margin_ht`;
elle reste affichée tant qu'aucun coût PAMP calculé ne correspond à la ligne.

Un PAMP nul n'est pas considéré comme un coût exploitable. Dans ce cas, la marge
source est conservée, sauf pour les références `DEPV%` (dépôt-vente) auxquelles
une marge par défaut de 25 % du CA HT est appliquée.

Resynchronisation manuelle des marges déjà importées:

```bash
php bin/console app:etl:sync-invoice-pamp-margins
```

### Comportement

- import incrémental par défaut
- reprise depuis le dernier `IDLigneFac` déjà présent
- rattrapage possible par date avec `--since`
- mise à jour des doublons via `source_line_id`
- route web d’import protégée par `ETL_WEB_TOKEN`
- aucune écriture dans `tm3dn_site_v3`
- chaque synchronisation copie les métadonnées courantes utiles de `K_ARTICLE` dans `reporting_product_dimension`
- les corrections de produit (marque, fournisseur et catégories) sont répercutées sur les anciennes lignes de vente sans rejouer les factures
- les montants affichés dans l’interface sont en HT
- les KPI de répartition et les cartes utilisent `Chart.js` pour les camemberts
- la home reste en vue cartes
- la page détail affiche le tableau ligne par ligne

### Règles métier d’import

- ignorer les lignes à quantité nulle
- ignorer le produit `18823`
- ignorer les références fournisseur commençant par `REPRISE`

## Accueil

La page d’accueil du dashboard met en avant:

- le global
- le CA par canal
- le bloc `Neuf / Occasion` réorganisé par canal
- le neuf
- l’occasion
- les marques
- les catégories
- les filtres de période, canal, marque, catégorie et occasion

### Sections visibles

- `Global`
- `Répartition par canal`
- `Neuf / Occasion`
- `Neuf`
- `Occasion`
- `Top 8 marques`
- `Catégories`

### Comportement interface

- la période par défaut va du premier jour du mois courant à aujourd’hui
- les dates sont modifiables à la main ou via le calendrier
- les cartes sont cliquables
- le détail reprend les filtres actifs
- les filtres du tableau sont séparés entre le périmètre analysé et l'organisation de l'affichage; le périmètre comprend notamment le canal, la marque, la catégorie et le fournisseur
- le tableau accepte un regroupement principal et un deuxième niveau optionnel
- quand un deuxième niveau est choisi, un clic sur une ligne principale déplie son détail; les données ne sont chargées qu'au premier clic
- les colonnes du deuxième niveau sont triables côté navigateur, sans nouvelle requête serveur
- quantité, CA HT, marge et stock sont présentés chacun dans trois colonnes distinctes: période courante, N-1 et variation
- le regroupement IDART liste l'union des produits vendus sur la période et des produits dont le stock actuel est non nul
- les montants sont affichés en HT
- les graphiques de répartition sont construits avec `Chart.js`

Les cartes de la home sont cliquables et le détail reprend la même période sélectionnée.

## Configuration

Les secrets ne doivent pas être stockés dans le webroot.

- variables d’environnement
- ou fichier privé hors racine publique

## Fichiers utiles

- `dashboard/src/Controller/DashboardController.php`
- `dashboard/src/Controller/EtlController.php`
- `dashboard/src/Repository/KpiRepository.php`
- `dashboard/src/Service/InvoiceLineImportService.php`
- `dashboard/templates/dashboard/home.html.twig`
- `dashboard/templates/dashboard/detail.html.twig`
