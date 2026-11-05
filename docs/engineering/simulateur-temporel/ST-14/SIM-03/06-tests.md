# SIM-03 — 06 · Stratégie et exécution des tests

**Scénario :** SIM-03 — Gel des admissions  
**Date d'exécution :** 2026-11-02  
**Suite :** PHPUnit / Feature tests Laravel  

---

## G.1 Matrice de couverture des tests

| Méthode de test | Fichier | Objectif et assertion clé |
|---|---|---|
| `test_sim03_gel_total_sur_tout_l_horizon_avec_reference_calculee_independamment` | `SimulationTemporelleSim03Test` | Gel sur tout l'horizon (D=3, H=3) : admissions rigoureusement nulles `[0, 0, 0]`, totaux stables `[60, 60, 60, 60]`. |
| `test_sim03_gel_temporaire_avec_reprise_composee_post_gel` | `SimulationTemporelleSim03Test` | Gel de 2 ans puis reprise à +10 % sur horizon 4 : admissions `[0, 0, 11, 12]`, totaux `[60, 60, 60, 71, 83]`. |
| `test_sim03_gel_avec_flux_et_conservation_stricte_des_effectifs` | `SimulationTemporelleSim03Test` | Gel de 2 ans avec passage 0.5, redoublement 0.3, abandon 0.2. Décroissance des effectifs `[60, 33, 18]`, somme des cohortes = total à chaque pas. |
| `test_sim03_memes_entrees_idempotentes_et_graine_reproductible` | `SimulationTemporelleSim03Test` | Idempotence avec même graine (ID identique, hash_resultat identique) et cohérence de la variante de référence. |
| `test_sim03_capacite_et_diminution_de_saturation_par_gel` | `SimulationTemporelleSim03Test` | Allègement des besoins en groupes physiques sous l'effet du gel, non-altération du groupe réel en base. |
| `test_sim03_permissions_et_isolation_facultaire` | `SimulationTemporelleSim03Test` | 403 Forbidden sans droit et 403 Forbidden pour tentative inter-facultaire. |
| `test_sim03_zero_ecriture_metier_et_tables_techniques_seules` | `SimulationTemporelleSim03Test` | Contrôle d'intégrité avant/après sur toutes les tables officielles et vérification d'empreintes d'attributs. |
| `test_sim03_est_une_extension_temporelle_de_gel_strict` | `SimulationTemporelleCatalogueTest` | Validation du schéma, rejet si $D < 1$, rejet si $D > H$, rejet si $D$ manquant, rejet si taux manquants. |

---

## G.2 Commande exécutée et résultats bruts

```bash
php artisan test tests/Feature/SimulationTemporelleSim03Test.php --compact
```

**Sortie observée :**
```json
{"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":53,"duration_ms":1252}
```

**Exécution de la suite complète Simulation Temporelle :**
```bash
php artisan test tests/Feature/SimulationTemporelle* --compact
```

**Sortie observée :**
```json
{"tool":"phpunit","result":"passed","tests":87,"passed":87,"assertions":800,"duration_ms":6709}
```

- Suite historique ST-11/ST-12 : 52 tests / 312 assertions (PASS)
- Suite catalogue ST-14.00 : 14 tests / 323 assertions (PASS)
- Suite SIM-01 (croissance) : 7 tests / 56 assertions (PASS)
- Suite SIM-02 (baisse) : 7 tests / 56 assertions (PASS)
- Suite SIM-03 (gel) : 7 tests / 53 assertions (PASS)
- **Total : 87 tests, 800 assertions, 0 échec.**

---

## G.3 Formatage Pint

```bash
vendor/bin/pint --test app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php tests/Feature/SimulationTemporelleCatalogueTest.php tests/Feature/SimulationTemporelleSim03Test.php
```
Résultat : `{"tool":"pint","result":"passed"}`.

