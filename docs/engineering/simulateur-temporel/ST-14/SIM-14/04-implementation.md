# SIM-14 — 04 · Implémentation

**Scénario :** Blocage en L1
**Aucune duplication :** réutilise `taux_par_niveau` (déjà présent), `analyserNiveaux()`, `analyserReussite()`, `sortants_par_periode`.

---

## A. Fichiers réels modifiés / créés

| Fichier | Action | Rôle |
|---|---|---|
| `Support/CatalogueScenarios.php` | modifié | `sim14()`, `schemaSim14()`, `regleTauxParNiveau()`, `validerReglesSim14()`, ajout aux définitions, retrait de `planifies()` |
| `tests/Feature/SimulationTemporelleSim14Test.php` | créé | 5 tests / 64 assertions, références calculées à la main |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | modifié | compteurs 18 → 20 disponibles, 27 → 25 planifiés, SIM-14 à l'index 13 |

## B. Absence de duplication

- Le moteur `ServiceSimulationTemporelle` n'est **pas modifié** par SIM-14 : `tauxParNiveau[$code] ?? $taux` existe déjà.
- Aucun nouveau service, contrôleur, route, permission ou table.
- La règle de blocage L1 vit dans le catalogue (`validerReglesSim14()`), pas dans le moteur.

## C. Non-duplication des formules

Les formules de flux (passage, redoublement, progression, sortants) sont celles du moteur (SIM-01 → SIM-13) et ne sont **pas recalculées** ni dupliquées pour SIM-14.
