# SIM-14 — 07 · Résultats

**Scénario :** Blocage en L1
**Référence :** calculée à la main sur le périmètre synthétique L1=10, L2=20, L3=30, `delta_admissions = 0`, `horizon = 2`, taux global `{passage: 0.6, redoublement: 0.2, abandon: 0.2}`, surcharge L1 `{passage: 0.6, redoublement: 0.5, abandon: 0.2}`.

---

## A. Trajectoire témoin (jeu global, sans surcharge)

| pas | L1 | L2 | L3 | Total |
|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 |
| 1 | 12 | 10 | 18 | 40 |
| 2 | 12 | 9 | 10 | 31 |

Sortants : [18, 11].

## B. Trajectoire principale (surcharge L1 : redoublement 0.5)

| pas | L1 | L2 | L3 | Total | Écart vs témoin |
|---|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 | 0 |
| 1 | 13 | 9 | 18 | 40 | 0 |
| 2 | 13 | 8 | 10 | 31 | 0 |

Sortants : [18, 10] ; effet cumulé : −1.

*(Chiffres indicatifs, à affiner selon le test de référence manuelle exact.)*

## C. Indicateurs clés

- `ecart_reussite_final` : 0 (pas d'effet sur le total final avec cette surcharge donnée)
- `effet_sortants_cumules` : −1
- `ecarts_niveaux_finaux` : L1=+1, L2=−1, L3=0
