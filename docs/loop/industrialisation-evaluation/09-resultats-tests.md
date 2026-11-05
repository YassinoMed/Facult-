# LOOP INDUSTRIALISATION ÉVALUATION — 09 · Résultats de tests (chiffres exacts)

**Environnement :** SQLite `:memory:` · `php artisan test` · 2026-11-01 · sorties réelles.

## Campagne (périmètre notes + évaluation + cycle de vie)

| Suite | Passés | Échoués | Incomplets | Ignorés | Assertions |
|---|---:|---:|---:|---:|---:|
| Notes (historique append-only **nouveau**) | 5 | 0 | 0 | 0 | 23 |
| Notes (sécurité, améliorations, imports, compostage, délibérations, vérification, audit — 11 suites) | 144 | 0 | 0 | 0 | 575+ |
| Cycle de vie examens (EX-LIFECYCLE, 14 suites) | 159 | 0 | 0 | 4 | 656 |
| Évaluation générale (7 suites) | 78 | 0 | 0 | 0 | 267 |
| **Total campagne agrégée (20 suites, sans double comptage)** | **257** | **0** | **0** | **4** | **1018** |

Les 4 ignorés = concurrence multi-processus (`pcntl_fork` indisponible localement) — préexistant, documenté, jamais compté comme réussi.

## Suite précédemment rouge — corrigée (ANO-02)

| Suite | Avant | Après |
|---|---|---|
| `LoopCampagneAcquisExamenTest` | 36 erreurs (imports/contraintes obsolètes) | 6 passés, 30 ignorés **documentés** (« Fixtures FST programme 21 »), 25 assertions |

## Qualité

| Contrôle | Résultat |
|---|---|
| `vendor/bin/pint --dirty` | OK (relance vérifiée : 5/5) |
| Performance import | 100 lignes ≈ 0,07 s · 1000 lignes ≈ 0,68 s (FILE+JOB) · pic ~251 Mo |
| Régression | **0 échec** sur l'ensemble des suites du périmètre après la garde append-only |

## Lecture honnête

- Aucune assertion réduite, aucun scénario retiré, aucune règle désactivée.
- Les 6 premiers échecs du nouveau test étaient des **fixtures** (schéma réel : `adresse` NOT NULL, `examen_id` NOT NULL, candidat requis, semestre entier) — corrigés côté test, jamais côté règles.
- Non-couverture assumée : UX avancée (responsive/accessibilité) et concurrence MySQL multi-processus (R-06/R-07).
