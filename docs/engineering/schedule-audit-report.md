# SCHEDULE-01 — Rapport de référence (audit initial du module Emplois du temps)

- Date : 2026-10-11. Boucle : SCHEDULE-01 (LAN uniquement — aucune modification applicative dans cette boucle).
- Périmètre : module `app/Modules/Schedule/` + routes portails (`/etudiant`, `/enseignant`) + API v1 (`/api/v1/schedules/import/*`) + intégrations (Attendance, Evaluation pour la surveillance).
- Environnement : pas de dépôt Git (traçabilité = docs + journaux) ; tests SQLite mémoire, prod MySQL.
- Règle : rien n'est marqué opérationnel sur la seule apparence du code ; les cas suspects sont des **hypothèses à prouver par test** dans les boucles suivantes.

## 1. Cartographie réelle (FAIT)

### 1.1 Code

| Couche | Fichiers | Points notables |
|---|---|---|
| Contrôleurs admin | `ScheduleController` (438 l.), `ScheduleExplorerController` (201), `ScheduleImportController` (131) | Contrôleur fin ; cycle de vie et conflits délégués aux services |
| Contrôleurs portails | `MonEmploiDuTempsController` (étudiant), `MonEmploiEnseignantController` (533 l.) | Ownership via session uniquement ; PDF enseignant throttlé |
| Services | `ScheduleService` (431), `ScheduleConflictService` (140), `ScheduleExplorerService` (367), `ScheduleImportService` (380), `ScheduleNotificationService` (70) | Verrous pessimistes ordonnés planning→enseignant→salle ; historique `ScheduleChange` ; événements post-commit |
| Modèles | `Schedule` (STATUTS brouillon/relecture/valide/publie/verrouille/archive + TRANSITIONS), `ScheduleSlot` (JOURS lundi→dimanche, `chevauche()` bornes strictes), `ScheduleException`, `ScheduleChange`, `ScheduleImport`, `Group`, `Room`, `Teacher` | Aucune table « course » dédiée : `Matiere` cœur réutilisé |
| Import | 4 importeurs (CSV/XLSX/JSON/XML) + `ScheduleImportValidator` + `ScheduleNormalizer` + `ImportResult` | Flux preview→confirm avec token, pas d'import direct |
| Événements | `ScheduleChanged` → `AuditSchedule` + `NotifierPlanning` (bindings `ModuleServiceProvider:319-320`) | Convention projet : notification après transaction |

### 1.2 Routes (FAIT — `app/Modules/Schedule/Routes/web.php`, `routes/web.php`, API)

- 26 routes admin (`/admin/emplois-du-temps/*`) : toutes sous `auth,admin,module:schedule` ; lecture `can:schedules.view`, écritures `can:schedules.manage` + `throttle` (10–30/min) ; segments fixes avant `{planning}` (anti-capture).
- Cycle de vie : `transiter/{cible}` avec `whereIn` strict (brouillon/relecture/valide/publie/verrouille) ; duplication avec prévisualisation obligatoire ; exceptions ponctuelles (`updateSlotPonctuel` interdit le changement de matière/type/jour).
- Portail étudiant : 2 routes (`etudiant.auth`) ; portail enseignant : 3 routes (`enseignant.auth`, PDF `throttle:10,1`).
- API v1 : `POST /api/v1/schedules/import/preview|confirm` (scope `schedules:import`, stateless — convention DataExchange).

### 1.3 Base de données (FAIT — migrations 2026_09_29 + extensions)

- `schedules` : FK année `restrictOnDelete`, groupe `cascadeOnDelete` ; contrainte unique logique groupe×année×semestre ( violation interceptée en création/duplication).
- `schedule_slots` : cascade planning, `restrictOnDelete` matière, `nullOnDelete` teacher/room.
- `schedule_exceptions` : cascade slot, `nullOnDelete` nouvelles références ; unicité slot×date (violation interceptée = refus métier).
- `schedule_changes` : cascade slot, `nullOnDelete` auteur (historique conservé hors auteur supprimé).
- Garde croisée existante : suppression de créneau refusée si présences enseignantes (`restrictOnDelete` Attendance) + filet anti-TOCTOU (`QueryException` → erreur métier).

### 1.4 Tests existants (FAIT — 11 fichiers, exécutés)

| Suite | Tests | Couvre |
|---|---|---|
| `ScheduleTest` | 16 | CRUD planning/créneau, règles de base |
| `ScheduleCycleDeVieTest` | 6 | transitions, publication, verrouillage |
| `ScheduleAccesTest` | 6 | permissions, isolation |
| `ScheduleSecurityTest` | 8 | sécurité module |
| `ScheduleBoardTest` | 7 | tableau DnD, vérification live |
| `ScheduleImportTest` | 18 | imports web multi-formats |
| `ScheduleImportApiTest` | 4 | API stateless, scopes |
| `ScheduleExplorerTest` | 15 | disponibilités, charge, occupation, conflits |
| `TeacherAttendanceScheduleTest` | 22 | intégration présences↔EDT |
| `WorkflowDossierPlanningTest` | 11 | workflows liés |
| `EmploiEnseignantIntelligentTest` | 15 | portail enseignant (vues, filtres, PDF, N+1 borné) |

### 1.5 Constat d'historique récent (FAIT)

- 11/10/2026 (3e main, suite complète 2288 verts) : bug réel corrigé dans `MonEmploiEnseignantController::exceptionsPeriode` (`whereBetween('exception_date')` vs cast `date` persisté 'Y-m-d H:i:s' → exceptions des bornes de semaine exclues sous SQLite ; fix `whereDate(...)`). Ce constat sert de précédent : **les comparaisons de dates doivent être auditées partout** (cas SCH-04-05).

## 2. Baseline mesurée (FAIT)

| Mesure | Commande | Résultat |
|---|---|---|
| Suite ciblée Schedule (11 fichiers) | `php artisan test --compact …` | **128/128 verts, 500 assertions, ~6,9 s** |
| Perf portail enseignant (fixture 100 créneaux) | incluse dans la suite | 0,18 s / 17 requêtes (bornes test < 30) |
| Audit routes | `php artisan audit:routes-securite` (antérieur) | authentification OK sur le périmètre ; REVUE conventionnelles ailleurs |

Baselines SQL par page : non mesurées en détail à ce stade (à produire si une optimisation est envisagée — règle projet : pas de chiffres sans mesure).

## 3. Risques et candidats défauts (HYPOTHÈSES à prouver en LOOP-02+)

1. **SCH-02-01 (élevé potentiel)** — `ScheduleController::verifierDeplacement` (:259-275) : dry-run JSON sans `abort_unless(peutAccederFaculte)` ni `Gate::manageSlot` par slot ; la route n'a que `can:schedules.manage` global. Les messages de conflit renvoyés incluent codes groupe/noms matière d'**autres facultés**. À prouver par test cross-facultés.
2. **SCH-02-02 (moyen)** — vues `create`/`show`/`editSlot` : listes `Group`/`Teacher`/`Room`/`Matiere` non scopées (énumération inter-facultés dans les dropdowns, écriture elle-même gardée côté serveur — confirmé pour `store`).
3. **SCH-05-01 (moyen)** — conflits : aucun verrou/unicité sur la combinaison salle×jour×heure au niveau SQL (impossible portalement) — la stratégie actuelle est verrous pessimistes ordonnés dans le service ; à éprouver sous concurrence.
4. **SCH-07-01 (faible)** — `controlePublication` itère tous les créneaux × `conflitsPour` (comptage groupe par créneau) : coût O(n) requêtes à la publication ; borné, à mesurer avant toute optimisation.
5. **SCH-12-01 (à inventorier)** — index réels vs migrations : non relevés en détail ici (boucle 12).

## 4. Critères d'acceptation de la boucle 01

- [x] Cartographie complète (fichiers, routes, modèles, services, migrations, tests).
- [x] Suite ciblée initiale exécutée et résultat enregistré.
- [x] Registre de cas créé avec identifiants/priorités/prochaines actions (`schedule-audit-cases.md`).
- [x] Progression consignée (`schedule-audit-progress.md`).
- [x] Aucune modification applicative dans cette boucle.

**Verdict boucle 01 : PASS.** Prochaine boucle : SCHEDULE-02 (sécurité et contrôle d'accès), dont le 1er cas est la preuve ou la réfutation de SCH-02-01.

---

# Clôture — SCHEDULE-07 → 15 (11/10/2026)

## Résumé exécutif

Toutes les boucles du fichier `loop_emploi.md` ont désormais un résultat référencé. Trois défauts réels ont été prouvés puis corrigés test-first dans cette clôture : (1) `deplacer` sans catch `DomainException` → 500 ; (2) émissions de notifications DANS la transaction (listener défaillant = mutation perdue, rollback/HTTP 500) ; (3) garde portail publié ajoutée par la 2e main sans alignement des fixtures (5 régressions détectées puis réparées le même jour, assertions métier inchangées).

## Matrice

| Boucle | État | Preuve principale |
|---|---|---|
| 07 Explorateurs | VALIDÉE | benchmark 1000 créneaux (10 éch./scénario) |
| 08 Imports | VALIDÉE | `ScheduleImportRepriseTest` 4/4 |
| 09 Portails | VALIDÉE | garde publié + portail 15/15 + fixtures réparées |
| 10 Notifications | VALIDÉE | `ScheduleNotificationTransactionTest` 5/5 (RED→GREEN) |
| 11 Performance | VALIDÉE | idem 07 ; multi-processus NOT_EXECUTED documenté |
| 12 Concurrence | VALIDÉE | `ScheduleRaceMysqlTest` 2/2 sur MySQL 9.2 réel (scratch jetable) |
| 13 API | VALIDÉE | `ScheduleImportApiTest` 4/4 + dérive openapi verte |
| 14 E2E | VALIDÉE AVEC RÉSERVE | `ScheduleParcoursCompletTest` 19 assertions ; navigateur NOT_EXECUTED |
| 15 Orchestrateur | CLÔTURÉ | présent document |

## Fichiers modifiés/créés (clôture)

- Modifiés : `app/Modules/Schedule/Services/ScheduleService.php` (émissions post-commit sur 8 sites), `app/Modules/Schedule/Listeners/NotifierPlanning.php` (fail-soft try/catch + log sans PII), `app/Modules/Schedule/Controllers/ScheduleController.php` (catch `deplacer`), `tests/Feature/ScheduleTest.php` + `tests/Feature/ScheduleImportTest.php` (fixtures publication — réparation d'une régression du jour, jamais d'affaiblissement).
- Créés : `tests/Feature/ScheduleDeplacementSecuriseTest.php` (6), `ScheduleNotificationTransactionTest.php` (5), `ScheduleImportRepriseTest.php` (4), `ScheduleParcoursCompletTest.php` (1, 19 assertions), `ScheduleRaceMysqlTest.php` (2, gaté MySQL), `tests/Feature/Performance/ScheduleExplorerBenchmarkTest.php` (2).

## Commandes et résultats réels

- Périmètre module complet après clôture : `php artisan test --compact` (18 fichiers) → **153/153 verts, 609 assertions** (+ 2 skips gatés MySQL, preuve exécutée séparément).
- Preuve MySQL : `SCHEDULE_RACE_MYSQL=1 php artisan test --filter=ScheduleRaceMysqlTest` → **2/2 verts** (base scratch `schedule_race_scratch` créée puis droppée ; dataset dev intact vérifié).
- Pint : vert sur tous les fichiers touchés (2 fix cosmétiques imports).

## Mesures (SCHEDULE-07/11 — SQLite mémoire, machine dev, 10 échantillons/scénario, cache vidé à chaque run)

| Scénario (1000 créneaux actifs) | p50 ms | p95 ms | Requêtes |
|---|---|---|---|
| disponibilites | 145 | 148 | 9 |
| sallesLibres | 19 | 21 | 8 |
| chargeEnseignants | 27 | 29 | 7 |
| conflitsDetectes (balayage mémoire) | 1349 | 1662 | 7 |
| occupationSalles | 462 | 469 | 8 |
| creneauxFiltres | 5,7 | 7,3 | 10 |

Aucune optimisation artificielle : requêtes déjà plates ; le coût dominant est le balayage CPU des conflits — référence initiale documentée, pas un SLA. Taux d'erreur : 0. Multi-processus : non mesuré (capacité locale).

## Preuve de concurrence MySQL

- Versions : MySQL 9.2.0 (Homebrew, Rosetta) ; moteur InnoDB ; isolation par défaut `REPEATABLE READ`.
- Prouvé : ordre des verrous `schedules` → `teachers` → `rooms` (anti-deadlock croisé) via écoute SQL ; verrou tenu par connexion 1 → `FOR UPDATE NOWAIT` de connexion 2 refusé (erreur 3572). Verrous relâchés par rollback ; aucune écriture persistante hors scratch.

## Couverture du parcours intégré

Traversé par HTTP (middleware → policy → FormRequest → service → transaction → événement → portail) : non-diffusion du brouillon (404), publication workflow, portail étudiant (périmètre groupe), portail enseignant, annulation (statut + `ScheduleChange` + notification étudiant uniquement dans le périmètre), recalcul des conflits à l'écriture (session errors) et au déplacement (422), refus inter-facultés. Navigateur : `NOT_EXECUTED` (aucun pilote disponible — non simulé).

## Corrections de registre

- S07-3, S08-4, S10-2, S10-3, S11-2, S12-2, S14-1, S15-1 mis à jour avec références.
- SCHEDULE-06 mentionnée : défaut `deplacer` trouvé pendant la clôture (RED→GREEN, `ScheduleDeplacementSecuriseTest` 6/6).

## Réserves et risques résiduels

- Le test de course MySQL exige `SCHEDULE_RACE_MYSQL=1` (skip silencieux sinon) — à brancher en CI recette MySQL si souhaité.
- Benchmark SQLite indicatif ; à rejouer sur recette MySQL avant tout dimensionnement.
- Multi-utilisateurs réel (p95/p99) : non mesurable en environnement local — explicitement NON prétendu.
- Décisions métier volontairement non couvertes par cette clôture (à arbitrer hors boucle) : attribution dossiers→agents, canal demande étudiant changement de groupe, périodes de gel, PDF emploi du temps côté étudiant.

## Verdict global

**CLOSED_WITH_RESERVATIONS** — toutes les exigences critiques sont prouvées (tests réels + preuve MySQL) ; les seules réserves sont environnementales (benchmarks relatifs SQLite, navigateur non exécuté, preuve de course gatée) et documentées.
