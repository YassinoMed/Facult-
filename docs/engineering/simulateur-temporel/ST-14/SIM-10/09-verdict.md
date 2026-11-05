# SIM-10 — 09 · Verdict final

**Scénario :** SIM-10 — Réduction du redoublement  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-05  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-10 est un **contrat catalogue** posé sur le descripteur d'axe générique
livré par SIM-09 (`taux_modifie` + `axe_variation` + `sens_variation`), sans
aucune modification du moteur (`ServiceSimulationTemporelle` reste en
`1.8`). Le sens `baisse` était déjà validé et restitué : SIM-10 épingle
`redoublement` / `baisse` au schéma, impose la baisse stricte et la somme
≤ 1, puis réutilise la double trajectoire et `analyserReussite()` sans
duplication. Les cas de référence calculés hors moteur concordent
exactement : modifié `[60,34,26]` + sortants `[18,9]` vs référence
`[60,40,31]` + sortants `[18,11]`, écarts `[-6,-5]`, effet `-2` ; baisse
absorbée par le reste : `[60,34]` vs `[60,40]`, effet aval `0` à horizon 1 ;
delta +10 % : admissions `[11,12]` identiques, écarts `[-6,-7]`, effet `-2`.
La rétrocompatibilité (aucune clé sans descripteur, alias SIM-07/08
préservés), la cohérence des analyses OAT/MC autour des taux modifiés, la
sécurité (403 ×2, isolation) et la lecture seule sont prouvées. La suite du
module passe de 136 à **144 tests / 1517 assertions**, sans échec, Pint
propre du premier coup.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — options, reclassement, cas vérifiés | R1→R3 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, règles R1→R9 | — |
| **Formules** | ✅ PASS | `02-formules.md` — 3 cas calculés hors moteur | — |
| **Conception** | ✅ PASS | `03-architecture.md` — réutilisation v1.8, ordre des gardes | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — catalogue + tests, moteur inchangé | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — concordance exacte | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture, exclusion | SIM10-SEC-1/2 |
| **Tests** | ✅ PASS | **7/7 SIM-10**, **21/21 catalogue**, **144/144 module** | — |
| **Non-régression** | ✅ PASS | 136 tests antérieurs inchangés (SIM-07 → SIM-09 inclus) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — lecture des signes, effet aval | R4, R5 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-10/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-10 | **7 / 7** (premier essai) |
| Assertions SIM-10 | **85** |
| Tests catalogue | **21 / 21** |
| Assertions catalogue | **520** |
| Suite module Simulation Temporelle | **144 / 144** |
| Assertions module | **1517** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur, premier passage) |
| Écritures métier | **0** |
| Version moteur | `1.8` (**inchangée**) |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM10-R1` | Aucun incrément de version moteur : le contrat SIM-10 est indistinguable d'une exécution générique par le seul hash de version | INFO | Décision assumée (aucun comportement nouveau) ; la version scénario `1` porte le contrat. |
| `SIM10-R2` | Le résultat mesure l'effet **dans le modèle déclaré** : la conversion d'une politique réelle en taux modifiés reste une expertise humaine | MOYEN | Documenté ; aucune vraisemblance revendiquée. |
| `SIM10-R3` | Le résultat dépend de l'origine **déclarée** de la baisse (abandon↑, reste, ou passage↑ non revendiqué) | INFO | Force du contrat (aucune compensation inventée) ; documenté. |
| `SIM10-R4` | « Sortants » = dernier niveau du **périmètre** | INFO | Définition explicite ; réserve héritée. |
| `SIM10-R5` | Taux globaux : pas de redoublement différencié par niveau | INFO | LOT B (extension par niveau). |
| `SIM10-R6` | Le moteur accepte tout axe du vocabulaire ; l'épinglage `redoublement/baisse` est porté par le schéma catalogue, non appelé par la création HTTP | MOYEN | Héritée (`SIM09-R6`) ; la validation moteur couvre la forme et la direction, le contrat d'axe reste au catalogue. |
| `SIM10-R7` | Capacité estimée au pas final, pas au pic intermédiaire | MOYEN | Héritée (`SIM09-R7`) ; Famille 4 (SIM-20). |
| `SIM10-R8` | Doublement du calcul par variante (trajectoire témoin) | FAIBLE | Variantes ≤ 20, horizon ≤ 6 ; coût borné. |
| `SIM10-R9` | L'exclusion mutuelle repose sur **l'ordre** des blocs (SIM-07 → SIM-08 → générique) | INFO | Ordre explicite et commenté ; testé. |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-10 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Catalogue :** 11 disponibles / 34 planifiés (45 entrées), SIM-10
  `analyse_nouvelle` confirmée.
- **Famille 2 :** SIM-07, SIM-08, SIM-09 et SIM-10 clôturés ; SIM-11 → SIM-13
  restants.
- **Prochain scénario fonctionnel autorisé :** `SIM-11 — Hausse de l'abandon`
  (LOT A) — même descripteur générique, axe `abandon`, sens `hausse` ; le
  moteur v1.8 est déjà prêt, le schéma catalogue et la suite de tests restent
  à créer ; reclassement à confirmer par son audit.
- **Interdits maintenus :** aucune origine inventée, aucun signe favorable
  supposé, aucune écriture métier, aucun commit / push / branche / migration
  destructive.
