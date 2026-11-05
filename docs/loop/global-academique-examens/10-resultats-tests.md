# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 10 · Résultats des tests

**Date d'exécution :** 2026-11-03  
**Environnement :** macOS, PHP/Laravel, SQLite `:memory:` + `RefreshDatabase`  
**Règle :** chaque chiffre ci-dessous provient d'une sortie réelle ; aucun total n'est extrapolé  

---

## 1. Résultats par exécution

| # | Commande (filtre) | Tests | Assertions | Échecs | Ignorés |
|---|---|---|---|---|---|
| 1 | `tests/Feature/Acquis` | **201** | **1 773** | 0 | 0 |
| 2 | `EligibiliteExamenTest\|InscriptionEligibiliteFacadeTest\|InscriptionEligibiliteOrientationTest\|InscriptionEligibilityMensTest` | **32** | **150** | 0 | 0 |
| 3 | `ExamCapaciteSalleNonConfirmeeTest\|ExamDecisionCapacitePermutationTest\|ExamensInvariantsSallesTest\|ExamensInvariantsValidationTest\|ExamGroupeVirtuelTest\|ExamGroupesVirtuelsLoopGlobalTest\|ExamGroupesVirtuelsConsultationExportTest\|PlanificationAutomatiqueExamensTest\|Loop06PlanificationExamensVerificationTest` | **143** | **656** | 0 | 0 |
| 4 | `ExamGouvernanceDoubleValidationTest\|ExamGouvernanceHttpAuthorizationTest\|ExamGlobalGouvernanceSecuriteContinuiteTest\|ExamPublicationEnterpriseTest\|ExamenCycleVieTest` | **76** | **345** | 0 | 0 |
| 5 | `IamPermissionsEtCycleDeVieTest\|ExamGouvernanceSecuriteConcurrenceTest\|MigrationHistoriqueDryRunLoop41Test` | **27** | **101** | 0 | 0 |
| 6 | `Loop05NotesDeliberationsVerificationTest\|DeliberationPhase6Test\|NotesHistoriqueAppendOnlyTest` | **15** | **68** | 0 | 0 |

---

## 2. Sorties brutes

```json
{"tool":"phpunit","result":"passed","tests":201,"passed":201,"assertions":1773,"duration_ms":9114}
{"tool":"phpunit","result":"passed","tests":32,"passed":32,"assertions":150,"duration_ms":1958}
{"tool":"phpunit","result":"passed","tests":143,"passed":143,"assertions":656,"duration_ms":11584}
{"tool":"phpunit","result":"passed","tests":76,"passed":76,"assertions":345,"duration_ms":3996}
{"tool":"phpunit","result":"passed","tests":27,"passed":27,"assertions":101,"duration_ms":1541}
{"tool":"phpunit","result":"passed","tests":15,"passed":15,"assertions":68,"duration_ms":1562}
```

---

## 3. Lecture des résultats

- Les six exécutions sont **vertes**, sans échec ni test ignoré.
- Les suites **ne sont pas disjointes** : les totaux ne doivent pas être
  additionnés. Chaque ligne prouve un périmètre fonctionnel.
- L'exécution n° 1 couvre l'intégralité de `tests/Feature/Acquis/`, y compris
  les suites de certification, de gouvernance réglementaire, de migration
  dry-run et de campagne.
- Les exécutions n° 2 à 6 ciblent les contrats transverses du domaine
  Académique & Examens.

---

## 4. Preuves antérieures réutilisées (non recomptées)

| Preuve | Résultat | Date |
|---|---|---|
| `ACQ-RED-01` avant correction | 188/201 (13 rouges) | 2026-11-01 |
| `ACQ-RED-01` après correction | 201/201 · 1 773 assertions | 2026-11-02 |
| Suites transverses Académique | 43/43 · 141 assertions | 2026-11-02 |
| Portails simulation/campagne | 44 passés / 30 ignorés | 2026-11-02 |

Les 30 tests ignorés des portails restent **non comptés comme réussis** ; ils
sont documentés comme limite de périmètre.

---

## 5. Verdict

**PASS_WITH_RESERVATIONS.** Toutes les exécutions du jour sont vertes. La
réserve porte sur les suites de concurrence non exécutables localement, pas
sur les résultats ci-dessus.
