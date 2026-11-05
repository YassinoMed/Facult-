# SIM-03 — 09 · Verdict final

**Scénario :** SIM-03 — Gel des admissions  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-02  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-03 active avec succès la classe de compatibilité `extension_temporelle` sur le moteur unique canonique (`ServiceSimulationTemporelle` v1.2) en introduisant la gestion de l'interruption temporaire des admissions ($admissions = 0$ pendant $D$ années). Les calculs ont été validés indépendamment hors moteur, la conservation des cohortes et la gestion des flux sont prouvées, le déterminisme et l'idempotence sont rigoureusement respectés, la sécurité (403, isolation multi-tenant) et la lecture seule sur les données métier officielles sont démontrées. L'intégralité de la suite du module (87 tests / 800 assertions) réussit avec 0 échec et le formatage Pint est sans erreur.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — moteur v1.1 audité, besoin temporel identifié | R1→R4 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, paramètres requis, cas $D=H$ et $D<H$ | — |
| **Formules** | ✅ PASS | `02-formules.md` — 3 cas calculés hors moteur, équations temporelles | — |
| **Conception** | ✅ PASS | `03-architecture.md` — moteur unique, hash_entrees complet | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — moteur v1.2, CatalogueScenarios::sim03 | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — écart nul sur toutes les références manuelles | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité multi-facultés, zéro écriture métier | SIM03-SEC-1 |
| **Tests** | ✅ PASS | **7/7 SIM-03**, **14/14 catalogue**, **87/87 module** | — |
| **Non-régression** | ✅ PASS | 52 tests historiques, 7 SIM-01, 7 SIM-02 inchangés | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — paramètres et interprétation métier | — |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-03/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-03 | **7 / 7** |
| Assertions SIM-03 | **53** |
| Tests catalogue | **14 / 14** |
| Assertions catalogue | **323** |
| Suite module Simulation Temporelle | **87 / 87** |
| Assertions module | **800** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur) |
| Écritures métier | **0** |
| Exécutions techniques | 1 par exécution testée |
| Snapshots techniques | $H + 1$ |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM03-R1` | Moteur passé en v1.2 : les exécutions historiques v1.1 ne sont pas fusionnées | INFO | Comportement de versionnement normal et attendu pour la traçabilité. |
| `SIM03-R2` | Reprise post-gel calculée à partir de $baseL1$ initial | INFO | Documenté dans les hypothèses ; LOT A scénario SIM-06 modélisera une récupération démographique plus complexe. |
| `SIM03-R3` | Capacité par défaut fixée à 35 en l'absence de groupe réel | MOYEN | Identifiée depuis LOT 0 ; contrat Famille 4. |
| `SIM03-R4` | Taux globaux pour tous les niveaux | INFO | Sera affiné dans le LOT B (cohorte par niveau). |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-03 :** Prononcée avec succès sous le verdict **PASS_WITH_RESERVATIONS**.
- **Prochain scénario fonctionnel autorisé :** `SIM-04 — Admissions exceptionnelles` (LOT A, sous-LOOP ST-14.04).

