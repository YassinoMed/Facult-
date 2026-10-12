# Tribal Knowledge — University Management Laravel Application

*Extracted from existing documentation, code structure, and project decisions. 
This knowledge feeds Phase 2 graduates (decisions, feature specs, glossary, conventions).*

## Category 1: Domain Knowledge

### Academic Structure (LMD - Licence Master Doctorat)
- **Faculté** → **Programme** → **Niveau** → **Semestre** → **UE** (Unité d'Enseignement) → **ECUE** (Enseignement Composant Unité d'Enseignement)
- `Programme` = Parcours (career path), contains `niveaux` (L1, L2, L3, M1, M2)
- `Matiere` = ECUE alias sans 2e table (legacy convention)
- `specialite` = Mention within faculty (e.g., INFO, GENIE)
- Academic year structure: `annee_universitaire` with `est_courante` flag
- Import/export whitelist controls which entities are exchangeable

### Student Lifecycle
- **Inscription** (enrollment) → Période d'inscription → Validation → Affectation groupe
- **Reinscription** (re-enrollment) vs **Nouvelle inscription** (new student)
- **Transfert** (transfer) between programmes/niveaux
- **Archivage** (archiving) student records
- **Récépissé** (receipt) generated after submission, distinct from acceptance

### Key Business Concepts
- **CIN** (National ID) - masked in exports as `BK***001` format; never stored permanently; fail-soft OCR
- **Matricule** - student identifier, may be national ID or generated
- **Dossier** (file) - student file with documents, status tracking
- **Justificatifs** (justificatives) - required documents for enrollment
- **Notification** - in-app and email notifications for various events
- **SLA** (Service Level Agreement) - for period-related notifications

### User Roles & Permissions
- **Super-admin** - last one protected, full access
- **Admin scolarité** - academic administration
- **Admin faculté** - faculty-level administration
- **Enseignant** (teacher) - has mirrored student auth, specific portail
- **Étudiant** (student) - public inscription routes, personal espace
- Permissions matrix based on `configuration.manage`, `document.*`, `assignment.*`, etc.
- IDOR protection via `faculteId()` scoping on Examen/Epreuve/Deliberation/Matiere

## Category 2: Architecture & Design

### Core + Modules Pattern
- **CORE** (stable, never modified for new module) + **MODULES** (independent features)
- `config/modules.php` + `ModuleRegistry` + `ModuleServiceProvider` (contrats, policies, events, routes, widgets)
- New module = dossier `app/Modules/Xxx/` + vues `resources/views/modules/xxx/` + permissions `xxx.*`
- JAMAIS de colonne en table centrale ni de route dans `routes/web.php` (tout via module registration)
- Activation via `MODULE_XXX` (middleware `module:xxx` → 404 if inactive)
- Default `.env.example`: `MODULE_ATTENDANCE=false`, `MODULE_STUDENT_DOSSIERS=false`, etc.

### Modular Architecture Details
- `config/integrations.php` - central configuration
- `config/cin_tunisia.php` - Tunisian CIN zones/labels, preprocessing variants
- `app/Support/Modules/` - ModuleRegistry: activation, navigation, widgets
- Routes declared per-module, not globally
- Middleware `module:xxx` for activation checking

### Data Exchange Pipeline
- `DataImportService` → `preview→token→confirm→revalidation anti-TOCTOU→transaction/file>500→rapport`
- `DataExportService` - exports CSV/XLSX/JSON/PDF
- `DefinitionRegistry` - central registry of importable/exportable entities
- `ExportColumnPolicy` - controls which columns are exposed
- `ExportRegistry` - registers exportable entity types
- `IoFactory` - abstraction for different IO operations (CSV, ODS, etc.)
- Preview→token→confirm→confirm flow for data imports
- `ImportDefinitionInterface` + registre + whitelist + preview→confirm→revalidation

### Authentication & Security
- **Sanctum** for API tokens with scopes
- **CSRF** protection on web routes; API uses Sanctum tokens
- **Throttles** on key endpoints (e.g., 5/min for identifier, 30/min for faq)
- **Password hashing** with Bcrypt/Hash
- Session regeneration after login
- `faculteId()` helper as single source of truth for faculty scoping on Examen/Epreuve/Deliberation/Matiere
- `dansPerimetre` pattern for scope checking
- Routes dev Boost local-gated

### OCR & Identity Scanning
- **Fail-soft by design**: no OCR engine (null/tesseract/ppocr/hfvlm) should raise exception or block registration - fallback = manual entry + verification alerts
- **No durable ID document**: scanned file is TEMPORARY private in `storage/app/private`, deleted immediately after extraction (`finally`), NO archiving in student dossier
- **Validation serveur profonde** (deep server validation): extension + MIME réel via finfo/signature + image ré-exécutable + taille max - pas de confiance au navigateur
- **OCR drivers**:
  - `null` (default, fallback manual) - `identity.ocr.hfvlm.active` vaut `false`
  - `ppocr` (PaddleOCR server) - for CIN multi-zones en local
  - `hfvlm` (Qwen3-VL-4B MLX) - for passeport/pleine page, ~20-60 s/appel
  - `colab` (API FastAPI distante sur GPU Google Colab) - for all document types without local dependency
- **Confidence thresholds** in `identity.confiance` (eleve/moyen/seuil_validation) - below triggers "à vérifier" alert
- **Arabic normalization/transliteration** preserved in dedicated field, never overwritten
- **MRZ TD3** (passport) relies on checksums 7-3-1 + ICAO 9303 parsing priority

### GED / Document Management
- `StudentDocument` 5 statuts: en_cours, valide, rejeté, expiré, archivé
- `NiveauConfidentialite` enum: standard/restreint/confidentiel
- `student_sensitive_records` table (4 types, métadonnées admin, lien GED, soft delete, rétention extensible)
- `document_templates` + `versions` (immuables) + `signatories` + `numbering_sequences` + `batches` + `events` + `verifications`
- `DefaultDocumentGenerator` - snapshot figé, blocage données manquantes, numérotation `lockForUpdate` sans trou, code opaque 32c, duplicata `-DUPn`, supersession
- `DocumentVerificationService` - nom masqué, IP hachée, 404 hors registre conservé
- Generated documents: `generated_documents` table (numero/code_verification/snapshot/locale/versions/emis/revoque/duplicatas - AUCUNE 2e table registre)

### Evaluation & Grading
- **Note** (grade) - decimal on 20 scale, NOT NULL, with constraints `chk_notes_note_0_20`
- `evaluation_notes` - valeur NULL + borne 0..note_max ligne via `SaisieNoteService`
- **Compostage** (composting) - anonymous grading, `CMP-XXXXXX` format, UNIQUE epreuve×etudiant
- `EvaluationCompostage` - service for anonymous grading, `random_int`, `getOrCreate` idempotent
- `NoteEpreuveController` - extended for anonymous saisie with scope→404 before Gate
- **Deliberation** - jury process with quorum, validation, publication
- **Compensation semestre** - load-bearing feature for semester compensation

### Affectation & Group Management
- **Affectation automatique** (auto-assignment) of students to groups
- `AffectationService` - persistence + `AffectationEngine` scoring/balancing
- `AffectationEligibilityResolver` - pre-filter without N+1
- `AffectationConstraintEngine` - reuses `controler` + SCHEDULE via `chevauche` + CAPACITY simulated + FACULTY_SCOPE
- `AssignmentSimulationService` - no-write business: constraints-first, tie-break effectif/places/id, MOVE then SWAP bounded, explicit metrics
- `AssignmentConfirmationService` - modification validated, confirm by group transactional + obsolescence + idempotence + audit `assignment.*`
- `AffectationEngine::noter()+certitudePour()` - pure extraction, zero-query (evaluer() refactored to identical behavior)
- `MassAssignmentService::analyser()` - choice équilibrage INCHANGÉ + `score/certitude/reasons/breakdown/alternatives` by proposition
- Doublon CIN normalisé via `NormalisationService` → `IDENTITY_CONFLICT` (homonymes toujours éligibles)
- `whereRaw` index on normalized CIN (covers spaced variants that unique DB brut leaves pass)
- `config/affectations.php` - scoring + seuils Phase 11, défauts = historique
- `AffectationMasseLot` - lotissement with `progression()`, `codesGroupes()` 1 requête
- `AffectationSurveillanceJob` - IDs seuls, `tries=1`, `timeout=1200`, `WithoutOverlapping` session×faculté vérifié OK

### Schedule & TimeTable
- `EmploiDuTemps` (schedule) - réactif + conflits + exceptions + historique
- `ScheduleSlot` - reserved for HARD conflicts, never summoned for soft
- `ScheduleConflictService` - conflict detection
- `evaluerBesoinsEpreuve` - needs calculation per examination
- `ventilerPropositionsParSalle` - ventilation principal+salles asc, principaux décomptés
- `min_par_salle` / `ratio_etudiants` - configurable via `surveillance_constraint_configs`
- `max_surveillances_enseignant` - unified on NUMBER OF POSTES (`$nbPostes`)

### Notifications System
- `NotificationService` - with dedup via `cle_dedup` key (`type + data->cle_dedup` exists → return existante sans réinsérer)
- `notifierEtudiant` / `notifierPlusieursEtudiants` - batch of 500, champs/troncatures identiques prouvés
- `NotifyDocumentRejete` - listener fail-soft on `DocumentRejected`, type+niveau `document.rejete`, lien mon-dossier, sans PII
- `StudentStatusService::changer` - notifie `dossier.statut` (libellé humain, motif si fourni, priorité 2 si rejeté, fail-soft post-transaction)
- `NotificationTemplate` - versionnés, CRUD, `notification_preferences` (obligatoires préservés)
- `CommunicationDispatcher` - 7 événements migrés, dédupliqué
- `NotifyDemande` - 4 méthodes: `approved`, `rejected`, `waiting`, `completed` with priorities 1/2/1/0
- `envoi()` helper privé - ZÉRO nouveau listener: `NotifierDecisionDemande` écrit puis SUPPRIMÉ après découverte du doublon
- Events: `parcours.decision`, `archive_view`, `impression_document`, `exam.incident.signale/traite`

### Conventions & Naming
- **Everything in French** (variables, methods, comments, user messages)
- **Controllers**: namespace by domain (`App\Http\Controllers\Admin\...`, `App\Http\Controllers\Etudiant\...`, `App\Http\Controllers\Api\V1\...`)
- **FormRequests**: `app/Http/Requests/*Request.php` for validation/normalisation; pas de validation lourde dans le contrôleur
- **Services métier** live in `app/Services`: OCR in `Services/Identity`, IO lourds in `Services/Io`, etc.
- **Routes**: préfixées par domaine, nommées (`admin.*`, `etudiant.*`, `api.v1.*`), with scopes Sanctum on API + throttle dédié
- **Variables and methods descriptifs** (`isRegisteredForDiscounts` vs `discount()`)
- **ENUM keys in TitleCase**: `FavoritePerson`, `BestLake`, `Monthly`
- **Curly braces for control structures**, even for single-line bodies
- **PHP 8 constructor property promotion**: `public function __construct(public GitHub $github) { }`
- **Explicit return type declarations** and type hints for all method parameters

## Category 3: Conventions & Rules

### Code Style
- `vendor/bin/pint` - code formatter, run `pint --dirty --format agent` or `pint --format agent`
- `vendor/bin/phpunit` - test runner
- Tests: `php artisan test --compact` or `vendor/bin/phpunit --filter testName`
- N+1 detection and prevention: eager loading `with([...])`, pas de requêtes en boucle
- `php artisan route:list` - verify routes after changes
- `php artisan config:show` - inspect configuration

### Migration & Schema Conventions
- Additive migrations preferred (no destructive changes)
- `lockForUpdate` pattern for concurrent access
- `afterCommit` for post-commit operations (e.g., `$afterCommit = true` guarantees post-commit even if dispatch is moved)
- Unique index on normalized CIN (covers variants)
- Soft deletes where appropriate, with audit logging

### Testing Conventions
- Feature tests: `php artisan make:test --phpuint {name}`
- Factories used for model creation in tests
- `phpunit.result.cache` - should be cleared after batch test runs
- Tests grouped by feature/phase in `tests/Feature/`
- Performance benchmarks in `tests/Feature/Performance/`

### Deployment & Operations
- `Laravel Cloud` for production deployment
- `schedule:run` - cron-based task execution
- `config/sauvegardes.php` - backup configuration with encryption
- `composer audit --no-dev` + `npm audit --audit-level=high` in CI
- Disaster recovery drill documented in `docs/operations/disaster-recovery.md`
- `route:list` 529 routes in the application

## Category 4: Risk & Assumptions

### Known Risks
- **PII exposure in logs**: Aucun donnée OCR/PII dans les logs (type de document, durée, résultat de matching uniquement) ; audit admin centralisé
- **SQLite vs MySQL transactional behavior**: Ne jamais considérer SQLite comme preuve suffisante du comportement transactionnel de MySQL
- **OCR engine availability**: macOS dev: PHP Homebrew x86_64 (Rosetta) rend les wheels arm64 du venv OCR inutilisables — les drivers ppocr/hfvlm forcent la tranche native via `arch -arm64`
- **Fail-closed vs fail-open**: Identification fail-closed provenued; MENS_SEUL|MENS_ET_LOCAL exigé sans BLOQUANT sinon 422
- **Concurrent write races**: `affecter()`/`retirer()` verrouillaient GROUPE + ligne existante, mais jamais ÉTUDIANT; correctif: `Etudiant::whereKey(...)->lockForUpdate()->firstOrFail()` en TÊTE des deux transactions
- **ODS format limitations**: ODS en lecture seule; message `formatInvalide` doit mentionner ou non ODS

### Assumptions Documented
- MySQL is the primary database; SQLite used for tests but not as transactional proof
- Tunisian timezone (TZ=UTC) documented for period boundaries
- Arabic/Francophone naming conventions preserved
- Fail-soft OCR by design - never block user registration
- Module activation via `MODULE_XXX` flags in `.env`

## Category 5: Operations & Deployment

### Known Commands
- `php artisan boost:install` - generate ADOS framework files
- `php artisan test --compact` - run tests
- `vendor/bin/pint --dirty` - check formatting
- `vendor/bin/phpunit` - run test suite
- `php artisan route:list` - list routes
- `php artisan config:show` - show config
- `composer audit` - audit dependencies
- `php artisan schedule:run` - run scheduled tasks

### Deployment Pipeline
- Laravel Cloud deployment recommended
- CI includes `composer audit --no-dev` + `npm audit --audit-level=high`
- Disaster recovery drill (trimestriel) documented
- Backup encryption with sodium secretbox
- `--verifier` flag for backup validation

### Documentation Maintenance
- All business rules documented in `docs/` (each document structured in numbered sections §N)
- Updates to business rules → update corresponding docs and add/adapt tests
- `AGENTS.md` - project guidelines, must stay exact when structure/config/processes change
- `GUIDE-DEPLOY.md` - deployment guide with sections for dev and prod

---
*Document generated from codebase and documentation analysis. 
Confidence rubric: High-confidence items extracted from explicit code/configuration; 
Medium-confidence items from documented conventions; 
Low-confidence items from inferred patterns requiring verification.*