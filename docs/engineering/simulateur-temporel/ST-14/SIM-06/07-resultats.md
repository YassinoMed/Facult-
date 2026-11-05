# SIM-06 — 07 · Résultats mesurés

**Date :** 2026-11-03  
**Environnement :** SQLite `:memory:` + `RefreshDatabase` (suite locale)  
**Mode :** TESTING — données synthétiques, aucune donnée de production

---

## G.1 Sorties brutes

### Suite SIM-06 (créée)

```json
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":87,"duration_ms":1303}
```

### Catalogue (adapté)

```json
{"tool":"phpunit","result":"passed","tests":17,"passed":17,"assertions":399,"duration_ms":1171}
```

### Suite module complète (non-régression)

```json
{"tool":"phpunit","result":"passed","tests":112,"passed":112,"assertions":1085,"duration_ms":7521}
```

### Formatage

```json
{"tool":"pint","result":"passed"}
```

> Premier passage Pint : corrections de style uniquement (`unary_operator_spaces`, `statement_indentation`, `single_quote`, `not_operator_with_successor_space`) — aucun changement sémantique, re-vérifié par la suite complète.

### Incident de test corrigé (sans affaiblissement)

Première exécution SIM-06 : **7/8** — l'assertion du facteur final attendait
`1.0`, la sérialisation JSON (sans zéro fraction) décode `1`. L'assertion a
été corrigée vers la valeur **réellement produite et documentée** (`1`),
sans toucher au moteur ni affaiblir le calcul. Exécution suivante : 8/8.

---

## G.2 Réconciliation des compteurs

| Périmètre | Avant SIM-06 | Après SIM-06 | Delta |
|---|---|---|---|
| Suite historique ST-11/ST-12 | 52 / 312 | 52 / 312 | 0 |
| SIM-01 | 7 / 56 | 7 / 56 | 0 |
| SIM-02 | 7 / 56 | 7 / 56 | 0 |
| SIM-03 | 7 / 53 | 7 / 53 | 0 |
| SIM-04 | 7 / 54 | 7 / 54 | 0 |
| SIM-05 | 7 / 68 | 7 / 68 | 0 |
| **SIM-06** | — | **8 / 87** | **+8 / +87** |
| Catalogue | 16 / 369 | **17 / 399** | **+1 / +30** |
| **Suite module** | **103 / 968** | **112 / 1085** | **+9 / +117** |

Vérification arithmétique : `+8 +1 = +9` tests ; `+87 +30 = +117`
assertions. La suite module mesurée (112 / 1085) correspond exactement.

---

## G.3 Concordance avec les cas de référence

| Cas | Valeur attendue (hors moteur) | Valeur obtenue | Concordance |
|---|---|---|---|
| Cas 1 — facteurs | `[0.9, 0.8, 0.9, 1]` | identiques | ✅ exacte |
| Cas 1 — admissions | `[9, 8, 9, 10]` | identiques | ✅ exacte |
| Cas 1 — totaux | `[60, 69, 77, 86, 96]` | identiques | ✅ exacte |
| Cas 2 — admissions | `[10, 10, 12, 15]` | identiques | ✅ exacte |
| Cas 2 — écarts / cumul | `[-1,-2,-1,0]`, −4 | identiques | ✅ exacte |
| Cas 3 — snapshots | `[11,11,19]` puis `[13,9,12]` | identiques | ✅ exacte |
| Cas 4 — admissions | `[0, 10]`, cumul −10 | identiques | ✅ exacte |
| Cas 5 — arrondi | `6` (round 5.5) | `6` | ✅ exacte |

Les valeurs flottantes des facteurs (`0.9`, `0.8`, `1.0`) ont été vérifiées
indépendamment avant écriture des assertions, y compris le comportement
JSON du zéro fraction.

---

## G.4 Preuves de non-régression

| Contrôle | Résultat |
|---|---|
| SOCLE / SIM-01 → SIM-05 | Inclus dans les 112/112 ; 0 échec |
| Aucune clé de récupération sans trio | Prouvé (test 7 : `assertArrayNotHasKey` ×4) |
| Admissions de fond sans trio | `[10, 10, 10, 10]`, totaux `[60,70,80,90,100]` |
| Exécutions historiques | Non rejouées, non réécrites |

---

## G.5 État des données et du dépôt

| Élément | Valeur mesurée |
|---|---|
| Exécutions créées par le test de lecture seule | 1 exécution + 5 snapshots (pas 0→4) |
| Écritures métier | **0** (compteurs + empreinte étudiant inchangés) |
| Git — commits créés | **0** |
| Git — entrées non suivies / modifiées | **260** (259 avant ; +1 test SIM-06) |
| Branche / push / PR | Aucun |

---

## G.6 Verdict des résultats

- **8/8** SIM-06, **17/17** catalogue, **112/112** module, **Pint OK**.
- **0** échec, **0** test ignoré sur les périmètres exécutés.
- **Aucune assertion affaiblie** : l'incident JSON a été corrigé vers la
  valeur réellement produite, avec la règle documentée ; les cas limites
  (creux total, frontière .5) sont assertés tels qu'ils se produisent.
