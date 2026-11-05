# SIM-05 — 07 · Résultats mesurés

**Date :** 2026-11-03  
**Environnement :** SQLite `:memory:` + `RefreshDatabase` (suite locale)  
**Mode :** TESTING — données synthétiques, aucune donnée de production

---

## G.1 Sorties brutes

### Suite SIM-05 (créée)

```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":68,"duration_ms":1287}
```

### Catalogue (adapté)

```json
{"tool":"phpunit","result":"passed","tests":16,"passed":16,"assertions":369,"duration_ms":1258}
```

### Suite module complète (non-régression)

```json
{"tool":"phpunit","result":"passed","tests":103,"passed":103,"assertions":968,"duration_ms":7939}
```

### Formatage

```json
{"tool":"pint","result":"passed"}
```

> Premier passage Pint : `{"tool":"pint","result":"fixed","files":[{"path":"app\/Modules\/SimulationTemporelle\/Support\/CatalogueScenarios.php","fixers":["single_quote","unary_operator_spaces","not_operator_with_successor_space"]}]}` — corrections de style uniquement, aucun changement sémantique. Second passage : `passed`.

---

## G.2 Réconciliation des compteurs

| Périmètre | Avant SIM-05 | Après SIM-05 | Delta |
|---|---|---|---|
| Suite historique ST-11/ST-12 | 52 tests / 312 assertions | 52 / 312 | 0 |
| SIM-01 | 7 / 56 | 7 / 56 | 0 |
| SIM-02 | 7 / 56 | 7 / 56 | 0 |
| SIM-03 | 7 / 53 | 7 / 53 | 0 |
| SIM-04 | 7 / 54 | 7 / 54 | 0 |
| **SIM-05** | — | **7 / 68** | **+7 / +68** |
| Catalogue | 15 / 345 | **16 / 369** | **+1 / +24** |
| **Suite module** | **95 / 876** | **103 / 968** | **+8 / +92** |

Vérification arithmétique : `+7 +1 = +8` tests ; `+68 +24 = +92`
assertions. La suite module mesurée (103 / 968) correspond exactement.

---

## G.3 Concordance avec les cas de référence

| Cas | Valeur attendue (hors moteur) | Valeur obtenue | Concordance |
|---|---|---|---|
| Cas 1 — réelles | `[11, 12, 13]` | `[11, 12, 13]` | ✅ exacte |
| Cas 1 — cibles | `[11, 11, 12]` | `[11, 11, 12]` | ✅ exacte |
| Cas 1 — totaux | `[60, 71, 83, 96]` | `[60, 71, 83, 96]` | ✅ exacte |
| Cas 2 — écarts | `[1, 2, 4]`, cumul 7 | `[1, 2, 4]`, cumul 7 | ✅ exacte |
| Cas 3 — snapshots | `[14,11,19]` puis `[16,10,12]` | identiques | ✅ exacte |
| Cas 4 — écart | 0, dépassement faux | 0, dépassement faux | ✅ exacte |

Aucune divergence d'arrondi : les valeurs PHP `round(10 × 1.05) = 11` et
`round(10 × 1.1025) = 11` ont été vérifiées indépendamment avant écriture des
assertions.

---

## G.4 Preuves de non-régression

| Contrôle | Résultat |
|---|---|
| SOCLE / SIM-01 → SIM-04 | Inclus dans les 103/103 ; 0 échec |
| Aucune clé de cible ajoutée sans `taux_cible_croissance` | Prouvé (test 6 : `assertArrayNotHasKey` ×4) |
| Totaux identiques avec / sans cible (mêmes critères) | Prouvé : `[60, 71, 83, 96]` dans les deux cas |
| Exécutions historiques | Non rejouées, non réécrites |

---

## G.5 État des données et du dépôt

| Élément | Valeur mesurée |
|---|---|
| Exécutions créées par le test de lecture seule | 1 exécution + 4 snapshots (pas 0→3) |
| Écritures métier | **0** (compteurs + empreinte étudiant inchangés) |
| Git — commits créés | **0** |
| Git — entrées non suivies / modifiées | **259** (258 avant ; +1 test SIM-05) |
| Branche / push / PR | Aucun |

---

## G.6 Verdict des résultats

- **7/7** SIM-05, **16/16** catalogue, **103/103** module, **Pint OK**.
- **0** échec, **0** test ignoré sur les périmètres exécutés.
- **0** assertion affaiblie : les cas limites (écart nul, arrondi annulé)
  sont assertés tels qu'ils se produisent, jamais contournés.
