# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 07 · Lot 4 — Gouvernance, publication et cycle de vie

**Date :** 2026-11-03  
**Périmètre :** validation 4 yeux, publication officielle, transitions d'examen  
**Mode :** vérification et clôture — aucune règle métier nouvelle  

---

## 1. Objectif du lot

Prouver qu'aucune publication officielle ne peut contourner le circuit de
gouvernance, que les journaux sont append-only, et que les transitions de
cycle de vie restent verrouillées et traçables.

---

## 2. Services et responsabilités

| Service | Responsabilité |
|---|---|
| `ExamGouvernanceValidationService` | Circuit `soumis → certifié → approuvé ×2 → publié` ; refus, escalade ; personnes **et** rôles distincts ; empreinte certifiée. |
| `ExamPublicationOrchestratorService` | Diagnostic de publication + scellement SHA-256 ; permission re-vérifiée dans le service. |
| `ExamenService` | Transitions verrouillées, matrice de cycle de vie, journal append-only, prévisualisation. |

---

## 3. Règles vérifiées

| ID | Règle | Preuve |
|---|---|---|
| GOV-01 | Publication uniquement via le circuit 4 yeux | `ExamGouvernanceDoubleValidationTest`, `ExamGouvernanceHttpAuthorizationTest` |
| GOV-02 | Empreinte de planning certifiée et scellée SHA-256 | `ExamPublicationEnterpriseTest`, `ExamGlobalGouvernanceSecuriteContinuiteTest` |
| GOV-03 | Auto-approbation interdite ; personnes et rôles distincts | `ExamGouvernanceDoubleValidationTest` |
| LIF-01 | Transitions verrouillées + journal append-only | `ExamenCycleVieTest` |
| LIF-02 | `published` exige la gouvernance | `ExamenCycleVieTest`, `ExamGlobalGouvernanceSecuriteContinuiteTest` |

---

## 4. Exécution réelle du 2026-11-03

```bash
php artisan test --filter="ExamGouvernanceDoubleValidationTest|ExamGouvernanceHttpAuthorizationTest|ExamGlobalGouvernanceSecuriteContinuiteTest|ExamPublicationEnterpriseTest|ExamenCycleVieTest"
```

```json
{"tool":"phpunit","result":"passed","tests":76,"passed":76,"assertions":345,"duration_ms":3996}
```

- **76 / 76 tests**, **345 assertions**, 0 échec.
- Aucun chemin de publication hors circuit accepté.

---

## 5. Points de vigilance

| ID | Constat | Traitement |
|---|---|---|
| `GOV-P1` | Décisions métier D-01…D-05 (réouverture, motif, notifications, propagation d'annulation, complétude `completed`) non tranchées | Non codées en hypothèses ; listées au registre des risques. |
| `GOV-P2` | Absence de `mfa.obligatoire` sur les routes modules | Constat systémique `ANOM-A9`. |
| `GOV-P3` | Journal append-only : aucune correction rétroactive autorisée | Immuabilité testée ; restauration = nouvelle entrée, jamais `UPDATE`. |

---

## 6. Verdict du lot

**PASS_WITH_RESERVATIONS.** Le circuit 4 yeux est non contournable, la
publication est scellée, le journal est append-only et les transitions sont
verrouillées. Les décisions D-01…D-05 restent ouvertes et explicites.
