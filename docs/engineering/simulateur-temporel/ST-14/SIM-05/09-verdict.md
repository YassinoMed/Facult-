# SIM-05 — 09 · Verdict final

**Scénario :** SIM-05 — Croissance supérieure à la cible  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-03  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-05 active la classe `analyse_nouvelle` sur le moteur unique
(`ServiceSimulationTemporelle` v1.4) en comparant les admissions produites à
une **trajectoire de croissance cible saisie**. Les cas de référence sont
calculés hors moteur et concordent exactement : `[11,12,13]` vs
`[11,11,12]` en admissions, écarts `[0,1,1]` (cumul 2), écarts `[1,2,4]`
(cumul 7), totaux `[60,71,83,96]` et `[60,72,86,103]`, conservation stricte
avec flux actifs `[60,44,38]`. Le reclassement
`configuration → analyse_nouvelle` est justifié par l'audit : la cible
constante `cible_total` ne modélise pas une trajectoire. La rétrocompatibilité
est prouvée (aucune clé ajoutée sans cible, totaux identiques), la sécurité
(403 ×2, isolation facultaire) et la lecture seule sont prouvées. La suite du
module passe de 95 à **103 tests / 968 assertions**, sans échec, et Pint est
propre.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — moteur v1.3 audité, options comparées, reclassement argumenté | R1→R3 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, règles R1→R7, cas non applicables | — |
| **Formules** | ✅ PASS | `02-formules.md` — 5 cas calculés hors moteur | — |
| **Conception** | ✅ PASS | `03-architecture.md` — moteur unique, additif, rétrocompatible | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — v1.4, `CatalogueScenarios::sim05` | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — concordance exacte sur tous les cas | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture | SIM05-SEC-1/2 |
| **Tests** | ✅ PASS | **7/7 SIM-05**, **16/16 catalogue**, **103/103 module** | — |
| **Non-régression** | ✅ PASS | 95 tests antérieurs inchangés (52 + 7 + 7 + 7 + 7 + 15) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — lecture des écarts, pièges d'arrondi | R2, R3 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-05/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-05 | **7 / 7** |
| Assertions SIM-05 | **68** |
| Tests catalogue | **16 / 16** |
| Assertions catalogue | **369** |
| Suite module Simulation Temporelle | **103 / 103** |
| Assertions module | **968** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur) |
| Écritures métier | **0** |
| Version moteur | `1.4` |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM05-R1` | Moteur passé en v1.4 : les exécutions v1.3 restent lisibles mais ne sont pas retournées par l'idempotence (hash d'entrées versionné) | INFO | Versionnement normal, traçabilité conservée. |
| `SIM05-R2` | Les écarts portent sur les **admissions**, pas sur les effectifs totaux : un dépassement peut être absorbé par les abandons | MOYEN | Croiser avec `totaux` ; affinage Famille 3 (cohortes LMD). |
| `SIM05-R3` | Les arrondis peuvent annuler un dépassement exact marginal (`depassement_cible = false`) | INFO | Comportement documenté et testé (cas 4) ; comparaison sur entiers assumée. |
| `SIM05-R4` | Taux cible global, non différencié par niveau | INFO | Héritée de `SIM03-R4` / `SIM04-R8` ; LOT B affinera par niveau. |
| `SIM05-R5` | La cible est descriptive : aucune contrainte dure ni alerte automatique sur le dépassement | MOYEN | Extension possible (Famille 7 / UNC) ; décision d'usage, pas un défaut de calcul. |
| `SIM05-R6` | La création HTTP n'appelle pas le catalogue : la protection moteur s'active en présence du paramètre, sans exiger sa présence | INFO | Héritée de l'architecture ; aucune règle contournée dès que la cible est fournie. |
| `SIM05-R7` | Capacité estimée au pas final, pas au pic intermédiaire | MOYEN | Héritée de `SIM04-R3` ; traitée par la Famille 4 (SIM-20). |
| `SIM05-R8` | Cible composée uniquement : ni palier, ni rupture, ni choc sur la cible | INFO | SIM-06 / CR-05 (récupération) et CR-06 (crises successives). |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-05 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Catalogue :** 6 disponibles / 39 planifiés (45 entrées), SIM-05
  `analyse_nouvelle` confirmée.
- **Prochain scénario fonctionnel autorisé :** `SIM-06 — Récupération
  démographique` (LOT A, sous-LOOP ST-14.06) — baisse suivie d'un retour
  progressif au niveau de référence ; compatibilité préliminaire
  `extension_temporelle` à confirmer par son audit.
- **Interdits maintenus :** aucune cible inventée, aucune écriture métier,
  aucun commit / push / branche / migration destructive.
