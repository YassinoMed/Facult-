# SIM-11 — 01 · Spécification

**Date :** 2026-11-05  
**Scénario :** SIM-11 — Hausse de l'abandon  
**Famille :** 2 — Réussite, redoublement et abandon  
**Compatibilité :** `analyse_nouvelle` (confirmée par l'audit)  
**Moteur :** `ServiceSimulationTemporelle` v`1.8` — **aucun changement attendu**

---

## B.1 Objectif

Mesurer l'effet d'une **hausse déclarée de l'abandon** sur la progression des
cohortes et les sortants, à admissions identiques, dans le modèle explicite
du moteur.

La question posée est :

> Si l'abandon augmente de X points, que deviennent les effectifs et les
> sortants, **compte tenu du mouvement joint déclaré qui finance cette
> hausse** ?

SIM-11 ne suppose **aucun effet propre à l'abandon** : dans le moteur,
`abandon` n'est pas réinjecté dans la progression. Une hausse absorbée par le
« reste » (somme des taux < 1) est **exactement neutre** ; tout effet provient
du taux qui finance la hausse (`passage↓`, `redoublement↓` ou combinaison).
Le scénario mesure un **mouvement joint déclaré** ; il n'isole pas une
causalité que le modèle ne représente pas.

---

## B.2 Périmètre

| Inclus | Exclu |
|---|---|
| Abandon **global** (identique pour tous les niveaux) | Taux différenciés par niveau (LOT B) |
| Un seul axe : `abandon` | Combinaisons multi-axes (SIM-13) |
| Un seul sens : `hausse` | Baisse de l'abandon (SIM-12) |
| Deux jeux de taux complets déclarés | Toute compensation calculée par le moteur |
| Périmètre faculté ou global | Décision ou recommandation |

---

## B.3 Entrées

| Paramètre | Type | Unité | Requis | Contrainte |
|---|---|---|---|---|
| `taux` | objet | ratios | **oui** | `passage`, `redoublement`, `abandon` ∈ [0,1] ; somme ≤ 1 |
| `taux_modifie` | objet | ratios | **oui** | les trois clés ; somme ≤ 1 ; `abandon` **strictement supérieur** |
| `axe_variation` | chaîne | — | **oui** | épinglé à `abandon` |
| `sens_variation` | chaîne | — | **oui** | épinglé à `hausse` |
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
| `R1` | `taux_modifie.abandon > taux.abandon` (hausse stricte) | Catalogue + moteur |
| `R2` | `taux_modifie` fournit `passage`, `redoublement`, `abandon` | Catalogue + moteur |
| `R3` | `passage + redoublement + abandon ≤ 1` pour chaque jeu | Catalogue + moteur |
| `R4` | Chaque taux ∈ [0,1] | Catalogue + moteur |
| `R5` | `axe_variation = abandon` et `sens_variation = hausse` | Catalogue (épinglage) |
| `R6` | `taux_modifie`, `axe_variation`, `sens_variation` indissociables | Moteur |
| `R7` | Incompatible avec gel (SIM-03), choc (SIM-04), cible (SIM-05), creux (SIM-06) | Moteur |
| `R8` | Incompatible avec `taux_ameliore` (SIM-07) et `taux_degrade` (SIM-08) | Moteur (ordre des blocs) |
| `R9` | Admissions identiques dans les deux trajectoires (même `delta`) | Moteur |
| `R10` | `abandon` n'est pas réinjecté : une hausse absorbée par le reste est neutre | Moteur (structurel) |

`R5` est une contrainte de **contrat de scénario** : le moteur générique
accepte les trois axes et les deux sens ; le schéma SIM-11 épingle le couple
`abandon` / `hausse`. La création HTTP n'appelant pas le catalogue, cette
réserve est héritée et documentée (`SIM11-R6`), jamais masquée.

`R10` est une **propriété du modèle**, pas une règle de saisie : elle est
documentée, testée (cas B) et rappelée dans le guide. Elle interdit
d'attribuer à `abandon` un effet que seul un autre taux produit.

---

## B.5 Sorties

| Sortie | Signification |
|---|---|
| `hausse_abandon` | Magnitude **positive** de la hausse déclarée (4 décimales) |
| `totaux` | Effectifs simulés pas 0 → H (trajectoire modifiée) |
| `totaux_reference_reussite` | Effectifs si les taux de référence s'appliquaient |
| `ecarts_reussite_par_periode` | `total_simule − total_reference` (signés) |
| `ecart_reussite_final` | Écart au dernier pas |
| `sortants_par_periode` | Sortants simulés vs référence |
| `effet_sortants_cumules` | Somme des écarts de sortants (négatif, nul ou positif) |
| Champs moteur | Admissions, variations, total final, capacités, alertes |
| Analyses | OAT, Monte-Carlo, Pareto autour des taux **modifiés** |

Aucune clé de réussite n'est ajoutée si le descripteur est absent.

**Analyse de sensibilité :** `AnalyseurSensibilite` ne teste que
`delta_admissions`, `taux_passage` et `taux_redoublement` — l'abandon n'est
pas un flux réinjecté. SIM-11 n'invente **aucune** ligne `taux_abandon` ;
l'absence est assertée et documentée (`SIM11-R6`).

---

## B.6 Invariants

1. **Conservation** : `partants + redoublants ≤ effectif` du niveau (plafond
   d'arrondi), aucune population créée.
2. **Arrondi par flux** : `round(effectif × taux)`, jamais d'arrondi global
   qui créerait ou détruirait des étudiants.
3. **Admissions identiques** entre trajectoires : l'écart isole les taux de
   flux.
4. **Aucune compensation** : les autres taux sont ceux déclarés, jamais
   déduits de la hausse.
5. **Déterminisme** : mêmes entrées + même graine + même version → même
   résultat et même hash.
6. **Abandon non réinjecté** : `abandon` ne sert qu'à la contrainte
   `somme ≤ 1` ; une hausse sans mouvement de `passage` ou `redoublement` ne
   modifie ni les effectifs ni les sortants.

---

## B.7 Cas non applicables

| Cas | Raison |
|---|---|
| Baisse de l'abandon | SIM-12 |
| Hausse du redoublement | SIM-09 |
| Baisse du redoublement | SIM-10 |
| Baisse du passage | SIM-08 |
| Hausse du passage | SIM-07 |
| Variation simultanée de plusieurs axes | SIM-13 |
| Taux par niveau | LOT B |
| Coût budgétaire de la hausse | LOT D (aucun coût validé) |

---

## B.8 Critères d'acceptation

1. Le catalogue publie SIM-11 `disponible`, version 1, formules 1.0,
   compatibilité `analyse_nouvelle`.
2. Les compteurs passent à **12 disponibles / 33 planifiés**.
3. Les trois cas de référence sont reproduits **exactement**.
4. Les refus (direction, bornes, somme, trio, axe/sens, incompatibilités)
   sont explicites et testés.
5. La rétrocompatibilité SIM-01 → SIM-10 est prouvée par la suite module.
6. Pint est propre ; aucune assertion affaiblie.
