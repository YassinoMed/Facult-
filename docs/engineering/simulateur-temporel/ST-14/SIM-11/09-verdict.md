# SIM-11 — 09 · Verdict final

**Scénario :** SIM-11 — Hausse de l'abandon  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-05  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-11 est un **contrat catalogue** posé sur le descripteur d'axe générique
livré par SIM-09 (`taux_modifie` + `axe_variation` + `sens_variation`), sans
aucune modification du moteur (`ServiceSimulationTemporelle` reste en
`1.8`). Le sens `hausse` était déjà validé et restitué : SIM-11 épingle
`abandon` / `hausse` au schéma, impose la hausse stricte et la somme ≤ 1,
puis réutilise la double trajectoire et `analyserReussite()` sans
duplication. Les cas de référence calculés hors moteur concordent
exactement après correction du calcul manuel du cas C : modifié
`[60,34,26]` + sortants `[18,9]` vs référence `[60,40,31]` + sortants
`[18,11]`, écarts `[-6,-5]`, effet `-2` ; hausse absorbée par le reste :
trajectoires **identiques** `[60,40,31]`, écarts `[0,0]`, effet `0` ;
delta +10 % : admissions `[11,12]` identiques, modifié `[60,38,32]` vs
référence `[60,41,35]`, écarts `[-3,-3]`, effet `-6`.

Le point structurel est documenté et prouvé : **`abandon` n'est pas
réinjecté**. Une hausse absorbée par le slack est exactement neutre ; aucun
effet n'est attribué au seul abandon. L'analyse OAT n'invente aucune ligne
`taux_abandon`. La rétrocompatibilité (aucune clé sans descripteur, alias
SIM-07/08 préservés), la cohérence des analyses OAT/MC autour des taux
modifiés, la sécurité (403 ×2, isolation) et la lecture seule sont prouvées.
La suite du module passe de 144 à **152 tests / 1640 assertions**, sans
échec, Pint propre.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — options, reclassement, cas vérifiés (cas C corrigé) | R1→R3 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, règles R1→R10 | — |
| **Formules** | ✅ PASS | `02-formules.md` — 3 cas calculés hors moteur | — |
| **Conception** | ✅ PASS | `03-architecture.md` — réutilisation v1.8, ordre des gardes, limite structurelle | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — catalogue + tests, moteur inchangé | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — concordance exacte après correction | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture, exclusion | SIM11-SEC-1/2/4 |
| **Tests** | ✅ PASS | **7/7 SIM-11**, **22/22 catalogue**, **152/152 module** | — |
| **Non-régression** | ✅ PASS | 144 tests antérieurs inchangés (SIM-07 → SIM-10 inclus) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — neutralité, contre-exemple, effet aval | R2, R6 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-11/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-11 | **7 / 7** |
| Assertions SIM-11 | **88** |
| Tests catalogue | **22 / 22** |
| Assertions catalogue | **555** |
| Suite module Simulation Temporelle | **152 / 152** |
| Assertions module | **1640** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur) |
| Écritures métier | **0** |
| Version moteur | `1.8` (**inchangée**) |
| Entrées WIP | **277** (aucun commit) |

**Incident de premier passage :** 6/7 au premier essai (écart de calcul
manuel sur le cas C). Correction documentée, valeurs recalculées, assertions
rendues plus exigeantes (`-3` au lieu de `0`), aucun test affaibli.

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM11-R1` | Aucun incrément de version moteur : le contrat SIM-11 est indistinguable d'une exécution générique par le seul hash de version | INFO | Décision assumée (aucun comportement nouveau) ; la version scénario `1` porte le contrat. |
| `SIM11-R2` | **`abandon` n'est pas réinjecté** : le résultat mesure un mouvement joint déclaré, pas un effet causal isolé de l'abandon | HAUTE | Documenté et prouvé (cas B) ; toute lecture causale est interdite. |
| `SIM11-R3` | Le résultat dépend de l'origine **déclarée** de la hausse (redoublement↓, passage↓, reste) | INFO | Force du contrat (aucune compensation inventée) ; documenté. |
| `SIM11-R4` | « Sortants » = dernier niveau du **périmètre** | INFO | Définition explicite ; réserve héritée. |
| `SIM11-R5` | Taux globaux : pas d'abandon différencié par niveau | INFO | LOT B (extension par niveau). |
| `SIM11-R6` | Le moteur accepte tout axe du vocabulaire ; l'épinglage `abandon/hausse` est porté par le schéma catalogue, non appelé par la création HTTP | MOYEN | Héritée (`SIM10-R6`) ; la validation moteur couvre la forme et la direction, le contrat d'axe reste au catalogue. |
| `SIM11-R7` | Aucune sensibilité OAT à l'abandon ; l'absence est structurelle, pas un oubli | MOYEN | Assertée (aucune ligne `taux_abandon`) ; aucune valeur inventée. |
| `SIM11-R8` | Capacité estimée au pas final, pas au pic intermédiaire | MOYEN | Héritée (`SIM10-R7`) ; Famille 4 (SIM-20). |
| `SIM11-R9` | Doublement du calcul par variante (trajectoire témoin) | FAIBLE | Variantes ≤ 20, horizon ≤ 6 ; coût borné. |
| `SIM11-R10` | Exclusion mutuelle reposant sur **l'ordre** des blocs (SIM-07 → SIM-08 → générique) | INFO | Ordre explicite et commenté ; testé. |
| `SIM11-R11` | Apparente redondance avec le cas A de SIM-10 (même mouvement joint `redoublement↓ + abandon↑`) | INFO | Contrats distincts par l'axe focal et la clé produite (`hausse_abandon` vs `baisse_redoublement`) ; moteur unique, trajectoire commune documentée. |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-11 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Catalogue :** 12 disponibles / 33 planifiés (45 entrées), SIM-11
  `analyse_nouvelle` confirmée.
- **Famille 2 :** SIM-07, SIM-08, SIM-09, SIM-10 et SIM-11 clôturés ;
  SIM-12 → SIM-13 restants.
- **Prochain scénario fonctionnel autorisé :** `SIM-12 — Réduction de
  l'abandon` (LOT A) — même descripteur générique, axe `abandon`, sens
  `baisse` ; le moteur v1.8 est déjà prêt, le schéma catalogue et la suite
  de tests restent à créer ; reclassement à confirmer par son audit.
- **Interdits maintenus :** aucune origine inventée, aucun signe favorable
  supposé, aucune écriture métier, aucun commit / push / branche / migration
  destructive.
