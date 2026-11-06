# SIM-19 — 06 · Tests

**Scénario :** Rééquilibrage des niveaux
**Fichier de tests :** `tests/Feature/SimulationTemporelleSim19Test.php`
**Résultat :** **5 tests / 39 assertions**, 0 échec (premier passage).

## A. Couverture

| Cas | Description |
|---|---|
| 1 | Référence manuelle : totaux [60, 41, 34] vs témoin [60, 40, 31], écarts [1, 3], final 3, sortants [18, 10] vs [18, 11], effet −1 |
| 2 | Niveau facultatif `niveau_focal` restitué avec écart cible |
| 3 | Refus définition : `taux_par_niveau` vide |
| 4 | Refus définition : triplet incomplet |
| 5 | Refus définition : taux hors [0,1], somme > 1, amélioration interdite, gel, choc |

Références calculées à la main sur le périmètre synthétique L1=10/L2=20/L3=30.

## B. Non-régression

- Historique SIM-01 → SIM-18 inchangé.
- Catalogue : 20 disponibles / 25 planifiés / 45 codes.
- `pint --dirty` : OK.
- `vendor/bin/phpunit --filter="SimulationTemporelle"` : 197 tests / 2183 assertions, 0 échec.
