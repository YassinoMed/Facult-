# SIM-04 — 06 · Stratégie et exécution des tests

**Scénario :** SIM-04 — Admissions exceptionnelles  
**Date d'exécution :** 2026-11-03  
**Suite :** PHPUnit / tests Feature Laravel  

---

## G.1 Matrice de couverture

| Méthode de test | Fichier | Objectif et assertion clé |
|---|---|---|
| `test_sim04_choc_ponctuel_exact_sans_effet_sur_la_trajectoire_de_fond` | `SimulationTemporelleSim04Test` | Référence manuelle : admissions `[10, 15, 10]`, totaux `[60, 70, 85, 95]` ; le pas 3 ne conserve pas le choc. |
| `test_sim04_choc_avec_flux_et_conservation_stricte_des_effectifs` | `SimulationTemporelleSim04Test` | Flux 0.5/0.3/0.2 : totaux `[60, 43, 41]`, snapshots `L1/L2/L3` exacts, somme des niveaux = total à chaque pas, aucun effectif négatif. |
| `test_sim04_definition_refuse_les_chocs_incomplets_ou_hors_horizon` | `SimulationTemporelleSim04Test` | `DomainException` sur paramètre isolé, pas = 4 > horizon 3, choc nul ; aucun scénario persisté après refus. |
| `test_sim04_memes_entrees_idempotentes_et_graine_reproductible` | `SimulationTemporelleSim04Test` | Même graine ⇒ même ID et même `hash_resultat`, 1 seule exécution ; autre graine ⇒ nouvelle exécution, variante 0 identique. |
| `test_sim04_capacite_et_besoin_de_groupes_au_pic` | `SimulationTemporelleSim04Test` | Périmètre L1, groupe de capacité 15 : sans choc 30 → 2 groupes / 1 déficit / 15 places ; avec choc 35 → 3 / 2 / 20 ; groupe réel intact. |
| `test_sim04_permissions_et_isolation_facultaire` | `SimulationTemporelleSim04Test` | `403` sans permission et `403` inter-faculté ; aucune exécution persistée. |
| `test_sim04_zero_ecriture_metier_et_tables_techniques_seules` | `SimulationTemporelleSim04Test` | Comptages et empreinte d'attributs identiques avant/après sur les tables officielles ; 1 exécution + 3 snapshots techniques pour $H = 2$. |
| `test_sim04_est_une_extension_temporelle_de_choc_ponctuel` | `SimulationTemporelleCatalogueTest` | Schéma publié, validation des bornes 1 et 3, rejets pas 0 / pas 4 / choc 0 / paramètre manquant / taux manquant. |

---

## G.2 Commandes exécutées et résultats bruts

```bash
php artisan test --filter="SimulationTemporelleSim04Test"
```

```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":54,"duration_ms":1547}
```

```bash
php artisan test --filter="SimulationTemporelleCatalogueTest"
```

```json
{"tool":"phpunit","result":"passed","tests":15,"passed":15,"assertions":345,"duration_ms":1495}
```

```bash
php artisan test --filter="SimulationTemporelle"
```

```json
{"tool":"phpunit","result":"passed","tests":95,"passed":95,"assertions":876,"duration_ms":8118}
```

Décomposition :

- suite historique ST-11/ST-12 : 52 tests / 312 assertions ;
- catalogue ST-14.00 + SIM-01→04 : 15 tests / 345 assertions ;
- SIM-01 : 7 tests / 56 assertions ;
- SIM-02 : 7 tests / 56 assertions ;
- SIM-03 : 7 tests / 53 assertions ;
- SIM-04 : 7 tests / 54 assertions ;
- **total : 95 tests / 876 assertions, 0 échec.**

---

## G.3 Formatage Pint

```bash
vendor/bin/pint --dirty
vendor/bin/pint --test app/Modules/SimulationTemporelle tests/Feature/SimulationTemporelleSim04Test.php tests/Feature/SimulationTemporelleCatalogueTest.php
```

Résultat : `{"tool":"pint","result":"passed"}` (après une passe de
normalisation sur `CatalogueScenarios.php`).

---

## G.4 Limites d'environnement

Les suites de concurrence (verrous `lockForUpdate`, `pcntl`) ne sont pas
exécutables sur SQLite en mémoire ; cette limite est déjà documentée dans le
registre ST-14 et n'est pas spécifique à SIM-04.
