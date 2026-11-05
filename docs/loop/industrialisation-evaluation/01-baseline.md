# LOOP INDUSTRIALISATION ÉVALUATION — 01 · Baseline

**Date :** 2026-11-01 · SQLite `:memory:` · `php artisan test` · sorties réelles conservées en session.

## 1. État Git initial

- Branche `main`, **237 entrées** `git status --porcelain` (héritage LOOP 43-BIS IAM + EX-LIFECYCLE + travaux antérieurs) — **préservées intégralement**.
- Aucun commit/push/branche/reset/clean effectué.

## 2. Baseline chiffrée (avant campagnes)

| Suite | Passés | Ignorés | Assertions |
|---|---:|---:|---:|
| Notes & import multi & compostage & délibérations (6 suites) | 106/106 | 0 | 373 |
| Notes élargies (11 suites, après correction ANO-01) | 149/149 | 0 | 590 |
| Cycle de vie examens (EX-LIFECYCLE, 14 suites) | 159/163 | 4 (pcntl) | 656 |
| Évaluation élargie (7 suites) | 78/78 | 0 | 267 |
| `LoopCampagneAcquisExamenTest` (avant correction : **36 erreurs**) | 6/36 après correction | 30 documentés | 25 |

## 3. Corrections apportées dans cette campagne (techniques, additives)

| Fichier | Changement | Motif |
|---|---|---|
| `app/Modules/Evaluation/Models/NoteEpreuveHistorique.php` | Garde append-only (`updating`/`deleting` → `DomainException`) | ANO-01 : historique critique modifiable (P0-03/SEC-012) — 5 sites d'écriture créent seulement, vérifié par grep |
| `tests/Feature/LoopCampagneAcquisExamenTest.php` (non suivi, WIP antérieur) | Imports `Programme` → `Referentiel`, fixture `faculte_id` + `SemestreLmd`/`Niveau` nullables, `Group` conforme (code/nom/faculte/niveau), `Etudiant::create()` conventionnel | ANO-02 : fixtures incompatibles avec le schéma réel — baseline faussement rouge |

**Zéro suppression de ligne de code métier ; zéro écrasement sans lecture préalable.**

## 4. Environnements et contraintes

- Tests : SQLite `:memory:` (`RefreshDatabase`) — règle du dépôt respectée ; MySQL réel requis pour la preuve de concurrence multi-processus (tests `pcntl` ignorés localement, 4 occurrences documentées).
- Performance import observée : 100 lignes ≈ 0,09 s / 1000 lignes ≈ 0,74 s (FILE+JOB) — pic mémoire ~231 Mo.

## 5. Postes de la campagne restants

1. LOT notes : documenté (`02`) — états réels, flux, contrôles, D-11.
2. Sécurité/traçabilité : documentée (`03`) + tests gardes.
3. UX, cockpit, intégration, guides, verdict : livrables suivants.
