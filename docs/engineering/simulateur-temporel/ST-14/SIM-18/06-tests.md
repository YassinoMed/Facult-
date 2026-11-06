# SIM-18 — 06 · Tests

**Scénario :** Double génération
**Fichier de tests :** `tests/Feature/SimulationTemporelleSim18Test.php`
**Résultat :** **5 tests**, 0 échec (premier passage, avec SIM-17 : 10 tests / 69 assertions).

## A. Couverture

| Cas | Description |
|---|---|
| 1 | Référence manuelle : totaux [60, 52, 51] vs témoin [60, 40, 31], écarts [12, 20], final 20 |
| 2 | Bilan de conservation : C1 (12 = 10 + 0 + 2), C2 (10 = 10 + 0 + 0) |
| 3 | Coexistence mesurée : pas 2 en L1 (C1+C2), durée 1, pic 2 |
| 4 | Suivi par pas : identifiés + non identifiés = total du niveau |
| 5 | Refus définition : < 2 cohortes, pas identiques, niveaux distincts, effectif < référence |

## B. Non-régression

- Historique SIM-01 → SIM-17 inchangé.
- Catalogue : 20 disponibles / 25 planifiés / 45 codes.
- `pint --dirty` : OK.
- `vendor/bin/phpunit --filter="SimulationTemporelle"` : 197 tests / 2183 assertions, 0 échec.
