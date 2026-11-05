# SIM-12 — 02 · Formules et cas de référence

**Date :** 2026-11-05  
**Convention :** tous les calculs ci-dessous sont faits **hors moteur**, à la
main. Le moteur applique les mêmes règles ; aucune assertion ne réutilise sa
formule.

---

## C.1 Notation

| Symbole | Sens |
|---|---|
| `L1`, `L2`, `L3` | Effectifs par niveau, du premier au dernier |
| `p`, `r`, `a` | Taux de passage, redoublement, abandon |
| `H` | Horizon en années |
| `δ` | Delta annuel d'admissions |
| `taux` | Jeu de référence (trajectoire témoin) |
| `taux_modifie` | Jeu simulé (trajectoire principale) |

**Convention d'arrondi :** chaque flux est arrondi séparément :
`round(effectif × taux)`. PHP arrondit les demi-entiers à l'écart de zéro
(`2.5 → 3`, `3.5 → 4`, `6.5 → 7`).

---

## C.2 Progression d'un pas

Pour chaque niveau, du dernier vers le premier :

```
partants_i    = round(effectif_i × p)
redoublants_i = round(effectif_i × r)
abandons_i    = round(effectif_i × a)      (NON réinjecté)

nouveau_L1 = redoublants_1 + admissions
nouveau_Li = redoublants_i + partants_(i−1)
sortants   = partants_dernier_niveau
```

**Conservation :** si `partants_i + redoublants_i > effectif_i`,
`redoublants_i = max(0, effectif_i − partants_i)`. Aucune population créée.

**Admissions :** `admissions_pas = round(L1_base × (1 + δ)^pas)`, identiques
dans les deux trajectoires.

**Point structurel SIM-12 :** `abandon` n'entre dans **aucune** réinjection.
Il ne sert qu'à contraindre `p + r + a ≤ 1`. Une baisse de `a` financée par
le « reste » (somme < 1) ne change donc **rien** aux trajectoires.

---

## C.3 Comparaison de réussite

```
baisse_abandon         = taux.abandon − taux_modifie.abandon   (≥ 0, 4 déc.)
ecart_pas              = total_simule_pas − total_reference_pas
ecart_reussite_final   = total_simule_H − total_reference_H
effet_sortants_cumules = Σ (sortants_simules_pas − sortants_reference_pas)
```

La variation est une **magnitude positive** : le sens est porté par le nom du
champ (`baisse_abandon`), jamais par un signe ambigu.

---

## C.4 Cas A — baisse financée par le redoublement↑, δ = 0, H = 2

Jeux : référence `{0.6, 0.2, 0.2}` ; modifié `{0.6, 0.3, 0.1}`.

### Référence

**Pas 1** — base `(10, 20, 30)`

| Niveau | Effectif | Partants | Redoublants | Abandons |
|---|---:|---:|---:|---:|
| L1 | 10 | 6 | 2 | 2 |
| L2 | 20 | 12 | 4 | 4 |
| L3 | 30 | 18 | 6 | 6 |

`L1 = 2 + 10 = 12` · `L2 = 4 + 6 = 10` · `L3 = 6 + 12 = 18` → **total 40**,
sortants **18**.

**Pas 2** — base `(12, 10, 18)`

| Niveau | Effectif | `effectif × p` | Partants | `effectif × r` | Redoublants |
|---|---:|---:|---:|---:|---:|
| L1 | 12 | 7.2 | 7 | 2.4 | 2 |
| L2 | 10 | 6.0 | 6 | 2.0 | 2 |
| L3 | 18 | 10.8 | 11 | 3.6 | 4 |

`L1 = 2 + 10 = 12` · `L2 = 2 + 7 = 9` · `L3 = 4 + 6 = 10` → **total 31**,
sortants **11**.

**Référence : totaux `[60, 40, 31]`, sortants `[18, 11]`.**

### Modifié

**Pas 1**

| Niveau | Effectif | Partants | Redoublants | Abandons |
|---|---:|---:|---:|---:|
| L1 | 10 | 6 | 3 | 1 |
| L2 | 20 | 12 | 6 | 2 |
| L3 | 30 | 18 | 9 | 3 |

`L1 = 3 + 10 = 13` · `L2 = 6 + 6 = 12` · `L3 = 9 + 12 = 21` → **total 46**,
sortants **18**.

**Pas 2** — base `(13, 12, 21)`

| Niveau | Effectif | `effectif × p` | Partants | `effectif × r` | Redoublants |
|---|---:|---:|---:|---:|---:|
| L1 | 13 | 7.8 | 8 | 3.9 | 4 |
| L2 | 12 | 7.2 | 7 | 3.6 | 4 |
| L3 | 21 | 12.6 | 13 | 6.3 | 6 |

`L1 = 4 + 10 = 14` · `L2 = 4 + 8 = 12` · `L3 = 6 + 7 = 13` →
**total 39**, sortants **13**.

**Modifié : totaux `[60, 46, 39]`, sortants `[18, 13]`.**

### Comparaison

| Pas | Modifié | Référence | Écart |
|---|---:|---:|---:|
| 1 | 46 | 40 | **+6** |
| 2 | 39 | 31 | **+8** |

`baisse_abandon = 0.2 − 0.1 = 0.1` · `ecart_final = +8` ·
`effet_sortants = (18 + 13) − (18 + 11) = +2`.

---

## C.5 Cas B — baisse absorbée par le reste, δ = 0, H = 2

Jeux : référence `{0.6, 0.2, 0.2}` (somme 1.0) ; modifié `{0.6, 0.2, 0.1}`
(somme 0.9). **Seul `abandon` change** ; `passage` et `redoublement` sont
identiques.

### Référence

**Pas 1** : flux identiques au cas A (référence), `L1 = 12`, `L2 = 10`,
`L3 = 18` → **total 40**, sortants **18**.

**Pas 2** : flux identiques au cas A (référence), `L1 = 12`, `L2 = 9`,
`L3 = 10` → **total 31**, sortants **11**.

**Référence : `[60, 40, 31]`, sortants `[18, 11]`.**

### Modifié

Les taux `p = 0.6` et `r = 0.2` sont inchangés : les flux réinjectés sont
**identiques**, donc les trajectoires sont **exactement les mêmes**.

**Modifié : `[60, 40, 31]`, sortants `[18, 11]`.**

### Comparaison

| Pas | Modifié | Référence | Écart |
|---|---:|---:|---:|
| 1 | 40 | 40 | **0** |
| 2 | 31 | 31 | **0** |

`baisse_abandon = 0.1` · `ecart_final = 0` · **`effet_sortants = 0`**.

**Preuve structurelle :** le moteur n'invente aucun effet à partir de
`abandon` seul. Toute interprétation causale de la baisse serait fausse.

---

## C.6 Cas C — baisse financée par le passage↑, δ = +10 %, H = 2

Admissions : pas 1 `round(10 × 1.1) = 11` ; pas 2 `round(10 × 1.21) = 12`.
Identiques dans les deux trajectoires.

### Référence `{0.6, 0.2, 0.2}`

**Pas 1** : flux du cas A (référence) avec `L1 = 2 + 11 = 13` ; `L2 = 10`,
`L3 = 18` → **total 41**, sortants **18**.

**Pas 2** — base `(13, 10, 18)`

| Niveau | `effectif × p` | Partants | `effectif × r` | Redoublants |
|---|---:|---:|---:|---:|
| L1 | 7.8 | 8 | 2.6 | 3 |
| L2 | 6.0 | 6 | 2.0 | 2 |
| L3 | 10.8 | 11 | 3.6 | 4 |

`L1 = 3 + 12 = 15` · `L2 = 2 + 8 = 10` · `L3 = 4 + 6 = 10` → **total 35**,
sortants **11**.

**Référence : `[60, 41, 35]`, sortants `[18, 11]`.**

### Modifié `{0.7, 0.2, 0.1}`

**Pas 1** — base `(10, 20, 30)`

| Niveau | `effectif × p` | Partants | `effectif × r` | Redoublants |
|---|---:|---:|---:|---:|
| L1 | 7.0 | 7 | 2.0 | 2 |
| L2 | 14.0 | 14 | 4.0 | 4 |
| L3 | 21.0 | 21 | 6.0 | 6 |

`L1 = 2 + 11 = 13` · `L2 = 4 + 7 = 11` · `L3 = 6 + 14 = 20` →
**total 44**, sortants **21**.

**Pas 2** — base `(13, 11, 20)`

| Niveau | `effectif × p` | Partants | `effectif × r` | Redoublants |
|---|---:|---:|---:|---:|
| L1 | 9.1 | 9 | 2.6 | 3 |
| L2 | 7.7 | 8 | 2.2 | 2 |
| L3 | 14.0 | 14 | 4.0 | 4 |

`L1 = 3 + 12 = 15` · `L2 = 2 + 9 = 11` · `L3 = 4 + 8 = 12` →
**total 38**, sortants **14**.

**Modifié : `[60, 44, 38]`, sortants `[21, 14]`.**

### Comparaison

| Pas | Modifié | Référence | Écart |
|---|---:|---:|---:|
| 1 | 44 | 41 | **+3** |
| 2 | 38 | 35 | **+3** |

`baisse_abandon = 0.1` · `ecart_final = +3` ·
`effet_sortants = (21 + 14) − (18 + 11) = +6`.

**Lecture :** la baisse financée par le passage augmente le débit ; l'effet
aval est positif (+6) et ne se déduit pas du seul écart de stock (+3).

---

## C.7 Synthèse des cas

| Cas | Mouvement joint | Totaux modifiés | Totaux référence | Écarts | Effet sortants |
|---|---|---|---:|---|---:|
| A | `redoublement 0.2 → 0.3`, `abandon 0.2 → 0.1` | `[60, 46, 39]` | `[60, 40, 31]` | `[+6, +8]` | **+2** |
| B | `abandon 0.2 → 0.1` seul (slack) | `[60, 40, 31]` | `[60, 40, 31]` | `[0, 0]` | **0** |
| C | `passage 0.6 → 0.7`, `abandon 0.2 → 0.1`, δ 10 % | `[60, 44, 38]` | `[60, 41, 35]` | `[+3, +3]` | **+6** |

**Lecture :** la baisse de l'abandon, **seule**, ne produit aucun effet (cas
B). Les effets observés en A et C proviennent du mouvement joint déclaré
(`redoublement↑` en A, `passage↑` en C). Aucun signe favorable n'est supposé ;
aucune causalité isolée n'est revendiquée.

---

## C.8 Cas limites vérifiés

| Cas limite | Résultat attendu |
|---|---|
| Somme du jeu modifié exactement 1 | Acceptée (cas A, C) |
| Somme du jeu modifié < 1 | Acceptée (cas B), le reste n'est pas réinjecté |
| Horizon 2 | Deux périodes comparées (tous les cas) |
| Abandon égal | Refusé (« strictement inférieur ») |
| Abandon supérieur | Refusé (« strictement inférieur ») |
| `taux_modifie` absent | Aucune comparaison produite (rétrocompatibilité) |
