# Registre des cas — Module Emplois du temps (SCHEDULE-01 → 15)

- Source des boucles : `loop_emploi.md` (15 prompts).
- Convention : chaque statut correspond à des éléments observables (test exécuté, code inspecté avec référence, mesure).
- Légende : PASS (preuve) · PASS_WITH_WARNINGS · FAIL · BLOCKED · NOT_STARTED · NOT_EXECUTED · NOT_APPLICABLE.
- Baseline exécutée le 2026-10-11 : **129 tests verts, 504 assertions** (11 fichiers schedule/EDT, SQLite).

## SCHEDULE-01 — Audit exhaustif (référence)

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S01-1 | Cartographie module (39 fichiers : 5 contrôleurs, 5 services, 8 modèles, 7 FormRequests, 4 importers, 2 listeners, 1 policy, 4 formats import) | — | PASS | Inventaire `app/Modules/Schedule/**` + `route:list` (31 routes web admin + 3 portail étudiant + 3 portail enseignant + 2 API import) | — |
| S01-2 | Baseline tests ciblés | — | PASS | 55 + 74 = 129 verts / 504 assertions (2 commandes, 7,1 s cumulées) | — |
| S01-3 | Défaut résiduel connu (corrigé le matin même, à tracer) : exceptions EDT bornes-semaine (lundi/dimanche) silencieusement exclues | P1 | PASS | Correctif `MonEmploiEnseignantController::exceptionsPeriode` (`whereDate`), preuve : `EmploiEnseignantIntelligentTest` 15/15 + suite complète 2288 verts (rapport registre-étudiants §Annexe) | — |
| S01-4 | Cosmétique moteur conflits : `$scheduleId` paramètre mort dans `conflitsEnseignant()` (la requête est déjà globale par enseignant — comportement correct) | P4 | PASS_WITH_WARNINGS | `ScheduleConflictService.php:80-88` — le paramètre n'est pas utilisé dans le corps ; comportement = détection cross-plannings ✓ | Nettoyer la signature en loop 05 |
| S01-5 | Registre + rapport + progression créés | — | PASS | Les 3 fichiers `schedule-audit-*` | — |

## SCHEDULE-02 — Sécurité et contrôle d'accès

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S02-1 | Toutes routes admin gated `can:schedules.view|manage` + throttles écritures (10–30/min) | P0 | PASS | `Routes/web.php:17-108` — 100 % des routes portent `can:` ; `ScheduleSecurityTest` 8/8 verts | — |
| S02-2 | Portails scopés par session (jamais paramètre enseignant/étudiant dans l'URL) | P0 | PASS | `MonEmploiEnseignantController::enseignantConnecte` (session) + `EmploiEnseignantIntelligentTest::test_filtres_scopes_mes_creneaux` (filtres d'autrui ignorés) | — |
| S02-3 | Module gardé : `module:schedule` → 404 si inactif | P0 | PASS | `Routes/web.php:17,109,116` ; `ScheduleSecurityTest::test_module_desactive_404` | — |
| S02-4 | IDOR cross-faculté sur `{slot}` (6 opérations d'écriture) | P0 | **PASS** | **Test ajouté (11/10)** : `ScheduleSecurityTest::test_admin_faculte_idor_slot_hors_perimetre_404` — update/déplacer/cancel/restore/destroy/exception → tous 404, créneau intact, aucune exception ni trace ; contrôle positif `test_admin_faculte_slot_dans_perimetre_autorise` (update 302, note persistée). Scoping : 14× `peutAccederFaculte` + `manageSlot` dans `ScheduleController`. Converge avec `ScheduleAccesTest` (6/6 : invités, consultation, contenu inspecté, enseignant-collègue, API, horaires) | — |
| S02-5 | CSRF / mass assignment / validation serveur | P0 | PASS | FormRequests dédiés (7) ; store/update validés par champs ; suite sécurité transversale 44 tests (AGENTS.md §10) | — |
| S02-6 | API import : scopes Sanctum `schedules:import` + HMAC/throttle stateless | P0 | PASS | `ScheduleImportApiTest` 4/4 verts ; AGENTS.md (API import preview/confirm) | — |
| S02-7 | Rate limiting effectif (429) sur écritures | P1 | PASS_WITH_WARNINGS | Throttles présents sur 100 % des routes d'écriture (preuve routes) ; pas de test 429 dédié schedule (évité : pollution throttle inter-tests documentée dans le projet) | Optionnel : test isolé |

## SCHEDULE-03 — CRUD et cycle de vie

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S03-1 | Machine à états serveur : 6 états, transitions explicites, verrouillage | P1 | PASS | `Schedule::STATUTS/TRANSITIONS/peutTransiterVers/estVerrouille` + route `transiter` throttle + tests ScheduleTest (16) | — |
| S03-2 | Contrôle pré-publication bloquant (conflits + créneaux sans enseignant) | P1 | PASS | `ScheduleService` (§12.19) : `conflitsPour` + `sans_enseignant` ; `WorkflowDossierPlanningTest` 11/11 | — |
| S03-3 | Suppression/annulation distinguées (annulation = statut, pas suppression) | P1 | PASS | `cancelSlot`/`restoreSlot` routes ; `status != cancelled` exclu du moteur | — |
| S03-4 | Concurrence modification/deux utilisateurs même séance | P1 | NOT_EXECUTED | Non rejoué à ce jour (pas de test de course dédié sur slots) | Loop 12 |
| S03-5 | Transitions au chargement d'état périmé (double soumission transiter) | P2 | PASS | `ScheduleCycleDeVieTest::test_double_soumission_transiter_sans_doublon` (rejeu séquentiel → refus propre, statut stable) ; simultané strict → Loop 12 | Loop 12 |
| S03-6 | Écritures sur planning verrouillé → 500 (6 actions créneau) | P0 | PASS | Reproduit puis corrigé (11/10) : `DomainException` → `back()->withErrors(['creneau'])` sur store/update/destroy/cancel/restore/exception(+ponctuelle) ; `ScheduleCycleDeVieTest` | — |
| S03-7 | Suppression créneau avec présences (`restrict`) → 500 | P0 | PASS | Reproduit puis corrigé (11/10) : garde `presences()->exists()` + filet anti-TOCTOU (`catch QueryException` → refus métier) dans `supprimerCreneau` ; test dédié | — |
| S03-8 | Audit suppression effacé en cascade (aucune trace durable) | P1 | PASS | Migration `2026_10_11_085919` (`schedule_slot_id` nullable + `nullOnDelete`, réversible) ; ligne `deleted` conservée ; preuves SQLite+MySQL | — |

## SCHEDULE-04 — Créneaux et intégrité temporelle

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S04-1 | Règle de chevauchement stricte `debutA < finB && debutB < finA` (adjacents OK) | P1 | PASS | `ScheduleSlot::chevauche:86-89` (exactement la règle du cahier) | — |
| S04-2 | `end_time` strictement `after:start_time` (début==fin refusé) | P1 | PASS | `StoreScheduleSlotRequest:31` + message métier | — |
| S04-3 | Créneau traversant minuit | P2 | NOT_APPLICABLE | `date_format:H:i` sur créneaux hebdo `day_of_week` — le modèle de données n'a pas de séances datées traversantes (exceptions datées séparées) ; à re-documenter si séances datées introduites | — |
| S04-4 | Validation horaire : formats, jours (whitelist JOURS), types (whitelist TYPES) | P1 | PASS | `StoreScheduleSlotRequest:26-33` | — |
| S04-5 | Bornes horaires établissement / jours non ouvrables | P3 | NOT_APPLICABLE | Aucune règle métier existante de bornes horaires (ni config) — absence documentée, pas de règle inventée | Décision métier si besoin |

## SCHEDULE-05 — Moteur de conflits

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S05-1 | Conflit enseignant cross-plannings (même enseignant, 2 plannings, même plage) | P0 | **PASS** | `conflitsEnseignant` global par `teacher_id`+`day_of_week` ; **signature nettoyée 11/10** (paramètre `$scheduleId` mort supprimé, docblock explicite « GLOBAUX tous plannings ») ; régression 78/78 verts | — |
| S05-2 | Conflit salle global (toutes plannings) | P0 | PASS | `conflitsSalle:100-115` | — |
| S05-3 | Conflit groupe (périmètre planning) | P0 | PASS | `conflitsGroupe:65-75` (1 groupe = 1 planning/semestre — cohérent) | — |
| S05-4 | Capacité salle : effectif > capacité = erreur bloquante | P1 | PASS | `conflitsCapacite:43-60` ; tous les conflits sont bloquants côté service (`$erreurs` → refus) — règle homogène | — |
| S05-5 | Exclusion du créneau courant à la modification (`ignorerSlotId`) | P0 | PASS | Présent dans les 3 familles (`:70,:89,:109`) | — |
| S05-6 | Séances annulées exclues du calcul | P0 | PASS | `creneauxActifs():138` (`status != cancelled`) | — |
| S05-7 | Réservation concurrente même salle/enseignant (TOCTOU) | P1 | **PASS_WITH_WARNINGS** | **Correctif 11/10** : vérification des conflits déplacée DANS la transaction, après verrous ordonnés planning→enseignant→salle (`creerCreneau`/`modifierCreneau`/`creerException`) — une réservation concurrente ne peut plus passer entre vérification et écriture (MySQL) ; preuve séquentielle 7/7 + régression module 78/78 ; course FOR UPDATE réelle MySQL = preuve dédiée à venir | Test MySQL gaté (loop 12) |

## SCHEDULE-06 — Déplacement sécurisé

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S06-1 | Vérification destination avant écriture + re-contrôle | P1 | **PASS** | `deplacer` → `modifierCreneau` : conflits re-vérifiés SOUS verrous dans la transaction (sousVerrous 11/10) ; `test_echec_conflit_apres_simulation_ancienne_sans_ecriture` (422 + position intacte) | — |
| S06-2 | Verrou `estVerrouille` : mutations interdites si verrouillé | P0 | PASS | + **défaut réel corrigé (11/10)** : `deplacer` ne catchait pas `DomainException` → 500 (RED prouvé) ; fix catch JSON 422/web erreur ; `test_deplacement_refuse_sur_planning_verrouille_sans_ecriture_ni_audit` | — |
| S06-3 | Historique déplacements + audit | P1 | PASS | `ScheduleChange` + listener `AuditSchedule` ; `WorkflowDossierPlanningTest` | — |
| S06-4 | Annulation/restauration d'un déplacement, idempotence, inexistants, 404 | P2 | **PASS** | Séquentialité idempotente (2× même cible = état stable, 0 duplication), ID inexistant 404, créneau annulé déplacé RESTE annulé (comportement documenté), restauration dédiée tracée — 6/6 `ScheduleDeplacementSecuriseTest` ; pas d'undo de déplacement (historique = trace) | — |

## SCHEDULE-07 — Explorateurs et disponibilité

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S07-1 | Disponibilités / salles libres / créneaux libres / charge / occupation | P2 | PASS | `ScheduleExplorerService` + `ScheduleExplorerTest` 15/15 ; portée requêtes mesurée (100 créneaux : 17 requêtes, 0,27 s — PERF-EDT-ENS) | — |
| S07-2 | Filtres combinés + isolation faculté | P1 | PASS | `ScheduleExplorerTest` + `ScheduleSecurityTest` | — |
| S07-3 | Volumétrie milliers de créneaux sur explorateurs | P2 | **PASS** (11/10) | `ScheduleExplorerBenchmarkTest` : 1000 créneaux réels (SQLite test), p50/p95 par scénario, requêtes plates 7-10, zéro N+1 ; artefact `storage/app/benchmark/schedule-explorer.json` | — |

## SCHEDULE-08 — Imports CSV/XLSX/JSON/XML

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S08-1 | 4 formats + flux preview→confirm→rapport (web + API stateless) | P1 | PASS | 4 importers + `ScheduleImportTest` 18/18 + `ScheduleImportApiTest` 4/4 | — |
| S08-2 | Prévisualisation sans écriture + re-calcul conflits à la confirmation | P0 | PASS | `ScheduleImportService` (2 phases) — idempotence par empreinte (AGENTS.md) | — |
| S08-3 | Sécurité fichiers : XXE, tailles, extensions/MIME réels | P0 | PASS | Wrappers import + `ImportFileInspector` central (audit §P3) ; tests ImportTest | — |
| S08-4 | Import interrompu/reprise gros volumes | P2 | **PASS** (11/10) | `ScheduleImportRepriseTest` 4/4 : TOCTOU confirm (conflit apparu entre preview/confirm → refus, zéro écriture) ; interruption intra-transaction → rollback intégral + rapport `failed` ; token usage unique (rejeu refusé) ; reprise après correction idempotente | — |

## SCHEDULE-09 — Portails et diffusion

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S09-1 | Portail enseignant : semaine/jour/liste + détail + PDF + filtres ownership | P1 | PASS | `EmploiEnseignantIntelligentTest` 15/15 (ownership 404, filtres d'autrui ignorés, PDF inline, N+1 borné) | — |
| S09-2 | Portail étudiant : semaine + actualisations | P1 | PASS | `MonEmploiDuTempsController` + tests portail étudiant (EspaceEtudiant) | — |
| S09-3 | PDF étudiant | P3 | NOT_APPLICABLE | Route absente (semaine + actualisations seules) — export PDF = enseignant uniquement, documenté | Décision métier si besoin |
| S09-4 | Affichage annulées/déplacées + seuils widgets | P1 | PASS | Widgets EDT enseignant + test `test_statuts_annule_deplace_affiches` (bornes semaine corrigées ce jour) | — |

## SCHEDULE-10 — Notifications et audit

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S10-1 | Événement `ScheduleChanged` sur chaque mutation + publication/verrouillage/duplication | P1 | PASS | 5 sites `dispatch` (`ScheduleService:233,278,301,317,366`) | — |
| S10-2 | Listeners audit + notification (inclusifs étudiants/enseignants) | P1 | **PASS** (11/10) | `AuditSchedule` + `NotifierPlanning` synchrones ; RED prouvé (listener jetant = mutation perdue) → émissions post-commit aux 8 sites (annuler/restaurer/supprimer/exception/transiter×3/dupliquer) ; convention 2 émissions/mutation (métier + `notifie`) documentée | — |
| S10-3 | Échec notification ne casse pas l'emploi du temps | P1 | **PASS** (11/10) | `ScheduleNotificationTransactionTest` : canal cassé → mutation persistée + HTTP conforme ; `NotifierPlanning` fail-soft (try/catch + `schedule.notification_echec` sans PII) | — |

## SCHEDULE-11 — Performance

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S11-1 | N+1 listes/fiches | P1 | PASS | Eager systématique ; mesures embarquées : index 11 req, fiche 39, EDT 100 créneaux 17 req/0,27 s | — |
| S11-2 | Volumétrie 1000+ créneaux (imports, conflits, explorateurs) | P2 | **PASS** (11/10) | `ScheduleExplorerBenchmarkTest` (1000 créneaux, 10 échantillons/scénario, cache vidé) : conflitsDetectes p50 1,3 s / 7 req ; occupation p50 462 ms / 8 req ; disponibilités 145 ms / 9 req ; artefact JSON conservé | — |
| S11-3 | p50/p95/p99 multi-utilisateurs | P3 | NOT_EXECUTED | Pas d'infrastructure de charge multi-processus en local — explicitement non mesuré, jamais revendiqué | Production seulement |

## SCHEDULE-12 — Intégrité SQL et concurrence

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S12-1 | Schéma : FK, index, uniques (slots, exceptions `unique(slot,date)`) | P1 | PASS | Migrations : FK contraintes + index `exception_date` + unique composite (audité schéma réel MySQL ce jour) | — |
| S12-2 | Courses vérification→écriture (conflits, déplacement, import) | P0 | **PASS** (11/10) | Correctif `sousVerrous` (supra) + **preuve MySQL réelle 9.2** : `ScheduleRaceMysqlTest` 2/2 sur base scratch jetable — (1) ordre FOR UPDATE planning→enseignant→salle prouvé par écoute SQL ; (2) NOWAIT 2e connexion refusée (3572) sous verrou tenu par la 1re. Skip gaté hors `SCHEDULE_RACE_MYSQL=1` ; dev dataset jamais touché | — |
| S12-3 | Données existantes cohérentes (aucune référence orpheline) | P2 | PASS | Purge orphelins hebdo + audit §33 ; aucune incohérence relevée lors des audits du jour | — |

## SCHEDULE-13 — API et contrats

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S13-1 | API import : enveloppe `{message,code,request_id}`, scopes, throttle, BOLA | P0 | PASS | `ScheduleImportApiTest` + conventions API v1 (openapi resynchronisé) | — |
| S13-2 | Contrat openapi sans dérive | P1 | PASS | Test de dérive vert (convention projet) | — |

## SCHEDULE-14 — E2E parcours complets

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S14-1 | Parcours admin : créer planning → créneau → publier → consulter portails → déplacer → recalcul conflits → notifs | P1 | **PASS** (11/10) | `ScheduleParcoursCompletTest` 19 assertions — frontières HTTP réelles, brouillon non diffusé (404) → workflow publication → portails (étudiant périmètres + enseignant) → annulation notifiée+historisée → conflit recalculé refusé 422 → 404 inter-facultés | — |
| S14-2 | Parcours import : preview → confirm → rapport → portails reflètent | P1 | PASS | `ScheduleImportTest` bout-en-bout (18 tests) | — |
| S14-3 | Navigateur authentifié | P2 | NOT_EXECUTED | Aucun navigateur piloté à ce jour (étiqueté) | Loop 14 si infra |

## SCHEDULE-15 — Orchestrateur

| ID | Cas | Priorité | Statut | Preuve | Action suivante |
|---|---|---|---|---|---|
| S15-1 | Priorisation P0→P3 + clôture des cas | — | **PASS** (11/10) | Tous les cas P0/P1 ont un résultat référencé ; P0 : S02-4 (IDOR, tests), S12-2 (courses, preuve MySQL réelle), S06-2 (deplacer 500→422) résolus et verrouillés par tests ; rapport §Clôture | — |
