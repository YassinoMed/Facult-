# SIM-09 — 07 · Résultats mesurés

**Date :** 2026-11-05  
**Environnement :** SQLite `:memory:` + `RefreshDatabase` (suite locale)  
**Mode :** TESTING — données synthétiques, aucune donnée de production

---

## G.1 Sorties brutes

### Suite SIM-09 (créée)

```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":83,"duration_ms":1253}
```

### Catalogue (adapté)

```json
{"tool":"phpunit","result":"passed","tests":20,"passed":20,"assertions":485,"duration_ms":1426}
```

### Suite module complète (non-régression)

```json
{"tool":"phpunit","result":"passed","tests":136,"passed":136,"assertions":1397,"duration_ms":8398}
```

### Formatage

```json
{"tool":"pint","result":"passed"}
```

**Aucun incident :** Pint est passé du premier coup et les suites SIM-09,
catalogue et module sont passées intégralement sans correction
d'assertion.

---

## G.2 Réconciliation des compteurs

| Périmètre | Avant SIM-09 | Après SIM-09 | Delta |
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
| **SIM-09** | — | **7 / 83** | **+7 / +83** |
| Catalogue | 19 / 450 | **20 / 485** | **+1 / +35** |
| **Suite module** | **128 / 1279** | **136 / 1397** | **+8 / +118** |

Vérification arithmétique : `+7 +1 = +8` tests ; `+83 +35 = +118`
assertions. La suite module mesurée (136 / 1397) correspond exactement.

---

## G.3 Concordance avec les cas de référence

| Cas | Valeur attendue (hors moteur) | Valeur obtenue | Concordance |
|---|---|---|---|
| Cas A — totaux modifiés | `[60, 46, 39]` | identiques | ✅ exacte |
| Cas A — totaux référence | `[60, 40, 31]` | identiques | ✅ exacte |
| Cas A — sortants | `[18,13]` vs `[18,11]` | identiques | ✅ exacte |
| Cas A — écarts / final | `[+6,+8]` / `+8` | identiques | ✅ exacte |
| Cas A — effet sortants | `+2` | identique | ✅ exacte |
| Cas B — totaux | `[60,46]` vs `[60,40]` | identiques | ✅ exacte |
| Cas B — effet sortants | `0` | identique | ✅ exacte |
| Cas C — delta +10 % | admissions `[11,12]`, totaux `[60,47,41]` vs `[60,41,35]` | identiques | ✅ exacte |
| Cas C — écarts / effet | `[+6,+6]` / `+2` | identiques | ✅ exacte |
| OAT | passage base `0.6`, redoublement base `0.3` | identiques | ✅ exacte |

Les valeurs sont cohérentes avec la mécanique SIM-07/08 : une hausse du
redoublement **accumule** des effectifs (écarts positifs) et alimente le
pipeline aval (effet sortants positif à horizon 2).

---

## G.4 Preuves de non-régression

| Contrôle | Résultat |
|---|---|
| SOCLE / SIM-01 → SIM-08 | Inclus dans les 136/136 ; 0 échec |
| SIM-07 spécifiquement | Suite `SimulationTemporelleSim07Test` inchangée : 7/7 (inclus) |
| SIM-08 spécifiquement | Suite `SimulationTemporelleSim08Test` inchangée : 7/7 (inclus) |
| Aucune clé de réussite sans descripteur | Prouvé (test 5 : 11 `assertArrayNotHasKey`) |
| Alias passage absents en mode générique | Prouvé (test 1 : 2 `assertArrayNotHasKey`) |
| Exclusion mutuelle | Prouvée (test 4) |
| Exécutions historiques | Non rejouées, non réécrites |

---

## G.5 État des données et du dépôt

| Élément | Valeur mesurée |
|---|---|
| Exécutions créées par le test de lecture seule | 1 exécution + 3 snapshots (pas 0→2) |
| Écritures métier | **0** (compteurs + empreinte étudiant inchangés) |
| Git — commits créés | **0** |
| Git — entrées totales | **275** (274 + nouveau test SIM-09) |
| dont skills `replica-*` installés par le harnais | 11 (hors périmètre produit) |
| dont `config/cache.php` supprimé (hors SIM-09, constaté le 2026-11-05) | 1 — **toujours non touché** |
| Branche / push / PR | Aucun |

---

## G.6 Verdict des résultats

- **7/7** SIM-09, **20/20** catalogue, **136/136** module, **Pint OK**.
- **0** échec, **0** test ignoré sur les périmètres exécutés.
- **Aucune assertion affaiblie** : les deux origines de hausse
  (abandon↓ vs reste) produisent des effets différents — effet aval `+2`
  vs `0` — et sont assertées telles quelles ; aucune valeur ajustée pour
  verdir.
