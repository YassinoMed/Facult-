# LOOP INDUSTRIALISATION ÉVALUATION — 08 · Matrice de tests

## Suites exécutées (identifiants stables, aucun doublon créé)

| Suite | Fichier | Couverture |
|---|---|---|
| Historique append-only (nouvelle) | `tests/Feature/NotesHistoriqueAppendOnlyTest.php` | SEC-012, NOTE-021, trace avant/après, revalidation |
| Sécurité notes | `NotesEvaluationsSecuriteTest` | NOTE-006/007/014, SEC-005/007 |
| Améliorations notes | `NotesEvaluationsAmeliorationsTest` | NOTE-001..009, correction, verrouillage |
| Import multi | `NotesImportMultiTest` (+ Association) | NOTE-010..013, INT-011, performance |
| Compostage anonyme | `CompostageAnonymeTest`, `EvaluationCompostageTest` | NOTE-005 (anonymisation), export |
| Délibérations | `DeliberationPhase6Test`, `DeliberationPhase14Test`, `DeliberationImportAssociationTest` | INT-005/006, SEC-004 |
| Vérification notes/délibérations | `Loop05NotesDeliberationsVerificationTest` | NOTE-015/016, INT-001 |
| Audit évaluation | `EvaluationAuditTest` | traçabilité, comparaison versions |
| Cycle de vie examens | `ExamenCycleVieTest` + 13 suites EX-LIFECYCLE | INT-005 amont, transitions |
| Évaluation générale | `EvaluationTest`, `EvaluationPhase5Test` | non-régression module |

## Traçabilité exigence → test → preuve

| Exigence (cahier) | Test | Preuve | Verdict |
|---|---|---|---|
| §0.2-1 note officielle protégée | suites notes + NotePolicy | 403/DomainException verrou | ✅ |
| §0.2-2 pas d'écrasement silencieux | `NotesHistoriqueAppendOnlyTest` | avant/après journalisés | ✅ |
| §0.2-3 publication via gouvernance | `DeliberationPhase6/14` | verrous + matrice | ✅ |
| §0.2-4 périmètre notes | `NotesEvaluationsSecuriteTest` | 403 + état inchangé | ✅ |
| §0.2-5 export contrôlé | contrôleur export | Gate + stream anonyme | ✅ |
| §0.2-6 simulation sans écriture | `DeliberationService::simuler` | run tracé, zéro écriture officielle | ✅ |
| §0.2-7 contrôle serveur | tous | Gates × routes + gardes services | ✅ |
| §0.2-8 historique non modifiable | `NotesHistoriqueAppendOnlyTest` | `DomainException` update/delete | ✅ (corrigé) |
| NOTE-017 auto-vérification | — | D-11 (décision métier) | ⚠️ documenté |
| NOTE-020 correction post-publication | — | D-12 (BLOCKED_BUSINESS) | ⚠️ documenté |
| KPI-002/009 isolation cockpit | suites isolation | Policy périmètre | ✅ |
