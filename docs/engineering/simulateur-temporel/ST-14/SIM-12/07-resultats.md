# SIM-12 — 07 · Résultats mesurés

**Date :** 2026-11-05  
**Environnement :** SQLite `:memory:` + `RefreshDatabase` (suite locale)  
**Mode :** TESTING — données synthétiques, aucune donnée de production

---

## G.1 Sorties brutes

### Suite SIM-12 (créée)

```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":88,"duration_ms":1419}
```

### Catalogue (adapté)

```json
{"tool":"phpunit","result":"passed","tests":23,"passed":23,"assertions":590,"duration_ms":1383}
```

### Suite module complète (non-régression)

```json
{"tool":"phpunit","result":"passed","tests":160,"passed":160,"assertions":1763,"duration_ms":10134}
```

### Formatage

```json
{"tool":"pint","result":"passed"}
```

---

## G.2 Absence d'incident de premier passage

Contrairement à SIM-11, SIM-12 a passé sa suite **du premier coup** :
**7/7 · 88 assertions**. Aucun écart de calcul manuel n'a été détecté, aucune
valeur attendue n'a été corrigée après exécution, aucune assertion n'a été
modifiée, supprimée ou relâchée.

Les points d'arrondi sensibles avaient été vérifiés avant écriture —
notamment `13 × 0.3 = 3.9 → 4`, `12 × 0.3 = 3.6 → 4`, `13 × 0.7 = 9.1 → 9`
et `11 × 0.7 = 7.7 → 8`.

---

## G.3 Réconciliation des compteurs

| Périmètre | Avant SIM-12 | Après SIM-12 | Delta |
|---|---|---|---|
| **SIM-12** | — | **7 / 88** | **+7 / +88** |
| Catalogue | 22 / 555 | **23 / 590** | **+1 / +35** |
| Autres suites du module (SOCLE, SIM-01 → SIM-11, ST-11/ST-12) | 130 / 1085 | 130 / 1085 | **0 / 0** |
| **Suite module** | **152 / 1640** | **160 / 1763** | **+8 / +123** |

Vérification arithmétique : `+7 +1 = +8` tests ; `+88 +35 = +123`
assertions. La suite module mesurée (160 / 1763) correspond exactement ;
les autres suites sont strictement inchangées.

---

## G.4 Concordance avec les cas de référence

| Cas | Valeur attendue (hors moteur) | Valeur obtenue | Concordance |
|---|---|---|---|
| Cas A — totaux modifiés | `[60, 46, 39]` | identiques | ✅ exacte |
| Cas A — totaux référence | `[60, 40, 31]` | identiques | ✅ exacte |
| Cas A — sortants | `[18,13]` vs `[18,11]` | identiques | ✅ exacte |
| Cas A — écarts / final | `[+6,+8]` / `+8` | identiques | ✅ exacte |
| Cas A — effet sortants | `+2` | identique | ✅ exacte |
| Cas B — totaux | `[60,40,31]` vs `[60,40,31]` | identiques | ✅ exacte |
| Cas B — écarts / effet | `[0,0]` / `0` | identiques | ✅ exacte |
| Cas C — delta +10 % | admissions `[11,12]`, totaux `[60,44,38]` vs `[60,41,35]` | identiques | ✅ exacte |
| Cas C — sortants | `[21,14]` vs `[18,11]` | identiques | ✅ exacte |
| Cas C — écarts / final | `[+3,+3]` / `+3` | identiques | ✅ exacte |
| Cas C — effet sortants | `+6` | identique | ✅ exacte |
| OAT | passage base `0.6`, redoublement base `0.3` | identiques | ✅ exacte |
| OAT — abandon | **aucune ligne `taux_abandon`** | absente | ✅ exacte |
| Monte-Carlo | 50 itérations, `taux_absents = false` | identiques | ✅ exacte |

Les valeurs sont cohérentes avec la mécanique du moteur :

- cas A : la baisse `abandon 0.2 → 0.1` est financée par
  `redoublement 0.2 → 0.3` → effectifs et sortants **accrus** (`+2`) ;
- cas B : `passage` et `redoublement` inchangés → trajectoires
  **strictement identiques**, effet `0` ;
- cas C : la baisse est financée par `passage 0.6 → 0.7`, avec δ = +10 % →
  effectifs `[60,44,38]`, sortants `[21,14]`, effet `+6`.

---

## G.5 Preuves de non-régression

| Contrôle | Résultat |
|---|---|
| SOCLE / SIM-01 → SIM-10 | Inclus dans les 160/160 ; 0 échec |
| SIM-11 spécifiquement | Suite `SimulationTemporelleSim11Test` inchangée : 7/7 (incluse) |
| SIM-10 spécifiquement | Suite `SimulationTemporelleSim10Test` inchangée : 7/7 (incluse) |
| SIM-09 spécifiquement | Suite `SimulationTemporelleSim09Test` inchangée : 7/7 (incluse) |
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
| Git — entrées totales | **278** |
| dont fichiers suivis modifiés | 66 |
| dont fichiers suivis supprimés | 1 (`config/cache.php`, hors périmètre, non touché) |
| dont entrées non suivies | 211 |
| dont test SIM-12 nouvellement créé | 1 |
| dont dossier `ST-14/` (déjà compté comme une entrée) | 1 (les 10 livrables SIM-12 y sont inclus) |
| Branche / push / PR | Aucun |

**Note de comptage :** le passage de 277 à 278 entrées correspond au seul
fichier `tests/Feature/SimulationTemporelleSim12Test.php`. Le dossier
`docs/engineering/simulateur-temporel/ST-14/` est déjà compté comme **une**
entrée non suivie par `git status --porcelain` (mode par défaut, sans
`-uall`) ; les dix livrables SIM-12 n'ajoutent donc aucune ligne
supplémentaire. Aucune entrée n'a été supprimée ni déplacée.

---

## G.7 Verdict des résultats

- **7/7** SIM-12, **23/23** catalogue, **160/160** module, **Pint OK**.
- **0** échec, **0** test ignoré sur les périmètres exécutés.
- **Aucune assertion affaiblie** : aucune correction post-exécution n'a été
  nécessaire ; le cas B prouve la neutralité exacte ; le cas A prouve un
  effet aval positif ; le cas C prouve un effet `+6` supérieur au seul écart
  de stock `+3`.
- Aucun incident de premier passage à documenter pour SIM-12.
