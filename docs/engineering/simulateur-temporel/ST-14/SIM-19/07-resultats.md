# SIM-19 — 07 · Résultats

**Scénario :** Rééquilibrage des niveaux
**Référence :** calculée à la main sur le périmètre synthétique L1=10, L2=20, L3=30, `delta_admissions = 0`, `horizon = 2`, taux global `{passage: 0.6, redoublement: 0.2, abandon: 0.2}`, surcharges L1 `{passage: 0.7, redoublement: 0.2, abandon: 0.1}` (passage ↑) et L2 `{passage: 0.5, redoublement: 0.3, abandon: 0.2}` (passage ↓ — directions opposées).

## A. Trajectoire témoin (jeu global, sans surcharge)

| pas | L1 | L2 | L3 | Total |
|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 |
| 1 | 12 | 10 | 18 | 40 |
| 2 | 12 | 9 | 10 | 31 |

Sortants : [18, 11].

## B. Trajectoire principale (L1 passage ↑, L2 passage ↓)

| pas | L1 | L2 | L3 | Total | Écart vs témoin |
|---|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 | 0 |
| 1 | 12 | 13 | 16 | 41 | +1 |
| 2 | 12 | 12 | 10 | 34 | +3 |

Sortants : [18, 10] ; effet cumulé : −1. Écart final : +3.

## C. Indicateurs clés

- `ecarts_par_niveau` : L1 écart 0, L2 écart +3, L3 écart −2 (pas 1).
- `ecarts_niveaux_finaux` : L2 +3.
- `effet_sortants_cumules` : −1.
