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
dashboard et détail

Veille concurrentielle:
Symfony orchestrator
        ↓
Python workers
        ↓
validation et prix
```

## Règles d’isolement

- le dashboard n’écrit que dans sa base reporting
- la veille concurrentielle travaille sur ses propres tables
- les deux flux partagent l’application Symfony, mais pas la logique métier
- les docs sont séparées pour réduire le temps de reprise
