# Registre canonique — Projet universitaire Laravel (12/10/2026)

Référence d'état : §1 du mandat LOOP 1 (reproduit en tête de chaque entrée par date).
Environnement : dev local (jamais la production). Dépôt Git absent (vérifié 3×).

## INSC — Inscription et expérience d'inscription

| Champ | Valeur |
|---|---|
| Identifiant | `INSC` (historique : LOOPs 02-23 expérience, MENS, CIN, périodes, reçus, FAQ, RDV) |
| Objectif | Parcours public 6 étapes, identification MENS fail-closed, périodes, checklist pièces, récépissé, FAQ+assistant, RDV, file de traitement |
| Statut | `DONE` (partiels assumés : FAQ/RDV motorisés minimaux, k6 partiel) |
| Priorité | Haute (cœur métier) |
| Preuves | Suites `Inscription*`, `Mens*`, `Periode*`, `Faq*`, `Complements*` vertes ; `docs/inscriptions/workflow.md` |
| Tests | ~150 (dossier `tests/Feature`, préfixe `Inscription|Mens|Periode|Faq|Complements`) |
| Risques | Trappe OTP absente (décision : CIN+naissance+throttles) ; préremplissage MENS partiel |
| Décisions | Aucune en suspens |
| Vérifié le | 12/10/2026, dev |

## PORT — Portails étudiant / enseignant

| Champ | Valeur |
|---|---|
| Identifiant | `PORT` (historique : LOOPs portails 01-23, EDT, présences, appel, notes, ressources, QR) |
| Objectif | 59 routes enseignant + 77 étudiant, auth session miroir, IDOR 404, modules gardés |
| Statut | `DONE` (NO-GO hors-ligne et IA exclus, sans réapprobation) |
| Preuves | 101+43 tests portail verts ; `route:list` 745 (référence historique) |
| Risques | Demandes enseignant (tables 2e main vs OnlineRequests — arbitrage requis, cf. DEC-03) |
| Vérifié le | 12/10/2026, dev |

## PACA — Affectation et parcours étudiants

| Champ | Valeur |
|---|---|
| Identifiant | `PACA` (historique : moteur affectation, cockpit masse, lots async, parcours-académique, simulation, contraintes) |
| Objectif | Moteur unique scoring/équilibrage, cockpit, lots >1000, plans capacité, contraintes configurables |
| Statut | `DONE` |
| Preuves | `Affectation*`, `Assignment*`, `ParcoursAcademique*` verts ; benchmarks 10k (LOOP 2 moteur) |
| Risques | Plafond 5000 sync (job au-delà) ; hiérarchie Domaine→Mention absente du modèle |
| Vérifié le | 12/10/2026, dev |

## REF — Référentiels et données académiques

| Champ | Valeur |
|---|---|
| Identifiant | `REF` (historique : master loop référentiel LOOP 0-8, imports, maquettes, standalone) |
| Objectif | Chaîne LMD, maquettes versionnées, imports, backfill, garde anti double-active |
| Statut | `PARTIAL` — 2 087 inscriptions sans programme, 33 matières sans correspondance, 5 ambiguës, SEMESTRES_NON_COUVERTS, DEMO-LGL S2-S6 sans sources |
| Preuves | `docs/engineering/referentiel-master-loop.md`, 235 tests ; chiffres : 4 programmes, 12 niveaux, 38 standalone |
| Risques | Toute affectation auto de données ambiguës interdite sans décision |
| Décisions | ARB-001…009 (`docs/pedagogie/registre-arbitrages-lmd.md`), toutes A_DECIDER |
| Vérifié le | 12/10/2026, dev |

## SCH — Emplois du temps et planification

| Champ | Valeur |
|---|---|
| Identifiant | `SCH` (historique : boucles SCHEDULE 01-15, TOCTOU, imports, notifications, benchmark) |
| Objectif | Créneaux verrouillés, concurrence FOR UPDATE, imports avec reprise, E2E chaîné |
| Statut | `DONE` (`CLOSED_WITH_RESERVATIONS` : navigateur NOT_EXECUTED) |
| Preuves | Module 153/153, `ScheduleRaceMysqlTest` 2/2 MySQL 9.2, benchmark 1000 créneaux |
| Vérifié le | 12/10/2026, dev |

## MTR — Matières, enseignements, maquettes

| Champ | Valeur |
|---|---|
| Identifiant | `MTR` (historique : catalogue ECUE, plan d'études, occurrences, wiring inscriptions/épreuves) |
| Objectif | Code canonique, scope `pourFaculte`, occurrences plan, génération épreuves occurrence-aware |
| Statut | `DONE` (réserve : normalisation accent MySQL vs PHP sur jumeaux, dette documentée) |
| Vérifié le | 12/10/2026, dev |

## EXM — Examens et évaluations

| Champ | Valeur |
|---|---|
| Identifiant | `EXM` (historique : délibérations, compostage anonyme, incidents, surveillances, dimensionnement, charge) |
| Objectif | Calcul centièmes, anonymat CMP, incidents, équilibrage surveillants, contraintes configurables |
| Statut | `DONE` |
| Preuves | `Evaluation*`, `Compostage*`, `Surveillance*`, `Deliberation*` verts ; 18 scénarios adversariaux |
| Risques | Tranche /50 heuristique sans source réglementaire (signalée) |
| Vérifié le | 12/10/2026, dev |

## SEC — Sécurité applicative

| Champ | Valeur |
|---|---|
| Identifiant | `SEC` (historique : audits 24-25/09, vagues 28-29/09, §11 du 04/10, hardening 12/10, CI/CD gate) |
| Objectif | Isolation faculté écritures, RBAC fail-closed, IDOR/BOLA, uploads, XXE/CSV, webhooks, headers |
| Statut | `DONE` (réserves : CSP `unsafe-inline/eval`, énumération temporelle login admin, sonde `verifier`, `SessionExamen` transverse) |
| Preuves | Security 63/63+ ; `FaculteScopeEcritureTest` 19 ; `composer audit` 0, `npm audit` 0 (12/10) ; `docs/AUDIT-SECURITE.md` §11 |
| Risques | `FinancePolicy::manage` latent (matrice) ; secrets/TLS prod `NOT_VERIFIED` |
| Vérifié le | 12/10/2026, dev |

## INFRA — CI/CD, infrastructure, exploitation

| Champ | Valeur |
|---|---|
| Identifiant | `INFRA` (historique : audit CI/CD, sauvegardes planifiées/chiffrées, Sentinel, supervision, DR) |
| Objectif | `.github/workflows/ci.yml` (qualité+Pint+tests+audit deps+build), backups RPO 1h, Sentinel, DR |
| Statut | `PARTIAL` — CI non prouvée de bout en bout (runs non observés) ; drill restauration PASS isolé ; prod `NOT_VERIFIED` |
| Preuves | `docs/security-hardening/` (baseline, remediation, production-checklist) ; `BackupRoundtripTest` gaté |
| Décisions | Protection `main`/checks requis à confirmer côté ops (sinon `BLOCKED`) |
| Vérifié le | 12/10/2026, dev |

## AUTH — Authentification, sessions, autorisations

| Champ | Valeur |
|---|---|
| Identifiant | `AUTH` (historique : 5 rôles × permissions, policies, scopes faculté, Sanctum 90j, sessions) |
| Objectif | Login temps constant (étudiant), générique + throttle (admin), `regenerate`, 404 scope-first |
| Statut | `DONE` (réserve : 2FA absente, mots de passe démo en dev) |
| Preuves | `ArchiveAuthorizationTest`, `SeparationProfilsTest`, `ApiAuthenticationTest` |
| Vérifié le | 12/10/2026, dev |

## Archives académiques (module transverse, LOOPs 0-12 du 12/10/2026)

| Champ | Valeur |
|---|---|
| Identifiant | Rattaché `REF` + `SEC` (registre `admin/archives`, 25 routes) |
| Statut | `DONE` (LOOP 11 `PASS WITH RISKS`, LOOP 12 `PASS`) |
| Preuves | Security-archives 107, Archives 57/57, voisins 154-294, MySQL concurrence 2/2 + bench 10k/50k/100k |
| Risques | P3 : `filesort` >100k, `adresse_ip` journal, listes `show()` non bornées ; a11y navigateur `NOT_EXECUTED` |
| Vérifié le | 12/10/2026, dev |
