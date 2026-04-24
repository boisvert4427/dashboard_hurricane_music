# 🚀 Dashboard Business - Hurricane Music (PHP Version)

## 🧠 Vision

Ce projet consiste à créer un **dashboard de pilotage d’entreprise** centralisé, permettant de suivre :

* chiffre d’affaires
* marge
* performance commerciale
* marketing (analytics)
* stock et achats

👉 L’objectif est de construire un **outil de décision interne**, rapide, fiable et adapté au métier.

---

## 🏗️ Architecture globale

```text
MariaDB PrestaShop (source)
        ↓
ETL (PHP ou Python) - 2x / jour
        ↓
Tables de reporting (MariaDB)
        ↓
Dashboard PHP
        ↓
Frontend JS (charts)
```

---

## ⚙️ Stack technique

### Backend

* PHP 8
* MariaDB (base existante PrestaShop)

### Configuration sécurisée

Les secrets ne doivent pas être stockés dans le webroot.

* Variables d’environnement: `DB_DSN`, `DB_USER`, `DB_PASSWORD`, `TARGET_MONTHLY_REVENUE`, `TARGET_MONTHLY_ORDERS`
* Fichier privé hors racine publique: `../dashboard-private/config.php`
* Exemple: `../dashboard-private/config.php.example`

### Comment la config est chargée

Le bootstrap lit la configuration dans cet ordre :

1. valeurs par défaut codées dans `dashboard/config/bootstrap.php`
2. variables d’environnement, si elles existent
3. fichier privé `../dashboard-private/config.php`, qui écrase les valeurs précédentes

Concrètement :

* pour modifier la base de données, édite `../dashboard-private/config.php`
* pour un déploiement automatisé, tu peux aussi injecter les variables d’environnement
* le fichier privé reste en dehors du webroot, donc il n’est pas servi par le navigateur

Exemple minimal de `../dashboard-private/config.php` :

```php
<?php

return [
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=dashboard;charset=utf8mb4',
        'user' => 'dashboard_user',
        'password' => 'change-me',
    ],
    'targets' => [
        'monthly_revenue' => 0,
        'monthly_orders' => 0,
    ],
];
```

### Data

* Tables de reporting dédiées

### ETL

* Script PHP ou Python
* Cron (2 fois par jour)

### Frontend

* HTML / CSS
* Bootstrap (optionnel)
* Chart.js ou ApexCharts
* JS léger (Alpine.js optionnel)

---

## 📊 Fonctionnalités principales

### 🏠 Dashboard (Accueil)

* CA total
* Marge
* Nombre de commandes
* Panier moyen
* Trafic
* Taux de conversion

Avec :

* comparaison N-1
* évolution dans le temps

---

### 💰 Ventes

* CA par :

  * magasin
  * canal
  * marque
  * catégorie
* Top produits
* évolution temporelle

---

### 📈 Marketing / Analytics

* Trafic total
* Sources de trafic
* Conversion par source
* Pages d’entrée

---

### 📦 Stock / Achats

* État du stock
* Valorisation
* Produits en rupture
* Rotation
* Achats fournisseurs

---

### 📊 Performance avancée

* CA vs trafic
* Marge par canal
* Rentabilité produit
* Analyses croisées

---

## 🔍 Filtres globaux

* Date
* Magasin
* Canal
* Marque
* Catégorie
* Fournisseur

---

## 🧮 Modèle de données (clé du projet)

### ⚠️ Principe fondamental

❌ Ne jamais requêter directement les tables PrestaShop pour le dashboard
✅ Toujours utiliser des **tables de reporting pré-calculées**

---

## 📁 Tables de reporting

```sql
reporting_kpi_daily
reporting_invoice_line_fact
reporting_margin_daily
reporting_stock_snapshot
reporting_product_daily
reporting_category_daily
reporting_brand_daily
reporting_supplier_daily
reporting_analytics_daily
```

### Point de départ recommandé

Au début du projet, il n'est pas nécessaire de créer toutes les tables.

Le plus simple est de commencer avec :

* `reporting_invoice_line_fact`
* `reporting_kpi_daily` si tu veux un cache journalier plus tard

Ces deux tables permettent déjà de construire la page d'accueil et de valider le flux complet :

* extraction des données PrestaShop
* calcul des agrégats
* écriture dans la base de reporting
* lecture par le dashboard PHP

Ensuite, on ajoute les autres tables au fur et à mesure selon les besoins métier.

---

## 🔄 ETL (Data Pipeline)

### Fréquence

* 2 fois par jour (cron)

### Rôle

* extraire données PrestaShop
* intégrer analytics (GA4 ou Matomo)
* calculer KPI
* remplir tables de reporting

### Approche conseillée

Ne pas dupliquer toute la base PrestaShop.

Le bon modèle est :

* lire uniquement les tables source utiles
* transformer les données
* stocker le résultat dans une base de reporting séparée
* interroger uniquement cette base depuis le dashboard

---

## 📂 Structure du projet

```text
/dashboard
  /public
    index.php
  /src
    Database.php
    Repository/
  /templates
  /config
  /scripts
    etl.php
```

---

## 🧭 Navigation

```text
/accueil
/ventes
/marketing
/stock
/performance
```

---

## 🔍 Drill-down

Chaque KPI doit être cliquable :

```text
CA global
→ CA par canal
→ Produits
→ Détail produit
```

---

## 📊 Graphiques

Utilisation de Chart.js ou ApexCharts :

* courbes CA vs N-1
* histogrammes
* comparatifs
* multi-séries

---

## 🎯 Roadmap

### V1 (MVP)

* table reporting_sales_daily
* page accueil
* KPI principaux
* 1 graphique CA vs N-1

---

### V2

* filtres globaux
* pages ventes / marketing
* drill-down

---

### V3

* stock / achats
* alertes
* objectifs
* optimisation UX

---

## ⚠️ Bonnes pratiques

* ❌ pas de requêtes lourdes en live
* ✅ pré-calcul via ETL
* ✅ index SQL optimisés
* ✅ requêtes simples côté dashboard

---

## 🏁 Lancement du projet

### Étape 1

Créer site PHP sur Infomaniak (`dashboard.hurricanemusic.fr`)

### Étape 2

Créer la base de reporting et les deux premières tables :

* `reporting_invoice_line_fact`

### Étape 3

Créer ETL (PHP ou Python)

### Étape 4

Créer page index.php (KPI)

### Étape 5

Ajouter graphiques

---

## 💡 Objectif final

Créer un **outil interne puissant**, rapide et adapté au métier, capable de remplacer :

* Excel
* exports manuels
* analyses dispersées

---

## 🧠 Principe clé

👉 Le succès du projet repose sur :
**la qualité des données, pas sur la techno front**

---
