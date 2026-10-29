# LOOP GLOBAL 00 — État initial et protection

**Date :** 2026-10-25 · **Mode :** lecture seule — aucun stash, reset, checkout,
nettoyage, commit, push ou opération destructive effectué.

## 1. Racine réelle et dépôt

- **Racine :** `/Users/mohamedyassine/Desktop/PFE/inscription-etudiants` (dépôt Git confirmé).
- **Branche :** `feature/admin-modifier-etudiant` (suivie de `origin/`, en retard de 3 commits distants — jamais tirés).
- **HEAD :** `38ef149 feat(etudiants): modification administrative d'un étudiant depuis le registre`.
- **Autres branches locales :** `main`. Distantes : `origin/main`, `origin/fix/scrutin-ci`, `origin/feature/admin-modifier-etudiant`.
- **Stash :** aucun.

## 2. État Git de travail (référence de protection)

| Catégorie | Nombre |
|---|---|
| Fichiers modifiés non suivis en index (` M`) | 342 |
| Fichiers non suivis (`??`) | 399 |
| Fichiers ajoutés en index (`A `) | 2 |
| **Total fichiers en travail à préserver** | **743** |

L'arbre contient notamment le travail **non commité** des Lots 14–20
(SimulateurScenarios, PreventionDecrochage, AssuranceQualite, compétences du
référentiel) et leurs rapports. **Aucun de ces fichiers ne sera touched sans
vérification préalable de contenu.** `app/Modules/Referentiel/Models/Niveau.php`
est modifié depuis avant la série — préservé tel quel.

## 3. Versions réellement installées

| Composant | Version constatée |
|---|---|
| PHP (CLI) | 8.5.11 (NTS, arm64) |
| Laravel Framework | 13.33.0 (composer.lock) |
| Livewire | ^4.4 |
| Sanctum | ^4.0 |
| dompdf / openspout | ^3.1 / ^5.11 |
| Node | v26.0.0 |
| npm | 11.12.1 |
| `vendor/`, `node_modules/` | installés |

## 4. Environnement et base de données (sans secrets)

| Variable | Valeur |
|---|---|
| `APP_ENV` | **production** |
| `APP_DEBUG` | false |
| `DB_CONNECTION` | mysql (base `inscription_etudiants`) |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | database |
| Tests (`phpunit.xml`) | **SQLite `:memory:`** — isolation effective de la base réelle |

Conséquence : toute exécution de tests via `php artisan test` écrit
uniquement en mémoire ; la base MySQL de production n'est JAMAIS touchée par
les tests. Les migrations des lots récents ne sont PAS appliquées à MySQL.

## 5. Processus et environnements

- Aucun serveur de développement détecté comme lancé par cet agent ;
  l'inspection passe par `artisan` CLI uniquement.
- `composer` opérationnel (`vendor/` complet) ; `npm ls --depth=0`
  utilisable (`node_modules/` présent).
- `.phpunit.result.cache` présent (790 Ko) — cache de runner, sans impact.

## 6. État initial des tests (baselines documentées, sessions précédentes)

| Suite | Baseline connue (réexécutée ce jour) |
|---|---|
| Assistant IA (filtre AiAssistant*) | 111 tests / 356 assertions — PASS |
| Lot 14 Simulateur + Lot 15 Décrochage + Lot 16 AssuranceQualité | 66 tests / 322 assertions — PASS |
| Lot 20 Compétences (Référentiel) | 18 tests / 90 assertions — PASS |
| Référentiel/maquettes (10 suites ciblées) | 86 tests / 333 assertions — PASS |

La suite complète (542 fichiers, 4 748 méthodes de test) n'a PAS été
exécutée en entier à ce stade : exécution prévue en LOOP-GLOBAL-10
(régression) sur périmètre autorisé.

## 7. Rapports préexistants exploitables (non écrasés)

`docs/engineering/` compte 175 rapports, dont les inventaires et audits
directeurs réutilisables : `LOOP-00-AUDIT-DIRECTEUR-UNIREGISTRE.md`,
`LOOP-14-20-AUDIT-DIRECTEUR.md`, `INVENTAIRE_TACHES_ET_URLS.md`,
`admin-center-inventaire.md`, `centre-admin-loop-i-inventaire.md`,
`correspondance-loops.md`, `AUDIT-SECURITE.md` (docs/), `audit-securite-transversale-2026-10-12.md`.
Le dossier `docs/engineering/loop-global/` n'existait pas — il est créé par
cette mission.

## 8. Verdict LOOP-00

**ÉTABLI** — l'état initial est certain (racine, branche, HEAD, 743 fichiers
en travail, versions, isolation de tests confirmée). La mission peut passer
à l'inventaire (LOOP-01) et à la matrice (LOOP-02) en lecture seule, puis
aux exécutions de tests contrôlées. Aucune action destructive réalisée ;
aucun secret exposé (mots de passe/jetons absents de ce rapport).
