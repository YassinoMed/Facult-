# SIM-04 — 09 · Verdict final

**Scénario :** SIM-04 — Admissions exceptionnelles  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-03  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-04 active la classe `extension_temporelle` sur le moteur unique
(`ServiceSimulationTemporelle` v1.3) en ajoutant un choc **additif ponctuel**
au seul pas déclaré, sans modifier la trajectoire de fond. Les cas de
référence sont calculés hors moteur et concordent exactement : `[10, 15, 10]`
en admissions, `[60, 70, 85, 95]` en totaux, `[60, 43, 41]` avec flux actifs.
La conservation des effectifs, l'idempotence, la reproductibilité, la
validation stricte (paire indissociable, pas dans l'horizon, choc ≥ 1), la
sécurité (403, isolation facultaire) et la lecture seule sont prouvées.
La suite du module passe de 87 à **95 tests / 876 assertions**, sans échec,
et Pint est propre.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — moteur v1.2 audité, besoin ponctuel identifié | R1→R4 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, bornes, cas non applicables | — |
| **Formules** | ✅ PASS | `02-formules.md` — 3 cas calculés hors moteur, terme additif | — |
| **Conception** | ✅ PASS | `03-architecture.md` — moteur unique, hash complet, ordre gel→fond→choc | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — v1.3, `CatalogueScenarios::sim04` | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — écart nul sur toutes les références | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture | SIM04-SEC-1/2 |
| **Tests** | ✅ PASS | **7/7 SIM-04**, **15/15 catalogue**, **95/95 module** | — |
| **Non-régression** | ✅ PASS | 87 tests antérieurs inchangés (52 + 7 + 7 + 7 + 14) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — paramètres, lecture, limite du pic | R3 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-04/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-04 | **7 / 7** |
| Assertions SIM-04 | **54** |
| Tests catalogue | **15 / 15** |
| Assertions catalogue | **345** |
| Suite module Simulation Temporelle | **95 / 95** |
| Assertions module | **876** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur) |
| Écritures métier | **0** |
| Exécutions techniques | 1 par jeu d'entrées non idempotent |
| Snapshots techniques | $H + 1$ |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM04-R1` | Moteur passé en v1.3 : les exécutions historiques v1.2 ne sont ni rejouées ni fusionnées | INFO | Versionnement normal, traçabilité conservée. |
| `SIM04-R2` | Le choc est exogène et non plafonné par la capacité : il peut créer un dépassement | INFO | Comportement attendu ; le déficit et `depassement_capacite` sont restitués. |
| `SIM04-R3` | Capacité estimée sur le **dernier snapshot**, pas sur le pic intermédiaire | MOYEN | Un choc en milieu d'horizon peut sous-estimer le besoin ; traité par la Famille 4 (SIM-20). |
| `SIM04-R4` | Le moteur applique gel puis choc, mais le schéma SIM-04 n'expose pas `duree_gel_annees` | INFO | Combinaison non contractuelle ; réservée à CR-06. |
| `SIM04-R5` | La closure sensibilité/Monte-Carlo ne propageait ni le gel ni le choc | MOYEN | Corrigée ; les nouvelles exécutions sont cohérentes, aucun historique réécrit. |
| `SIM04-R6` | Capacité par défaut de 35 en l'absence de groupe réel | MOYEN | Héritée de `L0-R7` ; contrat Famille 4. |
| `SIM04-R7` | Un seul choc ponctuel par scénario | INFO | Chocs multiples/récurrents : SIM-06, CR-05, CR-06. |
| `SIM04-R8` | Taux globaux identiques pour tous les niveaux | INFO | Héritée de `SIM03-R4` ; LOT B affinera par niveau. |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-04 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Prochain scénario fonctionnel autorisé :** `SIM-05 — Croissance supérieure
  à la cible` (LOT A, sous-LOOP ST-14.05).
- Aucun commit, push, branche ou migration destructive n'a été effectué.
