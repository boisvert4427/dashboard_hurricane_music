# Contexte Technique

Le dépôt contient deux sous-domaines indépendants:

1. le dashboard de reporting
2. la veille concurrentielle

## Entrées principales

- [Dashboard reporting](dashboard.md)
- [Veille concurrentielle](competitive-intelligence.md)

## Architecture

```text
Dashboard reporting:
K_LI_FAC + K_ARTICLE + WEB_FABRICANT + WEB_RAYON + WEB_FAMILLE + WEB_SSFAMILLE
        ↓
ETL Symfony
        ↓
reporting_invoice_line_fact
        ↓
dashboard cartes + détail ligne par ligne

Veille concurrentielle:
Symfony orchestrator
        ↓
Python workers
        ↓
validation et prix
```

## Règles d’isolement

- le dashboard n’écrit que dans sa base reporting
- le dashboard n’écrit jamais dans `tm3dn_site_v3`
- la veille concurrentielle travaille sur ses propres tables
- les deux flux partagent l’application Symfony, mais pas la logique métier
- les docs sont séparées pour réduire le temps de reprise

## Dashboard en pratique

- page d’accueil avec filtres de période, canal, marque, catégorie et occasion
- période par défaut: début du mois courant jusqu’à aujourd’hui
- page d’accueil en vue cartes
- page détail en vue tableau ligne par ligne
- graphiques de répartition rendus en `Chart.js`
- import ETL accessible par commande Symfony ou route web sécurisée par token
