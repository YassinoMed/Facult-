# SIM-17 — 07 · Résultats

**Scénario :** Cohorte exceptionnelle
**Référence :** calculée à la main sur le périmètre synthétique L1=10, L2=20, L3=30, `delta_admissions = 0`, `horizon = 2`, taux global `{passage: 0.6, redoublement: 0.2, abandon: 0.2}`, cohorte C1=5 entre en L1 au pas 1 (effectif 5 ≠ référence L1 = 10).

## A. Trajectoire témoin (sans cohorte)

| pas | L1 | L2 | L3 | Total |
|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 |
| 1 | 12 | 10 | 18 | 40 |
| 2 | 12 | 9 | 10 | 31 |

## B. Trajectoire principale (C1 = 5 en L1, pas 1)

| pas | L1 | L2 | L3 | Total | Écart vs témoin |
|---|---|---|---|---|---|
| 0 | 10 | 20 | 30 | 60 | 0 |
| 1 | 17 | 10 | 18 | 45 | +5 |
| 2 | 13 | 12 | 10 | 35 | +4 |

Écart final : +4.

## C. Bilan de conservation (C1)

`effectif_entree = effectif_final + sortants + autres_sorties` → `5 = 4 + 0 + 1` (écart de conservation = 0) :
- `effectif_final` : L1=1 + L2=3 = 4
- `sortants` : 0 (C1 n'atteint pas le dernier niveau en 2 pas)
- `autres_sorties` : 1

## D. Indicateurs clés

- `ecart_cohortes_final` : +4 (simulé − témoin).
- `duree_coexistence = 0`, `pic_coexistence = 0` (une seule cohorte).
- `poids_cohortes_par_periode` : part identifiée lue dans la partition (pas 1 : 5/45 ≈ 0.1111 ; pas 2 : 4/35 ≈ 0.1143).
