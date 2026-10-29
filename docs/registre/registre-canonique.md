# Registre canonique des travaux — Projet universitaire Laravel

> **Statut documentaire** : registre central établi le **12/10/2026** (LOOP 1 — travail documentaire
> uniquement, aucune modification applicative). Source : rapport LOOP 0 du 12/10/2026,
> `AGENTS.md`, `docs/roadmap.md`, `docs/engineering/*`, `docs/pedagogie/registre-arbitrages-lmd.md`,
> mesures fraîches exécutées le 12/10/2026.
>
> **Conventions** : identifiants canoniques stables par préfixe de domaine
> (`INSC`, `PORT`, `PACA`, `REF`, `SCH`, `MTR`, `EXM`, `SEC`, `INFRA`, `AUTH`) ;
> correspondance avec les anciens numéros LOOP dans `correspondance-loops.md`.
> Statuts autorisés : `DONE`, `PARTIAL`, `IN_PROGRESS`, `BLOCKED`, `NOT_STARTED`, `NOT_VERIFIED`.
> Une entrée n'est jamais `DONE` sans preuve exécutable nommée.
> L'état de développement **ne doit pas** être assimilé à celui de la production.

---

## 1. État de référence (mesuré le 12/10/2026)

| # | Mesure | Valeur | Environnement | Commande | Vérification |
|---|---|---|---|---|---|
| R1 | Dépôt Git | **Créé le 12/10 au soir** : `github.com/YassinoMed/Facult-`, `main` poussé (2 commits), CI déclenchée 2× (rouge — cf. INFRA-01) ; protections de branche à configurer | local + GitHub | `git log` / `gh run list` | `VERIFIED` (13/10) |
| R2 | Stack | PHP 8.5.11 · Laravel 13.33.0 · MySQL dev · Node 26 · Sanctum 4.3.3 · Livewire 4.4.7 | dev (macOS) | Boost `application-info` | `VERIFIED` |
| R3 | Routes | **775** hors vendor | dev | `php artisan route:list --json --except-vendor` (count) | `VERIFIED` |
| R4 | Migrations | **142 exécutées / 0 en attente** | dev (MySQL) | `php artisan migrate:status` | `VERIFIED` |
| R5 | Composer audit | **0 avis** | dev | `composer audit --no-dev` | `VERIFIED` |
| R6 | npm audit | **0 vulnérabilité** (après correctifs du jour 18:01 : concurrently 10.0.6, source-map-js 1.2.2) | dev | `npm audit` | `VERIFIED` |
| R7 | Suite complète | **3 054 tests · 3 037 réussis · 17 esquivés · 0 échec · 13 069 assertions · 5 min 10 s** (mono-processus) | dev (tests sqlite `:memory:`) | `php artisan test --compact` | `VERIFIED` |
| R8 | Pint | **Échec sur 7 fichiers** (`app/Providers/AppServiceProvider.php`, `app/Services/BackupService.php`, `tests/Feature/SaisiesFormatsTest.php`, `BackupRoundtripTest`, `ImportPrediagnosticTest`, `DoublonsGraduesTest`, `Performance/ImportExportBenchmarkTest`) | dev | `vendor/bin/pint --test` | `PARTIALLY_VERIFIED` — à confirmer lors de la prochaine exécution autorisée (contradiction C1) |
| R9 | Base de développement | **2 047 étudiants · 2 099 inscriptions (dont 2 087 sans programme) · 52 matières (38 standalone) · 4 programmes actifs · 1 maquette `review` (0 active) · 160 groupes · 2 008 affectations · 523 notes · 10 users** | dev (MySQL, lecture seule) | tinker `Schema::hasTable` + `DB::table()->count()` | `VERIFIED` |
| R10 | Modules actifs dev | attendance, student_dossiers, online_requests, schedule, evaluation, sentinel (env) + referentiel, enseignement (défauts) — les autres inactifs | dev | `grep MODULE_ .env` + `config/modules.php` | `VERIFIED` |
| R11 | Production | Non assimilable au dev : TLS, TRUSTED_PROXIES, secrets, rotation, drill = à vérifier par l'exploitant | prod | `docs/security-hardening/production-checklist.md` | `NOT_VERIFIED` |

---

## 2. Registre par domaine

Rappel des champs : pour chaque entrée — objectif/périmètre, statut, priorité, preuves/tests,
dépendances/collisions, risques/décisions, date de dernière vérification, environnement.
Les entrées non `DONE` disposent d'une fiche détaillée sous leur table.
La correspondance anciens LOOP → ID canoniques est dans `correspondance-loops.md`.

### 2.1 INSC — Inscription et expérience d'inscription

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| INSC-01 | Périodes d'inscription : table `registration_periods`, gardes dans les 2 créateurs, états OPEN/CLOSED/EXCEPTION, UI fieldset, fuseau Tunis, chevauchements, notifications `periodes:avertir` | DONE | — | `PeriodeInscriptionTest`, `PeriodeInscriptionMissionTest`, `PeriodeFuseauTest`, `PeriodeCorrectionLoopTest`, `PeriodesNotifierTest`, `InscriptionPagePeriodeTest` — tous verts dans la suite du 12/10 | INSC-07 | Canal in-app seul (assumé) ; types MASTER/LICENCE couverts par portées génériques | 12/10/2026 · dev |
| INSC-02 | Référentiel MENS : import idempotent, identification intelligente fail-closed, création dossier post-import, divergences workflowisées (R12), page référentiel MENS | DONE | — | `MensInscriptionIntelligenteTest`, `IdentificationFailClosedTest`, `MensDivergencesWorkflowTest`, `MensDossierCreationTest`, `MensReferenceTest` | — | Préremplissage MENS partiel (contact à saisir) — assumé | 12/10/2026 · dev |
| INSC-03 | Documents requis par type (matrice `documents_requis_par_type`, états, gate `REGISTRATION_DOCUMENTS_INCOMPLETE`) | DONE | P3 | `DocumentsRequisTest` + chaîne dans `AffectationApresDossierCompletTest` | PACA-05 | Dette : checklist UI dédiée (vues existantes réutilisées) ; types MASTER/LICENCE extensibles | 12/10/2026 · dev |
| INSC-04 | Notes : import multi-format (CSV/XLSX/JSON/XML/ODS), association CIN→matricule→numéro, `file_hash`, verrou refusé | DONE | P3 | `NotesImportAssociationTest`, `NotesImportMultiTest` | EXM-05 | Dette : résolution manuelle UI des ambiguïtés ; jobs/queues >10k | 12/10/2026 · dev |
| INSC-05 | Relevés semestriels/cursus + `code_verification` pré-généré + génération auto à la publication + portail `dossier/officiels` | DONE | P3 | `RelevesGenerationTest` | EXM-08 | Dette : QR image (pas de lib) ; snapshot Centre non unifié (documenté) | 12/10/2026 · dev |
| INSC-06 | Centre de résolution : blocages codés, correction inscription 4-yeux (`dossier_corrections.inscription_academique_id`), file + compteurs | DONE | — | `CentreResolutionTest`, `VerificationBoucleTest`, `InscriptionCorrection4YeuxTest` | INSC-11 | Décisions officielles jamais remplacées (règle préservée) ; dérogation formelle = dette documentée | 12/10/2026 · dev |
| INSC-07 | Parcours public 6 étapes : écran identification CIN+naissance (throttle 5/min), modale existant (e-mail masqué), fail-closed MENS au `store()`, gardes période | DONE | — | `InscriptionPagePeriodeTest`, `IdentificationFailClosedTest`, `InscriptionRecepissePdfTest` | INSC-01, INSC-02 | Décision ouverte : OTP (DH-17, écarts assumés) | 12/10/2026 · dev |
| INSC-08 | Notifications dossier (statut/dépôt/rejet pièce/correction, dédup idempotente) + récépissé PDF + reset mot de passe scolarité + libellés humains historique | DONE | — | `ExperienceInscriptionLoopTest`, `ComplementsInscriptionLoopTest`, `InscriptionRecepissePdfTest` | — | Garde « décision définitive rejeté » : réouverture = procédure scolarité (design) | 12/10/2026 · dev |
| INSC-09 | FAQ officielle (config sourcée) + assistant lexical (confiance, suggestions, état erreur) + RDV scolarité (créneaux, anti double-réservation, déplacement) | DONE | — | `FaqAideScolariteTest`, `InscriptionFaqOfficielleTest`, `InscriptionRendezVousAssistanceTest` | — | Aucun risqué documenté | 12/10/2026 · dev |
| INSC-10 | Accessibilité inscription : `aria-invalid` conditionnels, labels scan, fieldset, SW offline filtré | DONE | — | `FaqAideScolariteTest` (a11y), `InscriptionPagePeriodeTest` | — | — | 12/10/2026 · dev |
| INSC-11 | File de traitement admin : filtres (dont `depose_depuis`), 9 catégories dérivées, KPIs automatisation (1 requête groupée), pagination préférences, UX anti-double-submit | DONE | — | `FileTraitementTest`, `FileTraitementCategoriesTest`, `FileTraitementMetriquesTest`, `InscriptionPerformanceFiabiliteTest` | INSC-06 | — | 12/10/2026 · dev |
| INSC-12 | Sécurité transversale inscription : throttles, CSRF, mass assignment, XSS, PII logs hashées, UNKNOWN neutre | DONE | — | `InscriptionSecuriteTransversaleTest`, `XssCsrfSecurityTest` | SEC-02 | `?q=` GET dans access logs (voie no-JS assumée ; recommandation ops) | 12/10/2026 · dev |
| INSC-13 | Performance inscription : benchmark (58 req/soumission, 21 ms moy ; artefact JSON sans PII), VolumeSeeder | DONE | — | `Performance/InscriptionPubliqueBenchmarkTest` | — | k6 non adopté (dette assumée) | 12/10/2026 · dev |
| INSC-14 | Concurrence affectation : verrous ordonnés étudiant→groupe dans `affecter`/`retirer`/`tenter`, idempotence, preuve FOR UPDATE MySQL scratch | DONE | — | `AffectationConcurrenceTest` (1 skip SQLite documenté), `AffectationConcurrenceMysqlTest` (gated) | PACA-01, PACA-05 | Preuve MySQL sur scratch 12/10 (documentée), pas en CI | 12/10/2026 · dev |
| INSC-15 | Checklist pièces obligatoires portail (matrice serveur, progression, vide = masqué) | DONE | — | `ExperienceInscriptionLoopTest` | INSC-03 | — | 12/10/2026 · dev |

### 2.2 PORT — Portails enseignant/étudiant

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| PORT-01 | Socle accès : auth enseignant miroir (email/matricule, temps constant, throttle), `EnsureTeacher`/`EnsureStudent` (session serveur seule), activation admin, profil | DONE | — | `EspaceEnseignantTest`, `EnseignantPortailTest`, `EspaceEtudiantTest` | AUTH-06 | Asymétrie statut étudiant → fiche AUTH-03 | 12/10/2026 · dev |
| PORT-02 | Dashboard + canal notifications enseignant (cloche, batch, centre partagé) | DONE | — | `EspaceEnseignantTest` (notifs), `ModulesTest` | — | — | 12/10/2026 · dev |
| PORT-03 | EDT enseignant intelligent : vues jour/semaine/liste, filtres validés restreints à MES ids, widgets, détail créneau 404 autrui, PDF, exceptions 1 requête, annulées affichées | DONE | — | `EmploiEnseignantIntelligentTest` (fix bornes-semaine vérifié 12/10) | SCH-01 | — | 12/10/2026 · dev |
| PORT-04 | Appel intelligent : `seance_appels`, ouvrir/enregistrer/clôturer bulk, corrections tracées, QR nominatif 15 min (signature+expiry), pointage étudiant, admin lecture, export CSV neutralisé | DONE | — | `AppelIntelligentTest`, `AppelQrTest` | EXM-01 | Limites : pas d'auto-pointage portail, effectif = `groupe_id` courant, corrections admin sans trace (pré-existant) ; **2 P2 ouverts le 12/10** (QR écrase relevé ≠ present ; re-enregistrement supprime justifications cascade) → SEC-11 | 12/10/2026 · dev |
| PORT-05 | Saisie notes enseignant : éligibilité = ECUE affectée active, voie strictement anonyme CMP (jamais d'identifiant), import CSV anonyme, export anonyme | DONE | — | `EnseignantNotesTest` | EXM-05 | Ni validation ni correspondance CMP côté enseignant (séparation de pouvoirs voulue) | 12/10/2026 · dev |
| PORT-06 | Ressources pédagogiques : CRUD + publier/archiver, fichiers privés UUID, MIME réel, visibilité étudiant groupe×programmes | DONE | — | `RessourcesPedagogiquesTest` | — | Devoirs/remises N/A (aucun système, jamais présenté comme disponible) | 12/10/2026 · dev |
| PORT-07 | Demandes & réclamations enseignant (2e main : `teacher_requests`/`teacher_reclamations`) | **PARTIAL** | P2 | Code présent, routes conservées, **revue de sécurité non faite** | — | Arbitrage architecture requis (DH-16) | 12/10/2026 · dev |
| PORT-08 | Charge & statistiques enseignant (badge par enseignant, stats périodes) | DONE | — | `SurveillanceChargeEnseignementTest` | EXM-03 | Limite opérationnelle : affectations d'enseignement à alimenter | 12/10/2026 · dev |
| PORT-09 | Dashboard étudiant + publication garde (plannings publie/verrouille seuls visibles) | DONE | — | `PortailEtudiantPublicationTest` (6/6, 12/10) | SCH-04 | — | 12/10/2026 · dev |
| PORT-10 | EDT & calendrier étudiant | DONE | — | suites portails (101 tests) | SCH-01 | — | 12/10/2026 · dev |
| PORT-11 | Notes & progression étudiant (publiées seules, enveloppe stable) | DONE | — | `EvaluationTest`, suites portails | EXM-07 | — | 12/10/2026 · dev |
| PORT-12 | Présences & justificatifs étudiant (workflow pending→approved/rejected) | DONE | — | suites Attendance + portails | EXM-01 | — | 12/10/2026 · dev |
| PORT-13 | Dossier étudiant portail (GED personnelle, statuts, pièces, réception) | DONE | — | `EspaceEtudiantTest`, `StudentDocumentTest` | SEC-05 | — | 12/10/2026 · dev |
| PORT-14 | Ressources & travaux étudiant (supports publiés) | DONE | — | `RessourcesPedagogiquesTest` | PORT-06 | Devoirs N/A documenté | 12/10/2026 · dev |
| PORT-15 | Recherche portails (LIKE échappé, périmètre strict, sans PII côté enseignant, JSON) | DONE | — | `RecherchePortailTest` | — | — | 12/10/2026 · dev |
| PORT-16 | Exports portails (émargement PDF, CSV absences, documents générés CSV) | DONE | — | `AppelQrTest` (export neutralisé), suites portails | — | — | 12/10/2026 · dev |
| PORT-17 | Audit transversal portails (LOOP 23 ancienne) | DONE | — | Suite complète du 12/10 (3 054 tests, 0 échec) incluant 101 tests portails | SEC-02 | — | 12/10/2026 · dev |
| PORT-18 | Mode hors-ligne étudiant étendu | **NOT_STARTED** | P4 | — (SW filtré minimal livré côté INSC-10) | — | **NO-GO documenté** — ne pas implémenter sans nouvelle approbation | — |
| PORT-19 | Assistant IA | **NOT_STARTED** | P4 | — | — | **NO-GO documenté** — aucun service IA approuvé | — |

### 2.3 PACA — Affectation et gestion des parcours étudiants

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| PACA-01 | Moteur d'affectation groupes : 10 contraintes codées, idempotence, verrouillage capacité, bulk+simulation, sous-groupes | DONE | — | `AffectationMoteurTest` (20), `AssignmentAutomatiqueTest` | INSC-14 | — | 12/10/2026 · dev |
| PACA-02 | Affectation intelligente : scoring configurable (`config/affectations.php`), garde doublon CIN normalisé, propositions, cockpit masse enrichi (score/certitude/alternatives compactes -43 %), recherche serveur, export CSV | DONE | — | `AffectationIntelligenteLoopTest`, `AffectationCockpitTest`, `AffectationMoteurTest` | — | — | 12/10/2026 · dev |
| PACA-03 | Simulateur v2 : seed déterministe, plan capacité, API v1 `assignments/*` (scopes, BOLA 404), pré-check enrichi | DONE | — | `AssignmentAutomatiqueTest`, API tests | — | — | 12/10/2026 · dev |
| PACA-04 | Lots d'affectation en masse asynchrones : `affectation_masse_lots`, reprise `traites_ids`, annulation coopérative, auteur re-vérifié, seuil sync/async | DONE | — | `AffectationMasseLotTest` | — | — | 12/10/2026 · dev |
| PACA-05 | Affectation automatique post-validation : listener `InscriptionValidee` (afterCommit), idempotence, verrous, audit, notification | DONE | — | `AffectationAutomatiqueApresValidationTest` (Cas 1-11) | INSC-03, INSC-14 | — | 12/10/2026 · dev |
| PACA-06 | Changement de groupe : `simuler` (contraintes bloquantes seules) + `changer` (idempotence, audit, notif post-commit) | DONE | — | `ChangementGroupeTest` (12), dans suites automatisation | PACA-01 | — | 12/10/2026 · dev |
| PACA-07 | Façade éligibilité inscription (`InscriptionEligibilityService::evaluer`, zéro écriture) + chaîne dossier complet→affectation | DONE | — | `InscriptionEligibilityTest` (8), `AffectationApresDossierCompletTest` (3) | INSC-03 | `intervention_humaine` toujours vrai (design) | 12/10/2026 · dev |
| PACA-08 | Parcours académique avancé LMD : 8 tables, 9 services, 13 scénarios de simulation append-only, risques, workflows 4-yeux (409), prospective, comparaison, API 4 endpoints | DONE | — | `ParcoursAcademiqueTest` (57), `NavigationPhase7Test` | REF-02 | P01-P34 + UX + audit sécurité/perf exécutés | 26/10/2026 · vérification LOOP-04 |
| PACA-09 | Progression LMD : `AcademicProgressionService` (dry-run/appliquer transactionnel idempotent), `Admission` consommable, `niveauApres()` partagé | DONE | — | `AcademicProgressionTest` (15), `AdmissionTest` (8) | — | Fin de cycle sans admission = refus (jamais deviné) | 12/10/2026 · dev |
| PACA-10 | Datasets : FSEGS (seed commandes, validateur), continuité inscriptions, groupes manquants, pré-check | DONE | — | `FsegsDatasetTest`, `FsegsSimulationDatasetTest`, `FsegsValidateDataset` | — | ⚠️ Chiffres du 01/10 (4 958/1 004) **obsolètes** : base actuelle 2 047/2 008 (contradiction C2) | 12/10/2026 · dev |
| PACA-11 | Registre étudiants consolidé : pagination préférences, export sans PII, verrou `changer`, dedup bulk, doublons potentiels scopés | DONE | — | `StudentRegistry*` (159/159 claimants au 11/10, verts dans la suite du 12/10) | — | **Réserve** : attribution workflow à arbitrer (`student-registry-attribution-proposal.md`) | 12/10/2026 · dev |

### 2.4 REF — Référentiels et données académiques

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| REF-01 | Référentiel LMD complet : chaîne Domaine→…→ECUE, invariants (INV-2/4/5/7/10), imports, saturation, IDOR 404, scope canonique `Matiere::pourFaculte()` | DONE | — | `ReferentielIntegrityTest`, `MatiereCatalogueTest`, suites référentiel (233 tests) | MTR-05 | — | 12/10/2026 · dev |
| REF-02 | Maquettes versionnées : workflow draft→review→approved→active (bascule atomique), garde SQL anti double-active (colonne générée + unique, MySQL), immutabilité | DONE | — | `MaquetteVersioningTest`, `MaquetteRaceMysqlTest` (gated, 4/4 scratch 12/10) | REF-05 | — | 12/10/2026 · dev |
| REF-03 | Plan d'études wiring : occurrences ECUE du plan, `definirEcues` gardes, génération épreuves occurrence-aware, K-parmi-N | DONE | — | `MaquettePlanEcuesTest` (16) | EXM-01 | Repli ue_id documenté si plan sans occurrence | 12/10/2026 · dev |
| REF-04 | Matières standalone : inventaire (38 : 1 DOCUMENTEE / 5 AMBIGUE / 32 SANS_CORRESPONDANCE), classifieurs convergents, consommateurs cartographiés | **PARTIAL** | P2 | `ReferentielStandalone` (via `referentiel:backfill` dry-run, 12/10 : classification re-mesurée) | — | 33 sans correspondance → ARB-004 ; normalisation `é/ee` INFO201 à unifier (REF-10) | 12/10/2026 · dev |
| REF-05 | MASTER LOOP référentiel (LOOP 0→8 + consolidation + clôtures) : état, workflow, invariants, perf, sécurité, contrats consommateurs, drill restauration PASS | DONE | — | Rapports `docs/engineering/referentiel-*` + 233 tests référentiel verts le 12/10 | — | Technique clos ; arbitrages pédagogiques → REF-06..09 | 12/10/2026 · dev |
| REF-06 | Activation DEMO-LGL v1 (review→approved→active) | **BLOCKED** | P1 | `demo-lgl-activation-preflight.md` : S1 PASS technique, S2-S6 vides, 12 contrôles, **aucune écriture prouvée par re-mesure** | ARB-001/003 | Décision humaine requise (fichiers officiels + approbation formelle) | 12/10/2026 · dev |
| REF-07 | 2 087 inscriptions sans programme (re-mesuré exact le 12/10) | **BLOCKED** | P1 | Mesure R9 + ARB-005 | — | Décision humaine requise (rattachement/réorientation — jamais automatique) | 12/10/2026 · dev |
| REF-08 | Clarification « SEM-03 » | **BLOCKED** | P3 | ARB-006 | — | Décision humaine requise | 12/10/2026 · dev |
| REF-09 | Seuils de charge pédagogique | **BLOCKED** | P3 | ARB-007 | — | Décision humaine requise (valeurs d'administration) | 12/10/2026 · dev |
| REF-10 | Unification de la normalisation d'identifiants de matières (collation `é`/`ee` — divergence classifieurs INFO201) | **NOT_STARTED** | P3 | Signalée LOOP 3 (12/10) | REF-04 | Aucune correction sans preuve de correspondance unique | — |

### 2.5 SCH — Emploi du temps et planification

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| SCH-01 | Module schedule complet : créneaux réactifs, conflits, exceptions, historique, imports CSV/JSON/XML/XLSX, API, exploration multi-axes, verrouillage brouillon→publié | DONE | — | 153 tests module verts le 12/10 (clôture SCHEDULE-01→15) | PORT-03, PORT-09 | — | 12/10/2026 · dev |
| SCH-02 | Sécurité concurrence : `sousVerrous` ordonnés planning→enseignant→salle sur les 7 points de mutation, TOCTOU clos, preuve FOR UPDATE MySQL 9.2 scratch (NOWAIT refusé) | DONE | — | `ScheduleVerrouConcurrenceTest` (7/7), `ScheduleRaceMysqlTest` (gated, 2/2 scratch 12/10), `ScheduleSecurityTest` (8/8) | — | Preuve MySQL hors CI (gated) | 12/10/2026 · dev |
| SCH-03 | Déplacement sécurisé + notifs post-commit fail-soft + import reprise (token unique, rollback intégral) + E2E chaîné 19 assertions | DONE | — | `ScheduleDeplacementSecuriseTest` (6/6), `ScheduleImportRepriseTest`, `ScheduleNotificationTransactionTest`, `ScheduleParcoursCompletTest` | SCH-01 | — | 12/10/2026 · dev |
| SCH-04 | Publication portails : garde `publie/verrouille` côté étudiant, fixtures historiques alignées | DONE | — | `PortailEtudiantPublicationTest` (6/6) | PORT-09 | — | 12/10/2026 · dev |
| SCH-05 | Clôture SCHEDULE-01→15 + benchmark 1000 créneaux (p50/p95, SQL plat) | DONE | — | `Performance/ScheduleExplorerBenchmarkTest` + rapport `schedule-audit-report.md` §Clôture | — | **5 décisions métier ouvertes** (DH-15) : PDF EDT étudiant, attribution dossiers→agents, canal changement de groupe, gel, seuil début cours | 12/10/2026 · dev |
| SCH-06 | Garde de publication du **widget** EDT étudiant (dashboard) — contournement SCHEDULE-09 démasqué le 13/10 au passage de minuit : le composer résolvait le planning **sans** `whereIn(statut, [publie, verrouille])` (le filtre « prochain cours » `debut >= maintenant` masquait la fuite après les heures de cours — invisible hier soir, visible à 02:50) | **DONE** — corrigé le jour même | — | `PortailEtudiantPublicationTest` (le test de garde a **attrapé le vrai bug**) + fix `ModuleServiceProvider` (garde miroir du portail EDT) + suite complète 3 136/0 échec | INFRA-01 | Leçon documentée : suites chronodépendantes masquent des bugs — la CI (heures UTC diverses) les expose gratuitement | 13/10/2026 · dev+CI |

### 2.6 MTR — Matières, enseignements et maquettes pédagogiques

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| MTR-01 | Module enseignement : ECUE×type×année, sous-groupes, services+seuils, évaluation modalités scopées | DONE | — | suites Enseignement (78/78 au 08/10, verts le 12/10) | EXM-01 | — | 12/10/2026 · dev |
| MTR-02 | Module enseignants phases A-E : `destroy` gardé 6 familles, isolation salles scopée, import/export 9/9 (SALIMA absorbé), traçabilité + colonne `historique_academique.etudiant_id` | DONE | — | `EnseignantStatutCoherenceTest` (7/7), `TeacherHomonymeImportTest`, phases A-E (34 tests) | — | Migration additive **à déployer** en environnement cible (Ran en dev) | 12/10/2026 · dev |
| MTR-03 | Salles : campus/bâtiment, statut actif, hiérarchie dimensionnement grandes salles, exceptions par salle | DONE | — | `SurveillanceDimensionnementSallesTest` (22) | EXM-03 | — | 12/10/2026 · dev |
| MTR-04 | Imports référentiel lot 2 : statut/capacité programmes, cycles diplômes, garde année active unique, graphe dépendances étendu | DONE | — | `ReferentielLot2Test` (14) | REF-01 | — | 12/10/2026 · dev |
| MTR-05 | Catalogue matières/ECUE : normalisation code à l'écriture, `resoudreParCode`, imports fail-closed, NIVEAU_INCONNU | DONE | — | `MatiereCatalogueTest` (10) | REF-01 | — | 12/10/2026 · dev |

### 2.7 EXM — Examens et évaluations

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| EXM-01 | Module évaluation : sessions datées, épreuves à transitions serveur, conflits bloquants, surveillants, candidats, notes synchronisées, délibérations/jury/quorum, verrouillage + optimistic locking | DONE | — | suites Evaluation (45+ verts le 12/10), `EvaluationTest` | — | — | 12/10/2026 · dev |
| EXM-02 | Incidents d'examen : 6 types, transitions serveur, scope→404 avant Gate, XSS échappé, verrou ≠ blocage post-hoc | DONE | — | `EvaluationIncidentsTest` (8) | EXM-01 | — | 12/10/2026 · dev |
| EXM-03 | Surveillance & garde : dimensionnement par salle + grandes salles + exceptions, contraintes configurables versionnées (DTO, résolution 3 niveaux), job async snapshot, optimisation multi-jours jour-exact, ventilation par salle, conflits multi-salles fail-closed | DONE | — | `SurveillanceGardeTest` (12), `SurveillanceAffectationTest` (6), `SurveillanceAdversarialTest` (18), `SurveillanceContraintesTest` (15), `SurveillanceDimensionnementSallesTest` (22), `SurveillanceSallesSuiteTest` (12), job (15) | MTR-03 | Heuristique tranche /50 signalée (décision d'administration via UI) | 12/10/2026 · dev |
| EXM-04 | Emploi des surveillances : 3 vues, PDF, CSV jour/enseignant, conflits mémoire, stats | DONE | — | `EmploiSurveillancesTest` (15) | EXM-03 | — | 12/10/2026 · dev |
| EXM-05 | Compostage anonyme : `CMP-` idempotent, saisie anonyme, import/export anonymes, verrou épreuve, correspondance restreinte (super_admin+scolarité), PII jamais exposée | DONE | — | `CompostageAnonymeTest` (18), `EvaluationCompostageTest` | PORT-05 | P4 documenté : `verrouille_at` compostage dormant (0 appelant — volontairement non branché) | 12/10/2026 · dev |
| EXM-06 | Import notes DataExchange + boucle corrections : pipeline preview→confirm anti-TOCTOU, correcteur versionné, doublons (jamais de fusion), fail-closed étudiant sans identifiant | DONE | — | `NotesImportMultiTest` (32), `DataExchangeContratTest`, `VerificationBoucleTest` (11) | INSC-04 | — | 12/10/2026 · dev |
| EXM-07 | Délibérations : règles versionnées + compensation semestre, invalidation `resultats_invalides_le`, PV PDF, cas limite ±0,5, `calculation_runs` traçables, visibilité publiée seule | DONE | — | `DeliberationPhase6Test`, `DeliberationPhase14Test`, `EvaluationTest` (garde note verrouillée) | PACA-08 | — | 12/10/2026 · dev |
| EXM-08 | Documents académiques : catalogue 15 types, `DocumentEligibiliteService`, workflow approuver→émettre→envoyer, guichet attestations (CIN/matricule), maquettes documentaires (CRUD, versions, aperçu interpolé sûr), centre documents, vérification publique, numérotation sans trou | DONE | — | `DocumentsCatalogueTest` (22), `DocumentWorkflowTest` (12), `AttestationFlowTest` (10), `MaquettesFlowTest` (19), `DocumentsPhase16Test` (7) | INSC-05 | Dette : QR image, snapshots Centre non unifiés, STAGE/RADIATION reportées (zéro source) | 12/10/2026 · dev |
| EXM-09 | Charge d'enseignement : volumes annuels anti-double-comptage, quotités, HETD, badge par enseignant, 1 requête agrégée | DONE | — | `SurveillanceChargeEnseignementTest` (15) | PORT-08 | Limite opérationnelle documentée (alimenter les affectations) | 12/10/2026 · dev |

### 2.8 SEC — Sécurité applicative

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| SEC-01 | Hardening 29/09 : isolation faculté écritures (Gates + authorize scopés), uploads signatures, session encrypt/secure par défaut, suite Security (44 puis 63 tests) | DONE | — | `tests/Feature/Security/*` — 68/68 le 12/10 (158 assertions) | AUTH-06 | — | 12/10/2026 · dev |
| SEC-02 | Audit sécurité complet 04/10 (§11) : cartographie 529→714 routes, 18 reproductions test-first, corrections HIGH/MEDIUM/LOW, `FaculteScopeEcritureTest` (19) | DONE | — | Idem + `AuditAdversaireProofTest` | — | Risques résiduels assumés documentés (SEC-03) | 12/10/2026 · dev |
| SEC-03 | Résiduels documentés non corrigés : `FinancePolicy::manage` latent (permission absente de la matrice), sonde `epreuves/verifier` non scopée, `SessionExamen` transversale | **PARTIAL** | P3 | `docs/SECURITY.md` §3 + `docs/AUDIT-SECURITE.md` §11 | — | Non prouvables exploitables → non corrigés par règle « pas de correction sans preuve » ; à réévaluer si la matrice évolue | 12/10/2026 · dev |
| SEC-04 | Identité/OCR : fail-soft par conception, temporaire privé supprimé `finally`, MRZ ICAO, anti-polyglotte, CIN multi-variantes, arabe préservé | DONE | — | `IdentityScanTest` (23), `IdentityParsersTest` (18), `ArabicIdentityTest` (27) | — | IA hfvlm désactivée par défaut | 12/10/2026 · dev |
| SEC-05 | Journalisation sans PII + audit centralisé (IP hachée, acteur, avant/après) | DONE | — | `AuditLogTest`, `JournalisationPriveeTest`, `SensitiveAuditTest` | — | 1 occurrence debug local gated assumée | 12/10/2026 · dev |
| SEC-06 | Module Sentinel : 11 contrôles, incidents ack/resolu/ignore, dédup, cooldown en base, listener JobFailed gardé, cockpit scopé | DONE | — | `SentinelTest`, `SentinelMetierTest`, `SentinelConvergenceTest` (29) | INFRA-08 | — | 12/10/2026 · dev |
| SEC-07 | Sauvegardes : chiffrées sodium, planification 1 h, rétention, DR documentée, drill de restauration PASS en isolé (MySQL 9.2, ~1903 tables) | DONE | — | `BackupRoundtripTest` (gated mysqldump), `SauvegardeChiffrementTest` | INFRA-08 | Drill périodique = exploitation ; MySQL gated hors CI | 12/10/2026 · dev |
| SEC-08 | Audit sécurité du jour (baseline + remédiation) : npm 3→0, CSP `connect-src 'self'`, résilience import, 5 tests transversaux | DONE | — | `docs/security-hardening/*` (12/10) + `DurcissementTransversalTest` (5) | AUTH-04 | Contradiction C1 (pint) ouverte | 12/10/2026 · dev |
| SEC-09 | Revue sécurité 4 périmètres : auth/session ✅ · API v1 ✅ · modules récents ✅ · fichiers/uploads ✅ | **DONE** | P1 | Rapports 12/10-13/10, preuves fichier:ligne : auth (1 P2 + 5 P3), API v1 (1 P2 + 7 P3), modules récents (2 P2 + 8 P3), fichiers (1 P1 + 1 P2 + 4 P3) | AUTH-01, SEC-10, SEC-11, SEC-12 | Corrections restent NOT_STARTED | 13/10/2026 · dev |
| SEC-12 | Corrections revue fichiers : **P1 — XSS stockée via maquettes documentaires** (`{!! $rendu !!}` `maquettes/show.blade.php:51`, filtre 5 littéraux insuffisant, CSP `unsafe-inline` → escalade admin_scolarité → super_admin/admin_faculté) · **P2** uploads enseignant hors convention GED (`mimes:` seul, sans signatures/AV) · **P3** justificatifs absence (stockés AVANT gardes, jamais consultés, racine hors purgeur) · **P3** `.doc/.xls` VBA acceptés en GED · **P3** `identity-scan/tmp` sans ramasse-miettes · P3 surface dormante `TeacherDocument` | **PARTIAL** — P1 corrigé (AC-00a, 13/10) | P2 | **P1 corrigé** : contenu rendu échappé `{{ $rendu }}` + test RED→GREEN `MaquettesXssSecuriteTest` (27/27 avec voisins) ; reste P2+P3 | EXM-08, PORT-07 | Correctifs restants à autoriser (uploads enseignant, justificatifs, GED legacy, sweeper tmp) | 13/10/2026 · dev |
| SEC-10 | Corrections revue API v1 : **V-1 (P2)** réinscription cross-faculté (programme cible non re-scopé dans `ReinscriptionService`) + 7 P3 (POST vérification sous scope de lecture, groupe `assignments/confirm` non scopé, TOCTOU idempotence cache, `health` sans throttle, divulgation sonde publique, confirm sans Idempotency-Key, `schedules/import/preview` sans scope + 500 sur groupe inconnu) | **NOT_STARTED** | P2 | Rapport API §V-1…V-9 (preuves fichier:ligne, 12/10) | INSC-01, PACA-05 | Fix V-1 = garde `programme_cible->faculte_id === etudiant->faculte_id` (service partagé web+API) + test cible cross-faculté | — |
| SEC-11 | Corrections revue modules récents : **V1 (P2)** pointage QR écrase un relevé `absent`/`retard`/`excuse` posé par l'enseignant · **V2 (P2)** ré-enregistrement de feuille supprime en cascade des justifications approuvées + 8 P3 (RDV `deplacer` sans revalidation créneau, `orWhere` non groupé file régularisations, scope→404 manquant notes/sentinel/`annulerLot`, throttles absents demandes enseignant, messages d'exception bruts PII/SQL, pièces jointes enseignant hors convention GED) | **NOT_STARTED** | P2 | Rapport modules §V1-V10 (preuves fichier:ligne, 12/10) | PORT-04, PORT-07, SEC-06 | Aucune migration requise — gardes de service/factorisation uniquement | — |
| SEC-13 | Extension Session Lock Pro (LOOP N, même boucle) : verrou étendu aux 15 groupes modules + endpoint Livewire (`setUpdateRoute`), sonde passive sans keep-alive, cohérence inter-onglets (horloge ancrée serveur, reprise sous voile), interception soumissions, réauth révocation sessions, `auth` sur déconnexion verrou, contrôle Sentinel `SECURITE_VERROUILLAGE`, B3/B6/B7 fermés | DONE | — | `docs/engineering/loop-n-rapport.md` §5 + `VerrouillageSecondaireTest` (18/18, 5 rôles) + voisins (MFA, Sentinel, Industrialisation, Evaluation, Schedule, Security ≈ 300 tests verts) + `audit:routes-securite` exit 0 | SEC-06, AUTH-01 | Réserves levées en LOOP O (`SEC-14`) sauf navigateur/veille/prod | 13/10/2026 · dev |
| SEC-14 | LOOP O — Levée des réserves du verrouillage : inactivité réelle 6 min prouvée (écran + reprise sans reconnexion), MFA E2E, API Sanctum documentée sans changement (décision), portail enseignant tranché (statu quo validé), B4 vérifié (CSRF/invité), perf mesurée (+2 bornées, budget 32), 303/303 + pint + audit verts | **PASS_WITH_RESERVATIONS** | — | `docs/engineering/loop-o-rapport.md` (preuves E2E exactes, inventaires, décisions) | SEC-13, SEC-10 | Restes : navigateur/veille `NOT_EXECUTED` (pas de navigateur connecté, pas de suite E2E) ; production non testée (interdite) ; step-up API et verrou enseignant = décisions ouvertes | 13/10/2026 · dev |
| SEC-15 | LOOP P — Clôture SEC-14 : contournement Livewire corrigé (ordre `web`→verrou + fail-safe, RED 500→423), budgets throttle compartimentés, 419 voile→rechargement, TTL API 90 j prouvé, **24/24 scénarios Chrome réel PASS**, portail enseignant inchangé (proposition §10 du rapport), 4 échecs préexistants budgets requêtes distingués | **PASS_WITH_RESERVATIONS** | — | `docs/engineering/loop-p-rapport.md` (§§1-16) + `VerrouillageSecondaireTest` (21/21) + `ApiAuthenticationTest` (8/8) + suite navigateur 12/12 ×2 + `pint --test` PASS + `audit:routes-securite` exit 0 | SEC-13, SEC-14, SEC-10 | Décisions D1 (TTL/scopes API), D2 (step-up API), D3 (verrou enseignant) = `BLOCKED_DECISION` ; 4 budgets requêtes aux propriétaires ; prod `NOT_VERIFIED` | 13/10/2026 · dev + Chrome réel |

### 2.9 INFRA — CI/CD, infrastructure et exploitation

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| INFRA-01 | Workflow CI GitHub Actions (`ci.yml`) : audit composer/npm bloquants, pint, suite complète, build | **DONE** — **gate opérationnel** | — | **1ᵉʳ run CI vert le 13/10** : `38351101638` sur `1ab46c3` (job `qualite` ✓ 4m59s, 9/9 étapes : composer audit ✓ · Pint ✓ · 3 136 tests ✓ · npm audit ✓ · build ✓) après 3 itérations de durcissement prouvé au gate | INFRA-06 | Durcissements restants (permissions, épinglage SHA, Dependabot, job MySQL) → INFRA-06 ; protections de branche à configurer côté GitHub | 13/10/2026 · CI GitHub |
| INFRA-02 | Initialisation du dépôt Git + push distant (+ protections de branche) | **DONE** (init + push) | — | **Exécuté le 12/10** : `github.com/YassinoMed/Facult-`, `main` poussé (2 commits), `.env`/PII/venv/sqlite exclus — vérifiés avant ET après push ; **protections de branche non configurées** | INFRA-01 | Configurer required checks sur `qualite` une fois INFRA-03/04 verts | 13/10/2026 · GitHub |
| INFRA-03 | Clé de test `APP_KEY` dans `phpunit.xml` (CI sans `.env`) | **DONE** | — | **Exécuté le 13/10** (AC-00c) : `phpunit.xml` `<env APP_KEY>` + preuve = étape Tests CI **VERTE** (runs 38342392987→38351101638 : de 3 073 erreurs au vert complet) | INFRA-01 | — | 13/10/2026 · CI |
| INFRA-04 | Re-passe Pint | **DONE** | — | **Exécutée le 13/10** (AC-00c) : 10 fichiers corrigés, `pint --test` **VERT** local + **step CI vert** (runs 38342392987, 38351101638) — contradiction C1 résolue | INFRA-01 | — | 13/10/2026 · dev+CI |
| INFRA-05 | Couverture CI MySQL pour preuves de concurrence (`*MysqlTest` gated) | **NOT_STARTED** | P2 | 6 suites gated recensées (skips de la suite du 12/10 : 17) | INFRA-02 | La concurrence MySQL n'est jamais prouvée par CI | — |
| INFRA-06 | Durcissement CI : `permissions: contents: read`, épinglage SHA des actions, Dependabot, `composer validate`, `route:list` snapshot, `audit:routes-securite`, `concurrency`, `timeout-minutes` | **NOT_STARTED** | P2 | Audit CI/CD §6 (fiches CI-04..CI-10) | INFRA-01, INFRA-02 | — | — |
| INFRA-07 | Industrialisation Phases 25-32 : PWA manifest/SW, salles campus, reset mdp, sessions actives, CI + pint.json, sauvegardes `--verifier` + DR, `/admin/system`, `audit:routes-securite` | DONE | — | Suites industrialisation vertes le 12/10 ; `audit:routes-securite` : auth OK (48 REVUE qualifiées) | — | — | 12/10/2026 · dev |
| INFRA-08 | Exploitation production : TLS/TRUSTED_PROXIES, secrets/rotation, sauvegardes chiffrées, drill périodique, rotation logs | **NOT_VERIFIED** | P1 | `production-checklist.md` rédigée ; aucune vérification d'environnement | R11 | Décision/ops requise (DH-20) | — |
| INFRA-09 | Documentation import/export/affectation (audit-only 144 tests) + rapport `import-export-intelligent` (phases 0-12) | DONE | — | `docs/fonctionnement-import-export-affectation-groupes.md`, `docs/import-export-intelligent-rapport.md` | — | Limites signalées (export API export-seul, CIN clair export masse, plafond 10k mémoire) | 12/10/2026 · dev |

### 2.10 AUTH — Authentification, sessions et autorisations

| ID | Objectif & périmètre | Statut | Priorité | Preuves & tests | Dép. / collisions | Risques & décisions | Vérif. |
|---|---|---|---|---|---|---|---|
| AUTH-01 | Révocation des sessions au reset/changement de mot de passe + kill-switch super_admin + version mot de passe étudiant | **DONE** | — | **Corrigé le 13/10** (AC-00b) : purge aux 3 sites (broker reset, reset super_admin, suspension) + kill-switch `sessions.purger` (super_admin + throttle 5,1, log IP hachée) + `mot_de_passe_version` étudiant (`EnsureStudent`, rétrocompatible) — `RevocationSessionsTest` **6/6** + voisins 130 verts | SEC-01 | Suivi symétrique enseignant (même pattern version) = reste documenté | 13/10/2026 · dev |
| AUTH-02 | Temps constant sur les 3 voies de login | **PARTIAL** | P3 | Étudiant/enseignant : bcrypt factice (`ConnexionController.php:40-44`) ; admin : absent (`AuthController.php:31-38`) — oracle de timing | — | Résidu documenté (throttle+générique) ; à aligner à l'occasion | 12/10/2026 · audit |
| AUTH-03 | Contrôle du statut du dossier dans `EnsureStudent` (asymétrie avec `EnsureTeacher::actif`) | **NOT_STARTED** | P3 | `EnsureStudent.php:27` (`exists()` seul) | PORT-01 | Impact faible (accès consultatif SON dossier) ; à aligner ou assumer | 12/10/2026 · audit |
| AUTH-04 | CSP compatible Alpine (`@alpinejs/csp` build + nonces) | **NOT_STARTED** | P3 | `SecurityHeadersMiddleware.php:53` (`unsafe-inline/eval`) — nécessité Alpine documentée | SEC-08 | Décision requise (DH-19) : dépendance + refonte | 12/10/2026 · audit |
| AUTH-05 | Migration des comptes historiques `users.role=enseignant` (pont back-office→portail) | **NOT_STARTED** | P3 | `AuthController.php:62-74` — pas d'usurpation prouvable (revalidation base) | PORT-01 | Anomalie cosmétique (message de bienvenue) ; à documenter/migrer | 12/10/2026 · audit |
| AUTH-06 | Socle auth complet : sessions chiffrées/Secure/HttpOnly/Lax, `regenerate()` systématique, throttles 5/min sur 3 voies, reset broker 60 min, RBAC relu en base, CSRF zéro exemption, CORS fail-closed, tokens Sanctum CLI-only 90 j, remember rota | DONE | — | Audit auth 12/10 §A (preuves fichier:ligne) + `EspaceEtudiantTest`, `ArchiveAuthorizationTest`, `ApiAuthenticationTest` | SEC-01 | — | 12/10/2026 · dev |
| AUTH-07 | `TRUSTED_PROXIES` en production (fiabilité throttles IP + audit) | **NOT_VERIFIED** | P2 | `bootstrap/app.php:62` (vide par défaut, branché) | INFRA-08 | Derrière proxy non déclaré : throttles collectifs (auto-DoS) + IP du proxy dans les audits | — |

---

## 3. Fiches détaillées — entrées non DONE

Les risques, décisions et critères de clôture des entrées `PARTIAL`/`BLOCKED`/`NOT_STARTED`/`NOT_VERIFIED`/`IN_PROGRESS` sont développés dans `matrice-preuves-risques.md` (preuves) et `decisions-humaines.md` (décisions requises). Les statuts `BLOCKED` renvoient tous à une entrée du registre des arbitrages (`docs/pedagogie/registre-arbitrages-lmd.md`, ARB-001…009) ou à une décision d'exploitation.

## 4. Règles de maintenance du registre

1. Toute nouvelle mesure doit dater la commande et l'environnement ; les chiffres historiques ne sont **jamais** l'état courant (voir contradiction C2).
2. Une entrée passe à `DONE` uniquement sur preuve exécutable (test nommé vert dans une suite datée, ou mesure reproductible).
3. Les anciens numéros LOOP restent des références historiques : la clé stable est l'identifiant canonique.
4. Aucune contradiction n'est supprimée avant résolution par preuve vérifiable (`contradictions.md`).
5. L'état de développement n'est jamais assimilé à la production (`NOT_VERIFIED` jusqu'à vérification exploitant).
