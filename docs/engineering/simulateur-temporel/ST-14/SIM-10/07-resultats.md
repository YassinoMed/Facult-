# SIM-10 — 07 · Résultats mesurés

**Date :** 2026-11-05  
**Environnement :** SQLite `:memory:` + `RefreshDatabase` (suite locale)  
**Mode :** TESTING — données synthétiques, aucune donnée de production

---

## G.1 Sorties brutes

### Suite SIM-10 (créée)

```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":85,"duration_ms":1267}
```

### Catalogue (adapté)

```json
{"tool":"phpunit","result":"passed","tests":21,"passed":21,"assertions":520,"duration_ms":1402}
```

### Suite module complète (non-régression)

```json
{"tool":"phpunit","result":"passed","tests":144,"passed":144,"assertions":1517,"duration_ms":9213}
```

### Formatage

```json
{"tool":"pint","result":"passed"}
```

**Aucun incident :** Pint est passé du premier coup et les suites SIM-10,
catalogue et module sont passées intégralement sans correction d'assertion.

---

## G.2 Réconciliation des compteurs

| Périmètre | Avant SIM-10 | Après SIM-10 | Delta |
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
| **SIM-10** | — | **7 / 85** | **+7 / +85** |
| Catalogue | 20 / 485 | **21 / 520** | **+1 / +35** |
| **Suite module** | **136 / 1397** | **144 / 1517** | **+8 / +120** |

Vérification arithmétique : `+7 +1 = +8` tests ; `+85 +35 = +120`
assertions. La suite module mesurée (144 / 1517) correspond exactement.

---

## G.3 Concordance avec les cas de référence

| Cas | Valeur attendue (hors moteur) | Valeur obtenue | Concordance |
|---|---|---|---|
| Cas A — totaux modifiés | `[60, 34, 26]` | identiques | ✅ exacte |
| Cas A — totaux référence | `[60, 40, 31]` | identiques | ✅ exacte |
| Cas A — sortants | `[18,9]` vs `[18,11]` | identiques | ✅ exacte |
| Cas A — écarts / final | `[-6,-5]` / `-5` | identiques | ✅ exacte |
| Cas A — effet sortants | `-2` | identique | ✅ exacte |
| Cas B — totaux | `[60,34]` vs `[60,40]` | identiques | ✅ exacte |
| Cas B — effet sortants | `0` | identique | ✅ exacte |
| Cas C — delta +10 % | admissions `[11,12]`, totaux `[60,35,28]` vs `[60,41,35]` | identiques | ✅ exacte |
| Cas C — écarts / effet | `[-6,-7]` / `-2` | identiques | ✅ exacte |
| OAT | passage base `0.6`, redoublement base `0.1` | identiques | ✅ exacte |
| Monte-Carlo | 50 itérations, `taux_absents = false` | identiques | ✅ exacte |

Les valeurs sont cohérentes avec la mécanique SIM-09 : une **baisse** du
redoublement **réduit** les effectifs (écarts négatifs) ; l'effet sur les
sortants dépend de l'origine déclarée (`-2` si l'abandon absorbe la baisse,
`0` si le reste l'absorbe à horizon 1).

---

## G.4 Preuves de non-régression

| Contrôle | Résultat |
|---|---|
| SOCLE / SIM-01 → SIM-09 | Inclus dans les 144/144 ; 0 échec |
| SIM-07 spécifiquement | Suite `SimulationTemporelleSim07Test` inchangée : 7/7 (inclus) |
| SIM-08 spécifiquement | Suite `SimulationTemporelleSim08Test` inchangée : 7/7 (inclus) |
| SIM-09 spécifiquement | Suite `SimulationTemporelleSim09Test` inchangée : 7/7 (inclus) |
| Aucune clé de réussite sans descripteur | Prouvé (test 5 : 11 `assertArrayNotHasKey`) |
| Alias passage absents en mode générique | Prouvé (test 1 : 4 `assertArrayNotHasKey`) |
| Exclusion mutuelle | Prouvée (test 4) |
| Exécutions historiques | Non rejouées, non réécrites |
| Version moteur | Inchangée `1.8` — aucun hash existant modifié |

---

## G.5 État des données et du dépôt

| Élément | Valeur mesurée |
|---|---|
| Exécutions créées par le test de lecture seule | 1 exécution + 3 snapshots (pas 0→2) |
| Écritures métier | **0** (compteurs + empreinte étudiant inchangés) |
| Git — commits créés | **0** |
| Git — entrées totales | **276** |
| dont fichiers suivis modifiés | 66 |
| dont fichiers suivis supprimés | 1 (`config/cache.php`, hors périmètre, non touché) |
| dont entrées non suivies | 209 |
| dont skills `replica-*` installés par le harnais | 11 (hors périmètre produit) |
| Branche / push / PR | Aucun |

---

## G.6 Verdict des résultats

- **7/7** SIM-10, **21/21** catalogue, **144/144** module, **Pint OK**.
- **0** échec, **0** test ignoré sur les périmètres exécutés.
- **Aucune assertion affaiblie** : les deux origines de baisse
  (abandon↑ vs reste) produisent des effets différents — effet aval `-2`
  vs `0` — et sont assertées telles quelles ; aucune valeur ajustée pour
  verdir.
