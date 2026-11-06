# SIM-16 — 04 · Implémentation

**Scénario :** Flux déséquilibrés entre niveaux
**Aucune duplication :** réutilise `taux_par_niveau`, `analyserNiveaux()`, `analyserReussite()`.

## A. Fichiers réels modifiés / créés

| Fichier | Action | Rôle |
|---|---|---|
| `Support/CatalogueScenarios.php` | modifié | `sim16()`, `schemaSim16()`, `validerReglesSim16()`, dépendances SIM-14, SIM-15, retrait de `planifies()` |
| `tests/Feature/SimulationTemporelleSim16Test.php` | créé | 5 tests / 39 assertions, références calculées à la main |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | modifié | compteurs 20/25, SIM-16 à l'index 15 |

## B. Absence de duplication

Moteur non modifié. Aucun nouveau service, contrôleur, route, permission ou table. Règle de déséquilibre multi-niveaux dans le catalogue (`validerReglesSim16()`).

## C. Non-duplication des formules

Formules de flux du moteur (SIM-01 → SIM-13), non recalculées ni dupliquées.
