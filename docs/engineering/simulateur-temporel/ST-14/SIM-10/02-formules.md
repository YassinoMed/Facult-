# SIM-10 — 02 · Formules et cas de référence

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
`round(effectif × taux)`. PHP arrondit les demi-entiers à l'écart de zéro.

---

## C.2 Progression d'un pas

Pour chaque niveau, du dernier vers le premier :

```
partants_i    = round(effectif_i × p)
redoublants_i = round(effectif_i × r)
abandons_i    = round(effectif_i × a)      (reste non réinjecté)

nouveau_L1 = redoublants_1 + admissions
nouveau_Li = redoublants_i + partants_(i−1)
sortants   = partants_dernier_niveau
```

**Conservation :** si `partants_i + redoublants_i > effectif_i`,
`redoublants_i = max(0, effectif_i − partants_i)`. Aucune population créée.

**Admissions :** `admissions_pas = round(L1_base × (1 + δ)^pas)`, identiques
dans les deux trajectoires.

---

## C.3 Comparaison de réussite

```
baisse_redoublement    = taux.redoublement − taux_modifie.redoublement   (≥ 0, 4 déc.)
ecart_pas              = total_simule_pas − total_reference_pas
ecart_reussite_final   = total_simule_H − total_reference_H
effet_sortants_cumules = Σ (sortants_simules_pas − sortants_reference_pas)
```

La variation est une **magnitude positive** : le sens est porté par le nom du
champ (`baisse_redoublement`), jamais par un signe ambigu.

---

## C.4 Cas A — baisse alimentée par l'abandon↑, δ = 0, H = 2

Jeux : référence `{0.6, 0.2, 0.2}` ; modifié `{0.6, 0.1, 0.3}`.

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
| L1 | 10 | 6 | 1 | 3 |
| L2 | 20 | 12 | 2 | 6 |
| L3 | 30 | 18 | 3 | 9 |

`L1 = 1 + 10 = 11` · `L2 = 2 + 6 = 8` · `L3 = 3 + 12 = 15` → **total 34**,
sortants **18**.

**Pas 2** — base `(11, 8, 15)`

| Niveau | Effectif | `effectif × p` | Partants | `effectif × r` | Redoublants |
|---|---:|---:|---:|---:|---:|
| L1 | 11 | 6.6 | 7 | 1.1 | 1 |
| L2 | 8 | 4.8 | 5 | 0.8 | 1 |
| L3 | 15 | 9.0 | 9 | 1.5 | 2 |

`L1 = 1 + 10 = 11` · `L2 = 1 + 7 = 8` · `L3 = 2 + 5 = 7` → **total 26**,
sortants **9**.

**Modifié : totaux `[60, 34, 26]`, sortants `[18, 9]`.**

### Comparaison

| Pas | Modifié | Référence | Écart |
|---|---:|---:|---:|
| 1 | 34 | 40 | **−6** |
| 2 | 26 | 31 | **−5** |

`baisse_redoublement = 0.2 − 0.1 = 0.1` · `ecart_final = −5` ·
`effet_sortants = (18 + 9) − (18 + 11) = −2`.

---

## C.5 Cas B — baisse absorbée par le reste, δ = 0, H = 1

Jeux : référence `{0.6, 0.2, 0.1}` (somme 0.9) ; modifié `{0.6, 0.1, 0.1}`
(somme 0.8).

### Référence

| Niveau | Effectif | Partants | Redoublants | Abandons |
|---|---:|---:|---:|---:|
| L1 | 10 | 6 | 2 | 1 |
| L2 | 20 | 12 | 4 | 2 |
| L3 | 30 | 18 | 6 | 3 |

`L1 = 12` · `L2 = 10` · `L3 = 18` → **total 40**, sortants **18**.

### Modifié

| Niveau | Effectif | Partants | Redoublants | Abandons |
|---|---:|---:|---:|---:|
| L1 | 10 | 6 | 1 | 1 |
| L2 | 20 | 12 | 2 | 2 |
| L3 | 30 | 18 | 3 | 3 |

`L1 = 11` · `L2 = 8` · `L3 = 15` → **total 34**, sortants **18**.

### Comparaison

| Pas | Modifié | Référence | Écart |
|---|---:|---:|---:|
| 1 | 34 | 40 | **−6** |

`baisse_redoublement = 0.1` · `ecart_final = −6` · **`effet_sortants = 0`**
(le passage est inchangé et le niveau terminal n'est pas atteint par l'effet
aval à H = 1).

---

## C.6 Cas C — δ = +10 %, H = 2

Admissions : pas 1 `round(10 × 1.1) = 11` ; pas 2 `round(10 × 1.21) = 12`.
Identiques dans les deux trajectoires.

### Référence `{0.6, 0.2, 0.2}`

**Pas 1** : flux identiques au cas A ; `L1 = 2 + 11 = 13`, `L2 = 10`,
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

### Modifié `{0.6, 0.1, 0.3}`

**Pas 1** : flux du cas A ; `L1 = 1 + 11 = 12`, `L2 = 8`, `L3 = 15` →
**total 35**, sortants **18**.

**Pas 2** — base `(12, 8, 15)`

| Niveau | `effectif × p` | Partants | `effectif × r` | Redoublants |
|---|---:|---:|---:|---:|
| L1 | 7.2 | 7 | 1.2 | 1 |
| L2 | 4.8 | 5 | 0.8 | 1 |
| L3 | 9.0 | 9 | 1.5 | 2 |

`L1 = 1 + 12 = 13` · `L2 = 1 + 7 = 8` · `L3 = 2 + 5 = 7` → **total 28**,
sortants **9**.

**Modifié : `[60, 35, 28]`, sortants `[18, 9]`.**

### Comparaison

| Pas | Modifié | Référence | Écart |
|---|---:|---:|---:|
| 1 | 35 | 41 | **−6** |
| 2 | 28 | 35 | **−7** |

`baisse_redoublement = 0.1` · `ecart_final = −7` ·
`effet_sortants = (18 + 9) − (18 + 11) = −2`.

---

## C.7 Synthèse des cas

| Cas | Origine de la baisse | Totaux modifiés | Totaux référence | Écarts | Effet sortants |
|---|---|---|---:|---|---:|
| A | abandon ↑ (0.2 → 0.3) | `[60, 34, 26]` | `[60, 40, 31]` | `[−6, −5]` | **−2** |
| B | reste (somme 0.9 → 0.8) | `[60, 34]` | `[60, 40]` | `[−6]` | **0** |
| C | abandon ↑ + δ 10 % | `[60, 35, 28]` | `[60, 41, 35]` | `[−6, −7]` | **−2** |

**Lecture :** la baisse du redoublement **réduit** les effectifs dans les
trois cas, mais l'effet sur les sortants varie (`−2`, `0`) : aucun signe
favorable n'est supposé. Une baisse alimentée par le passage produirait un
effet aval de signe opposé — ce cas n'est pas revendiqué par SIM-10.

---

## C.8 Cas limites vérifiés

| Cas limite | Résultat attendu |
|---|---|
| Somme du jeu modifié exactement 1 | Acceptée (cas A, C) |
| Somme du jeu modifié < 1 | Acceptée (cas B), le reste n'est pas réinjecté |
| Horizon 1 | Une seule période comparée (cas B) |
| Redoublement égal | Refusé (« strictement inférieur ») |
| Redoublement supérieur | Refusé (« strictement inférieur ») |
| `taux_modifie` absent | Aucune comparaison produite (rétrocompatibilité) |
