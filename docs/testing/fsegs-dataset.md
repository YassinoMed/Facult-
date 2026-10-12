# Dataset FSEGS — Faculté des Sciences Économiques et de Gestion

Jeu de test réaliste et fictif : **≈ 5 000 étudiants** (tolérance 4 800–5 200),
3 formations × 3 niveaux, **162 groupes**, inscriptions + affectations via
le vrai moteur (`AffectationService`), cas verrouillés/contraints/spéciaux.

## Commandes

```bash
php artisan fsegs:seed-dataset            # normal (~5 000)
php artisan fsegs:seed-dataset --load=2    # charge (~10 000, mêmes règles)
php artisan db:seed --class=FsegsDatasetSeeder
php artisan fsegs:validate-dataset        # intégrité (PASS/FAIL par règle)
```

Local/testing uniquement. Idempotent (clés naturelles, relançable),
déterministe (seed `20261001`, capacités par formule). Transaction globale.

## Structure

`config/fsegs-dataset.php` (volumes) → `FsegsDatasetSeeder` :
faculté → année 2026-2027 (réutilisée) → programmes + niveaux →
groupes (`PREFIXE-Lx-Gnn`, capacités 24–35) → étudiants (bulk 500,
`FSEGnnnnnn` / `FSEGS2026nnnnn` / `etudiantnnnnn@fsegs.example`) →
inscriptions (bulk, 80 % `en_cours` / 20 % `valide`) →
pré-affectations moteur (15 %, groupes remplis en ordre → déséquilibre
voulu, dernier groupe ~60 %) + verrouillées (5 %) + contraintes réelles
(ajournés, suspendus) → 12 `TEST-AFFECT-xxx` → rapport.

Seules les colonnes existantes sont remplies (pas de `nom_ar`/`lieu` :
absents du schéma). Redoublements : inscription 2025-2026 en plus.

## Cas testables

- Équilibrage : premiers groupes pleins, derniers partiels (MOVE/SWAP).
- Conflits : `GROUP_FULL` (groupes pleins), `LEVEL/PROGRAM_MISMATCH`,
  `REGISTRATION_*`, `ETUDIANT_SUSPENDU`, verrouillées non déplaçables.
- Recherche : homonymes déterministes, matricules indexables, scope FSEGS.
- Non affectés : ~75 % libres + raisons moteur explicites.

## Correction dataset (prompt master, 02/10/2026)

Constat : simulation DEMO-GL2-A → 0 proposée / 3 947 bloquées.
Cause (données, pas moteur) : chaîne démo fac-1 (programme DEMO-LGL)
vs population FSEGS → `PROGRAM_MISMATCH` massif + anciens sans
inscription 2026-2027 → `REGISTRATION_NOT_FOUND`. Règles inchangées.
Corrections : `ContinuiteInscriptionsSeeder` (réinscriptions 2026-2027 : continuants L1/L2/L3/M1/M2 + jamais-inscrits hors TEST-*, D1/rejetés exclus, idempotent) + `GroupesNiveauxManquantsSeeder` (L3-INFO-A, M1-INFO-A, M2-INFO-A ; D1 volontairement sans groupe), pré-check
`AffectationService::precheck()` (zéro éligible → pas d'algorithme),
pistes correctives sur les 3 écrans, TEST mapping (`TEST-AFFECT-*`).
Mesuré : ancienne faculté 23/26 proposés ; Gestion-L2 (650 × 22) → 526
proposées en 0,11 s. Détail : `docs/assignments/simulation.md`.

## Extension charge

`--load=N` multiplie groupes et effectifs (effectifs plafonnés par
Σ capacités, jamais de dépassement). Mêmes règles, mêmes codes.

## Fichiers

`config/fsegs-dataset.php`, `database/seeders/FsegsDatasetSeeder.php`,
`app/Console/Commands/{SeedFsegsDataset,ValidateFsegsDataset}.php`,
`tests/Feature/FsegsDatasetTest.php` (config réduite : structure,
validation, idempotence, moteur sans écriture).
