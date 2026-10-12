# Rapport — Automatisation intelligente des inscriptions et affectations (LAN)

- Date : 2026-10-11. Phase : **LAN uniquement** (diagnostic + plan) conformément au prompt §17.
- Registre des cas : `inscription-automation-cases.md` · Progression : `inscription-automation-progress.md` · Règles d'affectation : `inscription-group-assignment.md`.
- Baseline exécutée : **85 tests, 358 assertions, 0 échec, 1 skip (FOR UPDATE MySQL, gaté)**.

## 1. Architecture réelle (proposition §3 → existant)

Principe du prompt appliqué : « réutiliser les composants existants, éviter les services redondants ». Aucune classe n'est créée pendant cette phase LAN.

| Composant proposé | Composant réel du dépôt | Verdict LAN |
|---|---|---|
| `InscriptionAutomationOrchestrator` | Orchestration ÉVÉNEMENTIELLE existante : `InscriptionValidee` (dispatch APRÈS commit, `$afterCommit`) → listener `TenterAffectationAutomatique` (catch-all jamais bloquant) ; `AffectationMasseLot` (job tranches 500, reprise `traites_ids`, annulation coopérative, auteur re-vérifié) ; file administrative = état **calculé** (`FileTraitementService`), pas de table | **Ne pas créer** d'orchestrateur monolithique (duplication) — l'orchestration par événements est en place et prouvée |
| `InscriptionEligibilityService` | Moteurs séparés : `DocumentsRequisService` (matrice par type, `exigerPourValidation`), `IdentificationIntelligenteService` (MENS fail-closed, 4 statuts, divergences), `BlocageInscriptionService` (moteur + codes), `VerificationEngine` (11 règles, verdicts BLOQUANT/A_VERIFIER), `AffectationEligibilityService` (5 axes), `DoublonsRule` + `FicheEtudiantService::doublonsPotentiels` (scopé périmètre) | **GAP (façade)** : aucun point unique ne retourne le « résultat structuré §6 » ({eligible, contrôles, blocages, avertissements, actions, intervention humaine}) → à créer comme FAÇADE AUCUNE NOUVELLE RÈGLE (LOOP A) |
| `InscriptionRulesEngine` | `VerificationEngine` + `DocumentsRequisService` + `PeriodeInscriptionService` (priorité NORMAL→EXCEPTION, bornes fuseau Tunis, re-vérification dans les transactions §19-20) | Existant — réutiliser |
| `InscriptionAnomalyDetectionService` | `VerificationEngine` (verdicts), `DetecteurIncoherencesInscription`, `DoublonsRule`, `AnomalieQualite` (persisté, dédup `cle_unique`), `MensDivergenceRule` (R12, 4-eyes) | Existant — réutiliser |
| `InscriptionWorkflowService` | `InscriptionService::transiter` (TRANSITIONS serveur `en_cours→{valide,ajourne,abandon}`, `lockForUpdate` + re-vérifications période/matrice/blocages DANS la transaction), `StudentStatusService::changer` (verrou + re-vérif + no-op + notif dédup), `AcademicProgressionService` (dry-run/appliquer transactionnel idempotent) | Existant — machine à états UNIQUE à préserver (aucune 2e à créer) |
| `InscriptionProcessingJob` | `AffectationMasseLot` + jobs existants (`GenerateOfficialDocumentsJob`, `ExecuteDataImportJob`) — queue `database` opérationnelle | File dossier = état calculé : job générique NON REQUIS (documenté, pas de table créée sans besoin) |
| `AffectationDecisionService` | `AffectationEngine` (scoring configurable `config/affectations.php`, `noter/certitude`), `AssignmentSimulationService` (contraints-first, seed déterministe `hash(seed:id)`, `planCapacite`, alternatives, simulation SANS écriture), `AffectationAutomatiqueService::tenter` (éligibilité, scope-first, idempotence EXISTING, verrou LOCKED, moins-rempli/id + fallback capacité) | Existant — réutiliser |
| `ChangementGroupeService` | **ABSENT**. Primitives : `AffectationService::retirer` (admin), `POST inscriptions/{inscription}/reaffecter` (relance mêmes règles), `AffectationGroupe.statut = cloturee` (transfert direct A→B prouvé `AffectationConcurrenceTest`), propositions acceptee/modifiee/rejetee | **GAP PRINCIPAL** (§9) : workflow de changement de groupe distinct à créer (LOOP B) |
| `AutomationAuditService` | `AuditService` (historique académique sans PII, actions libellées), `IntegrationLog` (request_id, sans PII), `HistoriqueAcademique` | Existant — réutiliser, ne pas dupliquer |
| `AutomationMetricsService` | `FileTraitementService::kpis` (volumes, souffrance 48h/7j, délai moyen, actions, flux du jour), `BIService` (gouverné + cache versionné), `DemandesStatsService` | Existant — compléter par des taux dérivés si besoin (LOOP D, optionnel) |

## 2. Statuts et transitions réels (inventaire §5)

| Entité | États | Transitions/gardes | Preuves |
|---|---|---|---|
| `inscriptions_academiques` | `en_cours` → `valide` \| `ajourne` \| `abandon` (terminaux) | `TRANSITIONS` const + `lockForUpdate` + re-vérif période (§19-20) + matrice documents (`REGISTRATION_DOCUMENTS_INCOMPLETE`) + blocages codes explicites (`BlocageInscriptionService`) ; jamais de bypass | `InscriptionsPhase3Test`, `CentreResolutionTest`, `PeriodeInscriptionTest` |
| `etudiants.statut` | `en_attente` / `valide` / `rejete` | `StudentStatusService::changer` : verrou + re-vérification + no-op sans redispatch + notif dédup (clé situation) | `ExperienceInscriptionLoopTest` |
| Verdicts dossier | `BLOQUANT` / `A_VERIFIER` (+ PASS) | 11 règles + `PiecesStatutsRule` ; runs append-only (`dossier_verifications`) ; corrections 4-eyes `proposee→approuvee/rejetee→appliquee` | `VerificationBoucleTest` (11) |
| Affectations | `active` / `cloturee` (+ `verrouille`, `confirme_le/par`) ; propositions `en_attente→acceptee/rejetee/modifiee` | `affecter/retirer` : verrous ordonnés **étudiant→groupe** (anti-deadlock), double `tenter` = EXISTING, transfert A→B = cloturee+active | `AffectationConcurrenceTest` (4, dont 1 skip MySQL) |
| Identification MENS | `EXISTING_STUDENT` / `MENS_SEUL` / `MENS_ET_LOCAL` / `UNKNOWN` (+ `BLOQUANT` divergences) | fail-closed prouvé (`IdentificationFailClosedTest` 11) ; divergences → 4-eyes `MensDivergenceRule` | `MensInscriptionIntelligenteTest` (9) |
| Périodes | `active` / `revoquee` / `utilisee` ; états UI `OPEN/CLOSED/EXCEPTION_OPEN/NOT_CONFIGURED` | Priorité NORMAL→EXCEPTION, one-shot verrouillée, fuseau Tunis (`convertirSaisie`), re-vérification DANS les 2 transactions de création | `PeriodeInscriptionTest` (10) + mission (5) |

**Couverture §5 (17 situations)** : 14 couvertes par l'existant (reçu, en traitement, à vérifier, documents manquants, MENS indisponible/divergent, doublon, bloqué, prêt, validé, rejeté motivé, correction, corrigé resoumis, affecté, réaffectation) ; « identité à confirmer » = divergence MENS/OCR (couvert) ; « dossier nécessitant une réaffectation » = relance `reaffecter` + propositions (couvert pour l'admin) ; **« changement de groupe » = gap** (LOOP B) ; « archivé » = module Archives (existant, hors périmètre inscription).

## 3. Gaps confirmés (LAN) et plan de développement

| # | Gap | Priorité | LOOP | Contenu |
|---|---|---|---|---|
| G1 | Façade d'éligibilité unique (résultat structuré §6) | P1 | **A** | `InscriptionEligibilityService::evaluer(inscription)` — agrège Documents/MENS/Blocages/Vérifications/Période → {eligible, controles, blocages codés, avertissements, actions_proposees, intervention_humaine} ; dry-run pur, AUCUNE nouvelle règle, aucune écriture ; tests : complet/doc manquant/MENS indispo/divergence/doublon/période fermée/blocage |
| G2 | Changement de groupe = workflow distinct (§9) | P1 | **B** | `ChangementGroupeService` : demande (étudiant via type OnlineRequests ou admin), éligibilité (origine active, cible compatible niveau/programme/faculté, capacité), simulation, approbation humaine si requise, application transactionnelle (verrous ordonnés étudiant→groupes, re-vérif sous verrou), historique cloturee, idempotence (demande identique → no-op), notifications post-commit ; tests des 18 cas §9 |
| G3 | Catégories file §7 (tentatives, reprise) | P2 | **C** | File = état calculé : tentatives déjà tracées par les runs `dossier_verifications` + corrections ; documenter la catégorie « erreur technique temporaire » → mapping vers `AnomalieQualite`/reprise lots (existant) ; PAS de table file créée sans besoin |
| G4 | Métriques §14 (taux dérivés) | P3 | **D** | Taux d'affectation auto réussie (dérivé audits `affectation.auto_assignee/auto_non_assignee`), taux changements nécessitant intervention (dérivé propositions) — agrégats sans PII via KPIs existants |

**Décisions LAN à préserver (règles §16)** : machine à états unique ; MENS fail-closed ; jamais de validation auto assimilée à validation humaine (la façade G1 marque explicitement `intervention_humaine: true` là où `exigerPourValidation`/4-eyes s'appliquent) ; OCR = extraction + signalement, jamais décision.

## 4. Verdict LAN

**Diagnostic établi sur preuves** : l'architecture cible est couverte à ~80 % par l'existant (moteurs, workflow verrouillé, idempotence, concurrence, audit, simulation, file) ; 2 gaps réels (façade éligibilité, changement de groupe) + 2 compléments optionnels (catégories file documentées, métriques dérivées). Plan prêt pour DEV — aucune modification de code effectuée en LAN, conformément au prompt.
