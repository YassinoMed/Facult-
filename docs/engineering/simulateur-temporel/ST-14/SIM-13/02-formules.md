# SIM-13 — 02 · Formules et références mathématiques

**Scénario :** SIM-13 — Crise académique combinée
**Formules :** version 1.0 (réutilisées du moteur, aucune modification)

---

## A. Aucune formule nouvelle

SIM-13 ne définit aucune formule : la progression est **exactement** celle du
moteur v1.8, avec le jeu de taux modifié `{passage', redoublement', abandon'}`.

```text
admissions(pas n)   = round(baseL1 × (1 + delta_admissions)^n)
partants(niveau)    = round(effectif × passage')
redoublants(niveau) = round(effectif × redoublement')
L1' = redoublants(L1) + admissions
L2' = redoublants(L2) + partants(L1)
L3' = redoublants(L3) + partants(L2)
sortants = partants(L3)          (diplômés)
```

`abandon'` n'est pas réinjecté : la part absorbée par le slack
(`1 − passage' − redoublement'`) est neutre.

---

## B. Références vérifiées indépendamment (calculées à la main)

Base synthétique : `L1=10, L2=20, L3=30` (total 60), `delta = 0`, `H = 2`,
admissions `[10, 10]`. Référence `{0.6, 0.2, 0.2}`.

### Cas A — focal redoublement↑, modifié `{0.5, 0.3, 0.2}`

| pas | modifié | référence | écart |
|---|---|---|---|
| 0 | 60 | 60 | — |
| 1 | 43 (L1 13, L2 11, L3 19) | 40 (L1 12, L2 10, L3 18) | +3 |
| 2 | 36 (L1 14, L2 10, L3 12) | 31 (L1 12, L2 9, L3 10) | +5 |

- Sortants : modifié `[15, 10]` vs référence `[18, 11]`.
- `effet_sortants_cumules = (15+10) − (18+11) = −4`.
- `hausse_redoublement = 0.3 − 0.2 = 0.1` (magnitude positive).

### Cas B — focal abandon↑, modifié `{0.4, 0.3, 0.3}` (3 strictes)

| pas | modifié | référence | écart |
|---|---|---|---|
| 1 | 40 (L1 13, L2 10, L3 17) | 40 (L1 12, L2 10, L3 18) | 0 |
| 2 | 31 (L1 14, L2 8, L3 9) | 31 (L1 12, L2 9, L3 10) | 0 |

- Sortants : modifié `[12, 7]` vs `[18, 11]` → `effet_sortants_cumules = −10`.
- **Démonstration** : les dégradations combinées ne sont pas additives — les
  écarts de totaux s'annulent `[0, 0]` alors que les sortants chutent.
- `hausse_abandon = 0.1` — mais aucun effet causal isolé n'est attribué à
  l'abandon (non réinjecté) ; l'effet provient des mouvements joints.

### Cas C — admissions non nulles, `delta = +0.10` → `[11, 12]`

| pas | modifié `{0.5,0.3,0.2}` | référence `{0.6,0.2,0.2}` | écart |
|---|---|---|---|
| 1 | 44 (L1 14, L2 11, L3 19) | 41 (L1 13, L2 10, L3 18) | +3 |
| 2 | 38 (L1 16, L2 10, L3 12) | 35 (L1 15, L2 10, L3 10) | +3 |

- Admissions identiques `[11, 12]` dans les deux trajectoires.
- `effet_sortants_cumules = −4`.

**Toutes ces valeurs sont assertées dans les tests SIM-13 (calcul manuel
dans les commentaires, aucune réutilisation de la formule dans les
assertions).**

---

## C. Règles arithmétiques vérifiées

- `passage' + redoublement' + abandon' ≤ 1.0001` (moteur + catalogue).
- `partants + redoublants ≤ effectif` (jamais de population créée).
- Arrondi au plus proche (`round`) : half-up, cohérent avec le reste du
  moteur.
- Aucune admission négative (`baseL1 ≥ 0`).

---

## D. Absence de décision institutionnelle

Les taux d'exemple, la base synthétique et les seuils de test sont des choix
de modélisation. Aucune valeur de cette fiche ne constitue une règle
institutionnelle validée.
