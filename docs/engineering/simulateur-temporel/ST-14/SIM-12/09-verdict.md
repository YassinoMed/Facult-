# SIM-12 — 09 · Verdict final

**Scénario :** SIM-12 — Réduction de l'abandon  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-05  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-12 est un **contrat catalogue** posé sur le descripteur d'axe générique
livré par SIM-09 (`taux_modifie` + `axe_variation` + `sens_variation`), sans
aucune modification du moteur (`ServiceSimulationTemporelle` reste en
`1.8`). Le sens `baisse` était déjà validé et restitué : SIM-12 épingle
`abandon` / `baisse` au schéma, impose la baisse stricte et la somme ≤ 1,
puis réutilise la double trajectoire et `analyserReussite()` sans
duplication. Les cas de référence calculés hors moteur concordent
exactement, dès le premier passage : modifié `[60,46,39]` + sortants
`[18,13]` vs référence `[60,40,31]` + sortants `[18,11]`, écarts `[+6,+8]`,
effet `+2` ; baisse absorbée par le reste : trajectoires **identiques**
`[60,40,31]`, écarts `[0,0]`, effet `0` ; delta +10 % : admissions `[11,12]`
identiques, modifié `[60,44,38]` vs référence `[60,41,35]`, écarts
`[+3,+3]`, effet `+6`.

Le point structurel est documenté et prouvé : **`abandon` n'est pas
réinjecté**. Une baisse absorbée par le slack est exactement neutre ; aucun
effet n'est attribué au seul abandon. L'analyse OAT n'invente aucune ligne
`taux_abandon`. La rétrocompatibilité (aucune clé sans descripteur, alias
SIM-07/08 préservés), la cohérence des analyses OAT/MC autour des taux
modifiés, la sécurité (403 ×2, isolation) et la lecture seule sont prouvées.
La suite du module passe de 152 à **160 tests / 1763 assertions**, sans
échec, Pint propre.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — options, reclassement, cas vérifiés | R1→R3 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, règles R1→R10 | — |
| **Formules** | ✅ PASS | `02-formules.md` — 3 cas calculés hors moteur | — |
| **Conception** | ✅ PASS | `03-architecture.md` — réutilisation v1.8, ordre des gardes, limite structurelle | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — catalogue + tests, moteur inchangé | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — concordance exacte au premier passage | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture, exclusion | SIM12-SEC-1/2/4 |
| **Tests** | ✅ PASS | **7/7 SIM-12**, **23/23 catalogue**, **160/160 module** | — |
| **Non-régression** | ✅ PASS | 152 tests antérieurs inchangés (SIM-01 → SIM-11 inclus) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — neutralité, contre-exemple, effet aval | R2, R6 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-12/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-12 | **7 / 7** |
| Assertions SIM-12 | **88** |
| Tests catalogue | **23 / 23** |
| Assertions catalogue | **590** |
| Suite module Simulation Temporelle | **160 / 160** |
| Assertions module | **1763** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint | **OK** (0 erreur) |
| Écritures métier | **0** |
| Version moteur | `1.8` (**inchangée**) |
| Entrées WIP | **278** (aucun commit) |
| Incident de premier passage | **Aucun** — 7/7 dès le premier essai |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM12-R1` | Aucun incrément de version moteur : le contrat SIM-12 est indistinguable d'une exécution générique par le seul hash de version | INFO | Décision assumée (aucun comportement nouveau) ; la version scénario `1` porte le contrat. |
| `SIM12-R2` | **`abandon` n'est pas réinjecté** : le résultat mesure un mouvement joint déclaré, pas un effet causal isolé de l'abandon | HAUTE | Documenté et prouvé (cas B) ; toute lecture causale est interdite. |
| `SIM12-R3` | Le résultat dépend de l'origine **déclarée** de la baisse (redoublement↑, passage↑, reste) | INFO | Force du contrat (aucune compensation inventée) ; documenté. |
| `SIM12-R4` | « Sortants » = dernier niveau du **périmètre** | INFO | Définition explicite ; réserve héritée. |
| `SIM12-R5` | Taux globaux : pas d'abandon différencié par niveau | INFO | LOT B (extension par niveau). |
| `SIM12-R6` | Le moteur accepte tout axe du vocabulaire ; l'épinglage `abandon/baisse` est porté par le schéma catalogue, non appelé par la création HTTP | MOYEN | Héritée (`SIM11-R6`) ; la validation moteur couvre la forme et la direction, le contrat d'axe reste au catalogue. |
| `SIM12-R7` | Aucune sensibilité OAT à l'abandon ; l'absence est structurelle, pas un oubli | MOYEN | Assertée (aucune ligne `taux_abandon`) ; aucune valeur inventée. |
| `SIM12-R8` | Capacité estimée au pas final, pas au pic intermédiaire | MOYEN | Héritée (`SIM11-R8`) ; Famille 4 (SIM-20). |
| `SIM12-R9` | Doublement du calcul par variante (trajectoire témoin) | FAIBLE | Variantes ≤ 20, horizon ≤ 6 ; coût borné. |
| `SIM12-R10` | Exclusion mutuelle reposant sur **l'ordre** des blocs (SIM-07 → SIM-08 → générique) | INFO | Ordre explicite et commenté ; testé. |
| `SIM12-R11` | Apparente symétrie avec SIM-11 (`abandon↑` vs `abandon↓`) et avec le cas A de SIM-09 (même mouvement joint `redoublement↑ + abandon↓`) | INFO | Contrats distincts par le sens et l'axe focal (`baisse_abandon` vs `hausse_abandon` vs `hausse_redoublement`) ; moteur unique, trajectoire commune documentée. |
| `SIM12-R12` | Aucune économie budgétaire de la rétention n'est calculée | INFO | Aucun coût unitaire validé ; LOT D. |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-12 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Catalogue :** 13 disponibles / 32 planifiés (45 entrées), SIM-12
  `analyse_nouvelle` confirmée.
- **Famille 2 :** SIM-07, SIM-08, SIM-09, SIM-10, SIM-11 et SIM-12 clôturés ;
  SIM-13 reste à ouvrir.
- **Prochain scénario fonctionnel autorisé :** `SIM-13 — Crise académique
  combinée` (LOT A) — **multi-axes** ; un changement moteur est probable,
  une décision d'extension et un audit préalable sont requis avant toute
  implémentation.
- **Interdits maintenus :** aucune origine inventée, aucun signe favorable
  supposé, aucune écriture métier, aucun commit / push / branche / migration
  destructive.
