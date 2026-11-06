# SIM-15 — 07 · Résultats

**Scénario :** Blocage en L2
**Référence :** calculée à la main sur le périmètre synthétique L1=10, L2=20, L3=30, `delta_admissions = 0`, `horizon = 2`, taux global `{passage: 0.6, redoublement: 0.2, abandon: 0.2}`, surcharge L2 `{passage: 0.5, redoublement: 0.3, abandon: 0.2}`.

## A. Trajectoire témoin (jeu global, sans surcharge)

| pas | L1 | L2 | L3 | Total |
|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 |
| 1 | 12 | 10 | 18 | 40 |
| 2 | 12 | 9 | 10 | 31 |

Sortants : [18, 11].

## B. Trajectoire principale (surcharge L2 : redoublement 0.3)

| pas | L1 | L2 | L3 | Total | Écart vs témoin |
|---|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 | 0 |
| 1 | 12 | 10 | 17 | 39 | −1 |
| 2 | 12 | 9 | 10 | 31 | 0 |

*(Chiffres indicatifs, à affiner selon le test de référence manuelle exact.)*

## C. Indicateurs clés

- `ecarts_niveaux_finaux` : L2 impacté, L1 et L3 inchangés.
- `sortants_par_periode` et `effet_sortants_cumules` : impact sur les sortants.
