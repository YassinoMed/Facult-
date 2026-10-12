# Baseline de maturité (preuves au 01/10/2026 — suite 935 passés/3 skips, Pint vert)

| Axe | Niveau | Preuve |
|---|---|---|
| Architecture | 4 industrialisée | 12 modules, CORE découplé, conventions bloquantes |
| Sécurité | 4 | RBAC 143 perms, scope 30+ usages, IDOR testé, hardening 29/09 |
| Données | 4 | ~60 migrations, uniques/index/EXPLAIN, 0 duplication prouvée |
| Tests | 4 | ~938 tests, suites Security + domaines, benchmarks MySQL |
| Performance | 3 structurée | chunk/eager, seuils 1k/5k/10k, baseline 3000 mesurée |
| Observabilité | 4 | X-Request-ID, supervision + cooldown, scheduler 12 jobs, audit immuable |
| Documentation | 4 | ~40 docs, OpenAPI + test de dérive |
| API | 4 | scopes, throttle, enveloppe, resources |
| DevOps | 3 | CI (tests+pint), sauvegardes vérifiées + DR, PWA, maintenance |
| UX | 4 | 21 composants figés, responsive, a11y |
| Gouvernance | 4 | AGENTS.md, ADDING-A-MODULE, audit trails |

Dette assumée : 0 soft delete hors sensible ; backups non chiffrés (infra) ;
pièces justificatives orphelines ; PDF dossier inclut titres médicaux
(gated, à exclure Phase G) — voir `docs/audit/technical-debt.md`.
