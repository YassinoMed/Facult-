# SIM-18 — 07 · Résultats

**Scénario :** Double génération
**Référence :** calculée à la main sur le périmètre synthétique L1=10, L2=20, L3=30, `delta_admissions = 0`, `horizon = 2`, taux global `{passage: 0.6, redoublement: 0.2, abandon: 0.2}`, C1=12 en L1 pas 1, C2=10 en L1 pas 2 (chaque effectif ≥ référence L1 = 10).

## A. Trajectoire témoin (sans cohortes)

| pas | L1 | L2 | L3 | Total |
|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 |
| 1 | 12 | 10 | 18 | 40 |
| 2 | 12 | 9 | 10 | 31 |

## B. Trajectoire principale (C1=12 pas 1, C2=10 pas 2)

| pas | L1 | L2 | L3 | Total | Écart vs témoin |
|---|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 | 0 |
| 1 | 24 | 10 | 18 | 52 | +12 |
| 2 | 25 | 16 | 10 | 51 | +20 |

Écart final : +20.

## C. Bilan de conservation (écart de conservation = 0 pour chaque cohorte)

- C1 : `12 = 10 (final: L1=3 + L2=7) + 0 (sortants) + 2 (autres sorties)`
- C2 : `10 = 10 (final: L1=10) + 0 (sortants) + 0 (autres sorties)`

## D. Coexistence mesurée

| pas | cohortes simultanées | niveaux en coexistence |
|---|---|---|
| 1 | 0 | — |
| 2 | 2 | L1 (C1+C2, effectif 13) |

- `duree_coexistence = 1`, `pic_coexistence = 2`.

## E. Indicateurs clés

- `ecart_cohortes_final` : +20.
- `poids_cohortes_par_periode` : part identifiée lue dans la partition.
