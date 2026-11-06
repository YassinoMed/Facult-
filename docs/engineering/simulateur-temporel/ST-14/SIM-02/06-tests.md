# SIM-02 — 06 · Tests et preuves

**Scénario :** SIM-02 — Baisse des admissions
**Suite principale :** `tests/Feature/SimulationTemporelleSim02Test.php`
**Suite catalogue :** `tests/Feature/SimulationTemporelleCatalogueTest.php`
**Résultat :** 7 tests / 56 assertions, 0 échec

---

## A. Vue d'ensemble

```
tests/Feature/SimulationTemporelleSim02Test.php

  ✓ ✓ ✓ ✓ ✓ ✓ ✓   7 tests / 56 assertions / 0 échec
```

La suite teste quatre aspects :

1. **Formules** (tests E) — `admissions(pas n) = round(baseL1 × (1+delta)^n)`
   est respectée, avec référence calculée à la main.
2. **Déterminisme / graine** (tests B/D) — idempotence et reproductibilité.
3. **Capacité / groupes** (test E) — la simulation ne modifie aucun groupe réel.
4. **Sécurité / lecture seule** (tests F) — permissions, isolation facultaire,
   zéro écriture métier.

---

## B. Tests de formules (lecture seule, données synthétiques)

Données de test : `L1=10, L2=20, L3=30` (total 60), faculté Sciences (FST).

### `test_sim02_baisse_composee_avec_reference_calculee_independamment`

**Paramètres** : `delta = -0.1`, `bornes [-0.5, 0]`, `horizon = 3`,
`taux {passage: 0, redoublement: 1, abandon: 0}` (progression gelée).

**Référence calculée à la main** (hors moteur) :

```
p1 : round(10 × 0.9^1) = 9     → L1 19, total 69
p2 : round(10 × 0.9^2) = 8     → L1 27, total 77
p3 : round(10 × 0.9^3) = 7     → L1 34, total 84
```

**Assertions** :

- `totaux == [60, 69, 77, 84]`
- `admissions_par_periode == [[p1:9],[p2:8],[p3:7]]`
- décroissance stricte des admissions (`9 > 8 > 7`)
- état initial inchangé (`total_initial = 60`, `cohortes [L1:10, L2:20, L3:30]`)

**But** : prouver la formule de décroissance compos
et que le point de départ n'est **jamais** réécrit.

### `test_sim02_borne_minimale_et_admissions_jamais_negatives`

**Paramètres** : `delta = -0.5`, `horizon = 6`, taux gelés `[0, 1, 0]`.

**Référence à la main** :

```
p1..p6 : round(10 × 0.5^n) = 5, 3, 1, 1, 0, 0
totaux = [60, 65, 68, 69, 70, 70, 70]
```

**Assertions** :

- `totaux == [60, 65, 68, 69, 70, 70, 70]`
- `admissions == [5, 3, 1, 1, 0, 0]`
- `min(admissions) >= 0` (aucune admission négative)
- `count(admissions_par_periode) == 6` (exact horizon)

**But** : la borne `-0.5` ne produit ni population négative ni erreur.
Extinction par arrondi (pas 5–6) : ce n'est pas un gel explicite, c'est
l'extinction d'une trajectoire.

### `test_sim02_conservation_avec_taux_explicites`

**Paramètres** : `delta = -0.2`, `horizon = 2`, `taux [0.5, 0.3, 0.2]`.

**Référence à la main** :

```
p1 : admissions 8
     L1 = 3(redoublants)+8(admis) = 11 ; L2 = 10(du L1)+5(du L2) = 11 ; L3 = 9+10 = 19
     total = 41 ; conservation : 60 + 8 − 15 diplômés − 12 abandons = 41
p2 : admissions 6
     L1 = 9 ; L2 = 9 ; L3 = 12 ; total = 30
```

**Assertions** :

- `totaux == [60, 41, 30]`
- `admissions == [[p1:8],[p2:6]]`
- snapshots : `cohortes p1 = [L1:11, L2:11, L3:19]`, `cohortes p2 = [L1:9, L2:9, L3:12]`
- pour chaque snapshot : `total == Σ cohortes` et `cohortes >= 0`

**But** : l'invariant de conservation `total(n) = total(n-1) + admissions(n) − sorties(n)`,
vérifié sur les snapshots persistés.

---

## C. Tests de déterminisme et de graine

### `test_sim02_memes_entrees_idempotentes_et_graine_reproductible`

**Paramètres** : `delta = -0.1`, `horizon = 3`, mêmes entrées.

**Assertions** :

- Exécution 1 et 2 (graine `graine-fixe`) : mêmes `id` et mêmes `hash_resultat`
  → **idempotence** (pas de duplication : `ExecutionTemporelle::count() == 1`).
- Graine `graine-autre` : nouvel `id` (nouvelle exécution), mais la variante
  de référence (index 0) reste **strictement identique** → le delta saisi ne
  dépend pas de la graine.

**But** : l'isolation des scénarios et la reproductibilité exacte.

---

## D. Test de capacité et de non-modification des groupes

### `test_sim02_capacite_et_absence_de_modification_des_groupes`

**Paramètres** : `delta = -0.1`, `horizon = 3`, `niveau_id = L1`,
`taux [0, 1, 0]`. Groupe réel préexistant `SIM02-G1` (capacité 15).

**Logique attendue** : L1 évolue `10 → 19 → 27 → 34` ; avec capacité 15 :
`34 / 15 = 3 groupes théoriques`, `2 groupes en déficit`,
`places_manquantes = 19`, `depassement_capacite = true`.

**Assertions** :

- `total_final == 34` (restreint à L1 via `niveau_id`)
- `capacite_groupe_hypothese == false` (capacité issue d'un groupe réel)
- `capacite_groupe_reference == 15`
- `groupes_theoriques_requis_total == 3`, `deficit_groupes == 2`,
  `places_manquantes == 19`, `depassement_capacite == true`
- le groupe réel n'a **pas** été modifié : `capacite == 15`, `actif == true`

**But** : la simulation calcule des besoins hypothétiques sans toucher aux
groupes réels de la base.

---

## E. Tests de sécurité et de lecture seule

### `test_sim02_permissions_et_isolation_facultaire`

**Assertions** :

- Utilisateur `sansPermission` (backoffice sans `simulation.executer`) :
  `POST /admin/simulation-temporelle/executer` → **403 Forbidden**.
- Admin faculté B sur un scénario faculté A : **403 Forbidden** (jamais de
  fuite de contenu inter-facultés).
- `ExecutionTemporelle::count() == 0` — aucune trace écrite après rejet.

**But** : la grille de permissions existante (`simulation.view`,
`simulation.gerer`, `simulation.executer`) est suffisante ; SIM-02 n'exige
aucune permission nouvelle.

### `test_sim02_zero_ecriture_metier_et_tables_techniques_seules`

**Méthode** : empreinte complète d'un étudiant témoin avant exécution, puis
comparaison après.

**Assertions** :

- Comptages inchangés : `etudiants, inscriptions, groupes, facultes, niveaux, scenarios`
- Empreinte exacte du témoin conservée (`sameAttributes`)
- Seules les tables techniques du module sont écrites

**But** : la règle projet « données métier en lecture seule ».

---

## F. Tests du catalogue

`tests/Feature/SimulationTemporelleCatalogueTest.php` (SIM-02) :

| Test | Assertion |
|------|-----------|
| `test_sim02_est_un_scenario_de_baisse_stricte` | `schemaSim02()['taux']['requis'] == true` |
| `test_sim02_est_un_scenario_de_baisse_stricte` | `schemaSocle()['taux']['requis'] == false` (différence SOCLE vs SIM-01/SIM-02) |
| `test_sim02_est_un_scenario_de_baisse_stricte` | `validerParametres('SIM-02', ...) === $valides` sur le jeu valide |
| `test_sim02_est_un_scenario_de_baisse_stricte` | `assertRefuse` → `passage 0.9 + redoublement 0.2 + abandon 0.0 = 1.1 > 1.0001` rejette (`'> 1'`) |
| `test_sim02_est_un_scenario_de_baisse_stricte` | `assertRefuse` → champ inconnu `delta_admission` rejette (`'inconnu'`) |

**But** : le schéma SIM-02 publie bien `taux.requis = true` et rejette les
entrées hors contrat.

---

## G. Couverture globale du module

```
tests/Feature/
├── SimulationTemporelleSim01Test.php     → 52 tests / 312 assertions  (inchangé)
├── SimulationTemporelleSim02Test.php     →  7 tests /  56 assertions  (NOUVEAU)
├── SimulationTemporelleCatalogueTest.php → 13 tests / 307 assertions  (SIM-02 ajouté)
└── SimulationTemporelleTestCase.php      ← inchangé

Total module : 79 tests / 731 assertions / 0 échec
```

---

## H. Commandes de vérification

```bash
# SIM-02 uniquement
vendor/bin/phpunit --filter="SimulationTemporelleSim02Test"

# Catalogue (inclut SIM-02)
vendor/bin/phpunit --filter="SimulationTemporelleCatalogueTest"

# SIM-02 + catalogue combinés
vendor/bin/phpunit --filter="SimulationTemporelleSim02Test|SimulationTemporelleCatalogueTest"
  → 30 tests / 646 assertions / 0 échec

# Format + tests (per-file dirty)
vendor/bin/pint --test --dirty
```

---

*Document de référence : `docs/engineering/simulateur-temporel/ST-14/SIM-02/06-tests.md`*
*Version : 1.0 — figé après exécution des tests (79/731, 0 échec)*
