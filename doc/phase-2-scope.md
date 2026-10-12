# Phase 2 — Scope & Roadmap

**Purpose:** Define Current Milestone scope and roadmap.  
**Inputs:** north star + material inventory + legacy analysis.  
**Classification:** legacy — define next-milestone scope as Current Milestone; do NOT call it MVP.  
**Legacy:** graduate consumed tribal knowledge to permanent homes: decisions, feature specs, glossary, conventions.  
**Draft:** `roadmap`, `assumption-register`, and `risk-register`.  
**Enforce project-derived cross-cutting coverage for roadmap/registers.**  
**Conditional UI-bearing:** `user-journeys` + `screen-inventory`.  
**Anti-sycophancy:** pre-mortem + four-risk-check.  

**Before gate:** write retrospectives + update open-questions (phase-exit meta).  
**State update:** mark Phase 2 completed; record roadmap/register status/confidence.  
**Human gate 2:** approve scope, roadmap, assumptions, risks, and graduations.  
**Artifact keys:** `roadmap`, `assumption-register`, `risk-register`, conditional `user-journeys`, `screen-inventory`.  
**Guide ref:** Phase 2 of `doc/guides/project-inception.md`.

---

## Current Project Context (for Phase 2)

**Project:** University Management Laravel Application  
**Flow:** legacy (existing project delivered without ADOS)  
**Classification:** legacy-project-delivered-without-ados  
**Characteristics:** ui_bearing=true, multi_user=true, complex_domain=true, code_project=true  

**User Types (multi-user):**
- **Admin utilisateur** — super-admin, admin scolarité, admin faculté
- **Enseignant** — mirrored student auth, portail spécifique
- **Étudiant** — public inscription routes, espace personnel

**Key Domains (complex_domain=true):**
- Student lifecycle: inscription, réinscription, transfert, archivage
- Academic programs: Faculte → Programme → Niveau → Semestre → UE → ECUE
- Evaluation cycle: saisie → délibération → publication
- Import/export: CSV, XLSX, ODS, JSON, XML with preview/confirm/revalidation
- Affectation automatique: assignment with capacity, eligibility, balancing
- Document management: attestations, diplomas, relevés, GED
- Search & navigation: universal search, quick actions, faceted filtering
- Security & confidentiality: CIN masking, fail-soft OCR, PII protection

**Existing Tribal Knowledge (from Phase 0):**
- 5-category tribal knowledge: Domain, Architecture, Conventions, Risk, Operations
- Modular CORE + MODULES pattern (config/modules.php + ModuleRegistry + ModuleServiceProvider)
- Fail-soft OCR by design, CIN masking BK***001, never store durable ID documents
- Data exchange pipeline: DataImportService → preview→token→confirm→revalidation→transaction
- Export pipeline: DataExportService with ExportColumnPolicy, ExportRegistry, DefinitionRegistry
- Affectation engine: AffectationService + AffectationEngine scoring/balancing
- Modular architecture: modules activated via MODULE_XXX flags, never colonnes en table centrale

**Current Test Status:**
- 2291 tests verts (5 blocs) : 0 échec stable, 4 skips documentés
- pint global vert sur tout le périmètre
- 596 routes OK, 0 TODO/FIXME (hors pré-existants documentés)
- MySQL réel : cluster poubelle absent, programmes 4, matières 52, niveaux 12 (L1-L3)

**Phase 2 Goals:**
1. Define Current Milestone scope (next delivery increment, NOT called MVP)
2. Graduate tribal knowledge to permanent homes: decisions, feature specs, glossary, conventions
3. Draft roadmap with milestones
4. Create assumption-register with four-risk framework (Value/Usability/Feasibility/Viability)
5. Create risk-register with identified risks and validation_status
6. Conditional: user-journeys + screen-inventory (ui_bearing=true)
7. Enforce project-derived cross-cutting coverage (not just "included in each story")
8. Pre-mortem and four-risk-check on all roadmap decisions

**Deliverables for Phase 2:**
- `roadmap` — project roadmap with milestones derived from north star
- `assumption-register` — assumptions tagged with risk_type and validation_status across Value/Usability/Feasibility/Viability
- `risk-register` — identified risks with mitigation status and owner
- `user-journeys` — conditional (ui_bearing=true): key user journeys for admin, enseignant, etudiant
- `screen-inventory` — conditional (ui_bearing=true): screen list with purposes and data flows
- Graduated tribal knowledge: decisions, feature specs, glossary entries, conventions documented