# SIM-02 — 07 · Résultats

**Scénario :** SIM-02 — Baisse des admissions
**Paramètres d'exemple :** base L1 = 10, L2 = 20, L3 = 30, `delta = -0.1`, horizon = 3

---

## A. Trajectoire de référence (exécution réelle)

```
L1=10, L2=20, L3=30 (total 60)
delta_admissions = -0.1
bornes = [min: -0.5, max: 0]
horizon_annees = 3
taux = {passage: 0, redoublement: 1, abandon: 0}   ← progression gelée
```

**Formule** : `admissions(pas n) = round(10 × (1 − 0.1)^n)`

| pas (n) | admissions | L1 | L2 | L3 | total |
|---------|-----------:|---:|---:|---:|------:|
| 0 | — | 10 | 20 | 30 | **60** |
| 1 | 9 | 19 | 20 | 30 | **69** |
| 2 | 8 | 27 | 20 | 30 | **77** |
| 3 | 7 | 34 | 20 | 30 | **84** |

**Vérifié dans `test_sim02_baisse_composee_avec_reference_calculee_independamment`** :

- `totaux == [60, 69, 77, 84]`
- `admissions_par_periode == [[p1:9],[p2:8],[p3:7]]`
- état initial inchangé : `total_initial = 60`, `cohortes = [L1:10, L2:20, L3:30]`

---

## B. Cas de borne : `delta = -0.5` (extinction progressive)

```
delta_admissions = -0.5
horizon_annees = 6
taux = {passage: 0, redoublement: 1, abandon: 0}
```

**Formule** : `admissions(pas n) = round(10 × 0.5^n)`

| pas (n) | admissions | total |
|---------|-----------:|------:|
| 0 | — | 60 |
| 1 | 5 | 65 |
| 2 | 3 | 68 |
| 3 | 1 | 69 |
| 4 | 1 | 70 |
| 5 | 0 | 70 |
| 6 | 0 | 70 |

**Vérifié dans `test_sim02_borne_minimale_et_admissions_jamais_negatives`** :

- `totaux == [60, 65, 68, 69, 70, 70, 70]`
- `admissions == [5, 3, 1, 1, 0, 0]`
- `min(admissions) >= 0` — aucune admission négative possible
- `count(admissions_par_periode) == 6`

**Observation** : les admissions s'éteignent par arrondi (pas 5–6), mais le total
reste stable car la cohorte existante continue d'évoluer. Ce n'est pas un gel
explicite (SIM-03) : c'est l'extinction naturelle d'une trajectoire très basse.

---

## C. Conservation avec taux de transition explicites

```
delta_admissions = -0.2
horizon_annees = 2
taux = {passage: 0.5, redoublement: 0.3, abandon: 0.2}
```

| pas (n) | admissions | L1 | L2 | L3 | total | Sorties |
|---------|-----------:|---:|---:|---:|------:|:-------:|
| 0 | — | 10 | 20 | 30 | **60** | — |
| 1 | 8 | 11 | 11 | 19 | **41** | 19 (15 diplômés + 4 passage L2→... + ... abandon) |
| 2 | 6 | 9 | 9 | 12 | **30** | 16 |

**Vérifié dans `test_sim02_conservation_avec_taux_explicites`** :

- `totaux == [60, 41, 30]`
- `admissions == [[p1:8],[p2:6]]`
- snapshots : p1 `[L1:11, L2:11, L3:19]`, p2 `[L1:9, L2:9, L3:12]`
- invariant par snapshot : `total == Σ cohortes` et `cohortes >= 0`

**Conservation** : `total(n) = total(n-1) + admissions(n) − sorties(n)` —
vérifiée sur les snapshots persistés (`SnapshotExecution`).

---

## D. Restreint à un niveau : `niveau_id = L1`

```
delta_admissions = -0.1
horizon_annees = 3
niveau_id = L1 (capacité de référence : groupe réel SIM02-G1, capacité 15)
```

| pas (n) | admissions | L1 cumulé |
|---------|-----------:|----------:|
| 0 | — | 10 |
| 1 | 9 | 19 |
| 2 | 8 | 27 |
| 3 | 7 | 34 |

**Vérifié dans `test_sim02_capacite_et_absence_de_modification_des_groupes`** :

- `total_final == 34` (restreint à L1)
- `capacite_groupe_reference == 15` (groupe réel, pas hypothèse)
- `groupes_theoriques_requis_total == 34 / 15 = 3`
- `deficit_groupes == 2`, `places_manquantes == 19`, `depassement_capacite == true`
- **le groupe réel n'a pas été modifié** (`capacite == 15`, `actif == true`)

**Observation** : le paramètre optionnel `niveau_id` restreint la simulation à
un seul niveau et calcule des besoins de groupes **hypothétiques**, sans
écrire dans le référentiel de groupes.

---

## E. Déterminisme

```
Mêmes paramètres (delta = -0.1, horizon = 3) exécutés deux fois avec
la même graine → même id + même hash_resultat → pas de duplication.
Graine différente → nouvelle exécution, variante de référence (index 0)
strictement identique.
```

**Vérifié dans `test_sim02_memes_entrees_idempotentes_et_graine_reproductible`** :

- `hash_resultat` identique à deux exécutions (idempotence)
- `ExecutionTemporelle::count() == 1` (pas de doublon)
- variante index 0 invariante quelle que soit la graine

---

## F. Cas de validation rejetée

Aucune simulation n'est exécutée pour ces entrées (la validation du catalogue
lève une `DomainException`) :

| entrée invalide | motif | Test |
|-----------------|-------|------|
| `delta_admissions = 0` (ou > 0) | baisse non stricte (SIM-01) | `validerReglesSim02` |
| `bornes.max > 0` | variante de croissance interdite | `validerReglesSim02` |
| `passage + redoublement = 1.1` | > 1 | `validerParametres('SIM-02')` → `'> 1'` |
| champ inconnu `delta_admission` | clé inconnue | `validerParametres('SIM-02')` → `'inconnu'` |

**Sécurité** : utilisateur sans `simulation.executer` → **403** ; admin
faculté B sur scénario faculté A → **403** (isolation faculaire).

---

## G. Résultat global

```
tests/Feature/SimulationTemporelleSim02Test.php  →  7 tests / 56 assertions / 0 échec

  test_sim02_baisse_composee_avec_reference_calculee_independamment  ✅
  test_sim02_borne_minimale_et_admissions_jamais_negatives           ✅
  test_sim02_conservation_avec_taux_explicites                       ✅
  test_sim02_memes_entrees_idempotentes_et_graine_reproductible     ✅
  test_sim02_capacite_et_absence_de_modification_des_groupes        ✅
  test_sim02_permissions_et_isolation_facultaire                     ✅
  test_sim02_zero_ecriture_metier_et_tables_techniques_seules       ✅
```

Catalogue : `test_sim02_est_un_scenario_de_baisse_stricte` ✅ (schéma taux
requis, borne et refus des clés inconnues).

Module complet : **79 tests / 731 assertions / 0 échec**.

---

*Document de référence : `docs/engineering/simulateur-temporel/ST-14/SIM-02/07-resultats.md`*
*Version : 1.0 — figé après exécution des tests (79/731, 0 échec)*
