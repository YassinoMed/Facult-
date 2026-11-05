# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 04 · Lot 1 — Acquis et capitalisation

**Date :** 2026-11-03  
**Périmètre :** réglementation, calcul des acquis, plan de redoublement, campagne de masse  
**Mode :** vérification et clôture — aucune règle métier nouvelle  

---

## 1. Objectif du lot

Vérifier que la chaîne **notes → calcul UE/ECUE → crédits → décision →
acquis / redoublement** respecte les règles réglementaires existantes, que
les corrections `ACQ-RED-01` sont stables, et qu'aucune donnée officielle
n'est modifiée par les simulations.

---

## 2. Services et responsabilités

| Service | Responsabilité |
|---|---|
| `ReglementationAcquisService` | Résolution **fail-closed** du régime (FORMATION / NATIONALE / AUCUNE), couverture réglementaire. |
| `CalculAcquisPedagogiquesService` | Moteur unique de capitalisation, compensation, meilleure note, seuil de compétence. |
| `MoteurDecisionAcademiqueService` | Décisions académiques explicables. |
| `GestionnaireCreditsService` | Crédits acquis et restitution. |
| `PlanRedoublementService` | Simulation de plan, clauses sensibles, revue humaine. |
| `ValidationApprobationPlanService` | Circuit 4 yeux du plan, séparation des rôles. |
| `CampagneRedoublementMasseService` | Campagne reprenable, permission dédiée. |
| `PilotAcademiqueService` | Indicateurs de pilotage, périmètre facultaire. |

---

## 3. Règles vérifiées

| ID | Règle | Preuve |
|---|---|---|
| ACQ-01 | Capitalisation UE/ECUE selon régime, fail-closed | `tests/Feature/Acquis/` |
| ACQ-02 | Couverture réglementaire FORMATION / NATIONALE / AUCUNE | `PiloteAcademiqueLoop42Test` |
| ACQ-03 | Incertitude ⇒ revue humaine **non bloquante** (G40) | `DecisionAcademiqueWorkflowTest`, campagne reprenable |
| ACQ-04 | Droits de redoublement épuisés ⇒ blocage conservé | `CertificationLoop39RegimesAcademiquesTest` |

---

## 4. Corrections ACQ-RED-01 (rappel)

Les 13 tests rouges ont été traités côté services, **sans modifier les
assertions** (`02-corrections-acq-red-01.md`) :

- `CORR-01` : restauration de `evaluerCouvertureReglementaire()` ;
- `CORR-02` : `avertissements_revue_humaine` non bloquants vs
  `erreurs_bloquantes` ;
- `CORR-03` : clé `revue_humaine` dans `PlanRedoublementService`,
  `BLOQUE_REGLEMENTATION_INCONNUE` non bloquant, `LIMITE_DEPASSEE` bloquant.

---

## 5. Exécution réelle du 2026-11-03

```bash
php artisan test tests/Feature/Acquis
```

```json
{"tool":"phpunit","result":"passed","tests":201,"passed":201,"assertions":1773,"duration_ms":9114}
```

- **201 / 201 tests**, **1 773 assertions**, 0 échec, 0 ignoré.
- Aucune assertion affaiblie.
- Aucun test marqué `skipped` pour verdir.

---

## 6. Réserves du lot

| ID | Réserve | Gravité |
|---|---|---|
| `ACQ-DEC-04` | Ratios de capacité et valeurs budgétaires non fournis par l'institution (ST-10) | MOYEN — décision métier attendue |
| `ACQ-INT-02` | Formation `LIC-ECO` absente du référentiel : traitée en revue humaine, pas en blocage | INFO — comportement conforme à G40 |
| `ACQ-RES-01` | Revue humaine G40 : validation humaine requise avant décision définitive | INFO — non bloquant par conception |

---

## 7. Verdict du lot

**PASS_WITH_RESERVATIONS.** Le lot est vérifié, les corrections sont stables,
la lecture seule est respectée. Les réserves sont des décisions métier ou des
revues humaines, jamais des règles inventées.
