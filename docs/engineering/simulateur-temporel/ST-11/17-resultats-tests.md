# ST-11 — 17 · Résultats de tests (chiffres exacts)

**Environnement :** SQLite `:memory:` · `php artisan test` · 2026-11-02 · sorties réelles.

**Résultat définitif de la suite exécutée : 52 tests / 52 réussis / 0 échec / 312 assertions.** Le décompte 51/305 est conservé ci-dessous comme état historique de ST-11, avant l'ajout du test UX de ST-12 ; il est remplacé par le résultat définitif.

## Évolution de la suite

| Étape | Tests | Passés | Échoués | Assertions |
|---|---:|---:|---:|---:|
| Baseline (avant ST-11) | 47 | 47 | 0 | 224 |
| Après P0-1 (permissions) — acteurs habilités IAM | 50 | 50 | 0 | 293 |
| Après P0-2 (preuve 10 tables) | 50 | 50 | 0 | 300 |
| Après P1-1 — état ST-11 (historique) | 51 | 51 | 0 | 305 |
| Après ST-12 (UX-014) — **résultat définitif** | **52** | **52** | **0** | **312** |

## Non-régression transverse (module vérifié après les corrections)

| Périmètre | Résultat |
|---|---|
| Suites notes/évaluation (257 tests, campagne précédente) | re-vérifiées lors de la campagne industrialisation — non régressées |
| `php -l` sur les 4 fichiers PHP modifiés | aucune erreur |
| `vendor/bin/pint --test --dirty` | OK (vérifié 2026-11-02) |
| Routes du module enregistrées | 16 |

## Référence ST-10

Le résultat historique ST-10 (64 tests / 342 assertions) est conservé comme référence antérieure. **Les périmètres et filtres diffèrent** : toute comparaison directe serait trompeuse. Seuls les résultats des suites et filtres effectivement exécutés sont opposables ; ils ne constituent pas une preuve de couverture exhaustive de tous les chemins de production.

## Lecture honnête

- Aucune assertion réduite, aucun test supprimé pour obtenir le vert.
- Les 4 tests existants touchés par P0-1 ont été **adaptés** (habilitations IAM réelles via `HabilitationIam` — le chemin de gouvernance des permissions), pas affaiblis.
- Les 30 tests ignorés « Fixtures FST » (autre suite, campagne antérieure) ne concernent pas ce module.
- Preuve multi-processus (concurrence réelle MySQL) : hors environnement local (pcntl) — réserve R-06, commune aux campagnes précédentes.
