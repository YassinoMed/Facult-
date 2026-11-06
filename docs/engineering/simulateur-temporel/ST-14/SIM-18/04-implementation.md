# SIM-18 — 04 · Implémentation

**Scénario :** Double génération
**Aucune duplication des formules :** lecture de la partition du moteur agrégé.

## A. Fichiers réels modifiés / créés

| Fichier | Action | Rôle |
|---|---|---|
| `Services/ServiceSimulationTemporelle.php` | modifié | `repartirIdentites()`, `analyserCohortes()`, trajectoire témoin, OAT/MC propagent `$cohortesIdentifiees` (livré SIM-17/18) |
| `Support/CatalogueScenarios.php` | modifié | `schemaSim18()`, `validerCohortesIdentifiees()`, `validerReglesSim18()`, `sim18()`, dépendance SIM-17, retrait de `planifies()` |
| `tests/Feature/SimulationTemporelleSim18Test.php` | créé | 5 tests, références calculées à la main (conservation, coexistence, suivi, poids) |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | modifié | compteurs 20/25, SIM-18 à l'index 18 (disponible) |

## B. Absence de duplication

- Les formules de flux restent celles du moteur (SIM-01 → SIM-13).
- L'identité de cohorte lit les `partsIdentifiees` produites par le moteur ; aucune seconde simulation.
- Aucun nouveau contrôleur, route, permission ou table.
