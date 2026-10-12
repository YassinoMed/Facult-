# Phase 2 Retrospective — Scope & Roadmap

**Phase**: 2  
**Date**: 2026-10-10  
**Project**: University Management Laravel Application  
**Flow**: legacy (existing project delivered without ADOS)

## What Happened
- Defined Current Milestone scope (legacy: next increment, NOT MVP) focused on Consolidation & Reliability Enhancement
- Created 5-milestone roadmap covering: Input Validation & Security, Import/Export Pipeline, Intelligent Assignment Engine, Enhanced Security & Confidentiality, Documentation & Training
- Graduated tribal knowledge to permanent homes: decisions (assumption-register, risk-register), feature specs (user-journeys, screen-inventory), conventions (roadmap)
- Created user-journeys for 3 user types (Étudiant, Enseignant, Admin Utilisateur) with 57 total journeys
- Created screen inventory with 57 screens documented across all user types
- Produced assumption-register with 10 assumptions (8 validated, 2 pending) using four-risk framework
- Produced risk-register with 10 risks (5 validated, 2 pending, 3 mitigated) using four-risk framework
- Defined cross-cutting coverage: Security & Confidentiality, Performance & Scalability, Compliance & Governance, Testing & Quality
- Performed pre-mortem and four-risk-check analysis on all roadmap decisions

## What Went Well
- Successfully extracted comprehensive vision from existing documentation
- Created user journeys that reflect the multi-user, UI-bearing nature of the project
- Screen inventory provides concrete understanding of the UI surface area
- Assumption register uses four-risk framework properly with validation_status tracking
- Risk register identifies mitigation strategies and owners
- Cross-cutting coverage explicitly addresses project-specific concerns (security, performance, compliance, testing)
- Roadmap aligned with north star objectives and current capabilities

## Improvement Patterns to Repeat
- Extract vision from existing docs rather than imposing external framework
- Create user journeys that reflect actual user types and workflows
- Document screen inventory to understand UI surface area
- Use four-risk framework (Value/Usability/Feasibility/Viability) consistently
- Maintain monotonic OPEN-Q IDs for traceability
- Create both user-facing and technical documentation
- Document anti-goals to prevent scope creep
- Include concrete metrics (2291 tests, 596 routes, MySQL stats)

## Caution / Why It Matters
- Four-risk framework ensures comprehensive risk assessment
- Legacy classification (not MVP) requires careful milestone definition
- Cross-cutting coverage prevents "included in each story" anti-pattern
- User journeys and screen inventory support Phase 3 persona/JTBD creation
- Roadmap success depends on accurate north star from Phase 1

## Anti-pattern Avoided
- Did not impose generic roadmap on an established educational system
- Did not create MVP for legacy project (used "Current Milestone" instead)
- Did not skip four-risk framework analysis
- Did not omit cross-cutting coverage in favor of siloed improvements
- Did not create user journeys from assumptions; used real user types

## Phase Outcome
- **Scope**: Current Milestone defined as Consolidation & Reliability Enhancement (5 milestones)
- **Roadmap**: 5-milestone delivery plan aligned with north star
- **Assumption register**: 10 assumptions tracked with four-risk framework
- **Risk register**: 10 risks identified with mitigation status and owners
- **Graduated knowledge**: decisions (assumption-register, risk-register), features (user-journeys, screen-inventory), conventions (roadmap)
- **Persona/JTBD**: User journeys provide foundation for persona creation
- **Cross-cutting coverage**: Security, Performance, Compliance, Testing explicitly addressed
- **State**: Phase 2 complete, ready for Phase 3 (Tech Stack & Architecture)

## Next Phase
- Phase 3: Tech Stack & Architecture
- Deliverables: Tech-decision with Build/Buy/Adopt/Borrow framework, feasibility confirmation
- Sign-off: Product + technical contract