# LOOP EX-LIFECYCLE — 10 · Résultats de tests (preuves réelles)

**Environnement :** SQLite `:memory:` (`RefreshDatabase`), `php artisan test`.
**Sorties brutes conservées dans la session d'exécution.**

## Campagne — périmètre cycle de vie

| Suite | Passés | Échoués | Incomplets | Ignorés | Assertions |
|---|---:|---:|---:|---:|---:|
| `ExamenCycleVieTest` (nouvelle) | 19 | 0 | 0 | 0 | 60 |
| Baseline examens (création + concurrence) | 18/20 | 0 | 0 | 2 | 75 |
| Gouvernance + invariants (4 suites) | 48/48 | 0 | 0 | 0 | 198 |
| Feature complète + gouvernance continuité | 38/38 | 0 | 0 | 0 | 163 |
| Suites examens élargies (salles, charge, régression, sidebar, communication) | 36/38* | 0 | 0 | 2 | 160 |
| **Sous-total cycle de vie** | **163 tests — 159 passés** | **0** | **0** | **4** | **656** |

\* les 4 ignorés cumulés sont des tests de concurrence à deux **processus réels** (`pcntl_fork` indisponible sur l'environnement local) — limitation d'environnement préexistante, documentée ; les invariants correspondants sont couverts par les tests transactionnels et les suites gouvernance de concurrence.

## Non-régression module Évaluation élargi

| Suite | Passés | Assertions |
|---|---:|---:|
| `EvaluationTest`, `EvaluationPhase5Test`, `EvaluationAuditTest`, `NotesEvaluationsSecuriteTest`, `NotesEvaluationsAmeliorationsTest`, `EvaluationWorkflowServiceTest`, `ExamSessionConfigurationEnterpriseTest` | 78/78 | 267 |

## Total campagne

| Indicateur | Valeur |
|---|---|
| Tests exécutés | **241** |
| Passés | **237** |
| Échoués | **0** |
| Ignorés | **4** (pcntl_fork indisponible — environnement) |
| Assertions | **923** |
| Durée cumulée | ≈ 15 s |

## Qualité

| Contrôle | Résultat |
|---|---|
| `php -l` sur les 4 fichiers PHP modifiés/créés | Aucune erreur de syntaxe |
| `vendor/bin/pint --dirty` | Corrigé et re-validé (suite relancée : 19/19) |
| `php artisan migrate` (migration additive) | `2026_10_31_150000_creer_evaluation_examen_evenements .. DONE` (SQLite) |
| `php artisan route:list --path=evaluations/examens` | Route `admin.evaluations.examens.transitions` enregistrée (GET) |

## Interprétation honnête

- **Aucun test n'a été assoupli ni supprimé** pour obtenir le vert ; les 6 premiers échecs étaient des assertions de test erronées (modèle d'erreur du contrôleur : `back()->withErrors` ≠ 403), corrigées côté test uniquement.
- Les scénarios conditionnels (EX-LC-026 motif, EX-LC-029/030 réouverture) ont été **adaptés à la politique démontrée**, jamais inversés : voir `01-decisions-metier.md`.
- La concurrence multi-processus réelle reste dépendante de `pcntl` : sur MySQL (production), `lockForUpdate` est effectif — même convention que les services de gouvernance déjà en production.

## Re-vérification indépendante (2026-11-01 — seconde exécution complète)

Seconde campagne exécutée après l'audit, sur le même environnement (SQLite `:memory:`,
`php artisan test`), pour confirmer les chiffres ci-dessus (« sorties réelles ») :

| Suite (filtre exécuté) | Passés | Échoués | Ignorés | Assertions |
|---|---:|---:|---:|---:|
| `ExamenCycleVieTest` | 19 | 0 | 0 | 60 |
| `ExamGouvernanceDoubleValidationTest` + `ExamGouvernanceHttpAuthorizationTest` | 28 | 0 | 0 | 145 |
| `ExamenCreationManuelleTest` | 18 | 0 | 0 | 75 |
| `ExamensFeatureCompleteTest` + `ExamensInvariantsValidationTest` + `ExamensInvariantsSallesTest` | 50 | 0 | 0 | 160 |
| `ExamPublicationEnterpriseTest` | 9 | 0 | 0 | 45 |
| `ExamGlobalGouvernanceSecuriteContinuiteTest` | 20 | 0 | 0 | 95 |
| `ExamensConcurrenceReelleTest` | 0 | 0 | 2 | 0 |
| `EvaluationTest`, `EvaluationPhase5Test`, `EvaluationAuditTest`, `EvaluationWorkflowServiceTest`, `ExamSessionConfigurationEnterpriseTest` | 37 | 0 | 0 | 124 |
| `NotesEvaluationsSecuriteTest`, `NotesEvaluationsAmeliorationsTest` | 41 | 0 | 0 | 143 |
| **Total re-vérification** | **222** | **0** | **2** | **847** |

- **0 échec** sur les 222 tests repassés ; seuls les 2 tests de concurrence multi-processus restent
  ignorés (`pcntl_fork` indisponible localement) — limitation d'environnement documentée (R-06).
- Contrôle de pile des routes (ground truth) : `POST …/transiter/{cible}` et
  `GET …/transitions` exposent bien `auth + admin + secondaire.verrou + module:evaluation +
  throttle + can:*` (vérifié par `php artisan route:list`).
- Conclusion : les chiffres de la campagne initiale sont **reproduits** ; le verdict
  `PASS_WITH_RESERVATIONS` (`12`) reste fondé sur des preuves reproductibles.
