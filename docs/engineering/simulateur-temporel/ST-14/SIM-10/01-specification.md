# SIM-10 — 01 · Spécification

**Date :** 2026-11-05  
**Scénario :** SIM-10 — Réduction du redoublement  
**Famille :** 2 — Réussite, redoublement et abandon  
**Compatibilité :** `analyse_nouvelle` (confirmée par l'audit)  
**Moteur :** `ServiceSimulationTemporelle` v`1.8` — **aucun changement attendu**

---

## B.1 Objectif

Mesurer l'effet d'une **baisse déclarée du redoublement** sur la progression
des cohortes et les sortants, à admissions identiques, dans le modèle
explicite du moteur.

La question posée est :

> Si le redoublement baisse de X points, que deviennent les effectifs et les
> sortants, **compte tenu de l'origine déclarée de cette baisse** ?

SIM-10 ne suppose **aucun signe favorable** : une baisse du redoublement peut
réduire les effectifs et les sortants (si les étudiants sortent du système)
comme elle peut augmenter les sortants (si la baisse alimente le passage).
Le scénario mesure ; il ne juge pas.

---

## B.2 Périmètre

| Inclus | Exclu |
|---|---|
| Redoublement **global** (identique pour tous les niveaux) | Taux différenciés par niveau (LOT B) |
| Un seul axe : `redoublement` | Combinaisons multi-axes (SIM-13) |
| Un seul sens : `baisse` | Hausse du redoublement (SIM-09) |
| Deux jeux de taux complets déclarés | Toute compensation calculée par le moteur |
| Périmètre faculté ou global | Décision ou recommandation |

---

## B.3 Entrées

| Paramètre | Type | Unité | Requis | Contrainte |
|---|---|---|---|---|
| `taux` | objet | ratios | **oui** | `passage`, `redoublement`, `abandon` ∈ [0,1] ; somme ≤ 1 |
| `taux_modifie` | objet | ratios | **oui** | les trois clés ; somme ≤ 1 ; `redoublement` **strictement inférieur** |
| `axe_variation` | chaîne | — | **oui** | épinglé à `redoublement` |
| `sens_variation` | chaîne | — | **oui** | épinglé à `baisse` |
| `delta_admissions` | nombre | ratio annuel | oui | borné par `bornes` |
| `bornes.min` / `bornes.max` | nombres | ratios | oui | encadrent `delta_admissions` |
| `horizon_annees` | entier | années | oui | 1 à `PLAFOND_HORIZON` (6) |
| `niveau_id` / `groupes` | identifiants | — | non | restreignent le périmètre |

Aucune valeur par défaut n'est injectée par le moteur : les deux jeux de taux
sont **entièrement saisis**.

---

## B.4 Règles contractuelles

| ID | Règle | Niveau |
|---|---|---|
| `R1` | `taux_modifie.redoublement < taux.redoublement` (baisse stricte) | Catalogue + moteur |
| `R2` | `taux_modifie` fournit `passage`, `redoublement`, `abandon` | Catalogue + moteur |
| `R3` | `passage + redoublement + abandon ≤ 1` pour chaque jeu | Catalogue + moteur |
| `R4` | Chaque taux ∈ [0,1] | Catalogue + moteur |
| `R5` | `axe_variation = redoublement` et `sens_variation = baisse` | Catalogue (épinglage) |
| `R6` | `taux_modifie`, `axe_variation`, `sens_variation` indissociables | Moteur |
| `R7` | Incompatible avec gel (SIM-03), choc (SIM-04), cible (SIM-05), creux (SIM-06) | Moteur |
| `R8` | Incompatible avec `taux_ameliore` (SIM-07) et `taux_degrade` (SIM-08) | Moteur (ordre des blocs) |
| `R9` | Admissions identiques dans les deux trajectoires (même `delta`) | Moteur |

`R5` est une contrainte de **contrat de scénario** : le moteur générique
accepte les trois axes et les deux sens ; le schéma SIM-10 épingle le couple
`redoublement` / `baisse`. La création HTTP n'appelant pas le catalogue, cette
réserve est héritée et documentée (`SIM10-R6`), jamais masquée.

---

## B.5 Sorties

| Sortie | Signification |
|---|---|
| `baisse_redoublement` | Magnitude **positive** de la baisse déclarée (4 décimales) |
| `totaux` | Effectifs simulés pas 0 → H (trajectoire modifiée) |
| `totaux_reference_reussite` | Effectifs si les taux de référence s'appliquaient |
| `ecarts_reussite_par_periode` | `total_simule − total_reference` (signés) |
| `ecart_reussite_final` | Écart au dernier pas |
| `sortants_par_periode` | Sortants simulés vs référence |
| `effet_sortants_cumules` | Somme des écarts de sortants |
| Champs moteur | Admissions, variations, total final, capacités, alertes |
| Analyses | OAT, Monte-Carlo, Pareto autour des taux **modifiés** |

Aucune clé de réussite n'est ajoutée si le descripteur est absent.

---

## B.6 Invariants

1. **Conservation** : `partants + redoublants ≤ effectif` du niveau (plafond
   d'arrondi), aucune population créée.
2. **Arrondi par flux** : `round(effectif × taux)`, jamais d'arrondi global
   qui créerait ou détruirait des étudiants.
3. **Admissions identiques** entre trajectoires : l'écart isole les taux de
   flux.
4. **Aucune compensation** : les autres taux sont ceux déclarés, jamais
   déduits de la baisse.
5. **Déterminisme** : mêmes entrées + même graine + même version → même
   résultat et même hash.

---

## B.7 Cas non applicables

| Cas | Raison |
|---|---|
| Hausse du redoublement | SIM-09 |
| Baisse du passage | SIM-08 |
| Hausse du passage | SIM-07 |
| Variation simultanée de plusieurs axes | SIM-13 |
| Taux par niveau | LOT B |
| Coût budgétaire de la baisse | LOT D (aucun coût validé) |

---

## B.8 Critères d'acceptation

1. Le catalogue publie SIM-10 `disponible`, version 1, formules 1.0,
   compatibilité `analyse_nouvelle`.
2. Les compteurs passent à **11 disponibles / 34 planifiés**.
3. Les trois cas de référence sont reproduits **exactement**.
4. Les refus (direction, bornes, somme, trio, axe/sens, incompatibilités)
   sont explicites et testés.
5. La rétrocompatibilité SIM-01 → SIM-09 est prouvée par la suite module.
6. Pint est propre ; aucune assertion affaiblie.
