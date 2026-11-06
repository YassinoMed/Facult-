# SIM-19 — 04 · Implémentation

**Scénario :** Rééquilibrage des niveaux
**Aucune duplication :** réutilise `taux_par_niveau`, `analyserNiveaux()`, `analyserReussite()`.

## A. Fichiers réels modifiés / créés

| Fichier | Action | Rôle |
|---|---|---|
| `Support/CatalogueScenarios.php` | modifié | `sim19()`, `schemaSim19()`, `validerReglesSim19()`, dépendances SIM-14/15/16, retrait de `planifies()` |
| `tests/Feature/SimulationTemporelleSim19Test.php` | créé | 5 tests / 39 assertions, références calculées à la main |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | modifié | compteurs 20/25, SIM-19 à l'index 19 (disponible) |

## B. Absence de duplication

Moteur non modifié. Aucun nouveau service, contrôleur, route, permission ou table. Règle de rééquilibrage dans le catalogue (`validerReglesSim19()`).

## C. Non-duplication des formules

Formules de flux du moteur (SIM-01 → SIM-13), non recalculées ni dupliquées.
