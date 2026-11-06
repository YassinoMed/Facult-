# SIM-17 — 04 · Implémentation

**Scénario :** Cohorte exceptionnelle
**Aucune duplication des formules :** lecture de la partition du moteur agrégé.

## A. Fichiers réels modifiés / créés

| Fichier | Action | Rôle |
|---|---|---|
| `Services/ServiceSimulationTemporelle.php` | modifié | `VERSION_MOTEUR = 2.0`, `repartirIdentites()`, `analyserCohortes()`, trajectoire témoin sans cohortes, OAT/MC propagent `$cohortesIdentifiees` |
| `Support/CatalogueScenarios.php` | modifié | `schemaSim17()`, `regleCohortesIdentifiees()`, `validerCohortesIdentifiees()`, `validerReglesSim17()`, `sim17()`, retrait de `planifies()` |
| `tests/Feature/SimulationTemporelleSim17Test.php` | créé | 5 tests, références calculées à la main (conservation, suivi, poids, coexistence) |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | modifié | compteurs 20/25, SIM-17 à l'index 17 (disponible) |

## B. Absence de duplication

- Les formules de flux restent celles du moteur (SIM-01 → SIM-13) : passage, redoublement, progression, sortants.
- L'identité de cohorte lit les `partsIdentifiees` produites par le moteur ; aucune seconde simulation, aucun taux recalculé.
- Aucun nouveau contrôleur, route, permission ou table.
