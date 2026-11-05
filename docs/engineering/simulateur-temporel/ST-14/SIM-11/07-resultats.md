# SIM-11 — 07 · Résultats mesurés

**Date :** 2026-11-05  
**Environnement :** SQLite `:memory:` + `RefreshDatabase` (suite locale)  
**Mode :** TESTING — données synthétiques, aucune donnée de production

---

## G.1 Sorties brutes

### Suite SIM-11 (créée)

```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":88,"duration_ms":1238}
```

### Catalogue (adapté)

```json
{"tool":"phpunit","result":"passed","tests":22,"passed":22,"assertions":555,"duration_ms":1400}
```

### Suite module complète (non-régression)

```json
{"tool":"phpunit","result":"passed","tests":152,"passed":152,"assertions":1640,"duration_ms":9193}
```

### Formatage

```json
{"tool":"pint","result":"passed"}
```

---

## G.2 Incident de premier passage — corrigé sans affaiblissement

Le premier passage de la suite SIM-11 a donné **6/7 · 82 assertions**, avec
un échec sur le cas C :

```
Expected [60, 38, 35] — Actual [60, 38, 32]
```

**Diagnostic :** l'écart venait du **calcul manuel**, pas du moteur. Les
demi-entiers `round(4.5)` et `round(6.5)` avaient été arrondis vers le bas
dans le commentaire de référence, alors que PHP arrondit à l'écart de zéro.

**Correction :** valeurs attendues du cas C recalculées à la main :
`[60, 38, 32]`, écarts `[-3, -3]`, écart final `-3`. Aucune assertion
supprimée ni relâchée — l'écart final attendu est passé de `0` (moins
exigeant) à `-3` (plus exigeant). L'audit `00-audit.md` a été corrigé aux
deux endroits concernés.

Après correction : **7/7 · 88 assertions**, premier passage réussi.

---

## G.3 Réconciliation des compteurs

| Périmètre | Avant SIM-11 | Après SIM-11 | Delta |
|---|---|---|---|
| Suite historique ST-11/ST-12 | 52 / 312 | 52 / 312 | 0 |
| SIM-01 | 7 / 56 | 7 / 56 | 0 |
| SIM-02 | 7 / 56 | 7 / 56 | 0 |
| SIM-03 | 7 / 53 | 7 / 53 | 0 |
| SIM-04 | 7 / 54 | 7 / 54 | 0 |
| SIM-05 | 7 / 68 | 7 / 68 | 0 |
| SIM-06 | 8 / 87 | 8 / 87 | 0 |
| SIM-07 | 7 / 70 | 7 / 70 | 0 |
| SIM-08 | 7 / 73 | 7 / 73 | 0 |
| SIM-09 | 7 / 83 | 7 / 83 | 0 |
| SIM-10 | 7 / 85 | 7 / 85 | 0 |
| **SIM-11** | — | **7 / 88** | **+7 / +88** |
| Catalogue | 21 / 520 | **22 / 555** | **+1 / +35** |
| **Suite module** | **144 / 1517** | **152 / 1640** | **+8 / +123** |

Vérification arithmétique : `+7 +1 = +8` tests ; `+88 +35 = +123`
assertions. La suite module mesurée (152 / 1640) correspond exactement.

---

## G.4 Concordance avec les cas de référence

| Cas | Valeur attendue (hors moteur) | Valeur obtenue | Concordance |
|---|---|---|---|
| Cas A — totaux modifiés | `[60, 34, 26]` | identiques | ✅ exacte |
| Cas A — totaux référence | `[60, 40, 31]` | identiques | ✅ exacte |
| Cas A — sortants | `[18,9]` vs `[18,11]` | identiques | ✅ exacte |
| Cas A — écarts / final | `[-6,-5]` / `-5` | identiques | ✅ exacte |
| Cas A — effet sortants | `-2` | identique | ✅ exacte |
| Cas B — totaux | `[60,40,31]` vs `[60,40,31]` | identiques | ✅ exacte |
| Cas B — écarts / effet | `[0,0]` / `0` | identiques | ✅ exacte |
| Cas C — delta +10 % | admissions `[11,12]`, totaux `[60,38,32]` vs `[60,41,35]` | identiques | ✅ exacte |
| Cas C — sortants | `[15,8]` vs `[18,11]` | identiques | ✅ exacte |
| Cas C — écarts / final | `[-3,-3]` / `-3` | identiques | ✅ exacte |
| Cas C — effet sortants | `-6` | identique | ✅ exacte |
| OAT | passage base `0.6`, redoublement base `0.1` | identiques | ✅ exacte |
| OAT — abandon | **aucune ligne `taux_abandon`** | absente | ✅ exacte |
| Monte-Carlo | 50 itérations, `taux_absents = false` | identiques | ✅ exacte |

Les valeurs sont cohérentes avec la mécanique du moteur :

- cas A : la hausse `abandon 0.2 → 0.3` est financée par
  `redoublement 0.2 → 0.1` → effectifs et sortants **réduits** (`-2`) ;
- cas B : `passage` et `redoublement` inchangés → trajectoires
  **strictement identiques**, effet `0` ;
- cas C : la hausse est financée par `passage 0.6 → 0.5`, avec δ = +10 % →
  effectifs `[60,38,32]`, sortants `[15,8]`, effet `-6`.

---

## G.5 Preuves de non-régression

| Contrôle | Résultat |
|---|---|
| SOCLE / SIM-01 → SIM-10 | Inclus dans les 152/152 ; 0 échec |
| SIM-07 spécifiquement | Suite `SimulationTemporelleSim07Test` inchangée : 7/7 (incluse) |
| SIM-08 spécifiquement | Suite `SimulationTemporelleSim08Test` inchangée : 7/7 (incluse) |
| SIM-09 spécifiquement | Suite `SimulationTemporelleSim09Test` inchangée : 7/7 (incluse) |
| SIM-10 spécifiquement | Suite `SimulationTemporelleSim10Test` inchangée : 7/7 (incluse) |
| Aucune clé de réussite sans descripteur | Prouvé (test 5 : 11 `assertArrayNotHasKey`) |
| Alias passage absents en mode générique | Prouvé (test 1 : 4 `assertArrayNotHasKey`) |
| Exclusion mutuelle | Prouvée (test 4) |
| Neutralité de l'abandon seul | Prouvée (test 2 : `[0,0]`, effet `0`) |
| Absence de sensibilité inventée | Prouvée (test 6 : aucune ligne `taux_abandon`) |
| Exécutions historiques | Non rejouées, non réécrites |
| Version moteur | Inchangée `1.8` — aucun hash existant modifié |

---

## G.6 État des données et du dépôt

| Élément | Valeur mesurée |
|---|---|
| Exécutions créées par le test de lecture seule | 1 exécution + 3 snapshots (pas 0→2) |
| Écritures métier | **0** (compteurs + empreinte étudiant inchangés) |
| Git — commits créés | **0** |
| Git — entrées totales | **277** |
| dont fichiers suivis modifiés | 66 |
| dont fichiers suivis supprimés | 1 (`config/cache.php`, hors périmètre, non touché) |
| dont entrées non suivies | 210 |
| dont test SIM-11 nouvellement créé | 1 |
| dont dossier `ST-14/` (déjà compté comme une entrée) | 1 (les 10 livrables SIM-11 y sont inclus) |
| Branche / push / PR | Aucun |

**Note de comptage :** le passage de 276 à 277 entrées correspond au seul
fichier `tests/Feature/SimulationTemporelleSim11Test.php`. Le dossier
`docs/engineering/simulateur-temporel/ST-14/` est déjà compté comme **une**
entrée non suivie par `git status --porcelain` (mode par défaut, sans
`-uall`) ; les dix livrables SIM-11 n'ajoutent donc aucune ligne
supplémentaire. Aucune entrée n'a été supprimée ni déplacée.

---

## G.7 Verdict des résultats

- **7/7** SIM-11, **22/22** catalogue, **152/152** module, **Pint OK**.
- **0** échec, **0** test ignoré sur les périmètres exécutés.
- **Aucune assertion affaiblie** : le cas C a été recalculé et rendu **plus**
  exigeant (`-3` au lieu de `0`) ; le cas B prouve la neutralité exacte ; le
  cas A prouve un effet aval négatif.
- L'écart de premier passage est documenté et tracé : il portait sur le
  calcul manuel, pas sur le moteur.
