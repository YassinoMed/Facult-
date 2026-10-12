# LOOP 0 — Baseline de clôture (lecture seule)

Date : 12/10/2026 · Environnement : `local` · Base : MySQL `inscription_etudiants` (dev partagée).
Dépôt : **pas de git** dans ce workspace (`git status` indisponible) — aucun travail non commité à préserver localement ; coordination inter-agents via `AGENTS.md` + docs.

## État initial observé (tinker, lecture seule)

| Indicateur | Valeur |
|---|---|
| Établissements / Programmes / Niveaux / Semestres / UE | 10 / 4 / 12 / 24 / 7 |
| Matières (rattachées / standalone `ue_id NULL`) | 52 (14 / 38) |
| Maquettes | 1 : v1 DEMO-LGL, statut `review` |
| Maquettes actives | 0 |
| Inscriptions (total / sans `programme_id`) | 2099 / 2087 |
| Index `maquettes_active_programme_unique` | présent (1) |
| Migration garde `2026_10_12_000001` | appliquée (1) |

## Écarts vs derniers rapports

**Aucun** : chiffres identiques à la consolidation (§17–18). Pas de migration/activation sauvage entre les boucles.

## Commandes et tests exécutés (LOOP 0)

- Comptages tinker ci-dessus (lecture seule, 0 écriture).
- `ReferentielStatsBlocageTest|DashboardGraphe|Securite|Gouvernance` : **22/22 verts**.
- `SHOW INDEX` + table `migrations` : garde vérifiée présente.
- `docs/operations/disaster-recovery.md` : présent, relu (pas d'exercice exécuté ici).

## Risques

1. Base dev **partagée et mutable** (pas de git, autres agents actifs) — tout chiffre est daté, jamais garanti.
2. 0 maquette active : `inscrireEcue`/`genererEpreuves` refusent (comportement voulu, pas une panne).
3. Deadlock 1213 possible en activation concurrente → refus rejouable prouvé, pas de 500.
4. `?q=` portail en clair dans les access logs (recommandation ops en suspens).

## Opérations interdites à ce stade

Activation/approbation de maquette · rattachement standalone · attribution de programme aux 2087 · modification d'inscriptions historiques · envoi de notifications · déploiement prod / rotation secrets · suppression de journaux · tests destructifs sur dev partagée.

## Revérification (12/10, suite mission « go »)

Autre main : LOOP 5-8 clos (sécurité 41/41, +2 contrats consommateurs `MaquettePlanEcuesTest`, budgets perf, 235 tests, PASS_WITH_RESERVATIONS) — convergé sans conflit : 46 + 50 tests rejoués ici, 0 échec, réel `4/1/0/38` intact. Baseline inchangée, blocage humain maintenu.

## Revérification 2 (12/10, suite « continuez »)

Autre main : LOOP 4 technique (drill isolé PASS, concurrence re-prouvée, `production-readiness.md` à jour) — convergé : `migrate:status` = 142 Ran / 0 pending vérifié ici, réel `4/1/0/38` intact, 18 tests rejoués (14 verts + 4 skips MySQL documentés). Restent les BLOCKED institutionnels (ARB-001…009) + ops (rotation logs, secrets prod).
