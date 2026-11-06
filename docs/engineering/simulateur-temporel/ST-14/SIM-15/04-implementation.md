# SIM-15 — 04 · Implémentation

**Scénario :** Blocage en L2
**Aucune duplication :** réutilise `taux_par_niveau`, `analyserNiveaux()`, `analyserReussite()`.

## A. Fichiers réels modifiés / créés

| Fichier | Action | Rôle |
|---|---|---|
| `Support/CatalogueScenarios.php` | modifié | `sim15()`, `schemaSim15()`, `validerReglesSim15()`, dépend SIM-14, retrait de `planifies()` |
| `tests/Feature/SimulationTemporelleSim15Test.php` | créé | 4 tests / 44 assertions, références calculées à la main |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | modifié | compteurs 20/25, SIM-15 à l'index 14 |

## B. Absence de duplication

Moteur non modifié. Aucun nouveau service, contrôleur, route, permission ou table. Règle de blocage L2 dans le catalogue (`validerReglesSim15()`).

## C. Non-duplication des formules

Formules de flux du moteur (SIM-01 → SIM-13), non recalculées ni dupliquées.
