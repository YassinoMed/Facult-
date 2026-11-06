# SIM-13 — 07 · Résultats

**Scénario :** SIM-13 — Crise académique combinée
**Base :** L1=10, L2=20, L3=30 (total 60), `delta = 0`, `H = 2`

---

## A. Trajectoire de référence (Cas A — focal redoublement↑)

```
Référence {0.6, 0.2, 0.2}   |   Modifié {0.5, 0.3, 0.2}   |   focal redoublement/hausse
```

| pas | admissions | Mod L1 | Mod L2 | Mod L3 | Mod total | Réf total | Écart |
|-----|-----------:|-------:|-------:|-------:|----------:|----------:|------:|
| 0 | — | 10 | 20 | 30 | **60** | 60 | — |
| 1 | 10 | 13 | 11 | 19 | **43** | 40 | +3 |
| 2 | 10 | 14 | 10 | 12 | **36** | 31 | +5 |

- Sortants : modifié `[15, 10]` vs référence `[18, 11]` → **effet cumulé −4**.
- `hausse_redoublement = 0.1` ; `ecart_reussite_final = +5`.
- Commande de référence : `test_sim13_crise_combinee_reference_calculee_independamment`.

---

## B. Non-additivité des effets combinés (Cas B — focal abandon↑, 3 strictes)

```
Modifié {0.4, 0.3, 0.3}   |   focal abandon/hausse
```

| pas | Mod total | Réf total | Écart | Mod sortants | Réf sortants |
|-----|----------:|----------:|------:|-------------:|-------------:|
| 0 | 60 | 60 | — | — | — |
| 1 | **40** | 40 | **0** | 12 | 18 |
| 2 | **31** | 31 | **0** | 7 | 11 |

**Observation majeure** : malgré trois dégradations simultanées, les écarts
de totaux **s'annulent** `[0, 0]` — tandis que les sortants chutent
(`effet_sortants_cumules = −10`). Les dégradations combinées ne sont pas
additives : baisse du passage et hausse du redoublement se compensent dans
la structure des effectifs, alors que l'effet sur les sortants reste
fortement négatif.

Aucun effet causal isolé n'est attribué à l'abandon (non réinjecté).

---

## C. Admissions identiques (Cas C — `delta = +0.10`)

| pas | admissions | Mod total | Réf total | Écart |
|-----|-----------:|----------:|----------:|------:|
| 0 | — | 60 | 60 | — |
| 1 | 11 | 44 | 41 | +3 |
| 2 | 12 | 38 | 35 | +3 |

- Les deux trajectoires partagent **strictement** les mêmes admissions.
- Effet sortants cumulé : `−4`.
- L'écart isole donc l'effet combiné des taux de flux.

---

## D. Cas de refus (aucune exécution)

| Entrée | Motif | Niveau |
|--------|-------|--------|
| Focal `abandon/baisse` | non dégradante | catalogue |
| `passage_mod 0.7 > 0.6` | amélioration | catalogue |
| Une seule strict (`passage↓` seul) | non combinée | catalogue |
| Somme modifiée 1.3 | > 1.0001 | moteur + catalogue |
| Focal non strict | descripteur | moteur |
| Gel / choc / cible / creux / alias | incompatibles | moteur + schéma |

---

## E. Déterminisme

- Même graine → **même** `id` et `hash_resultat` (zéro duplication).
- Graine différente → nouvelle exécution, variante 0 identique.
- Sans variation → aucune clé de réussite ; trajectoire unique `[60, 40, 31]`.

---

## F. Analyses autour du jeu modifié

- OAT : base `taux_passage = 0.5`, `taux_redoublement = 0.3` (modifiés).
- **Aucune** ligne `taux_abandon` : le moteur n'invente pas de sensibilité à
  un flux non réinjecté.
- Monte-Carlo : 50 itérations, taux complets (`taux_absents = false`).

---

## G. Résultat global des suites

```
SimulationTemporelleSim13Test.php      →   8 tests / 107 assertions / 0 échec
SimulationTemporelleCatalogueTest.php  →  23 tests / 598 assertions / 0 échec
Suite module complète                  → 168 tests / 1878 assertions / 0 échec
Pint --test --dirty                    → OK
```
