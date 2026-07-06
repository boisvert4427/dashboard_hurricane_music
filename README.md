# Hurricane Music Dashboard

Monorepo Symfony avec deux domaines séparés:

- [Dashboard reporting](docs/dashboard.md)
- [Veille concurrentielle](docs/competitive-intelligence.md)

## Lecture rapide

- le dashboard lit uniquement `reporting_invoice_line_fact`
- l’ETL alimente le reporting depuis `K_LI_FAC`
- la veille concurrentielle garde ses propres routes, tables et workers
- les docs de référence sont séparées par projet pour aller plus vite

## Point de départ

- [Contexte technique](docs/context.md)
- [Guide agent](agents.md)
