# SIM-14 — 06 · Tests

**Scénario :** Blocage en L1
**Fichier de tests :** `tests/Feature/SimulationTemporelleSim14Test.php`
**Résultat :** **5 tests / 64 assertions**, 0 échec (premier passage).

---

## A. Couverture

| Cas | Description |
|---|---|
| 1 | Référence manuelle : totaux simulés vs référence, écarts, sortants, effet |
| 2 | Niveau facultatif `niveau_focal` restitué |
| 3 | Refus définition : `taux_par_niveau` vide |
| 4 | Refus définition : triplet incomplet |
| 5 | Refus définition : taux hors [0,1] / somme > 1 / amélioration interdite / gel / choc |

Les résultats attendus (totaux, écarts, sortants) sont calculés **à la main** (hors moteur) sur le périmètre synthétique L1=10/L2=20/L3=30.

## B. Non-régression

- Historique SIM-01 → SIM-13 inchangé.
- Catalogue : compteurs passés de 18/27 à 20/25 disponibles/planifiés.
- `pint --dirty` : OK.
- `vendor/bin/phpunit --filter="SimulationTemporelle"` : 187 tests / 2098 assertions, 0 échec.
