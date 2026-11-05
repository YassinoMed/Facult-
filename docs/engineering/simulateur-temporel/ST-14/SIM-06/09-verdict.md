# SIM-06 — 09 · Verdict final

**Scénario :** SIM-06 — Récupération démographique  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-03  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-06 confirme la classe `extension_temporelle` sur le moteur unique
(`ServiceSimulationTemporelle` v1.5) : un **creux multiplicatif** sur la
trajectoire de référence, avec rampes linéaires et **retour exact** au pas
`d + r`. Les cas de référence sont calculés hors moteur et concordent
exactement : admissions `[9,8,9,10]` et `[10,10,12,15]`, facteurs
`[0.9,0.8,0.9,1]`, déficit `−4` identique sur référence plate et tendance
croissante, creux total `[0,10]` sans admission négative, totaux
`[60,69,77,86,96]`, `[60,70,80,92,107]`, `[60,41,34]`, `[60,60,70]`.
La rétrocompatibilité est prouvée (aucune clé sans trio, admissions de fond
intactes), la garde d'exécution renforcée (division par zéro impossible),
la sécurité (403 ×2, isolation facultaire) et la lecture seule sont
prouvées. La suite du module passe de 103 à **112 tests / 1085 assertions**,
sans échec, et Pint est propre.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — moteur v1.4 audité, options comparées, compatibilité confirmée | R1→R3 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, règles R1→R9, cas non applicables | — |
| **Formules** | ✅ PASS | `02-formules.md` — 5 cas calculés hors moteur, propriétés exactes | — |
| **Conception** | ✅ PASS | `03-architecture.md` — moteur unique, additif, rétrocompatible | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — v1.5, `CatalogueScenarios::sim06`, double barrière | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — concordance exacte sur tous les cas | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture, garde renforcée | SIM06-SEC-1/2 |
| **Tests** | ✅ PASS | **8/8 SIM-06**, **17/17 catalogue**, **112/112 module** | — |
| **Non-régression** | ✅ PASS | 103 tests antérieurs inchangés (52 + 7×5 + 16) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — lecture des phases, pièges d'arrondi | R2, R3 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-06/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-06 | **8 / 8** |
| Assertions SIM-06 | **87** |
| Tests catalogue | **17 / 17** |
| Assertions catalogue | **399** |
| Suite module Simulation Temporelle | **112 / 112** |
| Assertions module | **1085** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur) |
| Écritures métier | **0** |
| Version moteur | `1.5` |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM06-R1` | Moteur passé en v1.5 : les exécutions v1.4 restent lisibles mais ne sont pas retournées par l'idempotence (hash d'entrées versionné) | INFO | Versionnement normal, traçabilité conservée. |
| `SIM06-R2` | Rampe **linéaire** imposée : un profil non linéaire (S-curve, exponentiel) exigerait un paramètre de forme | INFO | Forme progressive minimale documentée ; extension possible (CR-05). |
| `SIM06-R3` | Le creux porte sur les **admissions**, pas sur les effectifs totaux : la baisse peut être absorbée par les abandons | MOYEN | Croiser avec `totaux` ; affinage Famille 3 (cohortes LMD). |
| `SIM06-R4` | Arrondis à deux étages (référence arrondie, puis produit arrondi) | INFO | Convention documentée et testée (frontière .5 incluse). |
| `SIM06-R5` | Taux globaux : pas de creux différencié par niveau | INFO | Héritée de `SIM03-R4` / `SIM05-R4` ; LOT B. |
| `SIM06-R6` | La création HTTP n'appelle pas le catalogue ; la protection moteur s'active dès qu'une clé du trio est présente (définition **et** exécution) | INFO | Héritée ; garde d'exécution renforcée contre les lignes hors service. |
| `SIM06-R7` | Capacité estimée au pas final, pas au pic intermédiaire | MOYEN | Héritée de `SIM04-R3` / `SIM05-R7` ; Famille 4 (SIM-20). |
| `SIM06-R8` | Creux exogène non plafonné par la capacité (comme SIM-02 et SIM-04) | INFO | Comportement attendu ; déficit et saturation restitués. |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-06 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Catalogue :** 7 disponibles / 38 planifiés (45 entrées), SIM-06
  `extension_temporelle` confirmée.
- **Famille 1 :** SIM-01 → SIM-06 clôturés (6 scénarios) ; reste SIM-07 →
  SIM-13 (famille 2 « Réussite, redoublement, abandon », LOT A).
- **Prochain scénario fonctionnel autorisé :** `SIM-07 — Amélioration de la
  réussite` (LOT A, sous-LOOP ST-14.07) — compatibilité préliminaire
  `configuration` à confirmer par son audit (hausse du taux de passage, avec
  redistribution des flux à somme constante — aucune valeur par défaut).
- **Interdits maintenus :** aucune profondeur ni durée inventée, aucune
  écriture métier, aucun commit / push / branche / migration destructive.
