# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 06 · Lot 3 — Planification, capacités et groupes virtuels

**Date :** 2026-11-03  
**Périmètre :** moteur de scénarios, conflits, capacité, affectation de salles, groupes virtuels  
**Mode :** vérification et clôture — aucune règle métier nouvelle  

---

## 1. Objectif du lot

Vérifier que la planification reste une **simulation** : elle produit des
scénarios et un audit de conformité, sans jamais écrire le planning officiel,
et qu'aucune capacité inconnue n'est traitée comme suffisante.

---

## 2. Services et responsabilités

| Service | Responsabilité |
|---|---|
| `ExamScheduleEngineService` | Génération de scénarios, audit `{conforme, conflits_bloquants[]}`, application transactionnelle verrouillée. |
| `ConflitEpreuveService` | Moteur **pur** : salle, horaire, groupe, surveillant ; aucune écriture. |
| `ExamRoomAssignmentService` | Affectation de salles selon les règles existantes. |
| `ExamDecisionCapacitePermutationService` | Décision de capacité D-04 V2, ratio configurable. |
| `GroupeVirtuelExamenService` | Groupes virtuels opérationnels, éligibilité déléguée, aucune réaffectation. |
| `ConsultationExportGroupeVirtuelService` | Consultation et exports contrôlés. |

---

## 3. Règles vérifiées

| ID | Règle | Preuve |
|---|---|---|
| PLN-01 | Conflits bloquants détectés avant toute planification | `ExamensInvariantsSallesTest`, `ExamensInvariantsValidationTest` |
| PLN-02 | Une simulation n'écrit jamais le planning officiel | `PlanificationAutomatiqueExamensTest`, `Loop06PlanificationExamensVerificationTest` |
| CAP-01 | Capacité inconnue ⇒ blocage, jamais hypothèse implicite | `ExamCapaciteSalleNonConfirmeeTest` |
| CAP-02 | Ratio D-04 configurable (`EXAM_CAPACITE_RATIO`, défaut `0.50`) | `ExamDecisionCapacitePermutationTest` |
| GV-01 | Groupe virtuel sans réaffectation des étudiants | `ExamGroupeVirtuelTest`, `ExamGroupesVirtuelsLoopGlobalTest` |
| GV-02 | Consultation/export soumis au périmètre | `ExamGroupesVirtuelsConsultationExportTest` |

La valeur `0.50` du ratio est un **paramètre de configuration**, pas une
règle institutionnelle validée : elle reste une réserve métier (`ST-10`).

---

## 4. Exécution réelle du 2026-11-03

```bash
php artisan test --filter="ExamCapaciteSalleNonConfirmeeTest|ExamDecisionCapacitePermutationTest|ExamensInvariantsSallesTest|ExamensInvariantsValidationTest|ExamGroupeVirtuelTest|ExamGroupesVirtuelsLoopGlobalTest|ExamGroupesVirtuelsConsultationExportTest|PlanificationAutomatiqueExamensTest|Loop06PlanificationExamensVerificationTest"
```

```json
{"tool":"phpunit","result":"passed","tests":143,"passed":143,"assertions":656,"duration_ms":11584}
```

- **143 / 143 tests**, **656 assertions**, 0 échec.
- Aucun test de non-mutation ignoré.

---

## 5. Points de vigilance

| ID | Constat | Traitement |
|---|---|---|
| `PLN-P1` | Les routes modules ne portent pas `mfa.obligatoire` | Constat systémique `ANOM-A9`. |
| `PLN-P2` | Ratio de capacité `0.50` non validé institutionnellement | Réserve `ACQ-DEC-04` ; configurable sans redéploiement de code. |
| `PLN-P3` | Le pic intermédiaire n'est pas suivi par l'estimateur (constat transverse ST-14) | Hors périmètre Académique ; réserve Famille 4. |

---

## 6. Verdict du lot

**PASS_WITH_RESERVATIONS.** La planification est pure, les conflits sont
bloquants, la capacité inconnue bloque, les groupes virtuels ne réaffectent
personne. Les réserves sont des paramètres ou des constats systémiques.
