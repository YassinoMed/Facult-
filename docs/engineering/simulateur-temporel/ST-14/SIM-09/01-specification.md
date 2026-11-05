# SIM-09 — 01 · Spécification

**Date :** 2026-11-05  
**Scénario :** SIM-09 — Hausse du redoublement  
**Version de scénario :** 1 · **Version de formules :** 1.0  
**Compatibilité :** `analyse_nouvelle` (reclassée par l'audit `00-audit.md`)  
**Dépendance :** `SOCLE-EFFECTIFS`

---

## B.1 Objectif

Comparer, dans une **même exécution**, une trajectoire simulée dont le
**redoublement est déclaré en hausse** (`taux_modifie` + axe `redoublement`
+ sens `hausse`) à la trajectoire de référence (`taux`), à admissions
identiques, et restituer l'accumulation d'effectifs et l'effet sur les
sortants. Aucune compensation n'est inventée : la provenance de la hausse
(abandon↓, reste, passage↓) est **déclarée**.

---

## B.2 Paramètres d'entrée

| Paramètre | Type | Requis | Bornes | Rôle |
|---|---|---|---|---|
| `delta_admissions`, `bornes`, `horizon_annees` | — | oui | socle | Identiques aux contrats précédents |
| `taux.passage/redoublement/abandon` | number | oui | [0,1], somme ≤ 1 | Référence (témoin) |
| `taux_modifie.passage/redoublement/abandon` | number | **oui** | [0,1], somme ≤ 1 | Jeu simulé (trajectoire principale) |
| `axe_variation` | string | **oui** | `redoublement` uniquement (schéma) | Axe focal |
| `sens_variation` | string | **oui** | `hausse` uniquement (schéma) | Sens focal |
| `niveau_id`, `groupes` | — | non | — | Périmètre |

Aucune valeur par défaut. Le trio `taux_modifie` + `axe_variation` +
`sens_variation` est **indissociable**.

---

## B.3 Règles de domaine

| # | Règle | Justification |
|---|---|---|
| R1 | `taux_modifie.redoublement > taux.redoublement` (strict) | Le scénario mesure une **hausse** du redoublement. |
| R2 | Chaque taux des deux jeux ∈ [0,1] ; sommes ≤ 1 | Invariants du moteur. |
| R3 | `taux` de référence requis | Sans témoin, aucune comparaison. |
| R4 | Axe `redoublement`, sens `hausse` (schéma) | Contrat du scénario. |
| R5-R8 | Gel, choc, cible, creux interdits | SIM-03/04/05/06. |
| R9 | Exclusion mutuelle avec `taux_ameliore`, `taux_degrade` | Un seul axe de réussite à la fois. |

Les autres taux du jeu modifié sont **libres** (origine déclarée).

---

## B.4 Sorties

| Champ | Type | Description |
|---|---|---|
| `hausse_redoublement` | number | `taux_modifie.redoublement − taux.redoublement` (4 décimales) |
| `totaux_reference_reussite` | array | Totaux témoins (pas 0 → H) |
| `ecarts_reussite_par_periode` | array | `[{pas, total_simule, total_reference, ecart}]` |
| `ecart_reussite_final` | integer | Écart final (positif = accumulation) |
| `sortants_par_periode` | array | `[{pas, sortants, sortants_reference}]` |
| `effet_sortants_cumules` | integer | Effet cumulé sur les sortants |
| `totaux`, `total_final`, `admissions_par_periode` | existants | Trajectoire simulée |

Les cinq champs neutres sont identiques à SIM-07/08.

---

## B.5 Critères d'acceptation

1. `estDisponible('SIM-09') === true` ; catalogue **10 disponibles / 35 planifiés**.
2. Refus : redoublement égal ou inférieur, axe/sens hors contrat, somme > 1, jeu incomplet, référence absente, gel/choc/cible/creux, formes simultanées.
3. Moteur conforme aux cas A/B/C vérifiés hors moteur.
4. Admissions identiques dans les deux trajectoires ; zéro écriture métier ; idempotence ; aucune clé sans `taux_modifie`.

## B.6 Réserves anticipées

`SIM09-S1` effet aval lent (sortants) selon horizon · `SIM09-S2` « sortants » = dernier niveau du périmètre · `SIM09-S3` taux globaux · `SIM09-S4` catalogue non appelé en HTTP (validation moteur aux deux niveaux) · `SIM09-S5` la forme générique reste mono-axe (SIM-13 traitera les combinaisons).
