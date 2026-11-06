# SIM-16 — 07 · Résultats

**Scénario :** Flux déséquilibrés entre niveaux
**Référence :** calculée à la main sur le périmètre synthétique L1=10, L2=20, L3=30, `delta_admissions = 0`, `horizon = 2`, taux global `{passage: 0.6, redoublement: 0.2, abandon: 0.2}`, surcharges sur ≥ 2 niveaux avec ≥ 2 triplets distincts.

## A. Trajectoire témoin (jeu global, sans surcharge)

| pas | L1 | L2 | L3 | Total |
|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 |
| 1 | 12 | 10 | 18 | 40 |
| 2 | 12 | 9 | 10 | 31 |

Sortants : [18, 11].

## B. Trajectoire principale (surcharges multi-niveaux)

Les écarts par niveau (`ecarts_par_niveau`) et les écarts finaux (`ecarts_niveaux_finaux`) mesurent le déséquilibre entre les niveaux surchargés et le témoin.

*(Chiffres à affiner selon le test de référence manuelle exact.)*

## C. Indicateurs clés

- `ecarts_par_niveau` : effet de chaque surcharge par niveau et par pas.
- `ecarts_niveaux_finaux` : effet final par niveau.
- `sortants_par_periode` / `effet_sortants_cumules` : impact sur les sortants.
