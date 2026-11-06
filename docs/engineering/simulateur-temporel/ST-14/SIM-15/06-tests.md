# SIM-15 — 06 · Tests

**Scénario :** Blocage en L2
**Fichier de tests :** `tests/Feature/SimulationTemporelleSim15Test.php`
**Résultat :** **4 tests / 44 assertions**, 0 échec (premier passage).

## A. Couverture

| Cas | Description |
|---|---|
| 1 | Référence manuelle : totaux simulés vs référence, écarts, sortants |
| 2 | Refus définition : `taux_par_niveau` vide |
| 3 | Refus définition : triplet incomplet |
| 4 | Refus définition : taux hors [0,1], somme > 1, amélioration interdite, gel, choc |

Références calculées à la main sur le périmètre synthétique L1=10/L2=20/L3=30.

## B. Non-régression

- Historique SIM-01 → SIM-14 inchangé.
- Catalogue : 20 disponibles / 25 planifiés / 45 codes.
- `pint --dirty` : OK.
- `vendor/bin/phpunit --filter="SimulationTemporelle"` : 187 tests / 2098 assertions, 0 échec.
