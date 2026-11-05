# LOOP INDUSTRIALISATION ÉVALUATION — 06 · Intégration globale (PHASE AF)

## Chaînes testées (preuves = suites exécutées, 257 tests / 1018 assertions)

| ID | Chaîne | Résultat | Preuve |
|---|---|---|---|
| INT-001 | Saisie → validation | ✅ notes cohérentes, historique avant/après | `NotesEvaluationsAmeliorationsTest` + `NotesHistoriqueAppendOnlyTest` |
| INT-002 | Validation → verrouillage | ✅ modification après verrou refusée | `NotesEvaluationsSecuriteTest` |
| INT-003 | Correction → re-saisie → re-validation | ✅ ancienne valeur conservée | workflow 3 étapes + historique |
| INT-004 | Note → calcul → acquis | ✅ resync officielle + moteurs règle | `SaisieNoteService` (synchronisation `ArchiveService`) + suites acquis |
| INT-005 | Résultat → gouvernance | ✅ décision versionnée (`publie=false` → verrou → publication) | `DeliberationPhase6Test` |
| INT-006 | Gouvernance → publication | ✅ `publie=true` + event, double publication idempotente | `DeliberationPhase14Test` |
| INT-007 | Publication → consultation | ✅ résultats officiels seulement | décisions `publie` + policies |
| INT-008 | Révocation → accès | ✅ réévaluation à chaque requête | `RevocationSessions*Test` |
| INT-009 | Correction → historique | ✅ nouvelle version traçable | historique append-only |
| INT-010 | Simulation → cockpit | ✅ `simuler` sans écriture officielle (run tracé) | `DeliberationService::simuler` |
| INT-011 | Échec opération → cohérence | ✅ rollback intégral lot/import | `NotesImportMultiTest` (perf + intégrité) |
| INT-012 | Isolation multi-facultés | ✅ 403 + état inchangé | `NotesEvaluationsSecuriteTest`, EX-LIFECYCLE 017/007 |
| INT-013 | Export → audit | ✅ exports streamés gate + événements | contrôleur + `IntegrationLog` |
| INT-014 | IA → service métier | ✅ aucune action autonome | module AssistantIa lecture seule |
| INT-015 | Opérations simultanées | ✅ verrous ligne (`lockForUpdate` épreuve/notes/note officielle) | suites concurrence (+ 4 tests pcntl ignorés localement, R-06) |

## Frontières transactionnelles (PHASE S/Q6)

- **Saisie** : une transaction par lot (épreuve + notes + historiques) — rollback intégral.
- **Validation** : transaction par note (ou lot pour `validerTout`, sans savepoint par note — convention Lot 7 documentée).
- **Correction** : 3 transactions distinctes (demande / autorisation / re-saisie) — aucune transaction géante inter-domaines.
- **Délibération** : transaction par étape (préparation, validation, publication) avec verrous pessimistes.
- Un échec est diagnostiquable sans perte des événements précédents (journal append-only).

## Détection des données obsolètes (Q7)

Chaque opération re-vérifie ses dépendances à l'application : épreuve re-verrouillée, note officielle re-verrouillée, état rechargé sous verrou — les données modifiées entre la consultation et l'action provoquent un refus explicite (jamais une écriture fondée sur un état ancien).
