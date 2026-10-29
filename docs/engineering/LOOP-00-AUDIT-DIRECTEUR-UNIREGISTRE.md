# LOOP-00 — AUDIT DIRECTEUR UNIREGISTRE

**Date d'exécution :** 24/10/2026
**Mode :** Lecture seule intégrale — AUCUNE modification métier (le présent rapport est le seul fichier créé par ce lot).
**Références programme :** LOOP MASTER — 12 fonctionnalités + LOT 13 validation globale. Nomenclature statuts : `EXISTANT_VALIDÉ / EXISTANT_PARTIEL / EXISTANT_DÉSACTIVÉ / À_ÉTENDRE / ABSENT_CONFIRMÉ / À_CLARIFIER`.

---

## 0. Méthode et limites de l'audit

- **Méthode :** état Git initial capturé, puis 4 explorations « very thorough » en lecture seule par domaine (notifications/KPI/IT ; IA-RAG ; pédagogique examens/notes/surveillances/émargement/charges ; documents/QR/workflow/finances/LMD), complétées par des vérifications transverses ciblées (scheduler, queue, composer, pratiquants de squelettes de tests) et un **run de tests ciblé réel**.
- **Distinction permanente :** *trouvé* (preuve fichier:ligne) ≠ *opérationnel* (module activé, flux réel) ≠ *validé* (exécution de suite de tests observée par moi).
- **Exclusions :** aucune suite complète (128+ fichiers de tests) n'a été relancée en totalité ; qualification par échantillons représentatifs + rappels des runs de régression antérieurs de ce même cycle (EXAMENS-04 : 78/78 régression Évaluation, 17/17 création examen).
- **Honnêteté des preuves :** tout résultat de commande est réellement exécuté ; les greps « ABSENT_CONFIRMÉ » sont accompagnés de leur liste de patterns.

---

## 1. État initial

| dimension | valeur |
|---|---|
| HEAD initial | `38ef149` (branche `feature/admin-modifier-etudiant`) |
| Fichiers localement présents | 669 entrées à l'ouverture du cycle → **671** à la rédaction (création des rapports LOOPS antérieurs de ce cycle) — aucune perte, HEAD inchangé |
| Environnement | PHP 8.5.11, Laravel 13.33.0, node 26.0.0 / npm 11.12.1 — **CI sur PHP 8.4 (écart documenté, réserves préexistantes)** |
| Queue | `database` (config/queue.php:16) |
| Architecture | CORE + MODULES : `app/Modules/*` (18 modules), activation `config/modules.php` (env `MODULE_*`, 404 via middleware `module:*`), permissions centralisées `app/Support/Permissions.php`, policies noyau dans `AppServiceProvider` |
| Docs d'architecture | `docs/architecture/` (KNOWLEDGE-BASE-RAG.md, KNOWLEDGE-BASE.md, UNIREGISTRE-AI-ASSISTANT.md, final-architecture.md, database.md, overview.md, source-of-truth.md…) ; `docs/data-mesh/` (18 documents, dont ARCHITECTURE.md, DATA-PRODUCT-CATALOG.md) ; série LOOP existante dans `docs/engineering/` (aucun rapport `LOOP-XX-<lot>` préexistants à écraser) |
| Composer | **AUCUNE** lib QR-code, webhook/billing, ni phpstan (proposition statique : ajouter phpstan à terme) ; dompdf 3.1 + openspout 5.11 opérationnels |
| CI | composer audit, `pint --test`, artisan test, npm audit, build Vite |

**Défauts d'activation (`config/modules.php`)** — actifs par défaut : `enseignement`, `referentiel`, `versioning`, `assistant-ia`, `aide` ; désactivés par défaut (activés localement via `.env`) : `evaluation`, `schedule`, `attendance`, `student-dossiers`, `student-archive`, `finances`, `parcours-academique`, `online-requests`, `sentinel`, `tasks`, `announcements`, `calendar`, `ai-assistant` (voir matrice §3).

---

## 2. Cartographie des 12 fonctionnalités (avec preuves)

### F1 — LOT 01 · Workflow de validation configurable
**Statut : EXISTANT_PARTIEL (moteur complet mais FIGÉ et désactivé par défaut).**
- Module `OnlineRequests` : transitions FSM en `const` sans définition versionnée — `app/Modules/OnlineRequests/Services/RequestWorkflowService.php:15-25` (draft→submitted→received→in_review→waiting_student/approved/rejected→completed ; 3 terminaux) ; transitions sécurisées par `OnlineRequestService.php:141-160` (`lockForUpdate` + garde FSM + audit ; statut jamais mass-assignable `Models/OnlineRequest.php:28-36`).
- SLA : `SlaService.php:60-103` + scheduler `demandes:verifier-sla` 07:30 (`routes/console.php:16`), échéance = `processing_days` du type (`Models/OnlineRequest.php:128-144`).
- Fil messages + pièces jointes + audit demandes ; Policy périmètre faculté (snapshot) : `Modules/OnlineRequests/Policies/OnlineRequestPolicy.php:14-66` ; 15 permissions `demandes.*` (`Permissions.php:93-107`, matrice L345-449 : agent sans approve/reject, consultation lecture).
- **Garde-fous transverses : ~20 FSM en `const`** (examens, sessions, épreuves, délibérations, NoteEpreuve, incidents — grep `TRANSITIONS =`).
- Manque pour le lot : définitions de workflow versionnées en base, quatre-yeux sur approbation, délégations.
- Tests : `OnlineRequestTest` (9 — cycle complet, retard), `OnlineRequestSecurityTest` (7 — anti-IDOR, statut non injectable) — **PASSED ce jour (§6)**.

### F2 — LOT 02 · Centre de notifications unifié
**Statut : EXISTANT_PARTIEL (noyau unifié réel et testé ; canaux/échecs incomplets).**
- `app/Services/NotificationService.php:27-69,150-192,253-281` : création typée, dédup `data->cle_dedup`, diffusion masse par lots de 500 sans N+1.
- Canal externe observable : job dédié queue `notifications` (tries 3, backoff 5/30/120) — `app/Jobs/AcheminerNotificationExterneJob.php:11-37` + pare-feu anti-PII `CanalNotificationExterneSimule.php:24-33` (binding `AppServiceProvider:78-81`).
- Gabarits versionnés + opt-out par catégorie (résultats/documents officiels jamais désactivables) — `PreferenceNotificationService.php:15-55`, tables `notification_templates` / `notification_preferences` (migrations 2026-10-01).
- Règles événement→gabarit + déduplications 60 min : `config/communication.php:15-80` + `CommunicationDispatcher.php:15-99`.
- 3 portails + cloche commune : `routes/web.php:309-311,414-416,672-674` ; composer unifié `AppServiceProvider:368-399` ; UI gabarits admin (`can:configuration.manage`).
- Scheduler : `annonces:publier`/`campagnes:envoyer` (15 min), `campagnes-courriel:traiter-planifiees` (5 min + jobs par lot), `notifications:purger` (hebdo), `exports:purge` (horaire) — `routes/console.php`.
- E-mail sans PII : journal `courriels` (adresse masquée + hash, migration 2026-10-14), SMTP nommé, `CampagnePerimetre` serveur.
- Manque : contrat commun inter-canal (pas de Mailable du centre), **pas d'outbox**, échecs tracés mais pas de scanner/reprise, `PreferenceNotificationService::definir()` **jamais exposé HTTP**, doublon de deux systèmes de campagnes.
- Tests PASSED ce jour : `NotificationTest`, `CentreNotificationsPortailTest`, `CommunicationPhase15Test` (§6).

### F3 — LOT 03 · Cockpit KPI scolarité
**Statut : À_ÉTENDRE (briques réelles et testées, pas de couche KPI unifiée).**
- Cockpit évaluation : 7 compteurs calculés avec isolation faculté — `TableauBordEvaluationController.php:23-51` ; anomalies in-memory + KPI gravité + résolution groupée + CSV — `EvaluationQualityService.php:32-101`, `AnomalieController.php:16-114`.
- Anomalies qualité persistées (Phase 19) : `anomalies_qualite` (`cle_unique` UNIQUE) + centre `QualiteController.php:24-104` + `AgregateurQualite.php` + cron `qualite:auditer --sync-tasks` hebdo.
- `Centre de Travail` confirmé = écran noyau sur table `tasks` — `routes/web.php:566-582` + `CentreTravailController.php:280-318` ; module Tasks : idempotence `cle_unique`, transitions serveur, bulk, auto-assignation par charge, escalade SLA — `TaskService.php:25-376`, génération 06:30 idempotente.
- BI + Data Mesh : KPI documentés + cache versionné — `BIService.php:14-80` (`can:statistiques.{view,export}`, préchauffe 04:30) ; **DataMesh opérationnel** : catalogue 6 produits versionnés `app/DataMesh/Catalogue.php:22-106`, outbox par statut, UI+routes, 18 docs.
- Composant `x-stat-card` + widgets déclaratifs (config/modules.php §widgets).
- Manque : table de définitions/version de KPI, couche snapshots, recalcul à chaque appel (anomalies in-memory paginées), isolation faculté inégale (widget annonces sans portée — `ModuleServiceProvider:499-513`), **outbox DataMesh non planifiée** (`datamesh:outbox-publier` absent de console.php).
- Doublon : **3 écrans de pilotage sur la même table `tasks`** (`admin/cockpit`, `admin/centre-travail`, `admin/taches`).
- Tests cités (cités, cadres assertifs) : `TasksCockpitTest`, `TaskGenerationInscriptionTest`, `CentreTravailAdminTest`, `QualiteApiSupervisionTest`, `DataMeshTest`, `DataMeshObservabiliteTest`, `TableauDeBordTest`, `AutomationMetricsTest`.

### F4 — LOT 04 · Passeport académique et simulation LMD
**Statut : EXISTANT_DÉSACTIVÉ (simulation complète, testée, append-only).**
- Module `ParcoursAcademique` (défaut `false`) : 7 contrôleurs, routes admin+étudiant+API (`Routes/web.php:18-93`, `routes/api.php:113-125`), tables append-only (migrations 2026-10-04 : simulations, dispenses, équivalences, passerelles, `alertes_risque`, règles versionnées `unique(code,version)`).
- `SimulationParcoursService` — 11 scénarios (L47-63) ; **contrat anti-écriture officielle prouvé** : `SimulationParcoursService.php:22-26` (« N'écrit JAMAIS dans notes, inscriptions, niveaux ou délibérations », seule écriture = ligne `parcours_simulations` + avertissement injecté L65, L74-76) ; **test dédié `PortailSimulationParcoursTest` (17 tests, dont `test_aucune_ecriture_officielle_apres_une_simulation` L476, isolation L294/334)**.
- Référentiel LMD actif (défaut `true`) : UE/ECUE/printemps/maquettes versionnées + monotransitions (`Modules/Referentiel/Models/*`, prerequis ECUE).
- Dossier étudiant 360 : `StudentArchive` (défaut `false`) + policies `StudentArchivePolicy`, `SensitiveRecordPolicy` ; noyau notes/documents (`NoteController`, `DocumentAcademiqueController` — `routes/web.php:1389-1487`).
- Manque : FSM décisionnel dispense/écriture (statuts existent sans transition contrôlée), versioning de règles de simulation explicité à la doc.
- Inconnues à clarifier : corps complet des policies `DossierAcademique`/`StudentArchive`, rôle exact `ParcoursApiController`, dépendances réelles `HistoriqueParcoursService`/`ProspectiveParcoursService`.

### F5 — LOT 05 · Carnet de notes et délibération contrôlée
**Statut : EXISTANT_VALIDÉ (chaîne complète testée + run de régression réel ce cycle).**
- Saisie anonyme/en masse avec token à 2 étapes + rejet `student_id` : `NoteEpreuveController.php:59,85,186-218,343-375` + Requests regex `CMP-\d{6}`.
- `SaisieNoteService.php:46-318` : transaction + `lockForUpdate`, bornes 0..note_max, absent ⇒ pas de note, F-01/F-02, `verrouiller`/`autoriserCorrection` + bascule verrou ; validation → synchro note officielle via `ArchiveService` (L430-457).
- Anonymat : compostages + correspondance auditée (L128-151) ; portail enseignant strictement anonyme (`Routes/web.php:451-465`).
- Historique : `evaluation_notes_historique` (migration 2026-09-30), motif+auteur par lot (`SaisieNoteService.php:206-250`), diff audit `EvaluationAuditService.php:107-212` (⚠ diff détecté par motif string — dette).
- Délibération : `DeliberationService` — simulation sans écriture (L39-48), préparation `lockForUpdate` idempotente (L52-91), snapshot `regles_appliquees` (L73-84), quorum président+2 (L274-280), anomalies bloquantes (L282-288), invalidation post-correction (L190-197), publication hors-transaction (L298-336), `decider` en lock optimiste `updated_at` (L222-257).
- Règles LMD versionnées + fallback propre : `RegleEvaluationService.php:24-64`, CRUD versionné `RegleEvaluationController.php:31-97` ; `CalculEvaluationService` : note éliminatoire, `compensation_ue`, `compensation_semestre` (L180-195).
- Permissions : `notes_eval.*` (L164-171), `deliberations.*` (L185-190), policy `EvaluationPolicy.php:57-133` (dansPerimetre), verrou `note_locked` (`NotePolicy:35/45`).
- Tests : `NotesEvaluationsSecuriteTest`, `EvaluationCompostageTest`/`CompostageAnonymeTest`, `DeliberationPhase14Test`, `DeliberationPhase6Test`, `EvaluationQualityServiceTest`, `CheckConstraintsNotesTest` — **+ run de régression antérieur de ce cycle : 78/78, 245 assertions (FaculteScopeEcriture, ExamensFeatureComplete, ExamensInvariants×2, EvaluationTest)**.
- Manque : prévisualisation d'import côté saisie web déjà préservée (import multi prévisualisé), pagination mémoire anomalies, diff GET des historiques.

### F6 — LOT 06 · Planification automatique des examens
**Statut : EXISTANT_PARTIEL (r_templates génération guidée + salles + surveillance robuste ; moteur de placement calendaire absent).**
- Génération épreuves depuis maquette : `SessionPreparationService.php:38-143` (1 épreuve/jour, idempotent L114-118) ; **readiness G2-G5 bloquant** : salles/surveillants/candidats/capacité/dérive de population — `SessionExamenService.php:23-87` + `PreparationExamenService` (snapshot population + numéros anonymes UNIQUE).
- Salles : `ExamRoomAssignmentService.php` — affectation verrouillée, capacité null refusée, `simulerRepartition` → `appliquerRepartition` (invariants §7.2 testés) ; anti-fraude ratio 0.50 — `ExamScheduleEngineService.php:23-32` — **non branché HTTP (dette d'intégration)**.
- Surveillance : `AffectationSurveillanceJob` (queue dédiée, `WithoutOverlapping`, snapshot figé, re-check permission) + équilibrage MIN 2 actifs + 1 garde, ratio 50 candidats/surveillant, contraintes dures/souples + configs hiérarchiques session>faculté>année>système (`SurveillanceEquilibrageService.php:35-86`, `SurveillanceConstraintResolver.php:71-89` + 2 tables) ; conflits épreuves + réutilisation `ScheduleSlot.chevauche`.
- Smart Timetables (EDT de cours — PAS examens) : CSP + dry-run + statuts protégés (`ScheduleGeneratorService.php:41,81-84`), pilotage **synchrone** (pas de job) — `ScheduleGenerationController.php:62-99`.
- Gaps vs cible : moteur de placement dates/heures (contraintes dures+souples, scénarios comparables, exécution async, validation avant publication) — absent ; 1 ECUE/jour à heures fixes ; salles pas assignées automatiquement au placement.
- Tests : `ExamensInvariantsValidationTest`, `ExamensInvariantsSallesTest`, `ExamPreparationTest`, `SurveillanceEquilibrageTest`, `SurveillanceContraintesTest`/`ContraintesSurveillanceTest`, `AffectationEnseignantExamenTest`, `SchedulePhase4MoteurGenerationCspTest`, `ScheduleSmartTimetablesPhaseCTest`, `SchedulePhase12ExamensExtensionTest`.

### F7 — LOT 07 · Coffre-fort documentaire et attestations QR
**Statut : stockage/OCR/vérification EXISTANT_VALIDÉ ; QR scannable ABSENT_CONFIRMÉ (à clarifier).**
- Storage privé : disque `private`, UUID + sha256 + racines séparées — `DocumentStorageService.php:43-87,174-223` ; validation finfo + MIME réel + signatures magiques + anti-polyglote (`IdentityDocumentService.php:85-155`) ; antivirus hook ClamAV/Null.
- OCR identité : upload → extraction → **suppression immédiate en `finally`** (`InscriptionController.php:521-542`) ; tmp dédié + purge ; drivers config (default `null` fail-soft, tesseract/ppocr/hfvlm désactivés par défaut) ; **23 tests IdentityScanTest incl. suppression finally + anti-énumération + CSRF/throttle**.
- Déjà vérification publique : `/verifier/{reference}` + `/verify/{code}` (throttle 30/min) → `VerifyDocumentController.php:17-37` + `DocumentVerificationService.php:18-74` — statuts `valide|expire|revoque|remplace|inexistant`, expiration `valide_jusquau`, révoquer soutenu, nom partiel masqué, log IP hashé ; codes `code_verification` (32) dans `OfficialDocumentService.php:90-180` + révoquer + supersession. **Pas de signature crypto**.
- **QR scannable : ABSENT** — aucune lib composer (grep `chillerlan|endroid|simplesoftware|qrcode|barcode` = 0), `QrCodeSvg.php` = QR décoratif **non ISO non scannable** ; bibliothèque JS `qrcode-generator.min.js` (MFA) seulement.
- Tendance : rétentions **toutes `null`** (`config/retention.php`, « aucune décision ») → décisions métier obligatoires ; `documents:purger-orphelins` (hebdo) + `documents:marquer-expires` (quotidien) déjà scédulés.
- GED + **Centre documentaire** élargi : `admin.documents-center.*` (`document.view/generate/revoke/approve/issue/download/print/send` + batches).
- Tests : `IdentityScanTest`, `StudentDocumentSecurityTest`, `StudentDocumentIsolationTest`, `SensitiveDocumentDownloadTest`, `DocumentsPhase16Test`, `DocumentAcademiqueTest`, `DocumentWorkflowTest` ; QR : `AppelQrTest`/`AttendanceQrSecurityTest` (QR étudiant).

### F8 — LOT 08 · Assistant IA RAG avec citations
**Statut : À_ÉTENDRE sur noyau EXISTANT_VALIDÉ (citations vérifiées serveur, tests anti-hallucination).**
- Chat multiportail + anti-IDOR : `AssistantIaController.php:206-293`, `AssistantIaPopupController.php:37-252`, `GestionnaireActeurPortail.php:21-76`, `ContextePageService.php:52-71`.
- Retrieval autorisation-scopé : `MoteurRechercheDocumentaire.php:78,105-113` → `AiDocumentIndex::scopeAccessiblePar` (:65-91) — sensible ⇒ `assistant_ia.sources_restreintes` ou whitelist par rôle. **Isolation facultaire sur l'index : NON (aucune colonne faculte_id) — uniquement sur outils/actions (`FiltreSecuriteIa::verifierPerimetreFaculte:93-108`)**.
- **Citations vérifiées SERVER-side** : `ValidateurCitationsIa.php:115-152` (élimine ID/titre/chemin inconnus ; extrait accepté seulement s'il est contenu dans le chunk réel) + `insufficient_evidence`/`confidence` (L177-256) ; `[SRC_…]` injecté au prompt (`OrchestrateurAgentIa.php:160-176`) ; sources persistées `ai_messages.sources`.
- Routage local-first + 6 gardes (sensibilité → REFUSE distant, anti-fallback cloud) : `PolitiqueRoutageIa.php:96-199` ; consentement HMAC (sha256 prompt|sources, TTL 300, anti-rejeu) — `GestionnaireConsentementIa.php:21-36,81-85,185-193` (⚠ fallback constant si APP_KEY vide :82).
- Approbation 4-yeux sur actions (lockForUpdate, auto-approbation interdite, hash intégrité) : `WorkflowApprobationService.php:65-123` + outils métier avec périmètre faculté.
- Quotas par rôle + audit + masquage secrets/PII : `PasserelleFournisseurs.php:140-181`, `config/assistant-ia.php:109-116`, `JournalisationIa.php:12-28,112-138`, `ObservabiliteIaService.php:24-127`.
- **Embeddings : NON** (grep = 0 ; retrieval 100 % lexical, chunks 1000/150, sha256 **par document** — pas par chunk).
- Fournisseurs : env `ASSISTANT_IA_{LOCAL,DISTANT}_*`, `GROQ_*` — **`GROQ_API_KEY` présent en clair dans `.env:77`, `AI_ENABLED=true`, `ASSISTANT_IA_DISTANT_AUTORISE=true`** (réserves sécurité) ; 504 sans bascule, repli intra-fournisseur.
- Duplication : `AiAssistant` (défaut `false`) = **concurrent antérieur doublonent** partageant `ai_conversations`/`ai_messages`/`ai_documents_index` ; spécifique AnimeUs : garde PII à l'indexation (à porter dans `IndexeurDocumentaire`), `ai_metrics`, sandbox fichiers. **Recommandation : garder `AssistantIa` canonique ; marquer `AiAssistant` legacy.**
- Tests : `tests/Feature/AssistantIa/GroqCloudEtRagIntegrationTest` (quota, citations conformes, **rejet de citations hallucinées** L158-195, exactitude), `tests/Unit/AssistantIa/RagEtIndexationTest` (exclusions strictes, anti-doublon, isolation docs sensibles), `ValidationFinaleAssistantIaTest` (RBAC, anti-IDOR, pas de fallback cloud, consentement client-seul rejeté, zéro secret, RAG sans hallucination), `SecuriteIaEtEvaluationQualiteTest` (17 patterns d'injection), `AgentsEtApprobationsTest`, `PopupUniRegistreAiMultiportailTest`.

### F9 — LOT 09 · Charges d'enseignement et remplacements
**Statut : EXISTANT_PARTIEL / À_CLARIFIER.**
- Calcul de charge : `ServiceEnseignantService.php:23-107` (Σ volume_heures par type, seuils grade>faculté>année, statut surcharge/sous-charge) ; données `SeuilService` (min/cible/max), `TypeEnseignement` (CM/TD/TP), `AffectationEnseignement` (rôles, volume decimal) — CRUD `ServiceController.php:37-116` ; module `enseignement` **active par défaut**.
- Affectation surveillance : KPIs/filtres/assigner/retirer/auto-async/optimiser écart charge (`AffectationEnseignantController.php:42-531`) ; charge surveillance `SurveillanceService.php:566`.
- Remplacements EXISTANTS : surveillant — `SurveillanceService::remplacer` L191-263 (historique `'remplace'`, motif, notification) ; EDT — `ScheduleRepairService.php:147-198` (transaction + historisation + notification) audité.
- **ABSENT_CONFIRMÉ : candidature de remplacement pédagogique** (grep `remplacement|suppleant|slack01|depannage` pédagogique = 0) ; charge() ignore l'EDT ; imputation horaire EDT→charge absente.
- Tests : `EnseignementPhase4Test` (scope faculté tableau, bulk ECUE), `AffectationEnseignantExamenTest`, `SurveillanceEquilibrageTest` (filiation remplacement).

### F10 — LOT 10 · Émargement numérique hors ligne
**Statut : EXISTANT_PARTIEL (web+PDF+QR solides ; PWA v1 ; pas de sync offline émargement).**
- Émargement web : statuts 7 sur `evaluation_candidats` + justification, disabled si verrouillé — `emargement.blade.php` + `Routes/web.php:139-144`.
- PDF : `ConvocationService::listeEmargement` L24-123 (sections par salle, historisation `DocumentExamenGenere`, aperçu) — émargement papier-only signature.
- Batch docs : `DocumentBatchController` + `EvaluationDocumentBatchService` (emargement | feuille_notes | pv_deliberation ; ZIP stockage privé TTL ; PV réservé aux délibérations validées) — `Routes/web.php:392-406`.
- QR étudiant idempotent anti-fraude : `PointageQrController` + `AttendanceQrSecurityService.php:33-92` (HMAC app.key, expiration, nonce, rejet) ; nominatif + scan admin/enseignant.
- PWA v1 : `public/manifest.webmanifest` + `public/sw.js` (**GET same-origin only, POST jamais**, fallback `/hors-ligne`), page+état JS, https-only en prod ; brouillons notes en `localStorage` (⚠ PII locale) ; inscriptions wizard idempotent token.
- Gaps : file de sync idempotente (aucun indexedDB / queue POST), gestion conflits multi-appareils, minimisation/cryptage des données locales, signature numérique de prise de poste, **liaison émargement↔présences Attendance (deux sources de vérité)**.
- Tests : `AttendanceQrSecurityTest` (token sans PII, falsifié/expiré, double scan idempotent), `AppelQrTest`, `AttendanceTemplateBuilderTest`, `AttendanceMultiModelGenerationTest`, `AppelIntelligentTest`, `AttendanceRisquesP1Test`, `EvaluationDocumentBatchTest` (ZIP valide, PV rejeté si non validée, anti-IDOR).

### F11 — LOT 11 · Centre de services IT
**Statut : ABSENT_CONFIRMÉ (aucun code/table/route/permission ticket/IT — greps vides) ; candidats de réutilisation solides.**
- Greps ABSENT : `ticket`, `helpdesk`, `service_it`, `assistance IT` dans app/docs/migrations/routes/config → 0 (seule référence : commentaire `create_tasks_tables.php:10`) ; **aucune permission `it.*`/`tickets.*` dans Permissions.php**.
- Candidats : pipeline Tasks (transitions/bulk/SLA/assignation — `TaskService.php:25-376`) ; **Sentinel incidents** (dé-dup `cle_unique`, cycle ouvert/acquitté/résolu/ignoré avec TRANSITIONS, cron 15 min, notification critique avec cooldown, 14 réels Checks, permissions `sentinel.*`) ; **TeacherReclamation** (seul « ticket utilisateur final » existant : catégories, statuts ouverte→en_cours→resolue/rejetee→fermee, priorités, PJ/clôture) ; OnlineRequests (dossiers/messages/SLA/pièces) ; Module Aide (KB publique, actif par défaut).
- Contrainte : un 4e système « ticket » surgirait en dupliquant Tasks/Reclamation/OnlineRequests — à arbitrer AVANT construction (recommandation : module `Helpdesk` s'appuyant sur le pipeline Tasks + catégories IT, ou extension strictement justifiée).
- Tests cités : `SentinelTest`, `SentinelMetierTest`, `SentinelConvergenceTest`, `TasksCockpitTest`, `TaskBulkAuthorizationTest`.

### F12 — LOT 12 · Paiements et rapprochement financier
**Statut : EXISTANT_PARTIEL (manuel vérifié + rapprochement audit-ready ; fournisseur/webhook ABSENT_CONFIRMÉ).**
- Tables : tarifs UNIQUE, factures `reference` UNIQUE, `paiements.reference_externe` UNIQUE, **montants en centimes entiers partout** (`create_finances_tables.php`) ; dossier paiement : `unique(dossier,version)`, `unique(dossier,est_valide)` anti double-validation, `unique(sha256)` anti réutilisation fichier, `unique(ref,date,montant,est_valide)` (`creer_dossiers_paiement_et_recus.php:108-126`).
- Services : `FinanceService` (trop-perçu refusé, ref_externe dupliqué refusé, pending→confirmed, remboursement plafonné) ; `DossierPaiementService:47-150` (création idempotente ; `montantsAttendus` = **facture puis tarifs — jamais inventés**) ; `FinancialReconciliationService.php:22-205` (= rapport read-only, 10 types d'anomalies, `--strict` exit 1).
- Routes : `admin.finances.*` (view/manage/confirm/receipts) + flux étudiant reçus ; perms `finances.*` (Permissions.php:264-270 + matrices).
- **ABSENT : fournisseur en ligne, webhook**, feed bancaire, livre comptable double-partie, solver facture↔paiement.
- Tests : `PaiementsInscriptionsTest` (22 tests — dossier unique, reçus non réutilisables, anti-IDOR, double validation refusée, 404 module) + `RapprochementFinancierTest` — **PASSED ce jour (§6)**.
- Règles financières : **aucune règle à inventer — `montantsAttendus` dérive de factures/tarifs ; toute passerelle en ligne = décision métier obligatoire (fournisseur autorisé)**.

---

## 3. Matrice de réutilisation

| # | Fonctionnalité (lot) | Statut matrice | Réusable direct (noyau) | À créer/étendre (recherché) | Tests de qualification | Activation défaut |
|---|---|---|---|---|---|---|
| 1 | Workflow validation configurable (L01) | **EXISTANT_PARTIEL** | OnlineRequests complet (FSM segura, SLA, messages, pièces, Policy), 20 FSM const transverses | définitions versionnées, 4-yeux, délégations | `OnlineRequestTest`, `OnlineRequestSecurityTest` ✅PASSED | `false` |
| 2 | Notifications unifiées (L02) | **EXISTANT_PARTIEL → À_ÉTENDRE** | NotificationService + Dispatcher + gabarits/préférences + scheduler + cloche 3 portails | contrat inter-canal, outbox, reprise échecs, UI préférences | `NotificationTest`, `CentreNotificationsPortailTest`, `CommunicationPhase15Test` ✅PASSED | `announcements:false` `tasks:false` |
| 3 | Cockpit KPI scolarité (L03) | **À_ÉTENDRE** | TableauBordEvaluation, Qualite persistée, BIService, DataMesh (outbox), Tasks/Centre de Travail, CockpitController | table définitions KPI versionnée, snapshots, rapprochement sources, planification outbox | `DataMeshTest`, `TableauDeBordTest`, `TasksCockpitTest`, `QualiteApiSupervisionTest` (cités) | `evaluation:false` `tasks:false` |
| 4 | Passeport académique LMD (L04) | **EXISTANT_DÉSACTIVÉ**(prêt & testé) | SimulationParcoursService (11 scénarios, append-only PROUVE), Referentiel LMD actif, StudentArchive | FSM décisionnelle dispenses, versioning règles explicité | `PortailSimulationParcoursTest` (17), `ParcoursAcademiqueTest`, `MaSimulationParcoursTest`, `ReferentielActivationSimulationTest` | `parcours-academique:false`, `referentiel:true` |
| 5 | Carnet notes + délibération (L05) | **EXISTANT_VALIDÉ** | SaisieNoteService ATM lock+, anonymat compostage, historique, DeliberationService, Regeles règles versionnées, policies | polissage (pagination mémoire, diff GET, prévisualisation web saisi) | Regression 78/78 ce cycle + suites sécurité/anonymat/délib | `evaluation:false` (`.env:true`) |
| 6 | Planification auto examens (L06) | **EXISTANT_PARTIEL** | génération maquette→épreuves, readiness G2-G5, ExamRoomAssignment (simulate/appliquer), SurveillanceEquilibrage configs hiérarchiques, Smart Timetables CSP dry-run | moteur placement dates/heures (dures+souples, scénarios, async, validation avant publication), brancher `ExamScheduleEngineService` | `ExamensInvariants*`, `SurveillanceEquilibrageTest`, `SchedulePhase*` (cités + run 78/78) | `evaluation:false`, `schedule:false` |
| 7 | Coffre-fort + QR attestations (L07) | **EXISTANT_VALIDÉ (stockage/vérif)** + **À_CLARIFIER (QR)** | disque private + finfo + OCR finally + `/verify/{code}`+`/verifier/{ref}` (expire/revoque+supersession), Centre documentaire + rétentions schedulées | QR ISO scannable SIGNÉ (lib composer), signature crypto, décisions rétention | `IdentityScanTest`(23), `StudentDocumentSecurityTest`, `DocumentsPhase16Test`, `DocumentWorkflowTest` | `student-dossiers:false`, `student-archive:false`, OCR `null` |
| 8 | Assistant IA RAG + citations (L08) | **À_ÉTENDRE** (noyau EXISTANT_VALIDÉ) | MoteurRecherche scoped permissions, ValidateurCitations (anti-hallucination), Orchestrateur, Routage local-first, 4-yeux, quotas, audit | embeddings, sha256 par chunk, scoping facultaire INDEX, garde PII indexation (port depuis AiAssistant), plafond tokens/global | `GroqCloudEtRagIntegrationTest`, `ValidationFinaleAssistantIaTest`, `RagEtIndexationTest`, `SecuriteIaEtEvaluationQualiteTest` | `assistant-ia:true`, `ai-assistant:false`(marquer legacy) |
| 9 | Charges enseignement + remplacements (L09) | **EXISTANT_PARTIEL / À_CLARIFIER** | ServiceEnseignantService (charge+seuils), SeuilService/TypeEnseignement, remplacement surveillant + EDT historisés, AffectationEnseignant KPIs | chaîne candidature remplacement + workflow validation, imputation EDT→charge, Former valide dépassement | `EnseignementPhase4Test`, `AffectationEnseignantExamenTest`, `SurveillanceEquilibrageTest` | **`enseignement:true`** |
| 10 | Émargement hors ligne (L10) | **EXISTANT_PARTIEL** | émargement web statuts 7 + PDF + batch ZIP privé TTL, Attendance QR HMAC idempotent, templates 8 types, PWA v1 | sync offline idempotente (indexedDB+queue POST), conflits multi-appareils, signature numérique, minimisation locale, liaison 2 sources vérité | `AttendanceQrSecurityTest`, `AppelQrTest`, `AttendanceTemplateBuilderTest`, `EvaluationDocumentBatchTest` | `attendance:false`, `evaluation:false` |
| 11 | Centre services IT (L11) | **ABSENT_CONFIRMÉ** | Tasks pipeline (SLA/dé-loops/assignation), Sentinel incidents (cycle+TRANSITIONS+cooldown), TeacherReclamation, Aide KB | module Helpdesk SANS dupliquer : catégories IT, SLA par catégorie, escalades, KB liée aux résolutions | `SentinelTest`, `TasksCockpitTest`, `TaskBulkAuthorizationTest` | `sentinel:false`, `tasks:false`, `aide:true` |
| 12 | Paiements + rapprochement (L12) | **EXISTANT_PARTIEL** | DossierPaiement/Recus idempotents (unique x4, centimes), FinanceService (trop-perçu/dup/, plafonds), FinancialReconciliationService read-only 10 anomalies | passerelle/webhook (décision métier : fournisseur autorisé), feed bancaire, compta | `PaiementsInscriptionsTest`(22), `RapprochementFinancierTest` ✅PASSED | `finances:false` (`.env INS_PAIEMENTS_ACTIF`) |

**Détail important:** aucun des 12 lots ne part d'une page vide sauf LOT 11 (ABSENT confirmé mais candidats réutilisables).

---

## 4. Risques et blocages

### 4.1 Risques par axe

| Axe | Constats (preuves) | Mitigation proposée |
|---|---|---|
| Isolation facultaire | globalement solide (FaculteScopeEcriture, Policies snapshot, `dansPerimetre`) mais **inégale** : widget annonces sans portée (`ModuleServiceProvider:499-513`), `AnnonceService:46-62` sans filtre rôle, **index IA sans `faculte_id`** | LOT 03/08 : ajouter scoping facultaire aux widgets/base aggregations et colonne `faculte_id` sur `ai_documents_index` si contenus métier par faculté ; test d'IDOR obligatoire par lot |
| Intégrité / concurrence | `lockForUpdate` + stabilisation FSM + lock optimiste `decider` ; idempotences Tasks/SaisieNotes/DossierPaiement. Dettes : 3 implémentations de chevauchement, 2 sources de vérité présence, statistJobs entre tests connus (lecture d'artefact mtimes) | réutiliser les locks existants propres à chaque lot ; tests concurrence MySQL pour touches critiques du lot ; toujours re-lire les fichiers avant edit |
| Auditabilité | EvaluationAuditService (diff), JournalisationIa (masquage), audit demandes, historiques notes/délibérations. Dette : diff sourcé par motif string fragile, POST→GET | coter les schemas existants, corriger le diff par snapshots hash au besoin |
| Exposition PII | journaux courriels hashés/masqués, OCR purge finally, QR sans PII, SW n'intersectionne jamais POST, notification mass sans PII — tension : **brouillons notes localStorage clair** ; **clé GROQ en .env clair + fallback HMAC constant si APP_KEY vide** | LOT 10 : minimiser/crypter locale ; sec : démocratiser `APP_KEY` obligatoire + provisoire secret de consentement propre (dép. config), garder masquage |
| Dépendances externes | IA : autorisé distant + clé Groq en clair ; QR : aucune lib (à installer avec licence/maintenance vérifiées — candidat `chillerlan/php-qrcode` à valider) ; SMTP nommé ; aucun webhook paiement (favorable aux tests) | LOT 08 : plafond global tokens/coût + qualité logs ; LOT 07 : lib QR = seule dépendance nouvelle à justifier ; **aucun appel réel fournisseur pendant les tests (canal simulé + provider faux défaut)** |
| Coût / volumétrie | quotas IA par rôle (défaut 50, super_admin 500) sans plafond global ; diffusion masse par lots 500 ; DataMesh pagination ; jobs queue dédiés (surveillance, notifications, campagnes) | imposer un plafond global IA ; passer le placement d'examens + équilibrage par jobs (déjà la méthode) ; mesurer avec volumes représentatifs |
| Accessibilité | hors périmètre détaillé cette passe (réserve : vues non auditée focus/labels) — SWayscale PWA https-only | checklist UX à intégrer à chaque lot (LOOP-06 du protocole) |

### 4.2 Blocages et dépendances inter-lots

- **D1 → D6/D9/D11 :** LOT 01 (workflow configurable + 4-yeux) est le backbone des validations des plannings (L06), remplacements (L09) et escalades IT (L11). Recommandation : suite le plus tôt possible mais après L02-L05 (nos noyaux sommes déjà validés).
- **L07 → L02 :** notifications doc officielle (résultats/documents_officiels jamais opt-out) réutilisent gabarits — ordre L02 avant L07.
- **L03 → L02 :** cockpit KPI devrait tirer des plateaux outbox/DataMesh déjà schedulés (réserves<Message/>
- **L08 indépendant** au niveau fonctionnel mais **scoping facultaire de l'index & garde PII** à traiter avant le LOT (risques §4.1).
- **L10 dépend du choix de politique de données locales** (minimisation, PII) — décision à prendre.
- **L11 exige arbitrage consolidation** : un nouveau module ne doit pas être un 4e système « ticket » (Tasks/Reclamation/OnlineRequests/Aide existent déjà).
- **L12 exige des décision(s) métier :** fournisseur autorisé, règles de remboursement/correction, feed bancaire — *rien à inventer* (contraîne absolue A11).
- **Rétention (L07) :** toutes durées = `null` → décisions métier obligatoires avant lot.

---

## 5. Plan de lots recommandé (au plus sûr, réutilisant l'existant)

**Ordre recommandé** — optimisé par risque croissant et dépendances (l'ordre numérique LOT 01→12 reste compatible, chaque lot comportant ses gates de passage) :

```
L05 → L02 → L03 → L04 → L07 → L01 → L06 → L09 → L08 → L10 → L11 → L12 → L13
```

Justification : départ par les lots dont le noyau est déjà validé par tests (L05, L02), montée progressive en complexité, lot S dé « backbone workflow » placé après stabilisation du socle, lots à décision métier (L07-rétention, L10-politique locales, L11-consolidation, L12-fournisseur) en fin de chaîne avec leurs réserves explicitées.

| Lot | Objectif | Réutilisation | Changements strictement nécessaires | Critères d'acceptation (conditions PASS) | Tests |
|---|---|---|---|---|---|
| **L05** | Carnet de notes + délibération contrôlée finalisée | SaisieNoteService, DeliberationService, Compostage, EvaluationAudit, Regeles versionnées | polissage pagination, prévisualisation import côté web, diff GET, verrou publication | notes invalides rejetées, import prévisualisé, corrections historisées, publication contrôlée, concurrence sans perte silencieuse | suites Notes/Délib/anonymat + concurrence MySQL |
| **L02** | Centre notifications unifié | NotificationService/Dispatcher/gabarits/préférences/scheduler | contrat commun inter-canal, outbox, reprise échecs, exposition `definir()` préférences + UI | livraisons et échecs traçables, pas de doublon, préférences respectées, secrets non loggés | Notification/Portail/Communication + échec config + retries |
| **L03** | Cockpit KPI scolarité | TableauBordEvaluation, Qualite, BIService, DataMesh, Tasks/Centre de Travail | table de définitions KPI versionnée, snapshots, rapprochement, scoping facultaire widgets, planif outbox DataMesh | KPI définis+documentés, rapprochés aux sources, isolation, signalement périmé, performance mesurée | DataMesh/TableauDeBord/Task* + contre-sources |
| **L04** | Passeport académique LMD | SimulationParcoursService (append-only prouvé), Referentiel actif | FSM décisionnelle dispenses, versioning règles, transmission hypothèses | simulation sans écriture officielle (déjà pour preuve test), règles explicable/reproductibles, droits corrects | PortailSimulationParcoursTest + cas limites crédits/rattrapages |
| **L07** | Coffre + QR attestations | storage private, OfficialDocumentService, VerifyDocument | lib QR ISO + QR SIGNÉ sur attestations (remplace URL imprimée), signature crypto, décisions rétention | docs privés non accessibles par ID manipulable, expiration/révoquation honorées, QR sans PII superflue, rétentions approuvées | IdentityScan/DocSecurity/Verify + révocation/expiration + anti-IDOR |
| **L01** | Workflow validation configurable | OnlineRequests, SlaService, AuditDemandes | définitions versionnées DB + attachments, 4-yeux, délégations, sans casser les lockfos existants | transitions contrôlées serveur, états interdits inaccessibles, 4-yeux sensibles, version alignée par dossier, audit complet | OnlineRequest* + concurrence + légitimités + double soumission |
| **L06** | Planification auto examens | SessionPreparation, PreparationExamen, ExamRoomAssignment, SurveillanceEquilibrage, Schedule CSP | moteur placement dates/heures (dures+souples → scénarios comparables), branchement ExamScheduleEngineService, exécution async + validation avant publication | aucun conflit interdit, capacités/indisponibilités prises en compte, contraintes impossibles signalées, pas d'écrasement planning validé, traçage | ExamensInvariants + contraintes + async + rébelération répétée |
| **L09** | Charges + remplacements | ServiceEnseignantService, SeuilService, AffectationEnseignant, remplacement surveillance/EDT | chaîne candidature remplacement (offre→validation→historique, workflow L01), imputation EDT→charge | règles documentées, remplacements conformes compétences/disponibilités, doubles affectations bloquées, historisé, périmètre autorisé | EnseignementPhase4 + remplacements concurrents + permissions |
| **L08** | Assistant IA RAG + citations | AssistantIa complet (citations/4-yeux/quotas/audit), porter garde PII d'AiAssistant | embeddings + empreinte par-chunk, scoping facultaire index, garde PII indexation, plafond global tokens, marquer AiAssistant legacy | citations vérifiables (déjà), documents non autorisés jamais récupérés, injections hostiles non exécutées, incertitudes qualifiées, actions supervisées | suites AssistantIa + nouveau tests retrieval facultaire + quota plafored |
| **L10** | Émargement hors ligne | émargement web/PDF/batch, Attendance QR, PWA v1 | file sync idempotente (indexedDB/queue POST), conflits multi-appareils, signature numérique, minimisation locale, liaison des 2 sources vérité | aucune double-lecture, conflits signalés, sessions expirées/revoques gérés, données locales minimisées, encaissetlement sans pertes | scénarios hors-ligne RÉELS exéctués (simulations réseau unitaires insuffisantes — réserve explicite) |
| **L11** | Centre services IT | TaskService pipeline, Sentinel cycles, TeacherReclamation, Aide KB | module Helpdesk sans dupliquer : catégories IT, SLA par catégorie, escalades, KB liée aux résolutions | ticket état+responsabilité identifiables, transitions contrôlées, SLA documentées, PJ privées, escalades idempotentes | cycle de vie + SLA + escalade + anti-pattern inter-facultés |
| **L12** | Paiements + rapprochement | DossierPpaiement/Recus idempotents, Réconciliation read-only | adaptateur fournisseur + webhook authentifié idempotent (décision métier pour la fournisseur), écritures compensatoires | confirmation serveur vérifiée, webhook répétés idempotents, transactions ambiguës en exception, écarts visibles/assignables, rembours racés | Possible état de dette : webhook falsifiés/doublons/montants faux/concurrence — **AUCUN paiement réel** |
| **L13** | Validation globale | toutes suites | consolidé : régression, permissions, frontières, MySQL, perf, jobs/scheduler sans actions de production, smoke, a11y | tous contrôles obligatoires passent, aucun risque critique ouvert, preuves reproductibles | suite complète + rapport consolidé |

**Verdicts de sortie par lot :** PASS (tous critères + tests réellement exécutés) / PASS_WITH_RESERVATIONS (réserves non bloquantes documentées et conditions de poursuite) / FAIL (arrêt de la chaîne dépendante) / NOT_EXECUTED (désormais traité comme réserve bloquante jusqu'à exécution).

---

## 6. Preuves d'exécution réelle (ce lot)

- État initial : `git status --short` (669 entrées), HEAD `38ef149`, branche `feature/admin-modifier-etudiant`.
- Explorations : 4 sous-agents « explore » très approfondis, lecture seule, par domaine (sorties condensées citées en §2/§3 avec preuves fichier:ligne).
- Vérifications transverses réelles : lecture de `routes/console.php` (9 commandes planifiées : exports purge horaire, SLA 07:30, notifs hebdo, taches 06:30, campagnes 15min/every5, présences 30min, qualité hebdo), `config/queue.php` (`database`), `composer.json` (aucune lib QR/webhook), greps `/verify`/`/verifier` (`routes/web.php:145-154`), présence `docs/data-mesh/` (18 docs), exclusions `Centre de Travail` (`CentreTravailController`, docs utilisateur) — 452 fichiers dans `tests/Feature/`.
- **Run de tests ciblé exécuté par moi ce jour :**
  `php artisan test --compact --filter="(NotificationTest|CentreNotificationsPortailTest|CommunicationPhase15Test|PaiementsInscriptionsTest|RapprochementFinancierTest|OnlineRequestTest|OnlineRequestSecurityTest)"`
  → **51 tests, 372 assertions, PASS (2.99 s)** — soutient les qualifications « validé » : F1 (workflow noyau), F2 (notifications), F12 (paiements).
- Run de régression antérieur de ce même cycle cité en preuve F5/F6 : 78/78 (245 assertions) Évaluation.

---

## 7. Inconnues explicites (à arbitrer avant les lots correspondants)

1. **Périmètre exact du « Cockpit KPI scolarité »** (L03) : terminologie à confirmer avec le métier — propose actuellement noyau de 7 compteurs évaluation + anomalies + BI/DataMesh.
2. **Rétentions documents (L07)** : les 4 durées de `config/retention.php` sont `null` — décisions métier obligatoires (pas de règle à inventer).
3. **Fournisseur de paiement autorisé (L12)** : aucun actuellement — passerelle/webhook = décision métier pure.
4. **Politique de données locales hors ligne (L10)** : minimisation, cryptage du `localStorage` notes — à définir.
5. **Consolidation « ticket » (L11)** : Helpdesk nouveau module vs extension Tasks — arbitrage OBLIGATOIRE avant d'éviter un 4e système.
6. **Schéma de migration legacy AiAssistant** (L08) : garder dormant désactivé vs nettoyage de vues/nav — plates docs / `.env.example` à aligner.
7. **Détail complet de certaines Policies** (DossierAcademique/StudentArchive/ParcoursPolicy) et de `HistoriqueParcoursService` — partiellement lus lors de l'exploration, à lire ligne-by-ligne pendant les lots respectifs.
8. **Écart de runtime CI (PHP 8.4 vs 8.5.11 local)** et **absence de phpstan** : à corriger sans urgence mission-critique, réserves préexistantes.

---

## 8. État Git final et verdict

- **Modifications métier : zéro.** Aucun fichier modifié, aucun commit/push/migration, aucune perte : HEAD toujours `38ef149` ; mesuré avant puis après l'écriture : `git status --short | wc -l` = **671 dans les deux cas** (`docs/engineering/` étant intégralement non suivi, l'ajout du présent rapport n'apparaît pas comme nouvelle entrée ; 350 entrées non suivies au total, artefacts du flux d'agents co-éditeur inclus).
- **Verdict du lot LOOP-00 : PASS_WITH_RESERVATIONS**

Le passage en LOOP-01 (ou tout lot, selon l'ordre retenu par auto-pass) est autorisé : les réserves ci-dessus n'invalident pas la cartographie. Les réserves ouvertes sont :

- **R1 (structurelle, non bloquante) :** doublons confirmés — 3 écrans pilotage sur `tasks`, 2 systèmes campagnes, 2 modules IA. Chaque extension doit EXPLICITEMENT réutiliser avant de créer.
- **R2 sécurité à traiter au premier lot touchant IA :** `GROQ_API_KEY` en `.env` clair + fallback constant de signature de consentement si APP_KEY vide.
- **R3 qualité transversale :** isolation facultaire des widgets/agrégations à homologuer (L03/L08) ; rétentions et politiques de données locales à arbitrages métier (L07/L10).
- **R4 infield assurance :** suites complètes de tests non relancées ici (échantillons + runs ciblés uniquement) — recalibrage complet de la régression avant tout LOT (FIGURE LOT 13).

**Conditions de poursuite :** chaque lot doit exécuter ses tests de bout en bout (LOOP-04 réels), composant la matrice sécurité LOOP-05 avec preuves négatives, et implémenter les consignes d'inconnues §7ntes à son lot. Aucune migration destructive ; toute nouvelle migration additive justifiée + testée isolément + stratégie de retour en arrière.
