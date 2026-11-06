# SIM-16 — 06 · Tests

**Scénario :** Flux déséquilibrés entre niveaux
**Fichier de tests :** `tests/Feature/SimulationTemporelleSim16Test.php`
**Résultat :** **5 tests / 39 assertions**, 0 échec (premier passage).

## A. Couverture

| Cas | Description |
|---|---|
| 1 | Référence manuelle : totaux simulés vs référence, écarts, sortants |
| 2 | Refus définition : un seul niveau surchargé |
| 3 | Refus définition : triplets identiques (pas de déséquilibre) |
| 4 | Refus définition : triplet incomplet / taux hors [0,1] / somme > 1 |
| 5 | Refus définition : gel, choc, cible, creux, descripteurs de réussite |

Références calculées à la main sur le périmètre synthétique L1=10/L2=20/L3=30.

## B. Non-régression

- Historique SIM-01 → SIM-15 inchangé.
- Catalogue : 20 disponibles / 25 planifiés / 45 codes.
- `pint --dirty` : OK.
- `vendor/bin/phpunit --filter="SimulationTemporelle"` : 187 tests / 2098 assertions, 0 échec.
