# LOOP GLOBAL 01 — Inventaire automatique de la plateforme

**Date :** 2026-10-25 · **Mode :** lecture seule.
**Méthode :** `route:list --json`, inventaire `app/` (Modules, Models,
Services, Jobs, Policies), `config/modules.php`, `app/Support/Permissions.php`,
`database/migrations/`, `tests/`, `routes/console.php`, rapprochement avec les
rapports préexistants (175 fichiers `docs/engineering/`). Les champs non
vérifiés portent `À IDENTIFIER` — jamais comblés par déduction.

## 1. Volumétrie globale constatée

| Indicateur | Valeur |
|---|---|
| Routes enregistrées | **1 011** (admin 758, étudiant 82, enseignant 67, api 53, inscription 25, livewire 9, assistant-ia 8, autres 9) |
| Modules déclarés (`config/modules.php`) | 22 (+ registres `nav`/`widgets`) |
| Modèles Eloquent | 198 (75 racine + 123 en modules) |
| Contrôleurs | 180 |
| Services métier | 206 |
| Jobs asynchrones | 10 (+ sous-dossier `Io`) |
| Commandes Artisan | 47 |
| Tâches planifiées (`routes/console.php`) | 21 |
| Policies | 37 |
| Migrations | 197 |
| Clés de permission (registre central) | 419 |
| Rôles back-office déclarés | `super_admin`, `admin_scolarite`, `agent_scolarite`, `consultation`, `admin_faculte` (+ comptes portails étudiant/enseignant) |
| Fichiers de test | 542 (525 Feature, 17 Unit) |
| Méthodes de test | 4 748 |

**Drapeaux modules par défaut :** actifs à true : `enseignement`,
`referentiel`, `versioning`, `assistant-ia`, `aide`. Tous les autres à false
(dont `schedule`, `evaluation`, `attendance`, `finances`, `sentinel`, et les
4 modules récents simulateur/décrochage/assurance-qualité/compétences-via-référentiel)
— l'activation est un acte serveur explicite.

## 2. Registre central des fonctionnalités

Identifiants stables `F-xx`. Statut de présence : `IMPLEMENTED` /
`PARTIAL` / `NOT_FOUND_AFTER_AUDIT`. Le verdict (colonne finale) est le
verdict **d'audit d'inventaire** ; les verdicts de contrôle approfondi
arriveront avec les LOOP 03-08.

| ID | Fonctionnalité | Module(s) | État présence | Preuves (routes/tests/rapports) | Points d'attention | Verdict inventaire |
|---|---|---|---|---|---|---|
| F-01 | Authentification back-office, MFA TOTP, PIN, verrouillage | `Admin/AuthController`, `MfaController`, `MfaTotpService`, `VerrouillagePin` | IMPLEMENTED | routes auth ; tests Mfa (2), Auth (6) ; rapports LOOP J | — | À contrôler (LOOP-04) |
| F-02 | Rôles/permissions/périmètres facultaires | `AttributionRoleService`, `Support/Permissions` (419 clés), `User::peutAccederFaculte` | IMPLEMENTED | tests Permission/Role, Securite (15) ; matrices admin-center | Registre volumineux — cohérence à vérifier | À contrôler |
| F-03 | Récupération de compte portails | `PortalPasswordResetService`, `PortalPasswordResetToken` | IMPLEMENTED | tests (audit-retention) | À contrôler (LOOP-04) | À contrôler |
| F-04 | Référentiel LMD (facultés→ECUE, 9 entités, CRUD, arbre, qualité) | `Referentiel` | IMPLEMENTED | 25 suites de tests ; `REFERENTIEL-*.md` | — | À contrôler |
| F-05 | Maquettes versionnées (workflow, verrouillage, snapshot, comparaison, activation unique) | `Referentiel` (MaquetteService…) | IMPLEMENTED | 9 suites Maquette* (dont concurrence MySQL) | — | À contrôler |
| F-06 | Compétences et couverture pédagogique (Lot 20) | `Referentiel` (+Competence/Objectif/Service) | IMPLEMENTED | `ReferentielCompetencesTest` 18/18 ; rapport LOOP-20 | UI différée (JSON) ; catalogue réel vide (à saisir) | PASS_WITH_RESERVATIONS (LOOP-20) |
| F-07 | Imports/exports référentiel et maquettes (DataExchange, définitions, idempotence) | `Services/DataExchange` | IMPLEMENTED | 27 suites Import*, 15 Export* | Ambiguïtés par code : traité pour les compétences (LOOP-20) | À contrôler |
| F-08 | Inscriptions académiques (nouvelle, validation, unicité annuelle, corrections, affectation auto) | `InscriptionService`, `AdmissionService`, `AffectationAutomatiqueService`… | IMPLEMENTED | 42 suites Inscription* ; rapports LOOP-INSCRIPTION* | — | À contrôler |
| F-09 | Dossiers étudiants (pièces, vérification, coffre, antivirus) | `StudentDossiers`, `DossierVerification` | IMPLEMENTED | 15 suites Document* ; VERIFICATION-DOSSIER.md | — | À contrôler |
| F-10 | Portail étudiant (profil, résultats publiés, documents, RDV, simulation parcours) | `Etudiant/*Controller`, `ParcoursAcademique` | IMPLEMENTED | 13 suites Etudiant*, 11 Parcours* ; LOOP-04 | Vérifier isolation inter-étudiants (IDOR) | À contrôler |
| F-11 | Portail enseignant (connexion, profil, tableau de bord) | `Enseignant/*Controller` | IMPLEMENTED | 14 suites Enseignant* | À contrôler | À contrôler |
| F-12 | Groupes et affectations (manuelle, masse, simulation technique) | `Affectation*`, `AssignmentSimulation` | IMPLEMENTED | docs assignments ; RAPPORT-AFFECTATION-AUTO | Simulation = objet technique (frontière documentée) | À contrôler |
| F-13 | Emploi du temps / salles (Room 69 lignes réelles) | `Schedule` | IMPLEMENTED | 32 suites Schedule* | Module désactivé par défaut | À contrôler |
| F-14 | Évaluations, notes, délibérations (verrouillage, publication) | `Evaluation`, `Note`, `AcademicCalculationService` | IMPLEMENTED | 14 Evaluation*, 6 Notes*, 13 Exam* | Publication/verrouillage = zones sensibles (LOOP-05) | À contrôler |
| F-15 | Examens (sessions, épreuves, surveillances, éligibilité) | `Evaluation`/exam | IMPLEMENTED | 13 Exam* ; LOOP-AUTO-PASS-EXAMENS | — | À contrôler |
| F-16 | Émargement/présences (module Attendance) | `Attendance` (Presence, UNIQUE étudiant/date/séance) | IMPLEMENTED | tests attendance ; presences réelles = 0 ligne | PWA **hors ligne** : `À IDENTIFIER` (route `hors-ligne` ×1 détectée) | À contrôler |
| F-17 | Archives étudiantes | `StudentArchive`, `ArchiveService` | IMPLEMENTED | 27 suites Archive* | Rétention à contrôler (LOOP-05) | À contrôler |
| F-18 | Attestations documentaires (QR, signatures, vérification) | `DocumentAcademique`, `DocumentVerification`, signatures | IMPLEMENTED | LOOP-07 coffre ; tests documents | QR : à contrôler (LOOP-04) | À contrôler |
| F-19 | Notifications unifiées (centre, préférences, templates, canaux simulés) | `CentreNotificationsUnifieService`, `NotificationPreference`… | IMPLEMENTED | LOOP-02 ; 9 suites Notification*, 3 Campagne* | Catégorie « suivi » absente (réserve Lot 15) | À contrôler |
| F-20 | Campagnes courriel (studio, SMTP UI, contrôle qualité) | `CampagneCourriel*`, `MoteurRenduCourriel` | IMPLEMENTED | LOOP J ; jobs TraiterLotCampagne | Envois réels interdits en tests (fake transport à vérifier) | À contrôler |
| F-21 | Cockpit KPI / BI scolarité | `CockpitController`, `BIService`, `KpiDefinition` | IMPLEMENTED | LOOP-03-COCKPIT-KPI* ; bi:prewarmer planifié | — | À contrôler |
| F-22 | Data Mesh (produits, contrats, observabilité, outbox) | `app/DataMesh`, `DataMeshController` | IMPLEMENTED | 9 suites DataMesh* ; DATA-EXCHANGE.md | — | À contrôler |
| F-23 | Workflow / Centre de travail / tâches | `Tasks`, `Workflow`, `GenererTaches` | IMPLEMENTED | 5 Workflow*, 3 Task* ; admin-center-rapports | — | À contrôler |
| F-24 | Assistant IA back-office (RAG, citations, outils, quotas) | `AssistantIa` | IMPLEMENTED | 7 suites AiAssistant* (111 tests baseline) ; rapports AI-* | Cloisonnement RAG/prompt injection déjà testés (baseline) | PASS_WITH_RESERVATIONS (baseline re-vérifiée ce jour) |
| F-25 | Assistant IA front (`ai-assistant`, module séparé `AiAssistant`) | `AiAssistant` | PARTIAL | 8 routes ai-assistant ; docs AI-ASSISTANT-FOUNDATION | Front distinct du back-office — articulation à clarifier | À contrôler |
| F-26 | Simulateur de scénarios institutionnels (Lot 14) | `SimulateurScenarios` | IMPLEMENTED | 16/16 tests ; LOOP-14 | UI différée ; coûts exclus | PASS_WITH_RESERVATIONS (LOOP-14) |
| F-27 | Prévention du décrochage (Lot 15) | `PreventionDecrochage` | IMPLEMENTED | 26/26 tests ; LOOP-15 | Notifications/ no-show réservés | PASS_WITH_RESERVATIONS (LOOP-15) |
| F-28 | Assurance qualité et accréditation (Lot 16) | `AssuranceQualite` | IMPLEMENTED | 24/24 tests ; LOOP-16 | Catalogue réel vide (à saisir) | PASS_WITH_RESERVATIONS (LOOP-16) |
| F-29 | Charges d'enseignement | `Enseignement` (AffectationEnseignement, actif par défaut) | IMPLEMENTED | tests enseignement ; MODULE_ENSEIGNEMENT=true | **Remplacements** : `À IDENTIFIER` | À contrôler |
| F-30 | Finances (frais, dossiers paiement, rapprochement) | `Finances`, `InitialiserDossiersPaiement`, `AuditRapprochementFinancier` | PARTIAL | 1 suite Finances* ; commandes dédiées | Webhooks fournisseurs : `À IDENTIFIER` ; prestataire simulé à confirmer | À contrôler |
| F-31 | Sentinel (incidents, règles, checks, supervision) | `Sentinel`, `ControlerSentinel` | IMPLEMENTED | 5 suites Sentinel* ; platform-sentinel.md | Désactivé par défaut | À contrôler |
| F-32 | Versioning (releases, déploiements, drift) | `Versioning` (actif par défaut), 6 commandes Versioning* | IMPLEMENTED | MODULE-VERSIONING.md | — | À contrôler |
| F-33 | Sauvegardes chiffrées + restauration testée | `BackupService`, `ExecuterSauvegardeJob`, `TesterRestaurationJob`, `EvaluerSauvegardes` | IMPLEMENTED | commandes + jobs | Restauration : preuve à produire (LOOP-05) | À contrôler |
| F-34 | Journal d'audit académique | `HistoriqueAcademique` (immuable, created_at only) | IMPLEMENTED | utilisé comme preuve Lot 16 | Pas de journal d'accès aux journaux (→ Lot 19) | À contrôler |
| F-35 | Hub d'intégration (Lot 18 — cible) | `Services/Io` (WebhookVerifier HMAC, IntegrationLog, tokens), `Api/V1/WebhookController` | PARTIAL | webhooks authentifiés + journal immuable confirmés (audit directeur 14-20) | Contrats versionnés formels, retries bornés, replay audité : NON livrés (lot 18 non démarré) | NOT_FOUND_AFTER_AUDIT (en tant que hub formalisé) |
| F-36 | Centre de gouvernance et conformité (Lot 19 — cible) | — | NOT_FOUND_AFTER_AUDIT | socle existant : Sentinel, MFA, rôles, sauvegardes | Vue unifiée accès sensibles / rétentions : absente | NOT_FOUND_AFTER_AUDIT |
| F-37 | Portail alumni (Lot 17 — cible) | — | NOT_FOUND_AFTER_AUDIT | audit directeur 14-20 : aucun modèle/consentement | Bloqué : source de diplomation non confirmée | NOT_FOUND_AFTER_AUDIT |
| F-38 | Aide / KB utilisateur / FAQ | `Aide` (actif), `AssistantFaqService` | IMPLEMENTED | LOOP-AIDE-KB-UTILISATEUR-01 | — | À contrôler |
| F-39 | Annonces / communications | `Announcements` (+ `annonces:publier` planifié) | IMPLEMENTED | tests announcements | Désactivé par défaut | À contrôler |
| F-40 | Calendrier | `Calendar` | IMPLEMENTED | tests calendar (À IDENTIFIER précisément) | Désactivé par défaut | À contrôler |
| F-41 | Demandes en ligne (RDV scolariat, online requests) | `OnlineRequests`, `RendezVousScolarite*` | IMPLEMENTED | SLA commandé (`demandes:verifier-sla`) | Statut « no-show » absent (réserve Lot 15) | À contrôler |
| F-42 | PWA hors-ligne (émargement déconnecté) | route `hors-ligne` ×1 | `À IDENTIFIER` | 1 route détectée ; manifest/JS à examiner | Probablement PARTIAL — audit LOOP-03 requis | UNKNOWN |

## 3. Tâches planifiées (21) et jobs asynchrones (10+) — inventaire

- **Planifiées :** annonces:publier, bi:prewarmer, bi:synchroniser-kpi-definitions,
  campagnes (envoyer, traiter-planifiees), demandes:verifier-sla,
  documents:marquer-expires, exports:purge, maintenance:purger-logs,
  notifications (purger, reprendre-echecs), + 12 autres (routes/console.php).
- **Jobs :** sauvegardes (create/exec/test-restore), imports data, lots
  d'affectation, campagnes, exports population, batches documentaires,
  vérification dossiers, acheminement notification externe.
- Files `database` ; aucune exécution réelle de job pendant l'audit.

## 4. Limites de cet inventaire

1. Tests navigateur/E2E : aucun outil automatisé détecté à ce stade —
   parcours UX en `NOT_EXECUTED` jusqu'à confirmation.
2. Les états « À contrôler » sont des périmètres présents avec tests
   existants, dont la ré-exécution systématique est planifiée (LOOP 03/10).
3. Les volumétries proviennent de `route:list --json` et de greps sur
   l'arbre — elles décrivent le code, pas l'activation runtime (drapeaux).

**Prochaine action :** matrice de couverture fonctionnelle (familles A→K)
puis plan de test systématique — avant toute correction.
