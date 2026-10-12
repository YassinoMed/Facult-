# Matrice des preuves et des risques (12/10/2026)

Légende : `VERIFIED` (exécutée), `PARTIALLY_VERIFIED` (incomplète),
`NOT_RUN`, `NOT_VERIFIED`, `BLOCKED_HUMAN_DECISION`.

## Preuves

| Preuve | Statut | Référence |
|---|---|---|
| Suite complète 3 054 tests (3 037 OK, 17 skips, 0 échec) | `PARTIALLY_VERIFIED` | Chiffre mandat §1 ; runs CI non observés → ne prouve pas la CI |
| `composer audit` 0 | `VERIFIED` | Exécuté 12/10 (cf. hardening) |
| `npm audit` 0 | `VERIFIED` | Après `concurrently@10.0.6` + `source-map-js@1.2.2`, build OK 12/10 |
| Isolation faculté écritures (19 tests rouges→verts) | `VERIFIED` | `FaculteScopeEcritureTest`, `docs/AUDIT-SECURITE.md` §11 |
| Concurrence suppressions archives (2 connexions) | `VERIFIED` | `ArchivesConcurrenceMysqlTest` 2/2, MySQL 9.2 scratch |
| Benchmark 10k/50k/100k | `VERIFIED` | Artefact `storage/app/benchmark/archives-volumetrie.json` |
| Concurrence générique (tous modules) | `NOT_RUN` | Tests MySQL esquivés hors gate env |
| Sécurité effective de la production | `NOT_VERIFIED` | `docs/security-hardening/production-checklist.md` non cochée |
| Maquette DEMO-LGL / ARB-001…009 | `BLOCKED_HUMAN_DECISION` | `docs/pedagogie/registre-arbitrages-lmd.md` |
| Backlog B/C/D/E | `NOT_VERIFIED` | Définitions d'origine introuvables (C7) |

## Risques (priorisés : sécurité > intégrité > blocages > défauts)

| ID | Risque | Priorité | Paré par |
|---|---|---|---|
| R-SEC-01 | `FinancePolicy::manage` sans périmètre si matrice évolue | P2 | Garde à l'activation |
| R-SEC-02 | Secrets/TLS/proxies prod inconnus | P1 | Checklist ops + drill |
| R-REF-01 | 2 087 inscriptions sans programme + 38 standalone | P1 | ARB en suspens, jamais de rattachement auto |
| R-REF-02 | Activation maquette sans sources S2-S6 | P0 si tentée | Interdit sans ARB-001/003/008/009 |
| R-INF-01 | CI non prouvée / `main` non protégée | P1 | Confirmation ops requise |
| R-ARC-01 | `filesort` registre >100k | P3 | Réévaluer au seuil |
