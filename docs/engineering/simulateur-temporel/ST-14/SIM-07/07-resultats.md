# SIM-07 — 07 · Résultats mesurés

**Date :** 2026-11-04  
**Environnement :** SQLite `:memory:` + `RefreshDatabase` (suite locale)  
**Mode :** TESTING — données synthétiques, aucune donnée de production

---

## G.1 Sorties brutes

### Suite SIM-07 (créée)

```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":70,"duration_ms":1242}
```

### Catalogue (adapté)

```json
{"tool":"phpunit","result":"passed","tests":18,"passed":18,"assertions":424,"duration_ms":1335}
```

### Suite module complète (non-régression)

```json
{"tool":"phpunit","result":"passed","tests":120,"passed":120,"assertions":1180,"duration_ms":7578}
```

### Formatage

```json
{"tool":"pint","result":"passed"}
```

> Premier passage Pint : corrections de style uniquement (`unary_operator_spaces`, `not_operator_with_successor_space`, `phpdoc_align`) — aucun changement sémantique, re-vérifié par la suite complète.

**Aucun incident de test :** la suite SIM-07 est passée **7/7 au premier
essai**, sans correction d'assertion.

---

## G.2 Réconciliation des compteurs

| Périmètre | Avant SIM-07 | Après SIM-07 | Delta |
|---|---|---|---|
| Suite historique ST-11/ST-12 | 52 / 312 | 52 / 312 | 0 |
| SIM-01 | 7 / 56 | 7 / 56 | 0 |
| SIM-02 | 7 / 56 | 7 / 56 | 0 |
| SIM-03 | 7 / 53 | 7 / 53 | 0 |
| SIM-04 | 7 / 54 | 7 / 54 | 0 |
| SIM-05 | 7 / 68 | 7 / 68 | 0 |
| SIM-06 | 8 / 87 | 8 / 87 | 0 |
| **SIM-07** | — | **7 / 70** | **+7 / +70** |
| Catalogue | 17 / 399 | **18 / 424** | **+1 / +25** |
| **Suite module** | **112 / 1085** | **120 / 1180** | **+8 / +95** |

Vérification arithmétique : `+7 +1 = +8` tests ; `+70 +25 = +95`
assertions. La suite module mesurée (120 / 1180) correspond exactement.

---

## G.3 Concordance avec les cas de référence

| Cas | Valeur attendue (hors moteur) | Valeur obtenue | Concordance |
|---|---|---|---|
| Cas A — totaux témoins | `[60, 43, 36]` | identiques | ✅ exacte |
| Cas A — totaux améliorés | `[60, 37, 28]` | identiques | ✅ exacte |
| Cas A — sortants | `[21,12]` vs `[15,10]` | identiques | ✅ exacte |
| Cas A — écarts / final | `[-6,-8]` / `-8` | identiques | ✅ exacte |
| Cas B — totaux | `[60,43]` vs `[60,37]` | identiques | ✅ exacte |
| Cas B — effet sortants | `+6` | identique | ✅ exacte |
| Cas C — delta +10 % | admissions `[11,12]`, écarts `[-6,-8]` | identiques | ✅ exacte |
| OAT | passage base `0.7`, redoublement base `0.1` | identiques | ✅ exacte |

Les valeurs ont été pré-calculées par un simulateur indépendant hors du
moteur (script PHP séparé, mêmes formules documentées) avant écriture des
assertions.

---

## G.4 Preuves de non-régression

| Contrôle | Résultat |
|---|---|
| SOCLE / SIM-01 → SIM-06 | Inclus dans les 120/120 ; 0 échec |
| Aucune clé de réussite sans `taux_ameliore` | Prouvé (test 5 : `assertArrayNotHasKey` ×6) |
| Trajectoire unique sans jeu amélioré | `[60, 43, 36]` — identique au témoin du cas A |
| Déstructuration à 2 éléments (appelants existants) | Inchangée : le 3ᵉ retour est ignoré sans erreur |
| Exécutions historiques | Non rejouées, non réécrites |

---

## G.5 État des données et du dépôt

| Élément | Valeur mesurée |
|---|---|
| Exécutions créées par le test de lecture seule | 1 exécution + 3 snapshots (pas 0→2) |
| Écritures métier | **0** (compteurs + empreinte étudiant inchangés) |
| Git — commits créés | **0** |
| Git — entrées totales | **273** |
| dont skills `replica-*` installés par le harnais | 11 (hors périmètre produit) |
| dont nouveau test SIM-07 | 1 |
| dont `config/cache.php` supprimé (hors SIM-07, constaté le 2026-11-04) | 1 — **non touché** |
| Branche / push / PR | Aucun |

> Le constat `config/cache.php` (suppression dans l'arbre de travail,
> horodatée 07:44) est **hors du périmètre SIM-07** : aucune modification
> n'a été apportée par ce LOOP. Il est signalé pour investigation par le
> propriétaire du dépôt.

---

## G.6 Verdict des résultats

- **7/7** SIM-07, **18/18** catalogue, **120/120** module, **Pint OK**.
- **0** échec, **0** test ignoré sur les périmètres exécutés.
- **Aucune assertion affaiblie** : les deux origines de hausse (redoublement
  vs reste) produisent des effets **opposés** et sont assertées telles
  quelles ; aucune valeur n'a été ajustée pour verdir.
