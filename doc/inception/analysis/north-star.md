# North Star & Vision — University Management Laravel Application

## Project Vision
Provide a comprehensive, modular university management system that supports the complete academic lifecycle of students from enrollment through graduation, with granular administrative control, secure data handling, and seamless inter-module integration.

## Core Mission
Enable institutions to manage student records, enrollments, evaluations, assessments, assignments, and documentation through a unified, modular Laravel platform that balances administrative flexibility with academic rigor and data security.

## Key Objectives

### 1. Student Lifecycle Management
- Support complete student journeys: pre-enrollment → inscription → validation → affectation → progression → graduation
- Handle periodic registration periods with opening/closing logic and transitional states
- Manage student dossiers with document tracking, justificatifs, and status workflows

### 2. Academic Program Administration
- Manage faculty/structure-organized programs (Faculté → Programme → Niveau → Semestre → UE → ECUE)
- Support LMD (Licence-Master-Doctorat) structure with levels, semesters, and course units
- Enable intelligent affectation (group assignment) with capacity constraints and balancing

### 3. Evaluation & Assessment
- Complete evaluation cycle: saisie des notes → délibération → publication des résultats
- Support anonymous grading (compostage) with CMP-XXXXXX format and uniqueness constraints
- Manage deliberation processes with quorum, validation, and decision publishing
- Handle grade compensation, compensation semester, and academic progression rules

### 4. Import/Export & Data Exchange
- Support heterogeneous file imports (CSV, XLSX, ODS, JSON, XML) with preview/confirm/revalidation workflow
- Provide intelligent column mapping and automatic correction of non-ambiguous errors
- Enable secure exports with permission-based access and confidential field masking (CIN)
- Ensure data integrity through validation pipelines, anti-TOCTOU measures, and idempotent processing

### 5. Intelligent Assignment & Scheduling
- Automated group assignment with scoring, eligibility, and capacity management
- Schedule conflict detection and resolution
- Surveillance/guard scheduling with needs calculation and venue allocation
- Intelligent session balancing with principale/salle assignments

### 6. Document & Certificate Management
- Generate official documents (attestations, diplomas, relevés) with numérotation verrouillée
- Manage document templates, versions, signatories, and numbering sequences
- Support GED integration with 15 document types and QR code optional output
- Enable attestation generation with CIN/matricule lookup and scope-gated access

### 7. Intelligent Search & Navigation
- Universal search across entities with normalized code/name handling
- Quick action registries for favoris, recent actions, and context-aware results
- Faceted navigation with scope filtering and derived criteria
- Volume-aware performance with chunked processing and N+1 prevention

### 8. Security & Confidentiality
- Fail-soft OCR by design — no engine should block registration
- CIN masking in exports (BK***001 format) with permission-gated access
- PII never logged; audit trails without sensitive data
- Fail-closed identification (MENS_SEUL|MENS_ET_LOCAL exigé)
- Scope-gated routes with faculteId() as single source of truth

### 9. Modular Architecture
- CORE (stable, never modified for new module) + MODULES (independent features)
- Module activation via MODULE_XXX flags in .env
- ModuleServiceProvider registering contracts, policies, events, routes, widgets
- JAMAIS de colonne en table centrale ni de route dans routes/web.php (tout via module registration)

### 10. Performance & Reliability
- Chunked processing for large volumes (array_chunk(500), chunk(1000))
- Streaming exports when library permits
- Benchmarked performance: 10000 étudiants → ~60s/256Mo
- Backup encryption with sodium secretbox, --verifier for validation
- Disaster recovery drill documented with RTO measured

## Success Metrics
- All 23 LOOPs covered (8 implemented, 12 conformes sur preuves, 3 PARTIEL assumés documentés)
- 2291 tests verts (5 blocs) : 0 échec stable, 4 skips documentés
- Pint global vert sur tout le périmètre
- 596 routes OK, 0 TODO/FIXME (hors pré-existants documentés)
- MySQL réel : cluster poubelle absent, programmes 4, matières 52, niveaux 12 (L1-L3)
- AUCUN code applicatif modifié sans justification et tests correspondants

## Anti-Goals (What We Deliberately Do NOT Do)
- Never block student registration due to OCR failures (fail-soft by design)
- Never store durable ID documents (temp files deleted post-extraction)
- Never expose CIN in exports without explicit permission
- Never merge identities on name similarity alone (CIN/matricule required)
- Never increase API limits arbitrarily without measurement
- Never skip security validations for performance
- Never create duplicate architectural patterns without justification

## Derived From
- `docs/ARCHIVES.md` — academic archive architecture and rules
- `docs/AUTHENTICATION.md` — auth flow and security model
- `docs/ARCHITECTURE-MODULES.md` — modular CORE + MODULES pattern
- `docs/DATA-EXCHANGE.md` — import/export pipeline definitions
- `docs/SECURITY.md` — security rules and protections
- `docs/AUDIT-SECURITE.md` — audit findings and corrections
- 30+ documentation files covering phases 0-34 LOOPs
- Existing codebase analysis (no git history available)