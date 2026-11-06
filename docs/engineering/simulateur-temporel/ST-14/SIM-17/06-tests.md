# SIM-17 — 06 · Tests

**Scénario :** Cohorte exceptionnelle
**Fichier de tests :** `tests/Feature/SimulationTemporelleSim17Test.php`
**Résultat :** **5 tests**, 0 échec (premier passage, avec SIM-18 : 10 tests / 69 assertions).

## A. Couverture

| Cas | Description |
|---|---|
| 1 | Référence manuelle : totaux [60, 45, 35] vs témoin [60, 40, 31], écarts [5, 4], final 4 |
| 2 | Bilan de conservation : `effectif_entree = effectif_final + sortants + autres_sorties` (5 = 4 + 0 + 1) |
| 3 | Suivi par pas : identifiés + non identifiés = total du niveau |
| 4 | Poids de la cohorte par période (lu dans la partition) |
| 5 | Refus définition : liste vide, code dupliqué, effectif < 1, pas hors bornes, taux_par_niveau, gel, choc, niveaux distincts, génération complète |

## B. Non-régression

- Historique SIM-01 → SIM-16 inchangé.
- Catalogue : 20 disponibles / 25 planifiés / 45 codes.
- `pint --dirty` : OK.
- `vendor/bin/phpunit --filter="SimulationTemporelle"` : 197 tests / 2183 assertions, 0 échec.
