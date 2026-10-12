# LOOP C — Décisions ouvertes (12/10/2026)

| ID | Décision | Options | Recommandation |
|---|---|---|---|
| D-C-01 | Corriger R-C-01 (`afterCommit`) | (a) `DB::afterCommit` + test course MySQL ; (b) statu quo | (a) en LOOP D — P0 potentiel, fix minimal prouvé par test |
| D-C-02 | Preuve course MySQL paramètres | (a) test deux-connexions scratch (pattern `ArchivesConcurrenceMysqlTest`) ; (b) accepter l'optimiste séquentiel | (a) si D-C-01 retenue |
| D-C-03 | Registre vs `sauvegarde.*` | statu quo / extension / migration UI | Statu quo + architecture ouverte (C4) |
| D-C-04 | `sentinel.*` seedés ou non | seed vs défaut codé | Trancher avec le propriétaire Sentinel (dette) |
| D-C-05 | Première clé B (facultaire) | aucune candidate prouvée | Refusé jusqu'à preuve métier |

Critères d'acceptation LOOP D : R-C-01 reproduit (test rouge) puis vert
après `afterCommit` ; R-C-02 prouvé ou requalifié ; D-C-03 tranchée ;
zéro régression (21+ suites Settings, voisins, Pint).
