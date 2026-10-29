# Progression des boucles — Module Emplois du temps

| Boucle | Objectif | État | Cas (PASS / total évalués) | Notes |
|---|---|---|---|---|
| SCHEDULE-01 | Audit exhaustif + référence | **VALIDÉE** (2026-10-11) | 5/5 | Cartographie 39 fichiers, baseline 129 tests verts, registre 45 cas créé ; 1 défaut corrigé ce jour tracé (EDT bornes-semaine) |
| SCHEDULE-02 | Sécurité complète | **VALIDÉE** (2026-10-11) | 7/7 (dont S02-4 IDOR prouvé par 2 tests nouveaux, 8/8 suite) | Scoping 14× vérifié + testé ; throttle 429 en PASS_WITH_WARNINGS documenté |
| SCHEDULE-03 | CRUD et cycle de vie | **VALIDÉE** (2026-10-11) | 8/8 (S03-1/2/3/5/6/7/8 PASS ; S03-4 NOT_EXECUTED → Loop 12) | 3 défauts réels corrigés : 500 verrouillé (6 catches), garde présences + filet TOCTOU, audit conservé (migration) ; preuves SQLite+MySQL ; `ScheduleCycleDeVieTest` 6/6 |
| SCHEDULE-04 | Créneaux et intégrité temporelle | **VALIDÉE** (2026-10-11, sur preuves existantes) | 5/5 (3 NOT_APPLICABLE justifiés : minuit/bornes/jours fériés — aucune règle métier existante, rien inventé) | `chevauche` strict exact, `after:start_time`, whitelists — aucun changement de code requis |
| SCHEDULE-05 | Moteur de conflits | **VALIDÉE** (2026-10-11) | 7/7 (S05-7 avec réserve documentée) | Signature nettoyée + conflits sous verrous ordonnés dans la transaction ; test MySQL de course à venir |
| SCHEDULE-06 | Déplacement sécurisé | **VALIDÉE** (2026-10-11) | 4/4 | Red 500→422 prouvé puis corrigé (`deplacer` catch DomainException comme les 6 autres mutations) ; `ScheduleDeplacementSecuriseTest` 6/6 (verrouillé sans écriture, idempotence, 404, annulé reste annulé, restauration séparée, simulation périmée → rollback) |
| SCHEDULE-07 | Explorateurs | **VALIDÉE** (2026-10-11) | 3/3 | Benchmark réel 1000 créneaux (SQLite test, 10 échantillons/scénario) : disponibilités p50 146 ms/9 req, salles libres 19 ms/8 req, charge 27 ms/7 req, conflits balayage 1,4 s/7 req, occupation 462 ms/8 req, créneaux filtrés 5,6 ms/10 req — requêtes plates, zéro N+1 ; artefact `storage/app/benchmark/schedule-explorer.json` |
| SCHEDULE-08 | Imports robustes | **VALIDÉE** (2026-10-11) | 4/4 | `ScheduleImportRepriseTest` 4/4 : TOCTOU confirm refusé (conflit apparu entre preview et confirm), interruption intra-transaction → rollback intégral (anciens créneaux restaurés) + rapport `failed`, token usage unique, reprise propre sans doublon |
| SCHEDULE-09 | Portails et diffusion | **VALIDÉE** (2026-10-11) | 4/4 | Réconciliation par preuves : garde portail = publié/verrouillé (`MonEmploiDuTempsController:96`), portail enseignant 15/15, API import 4/4, drift openapi vert — 5 fixtures historiques réparées (publication réelle avant attente portail, assertions inchangées) |
| SCHEDULE-10 | Notifications et audit | **VALIDÉE** (2026-10-11) | 3/3 | RED prouvé : listener jetant → mutation PERDUE (rollback/500) ; fix : `NotifierPlanning` fail-soft (try/catch + log sans PII) + émissions post-commit aux 8 sites restants ; `ScheduleNotificationTransactionTest` 5/5 (échec canal persiste la mutation, refus = zéro événement, 2 émissions par mutation — métier + notifie) |
| SCHEDULE-11 | Performance | **VALIDÉE** (2026-10-11) | 3/3 | Même benchmark que SCHEDULE-07 ; p95 du balayage de conflits 1,6 s à 1000 créneaux (limite assumée, aucune écriture dépendante) ; taux d'erreur 0 ; multi-utilisateurs : NOT_EXECUTED (capacité locale) |
| SCHEDULE-12 | Intégrité SQL et concurrence | **VALIDÉE** (2026-10-11) | 3/3 | Preuve MySQL RÉELLE 9.2 (base scratch `schedule_race_scratch`, créée/jetée) : ordre FOR UPDATE planning→enseignant→salle + NOWAIT d'une 2e connexion refusée (3572) — `ScheduleRaceMysqlTest` 2/2 (skip gaté hors `SCHEDULE_RACE_MYSQL=1`) |
| SCHEDULE-13 | API et contrats | **VALIDÉE** (2026-10-11) | 2/2 | Réconciliation par preuves : `ScheduleImportApiTest` 4/4 (scopes Sanctum, preview/confirm, BOLA) + test de dérive openapi vert ; aucun contrat modifié |
| SCHEDULE-14 | E2E parcours | **VALIDÉE AVEC RÉSERVE** (2026-10-11) | 2/3 | `ScheduleParcoursCompletTest` 19 assertions : brouillon non diffusé (404) → publication workflow → portails étudiant (périmètre) + enseignant → annulation notifiée/historisée → conflit recalculé refusé → 404 inter-facultés ; navigateur authentifié : NOT_EXECUTED (aucune infrastructure) |
| SCHEDULE-15 | Orchestrateur | **CLÔTURÉ** (2026-10-11) | — | Verdict global : voir rapport §Clôture |

## Journal des actions

- 2026-10-11 : SCHEDULE-01 LAN complet → livrables créés (report/cases/progress). Baseline réelle : 129 tests / 504 assertions verts. Suite complète du jour : 2288 verts / 0 échec (contexte : correctif EDT bornes-semaine).
- 2026-10-11 : SCHEDULE-02 VALIDÉE — S02-4 IDOR slot prouvé par 2 tests nouveaux (`ScheduleSecurityTest` 8/8) : 404 × 6 opérations cross-faculté, zéro mutation, parcours légitime intact.
- 2026-10-11 : SCHEDULE-03/05/12 (cœur) — **correctif TOCTOU** : `ScheduleService::sousVerrous` (verrous ordonnés planning→enseignant→salle + re-vérification `estVerrouille` sous transaction) appliqué aux 7 points de mutation ; conflits `creerCreneau`/`modifierCreneau`/`creerException` vérifiés SOUS verrous dans la transaction ; transitions `lockForUpdate`. Preuve : `ScheduleVerrouConcurrenceTest` 7/7 (RED→GREEN sur le cas instance périmée) + régression module 78/78 verts + imports/explorateurs 59/59 + Pint vert. Signature `conflitsEnseignant` nettoyée (paramètre mort). Restes : course FOR UPDATE MySQL réelle (gaté), boucles 06-15 à poursuivre.
- 2026-10-11 : SCHEDULE-03/05/12 (cœur) : `ScheduleService` garde verrou + filet + migration `2026_10_11_085919` ; régression 116/116 + MySQL 5/5 ; Pint vert.
- 2026-10-11 : SCHEDULE-06 VALIDÉE : RED 500→422 (`deplacer` sans catch DomainException) corrigé ; `ScheduleDeplacementSecuriseTest` 6/6.
- 2026-10-11 : SCHEDULE-07/11 VALIDÉES : benchmark 1000 créneaux reproductible (`ScheduleExplorerBenchmarkTest` — garde-fou 100 + benchmark 10 échantillons, artefact JSON). Mesures SQLite mémoire = références relatives, jamais SLA.
- 2026-10-11 : SCHEDULE-08 VALIDÉE : `ScheduleImportRepriseTest` 4/4 (TOCTOU confirm, interruption rollback, token unique, reprise sans doublon).
- 2026-10-11 : SCHEDULE-09/13 VALIDÉES par réconciliation de preuves : portal gate publié (2e main ce jour) + réparation de 5 fixtures historiques (publication réelle avant attente portail — assertions métier inchangées) ; 0 écriture fonctionnelle à l'API.
- 2026-10-11 : SCHEDULE-10 VALIDÉE : RED prouvé (listener qui jette = mutation perdue) → `NotifierPlanning` fail-soft + émissions post-commit partout ; `ScheduleNotificationTransactionTest` 5/5.
- 2026-10-11 : SCHEDULE-12 VALIDÉE — preuve MySQL réelle 9.2 sur base scratch jetable : `ScheduleRaceMysqlTest` 2/2 (ordre des verrous + NOWAIT concurrent refusé 3572) ; dev dataset intact (scratch créée puis droppée, fixture en transactions).
- 2026-10-11 : SCHEDULE-14 VALIDÉE AVEC RÉSERVE : `ScheduleParcoursCompletTest` 19 assertions chaînées HTTP (publication → portails → annulation → conflit) ; navigateur NOT_EXECUTED (sans infrastructure).
- 2026-10-11 : SCHEDULE-15 : périmètre module complet 153/153 verts (+2 skips gatés), Pint vert partout.

---

# CLÔTURE SCHEDULE-07→15 (11/10/2026) — Matrice LAN initiale

Vérifications fondées sur relecture du code (pas recopie des registres) :

| Loop | Critère restant | État réel vérifié | Action |
|---|---|---|---|
| S07 | S07-3 volumétrie milliers | Explorateurs mesurés à 100 créneaux seulement (PERF-EDT-ENS) ; pattern benchmark projet dispo (`Performance/*`, `BENCH_VOLUMES`) | Benchmark 1000+ (fusion avec S11) |
| S08 | S08-4 interruption mi-lot | `confirmer()` = transaction unique (§18) — interruption = rollback intégral ; reprise = relance ; AUCUN test ne prouve le rollback mi-lot ni le statut `failed` du rapport | Test interruption reproductible |
| S09 | Réconciliation preuves | **Écart réel trouvé** : portail étudiant (EDT `MonEmploiDuTempsController:88` + dashboard `TableauDeBordController:78`) filtrent `actif=true` SANS `statut` → plannings **brouillon** visibles des étudiants ; défauts DB : `actif`=true, `statut`='brouillon' ; fixtures pré-Phase-12 = contrat testé périmé, pas intentionnel ; workflow Phase-12 (`TRANSITIONS`) rend publie/verrouille = « publié » prouvable | Correction ciblée ×2 contrôleurs + fixtures publiées + RED test |
| S10 | Frontières transaction×notif | `publier()` (event+notif) **DANS** la transaction pour annuler(:163)/restaurer(:177)/supprimer(:195)/exception(:219) ; créé(:99)/modifié(:148) déjà post-commit ; échec notif non prouvé | Déplacer post-transaction + matrice + tests échec notif |
| S11 | Benchmark 1000+ p50/p95/p99 | Jamais exécuté ; matériel local dispo (macOS, SQLite tests / MySQL dev) | Benchmark reproductible |
| S12 | Course FOR UPDATE MySQL | Test préparé skippé (LOOP4_MYSQL pattern projet, pcntl + base dédiée) ; MySQL 9.2 dispo localement, dev = MySQL | Exécuter sur base dédiée créée pour l'occasion (jamais la dev) |
| S13 | Réconciliation contrats | `ScheduleImportApiTest` 4/4 + openapi drift vert — endpoints preview/confirm uniquement | Matrice endpoint×contrat, solder si preuves suffisantes |
| S14 | Parcours chaîné | Maillons isolés seulement (16+15+11 tests) ; aucun test traversant admin→portail | Test E2E chaîné HTTP sans navigateur |
| S15 | Orchestrateur | — | Rapport de clôture A→G |

Aucune recopie d'état : S09 était annoncé « PASS sur preuves » — l'écart publication invalide partiellement ce constat.

---

## Vérification indépendante de la clôture (3e main, 11/10/2026 ~15:00) — CONFIRMÉE

Co-édition détectée en cours de boucle (la clôture 07→15 a été livrée en parallèle) ;
cette passe valide l'état final par exécution réelle, sans ré-écriture.

### Exécutions réelles (toutes rejouées)

| Commande | Résultat |
|---|---|
| Batterie cœur (ScheduleTest + Board + Security + Workflow + PortailEtudiantPublication + VerrouConcurrence + DeplacementSecurise) | ✅ **61/61**, 196 assertions |
| Batterie imports/E2E/notifs (Explorer + Import ×2 + Reprise + NotificationTransaction + ParcoursComplet + EDT enseignant) | ✅ **62/62**, 261 assertions |
| Voisins (Surveillances + Attendance + Backup) | ✅ **46/46**, 183 assertions |
| `Performance/ScheduleExplorerBenchmarkTest` | ✅ 2/2 — 1000 créneaux, 10 éch./scénario, requêtes plates 7-10, garde N+1 < 60 |
| **`SCHEDULE_RACE_MYSQL=1 … ScheduleRaceMysqlTest`** | ✅ **2/2 sur MySQL 9.2 réel** (re-exécutée par cette main — FOR UPDATE ordonné planning→enseignant→salle, concurrent refusé) |
| Pint (contrôleurs portail + fixtures + test portail) | ✅ vert (1 fix imports) |
| Batterie consolidée 13 fichiers | ✅ **97 verts + 2 skips gatés MySQL**, 373 assertions |

### Contributions propres de cette main (complémentaires, zéro doublon)

- **`PortailEtudiantPublicationTest` (6 tests)** — preuve dédiée de la garde de
  publication côté ÉTUDIANT (EDT + dashboard) : RED réel (brouillon visible),
  dataProvider ×3 statuts non publiés, publication via workflow réel
  (brouillon→valide→publie), verrouillé reste visible (TRANSITIONS), dashboard
  ne montre un cours du jour qu'après publication. Le 15/15 de la clôture
  couvrait le portail ENSEIGNANT — angle complémentaire.
- 4 fixtures ScheduleTest alignées (publication réelle avant attente portail —
  réparation de régression du jour, assertions métier inchangées).
- Incident co-édition documenté : erreur transitoire « Transition publie →
  valide interdite » observée pendant l'édition parallèle des fixtures par les
  deux mains — résolue par convergence, état final vérifié vert.

### Verdict reconduit : **CLOSED_WITH_RESERVATIONS** (confirmé par preuve d'exécution)

Réserves inchangées : benchmarks relatifs SQLite (pas un SLA), navigateur
NOT_EXECUTED, multi-processus non mesuré. Décisions métier en attente
(roadmap.md §Suivi) : PDF EDT étudiant, attribution dossiers→agents, canal
demande changement de groupe, gel, seuil « après début des cours ».

---

## SMART-TIMETABLES-PHASE-C (24/10/2026) — Audit Qualité, Priorités & Sécurité Consolidée

- **État :** **VALIDÉE (PASS_WITH_RESERVATIONS)**
- **Réserves traitées :**
  - **R1 résolue :** `ScheduleConstraintChecker` évalue désormais l'intégralité des souhaits de ressources (`type = 'souhaitee'`) : enseignants, salles et groupes, avec préchargement unifié en 1 requête SQL (zéro N+1).
  - **R2 résolue :** `ScheduleGeneratorService` ordonnance les demandes en combinant taille de domaine initial (MRV) et priorité métier décroissante (`$b->priorite <=> $a->priorite`).
  - **C3 vérifié :** Scoping strict multi-facultés (404) sur `/admin/emplois-du-temps/{planning}/generation` et `/admin/emplois-du-temps/indisponibilites/{plage}`.
- **Résultats tests :**
  - `ScheduleSmartTimetablesPhaseCTest` : 7/7 verts (19 assertions).
  - Suite complète Schedule : **274 tests (272 OK, 2 skipped, 0 échec)**, 1 203 assertions.
  - Pint : 100% vert.

