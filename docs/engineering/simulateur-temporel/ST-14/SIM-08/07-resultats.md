# SIM-08 — 07 · Résultats mesurés

**Date :** 2026-11-04  
**Environnement :** SQLite `:memory:` + `RefreshDatabase` (suite locale)  
**Mode :** TESTING — données synthétiques, aucune donnée de production

---

## G.1 Sorties brutes

### Suite SIM-08 (créée)

```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":73,"duration_ms":1561}
```

### Catalogue (adapté)

```json
{"tool":"phpunit","result":"passed","tests":19,"passed":19,"assertions":450,"duration_ms":1627}
```

### Suite module complète (non-régression)

```json
{"tool":"phpunit","result":"passed","tests":128,"passed":128,"assertions":1279,"duration_ms":9983}
```

### Formatage

```json
{"tool":"pint","result":"passed"}
```

**Aucun incident :** Pint est passé du premier coup et les suites SIM-08,
catalogue et module sont passées intégralement sans correction
d'assertion.

---

## G.2 Réconciliation des compteurs

| Périmètre | Avant SIM-08 | Après SIM-08 | Delta |
|---|---|---|---|
| Suite historique ST-11/ST-12 | 52 / 312 | 52 / 312 | 0 |
| SIM-01 | 7 / 56 | 7 / 56 | 0 |
| SIM-02 | 7 / 56 | 7 / 56 | 0 |
| SIM-03 | 7 / 53 | 7 / 53 | 0 |
| SIM-04 | 7 / 54 | 7 / 54 | 0 |
| SIM-05 | 7 / 68 | 7 / 68 | 0 |
| SIM-06 | 8 / 87 | 8 / 87 | 0 |
| SIM-07 | 7 / 70 | 7 / 70 | 0 |
| **SIM-08** | — | **7 / 73** | **+7 / +73** |
| Catalogue | 18 / 424 | **19 / 450** | **+1 / +26** |
| **Suite module** | **120 / 1180** | **128 / 1279** | **+8 / +99** |

Vérification arithmétique : `+7 +1 = +8` tests ; `+73 +26 = +99`
assertions. La suite module mesurée (128 / 1279) correspond exactement.

---

## G.3 Concordance avec les cas de référence

| Cas | Valeur attendue (hors moteur) | Valeur obtenue | Concordance |
|---|---|---|---|
| Cas A — totaux dégradés | `[60, 43, 36]` | identiques | ✅ exacte |
| Cas A — totaux référence | `[60, 37, 28]` | identiques | ✅ exacte |
| Cas A — sortants | `[15,10]` vs `[21,12]` | identiques | ✅ exacte |
| Cas A — écarts / final | `[+6,+8]` / `+8` | identiques | ✅ exacte |
| Cas B — totaux | `[60,37]` vs `[60,43]` | identiques | ✅ exacte |
| Cas B — effet sortants | `−6` | identique | ✅ exacte |
| Cas C — delta +10 % | admissions `[11,12]`, écarts `[+6,+8]` | identiques | ✅ exacte |
| OAT | passage base `0.5`, redoublement base `0.3` | identiques | ✅ exacte |

Valeurs **miroir exact** du cas A de SIM-07 (rôles inversés) :
`[60,37,28]`/`[21,12]` ↔ `[60,43,36]`/`[15,10]`.

---

## G.4 Preuves de non-régression

| Contrôle | Résultat |
|---|---|
| SOCLE / SIM-01 → SIM-07 | Inclus dans les 128/128 ; 0 échec |
| SIM-07 spécifiquement | Suite `SimulationTemporelleSim07Test` inchangée : 7/7 (inclus) |
| Aucune clé de réussite sans `taux_degrade` | Prouvé (test 5 : `assertArrayNotHasKey` ×7) |
| `hausse_passage` absent en mode dégradé | Prouvé (test 1) |
| Exclusion mutuelle | Prouvé (test 4) |
| Exécutions historiques | Non rejouées, non réécrites |

---

## G.5 État des données et du dépôt

| Élément | Valeur mesurée |
|---|---|
| Exécutions créées par le test de lecture seule | 1 exécution + 3 snapshots (pas 0→2) |
| Écritures métier | **0** (compteurs + empreinte étudiant inchangés) |
| Git — commits créés | **0** |
| Git — entrées totales | **274** |
| dont skills `replica-*` installés par le harnais | 11 (hors périmètre produit) |
| dont nouveau test SIM-08 | 1 |
| dont `config/cache.php` supprimé (hors SIM-08, constaté le 2026-11-04) | 1 — **toujours non touché** |
| Branche / push / PR | Aucun |

---

## G.6 Verdict des résultats

- **7/7** SIM-08, **19/19** catalogue, **128/128** module, **Pint OK**.
- **0** échec, **0** test ignoré sur les périmètres exécutés.
- **Aucune assertion affaiblie** : les deux origines de baisse
  (redoublement↑ vs reste) produisent des effets opposés et sont assertées
  telles quelles ; aucune valeur ajustée pour verdir.
