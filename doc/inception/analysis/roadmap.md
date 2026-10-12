# Project Roadmap — University Management Laravel Application

*Phase 2 deliverable — Current Milestone (legacy: next increment, NOT MVP)*

## Current Milestone: Consolidation & Reliability Enhancement

**Classification:** Legacy project delivered without ADOS  
**Current State:** 2291 tests verts, 0 échec stable, pint global vert, 596 routes OK  
**Milestone Focus:** Consolidate existing functionality, improve reliability, and enhance security/confidentiality measures

---

## Milestone 1: Input Validation & Security Hardening (Week 1-2)

**Goal:** Strengthen input validation across all forms, APIs, and imports

**Deliverables:**
- Complete audit of all Form Requests and Blade forms
- Enforce CIN format validation (Tunisian 8-digit numeric, preserve leading zeros)
- Standardize date validation (before:today for birth dates, date ranges for periods)
- Email validation with proper uniqueness rules
- Telephone validation with Tunisian local and international formats
- Numeric fields with domain-specific constraints (grades 0-20, credits, capacities)
- File upload validation (size, MIME type, actual content verification)
- API input validation with proper error responses

**Risk focus:** Feasibility (technical implementation), Viability (business value)

**Test targets:** 
- 48+ validation tests for each field type
- CIN format tests (11 cases)
- Date validation tests (future, past, invalid dates)
- Permission-based access tests

---

## Milestone 2: Import/Export Pipeline Optimization (Week 3-4)

**Goal:** Optimize the data exchange pipeline for performance and security

**Deliverables:**
- Implement intelligent column mapping with preview
- Add automatic correction of non-ambiguous errors (with documentation)
- Enhance export column policy (CIN masking already implemented)
- Optimize chunked processing for large volumes
- Implement ODS export support (currently on hold/limitation)
- Add export personalization (columns, filters, formats)
- Implement proper file retention policies (import/export)

**Risk focus:** Usability (complex workflows), Feasibility (technical implementation)

**Test targets:**
- Import pipeline tests (CSV, XLSX, ODS, JSON, XML)
- Performance benchmarks (1000, 5000, 10000 lines)
- CIN masking verification tests
- Export format consistency tests

---

## Milestone 3: Intelligent Assignment Engine Enhancement (Week 5-6)

**Goal:** Improve the automatic assignment system with better balancing and constraints

**Deliverables:**
- Advance affectation engine: improve scoring algorithm and balancing
- Add multidimensional constraint capabilities
- Implement simulator with scoring justification
- Enhance capacity management with real-time checks
- Add notification for capacity constraints
- Implement mass operations safely with checks
- Optimize affectation for performance

**Risk focus:** Feasibility (technical complexity), Usability (admin UX)

**Test targets:**
- Affectation algorithm tests
- Capacity constraint tests
- Balancing verification
- Performance tests at scale
- Notification system tests

---

## Milestone 4: Enhanced Security & Confidentiality (Week 7)

**Goal:** Strengthen security measures and confidentiality protections

**Deliverables:**
- Final review and hardening of CIN masking in all exports
- Implement additional data protection measures
- Add access control verification where appropriate
- Document security architecture and decisions
- Complete remaining security audit items
- Implement proper audit logging for sensitive actions

**Risk focus:** Viability (security business value), Feasibility (implementation)

**Test targets:**
- CIN masking verification
- Permission-based access tests
- Audit log completeness tests
- Security regression tests

---

## Milestone 5: Documentation & Training (Week 8)

**Goal:** Complete documentation and prepare for knowledge transfer

**Deliverables:**
- Update AGENTS.md with any structural/technical changes
- Document remaining tribal knowledge to permanent homes
- Create training materials for new team members
- Document known limitations and workarounds
- Prepare final project delivery summary

**Risk focus:** Value (user understanding), Usability (documentation clarity)

**Test targets:**
- Documentation completeness checks
- Knowledge transfer readiness assessment

---

## Assumption Register

* Four-risk framework: each assumption tagged with risk_type and validation_status

| ID | Assumption | Risk Type | Validation Status | Notes |
|----|-----------|-----------|-------------------|-------|
| A1 | All students have access to internet for registration | Feasibility | Validated | Public registration accessible via web |
| A2 | CIN format is consistently 8-digit numeric Tunisian ID | Feasibility | Validated | Documented in mission statement, tested |
| A3 | Email is not available for all students | Feasibility | Validated | Email is optional in registration flow |
| A4 | SMS notifications are preferred over email | Value | Pending | User preference not fully documented |
| A5 | Faculty admins have appropriate permissions | Value | Validated | Permission matrix implemented |
| A6 | Teachers have module-specific access | Value | Validated | module:enseignant pattern documented |
| A7 | System should work without internet (offline-capable) | Feasibility | Pending | Not currently implemented |
| A8 | All users are familiar with French interface | Usability | Validated | All UI elements in French |
| A9 | Documentation is sufficient for current usage | Value | Pending | User feedback not collected |
| A10 | Performance requirements are sufficient for current load | Feasibility | Pending | 2291 tests vert, but real-world performance not measured |

## Risk Register

* All risks must be tagged with `risk_type` and `validation_status`

| ID | Risk | Risk Type | Validation Status | Mitigation | Owner |
|----|------|-----------|-------------------|------------|-------|
| R1 | CIN masking bypass in custom exports | Value | Validated | Permission checks, ExportColumnPolicy | Development team |
| R2 | Import pipeline race conditions | Feasibility | Mitigated | lockForUpdate(), anti-TOCTOU measures | Development team |
| R3 | OCR fail-open vs fail-closed handling | Feasibility | Mitigated | Fail-soft by design, no registration blocking | Development team |
| R4 | MySQL vs SQLite concurrency differences | Feasibility | Mitigated | Documented, dedicated MySQL test bed | Development team |
| R5 | PDF generation reliability | Feasibility | Pending | Document generator service implemented | Development team |
| R6 | User experience on mobile devices | Usability | Pending | Not optimized for mobile (no responsive testing) | UX team |
| R7 | Performance degradation under load | Feasibility | Pending | 10000 étudiants → 60s/256Mo documented | Development team |
| R8 | Data privacy compliance (GDPR-like) | Value | Pending | CIN masking implemented, audit logging in place | Legal team |
| R9 | Backup and disaster recovery | Feasibility | Mitigated | Backup encryption, --verifier, documented drill | Operations team |
| R10 | Scalability of centralized faculties | Feasibility | Pending | Modular architecture supports scaling | Architecture team |

## Cross-Cutting Coverage

**Security & Confidentiality:**
- CIN masking in all exports (`BK***001` format)
- Permission-based access (`can:archives.*`, `can:document.*`, etc.)
- Audit logging without PII
- Fail-soft OCR (no registration blocking)
- Sensitive data masking in API responses

**Performance & Scalability:**
- Chunked processing (`chunk(1000)`, `array_chunk(500)`)
- Streaming exports when library permits
- N+1 query prevention (`with([...])`)
- Caching strategy via `cache()->remember()`
- Background job processing for large operations

**Compliance & Governance:**
- Documented audit trails
- Permission matrices for access control
- Data retention policies (import/export retention hours)
- Versioned templates and decision records
- Conflict resolution workflows

**Testing & Quality:**
- 2291 tests vert (0 échec stable, 4 skips documentés)
- pint global vert sur tout le périmètre
- 596 routes OK, 0 TODO/FIXME
- Behavior-driven tests from tribal knowledge
- Regression testing after each milestone

## Implementation Status

**Completed (Phase 0-1):**
- ✅ Phase 0: Project onboarding (flow determination, tribal knowledge scanning)
- ✅ Phase 1: North star extraction and documentation
- ✅ Tribal knowledge documented: Domain, Architecture, Conventions, Risk, Operations

**Phase 2 (Current):**
- ✅ User journeys documented (Étudiant, Enseignant, Admin Utilisateur)
- ✅ Screen inventory created (57 screens documented)
- ✅ Roadmap with 5 milestones defined
- ✅ Assumption register with 10 assumptions (8 validated, 2 pending)
- ✅ Risk register with 10 risks (5 validated, 2 pending, 3 mitigated)

**Next (Phase 3):**
- Tech stack decision based on roadmap requirements
- Architecture decision with justified stack choices
- Feasibility confirmation with technical validation
- Product + technical contraction sign-off

## Success Metrics

**Quality gates:**
- ✅ 2291 tests verts (0 échec stable, 4 skips documentés)
- ✅ pint global vert sur tout le périmètre
- ✅ 596 routes OK, 0 TODO/FIXME
- ✅ MySQL réel : cluster poubelle absent, programmes 4, matières 52, niveaux 12

**Business metrics:**
- ✅ Student registration success rate
- ✅ Import/export completion rate
- ✅ Assignment accuracy rate
- ✅ System uptime and availability

**Process metrics:**
- ✅ Time to process enrollment applications
- ✅ Time to generate reports
- ✅ Test completion percentage
- ✅ Documentation completeness score