# LOOP EX-LIFECYCLE — 09 · Matrice de tests

## Couverture cible (cahier §9) → réalisée

| ID | Scénario | Statut | Adaptation à la politique réelle |
|---|---|---|---|
| EX-LC-001 | Création valide, état initial `draft` | ✅ test | — |
| EX-LC-002 | Données obligatoires absentes | ✅ test | — |
| EX-LC-003 | Programme invalide | ✅ couvert existant | validation `exists` + 404 périmètre (suite création manuelle) |
| EX-LC-004 | Faculté non autorisée | ✅ couvert existant | `abort_unless(peutAccederFaculte)` |
| EX-LC-005 | État privilégié imposé par le client | ✅ test | statut ignoré, `published_at` null |
| EX-LC-006/007 | Consultation périmètre / hors périmètre | ✅ test (007) + existant (006) | 007 → 403 (Policy) et non 404 : adaptation conforme au code |
| EX-LC-008 | Transition normale autorisée | ✅ test | + historisation vérifiée |
| EX-LC-009 | Transition inconnue | ✅ test | refus = erreur session (200 redirect), adaptation conforme |
| EX-LC-010 | État initial incompatible (saut d'état) | ✅ test | aucun événement écrit |
| EX-LC-011 | Transition répétée | ✅ test | un seul événement |
| EX-LC-012 | Transition directe interdite (published) | ✅ test | — |
| EX-LC-013 | Depuis état terminal | ✅ test | archived → garde « verrouillé » |
| EX-LC-014/015 | Préconditions manquantes / config incohérente | ✅ couvert existant | audit contraintes dures + sessions (suites gouvernance) |
| EX-LC-016 | Sans autorisation | ✅ test | 403 |
| EX-LC-017 | Permission mais hors périmètre | ✅ test | 403, état inchangé |
| EX-LC-018 | Appel direct du service | ✅ test | mêmes règles |
| EX-LC-019 | Modification autorisée | ✅ couvert existant | (pas de route update générique d'examen — la modification passe par les flux dédiés) |
| EX-LC-023 | Modification générique de l'état | ✅ par construction | aucune route PATCH d'examen ; `statut` non mass-assignable via flux contrôleur |
| EX-LC-025/028 | Annulation autorisée / double | ✅ test | EX-LC-047 (rejeu) + EX-LC-051 (cancelled→archived) |
| EX-LC-026 | Annulation sans motif | ⚠️ adapté | motif facultatif — D-02 ouvert, exigence non inventée |
| EX-LC-029/030/031 | Réouverture | ✅ statut documenté | **non prise en charge explicitement** (D-01) — la matrice refuse ; pas de test « passant » artificiel |
| EX-LC-032/033/034 | Session requise/incohérente | ✅ couvert existant | StoreExamenRequest + suites |
| EX-LC-035/036/037 | Épreuves incohérentes / annulation / clôturé modifiable | ✅ couvert existant | audit conformité détecte — pas de correction silencieuse |
| EX-LC-038 | Génération répétée épreuves | ✅ couvert existant | suites sessions/épreuves |
| EX-LC-039/040/041 | Gouvernance manquante | ✅ couvert existant | 198 assertions gouvernance |
| EX-LC-042 | Requête directe de publication | ✅ test + existant | — |
| EX-LC-043 | Modification après approbation | ✅ couvert existant | empreinte refusée |
| EX-LC-044 | Publication répétée | ✅ couvert existant | idempotence orchestrateur |
| EX-LC-045/046/047 | Concurrence / rejeu | ✅ test (transactionnel) | 2 processus réels = 2 tests `pcntl` ignorés localement (documenté) |
| EX-LC-048/049 | Conflit / échec transaction | ✅ par construction | verrou + écriture unique en transaction ; échec → rollback intégral |
| EX-LC-050 | Échec notification après commit | ✅ convention conservée | listeners existants inchangés |
| EX-LC-051/052/053 | Historique créé / motif / traçabilité | ✅ test | — |
| EX-LC-054 | Consultation historique contrôlée | ✅ test (endpoint sous Gate view) | — |
| EX-LC-055 | Modification de l'historique | ✅ test | `DomainException` |
| EX-LC-056 | Ordre cohérent | ✅ test | 4 événements ordonnés |
| EX-LC-057 | Incohérence état/historique | ✅ détection | écriture unique en transaction (impossible d'avoir état ≠ dernier événement) |

## Suites exécutées

| Suite | Fichier |
|---|---|
| Cycle de vie (nouvelle) | `tests/Feature/ExamenCycleVieTest.php` |
| Création manuelle | `tests/Feature/ExamenCreationManuelleTest.php` |
| Concurrence réelle | `tests/Feature/ExamensConcurrenceReelleTest.php` (2 skipped pcntl) |
| Gouvernance double validation | `ExamGouvernanceDoubleValidationTest` |
| Gouvernance HTTP authorization | `ExamGouvernanceHttpAuthorizationTest` |
| Gouvernance sécurité concurrence | `ExamGouvernanceSecuriteConcurrenceTest` |
| Invariants validation | `ExamensInvariantsValidationTest` |
| Feature complète | `ExamensFeatureCompleteTest` |
| Gouvernance sécurité continuité | `ExamGlobalGouvernanceSecuriteContinuiteTest` |
| Module élargi | `EvaluationTest`, `EvaluationPhase5Test`, `EvaluationAuditTest`, `NotesEvaluationsSecuriteTest`, `NotesEvaluationsAmeliorationsTest`, `EvaluationWorkflowServiceTest`, `ExamSessionConfigurationEnterpriseTest` |

Résultats chiffrés : voir `10-resultats-tests.md`.
