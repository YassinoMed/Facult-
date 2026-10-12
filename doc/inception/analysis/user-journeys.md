# User Journeys — University Management Laravel Application

*Derived from project analysis: ui_bearing=true, multi_user=true, complex_domain=true*

## User Types

### 1. Étudiant (Student)
**Primary goal:** Complete enrollment, track application status, access documents, view results

### 2. Enseignant (Teacher)
**Primary goal:** Access schedule, manage surveillances, view presences, access pedagogical resources, communicate with students

### 3. Admin Utilisateur (Admin User)
**Primary goal:** Manage academic structure, process enrollments, configure import/export, monitor system health, generate reports

---

## User Journeys

### Journey 1: Student Enrollment (Étudiant → Inscription complète)
**Actor:** Étudiant  
**Entry point:** `/inscription` (public registration) or `inscription.faq`

1. **Discovery:** Student finds registration page or QR code
2. **Initial identification:** CIN + date de naissance verification via `POST /inscription/identifier`
   - Success: proceed to form
   - Existing student: redirect to dossier
   - Unknown: warned with error message
3. **Form completion:** 6-step wizard (identification, identité, coordonnées, formation, documents, confirmation)
   - Step validation at each stage
   - Draft auto-save (localStorage)
   - CIN/naissance locked after verification
4. **Document upload:** Justificatifs verified against matrice `documents_requis_par_type`
5. **Confirmation receipt:** `RecepisseController` generates PDF, stored under `generated_documents`
6. **Status tracking:** Student portal shows dossier status, justificatifs checklist, notifications
7. **Completion:** Notification on status change (valide/rejete/en_attente)

**Touchpoints:**  
- Routes: `/inscription`, `/inscription/identifier`, `/inscription/faq`, `/etudiant/dashboard`, `/etudiant/inscriptions`, `/etudiant/dossier`
- Controllers: `InscriptionController`, `RecepisseController`, `MonDossierController`, `StudentStatusService`
- Views: `etudiant/inscription/*`, `etudiant/profil`, `etudiant/mon-dossier`, `etudiant/recherche`

**Key validations:**  
- CIN format: `required|string|regex:/\A[0-9]{8}\z/` (Tunisian CIN)
- Date of birth: `before:today` (no future dates)
- Email: `required|string|email|max:254` (when required)
- File uploads: validation server-side (extension, MIME, taille max)

---

### Journey 2: Teacher Schedule & Surveillance (Enseignant → Mes Gardes)
**Actor:** Enseignant  
**Entry point:** `/enseignant/dashboard` or `/enseignant/emploi-du-temps`

1. **Login:** Teacher logs in with email/matricule (mirrored from student auth)
2. **Dashboard:** View personal schedule, current assignments, pending surveillances
3. **My Surveillances:** List of assigned surveillance duties with dates, times, locations
4. **Schedule view:** Weekly/monthly emploi-du-temps with exceptions
5. **Presences:** Access student presence records for their modules
6. **Annonces:** Receive targeted announcements
7. **Documents:** Access pedagogical resources (supports de cours)
8. **Notifications:** Receive alerts for schedule changes, new assignments, etc.

**Touchpoints:**  
- Routes: `/enseignant/*` (enseignant.auth, `module:enseignant`)
- Controllers: `MonEmploiEnseignantController`, `EmploiSurveillanceController`, `MesPresencesController`, `MesRessourcesController`
- Views: `enseignant/dashboard`, `enseignant/emploi-du-temps`, `enseignant/surveillances`, `enseignant/presences`, `enseignant/mes-documents`

**Key features:**  
- Session-based authentication (mirrored student flow)
- Module-gated routes (`module:enseignant`)
- Notifications with deduplication via `cle_dedup`
- N+1 prevention with eager loading (`with([...])`)

---

### Journey 3: Admin Enrollment Processing (Admin → Traitement dossier)
**Actor:** Admin scolarité / Admin faculté  
**Entry point:** `/admin/dashboard` or `/admin/inscriptions`

1. **Access control:** Login → permission verification (`configuration.manage`, `archives.view`, `archives.update`)
2. **Review queue:** View filtered inscription queue by status, date, faculty
3. **Dossier review:** Open student dossier with `documents_requis` checklist
4. **Validation:** Mark documents as valid/invalid, request additional documents
5. **Affectation:** Assign student to group via `AffectationService` (auto or manual)
6. **Confirmation:** Update dossier status, trigger notifications
7. **Reporting:** Generate status reports, export filtered student lists

**Touchpoints:**  
- Routes: `/admin/inscriptions/*`, `/admin/dossiers/*`, `/admin/export/*`
- Controllers: `InscriptionGestionController`, `MonDossierController`, `FileTraitementController`, `ListeEtudiantsService`
- Views: `admin/inscriptions/index`, `admin/inscriptions/show`, `admin/dossiers/*`, `admin/exports/*`

**Key validations:**  
- Permission gates: `can:archives.update`, `can:configuration.manage`
- Scope gating: `faculteId()` on all queries
- CIN normalization and duplicate detection via `NormalisationService`

---

### Journey 4: Import/Export Operations (Admin → Import intelligent)
**Actor:** Admin scolarité / Admin faculté  
**Entry point:** `/admin/donnees` (import) or `/admin/exports` (export)

1. **Import preview:** Upload file → preview data with column mapping
2. **Validation:** Automatic validation, error detection, correction suggestions
3. **Confirmation:** User reviews and confirms import
4. **Execution:** Import runs with anti-TOCTOU measures, chunked processing
5. **Result:** Success report with created/updated/failed counts
6. **Error handling:** Downloadable error report for corrections

**Export flow:**
1. **Filter selection:** Choose entities, columns, filters (faculte, programme, niveau)
2. **Preview:** Estimate volume and preview fields
3. **Execute:** Generate export with permission-based column masking
4. **Download:** Secure download link with retention (default 168 hours)

**Touchpoints:**  
- Routes: `/admin/donnees/*`, `/admin/exports/*`, `/api/v1/data/*`
- Controllers: `DataExchangeController`, `ImportController`, `ExportController`, `GeneratePopulationExport`
- Services: `DataImportService`, `DataExportService`, `ExportColumnPolicy`, `ExportRegistry`
- Views: `admin/donnees/*`, `admin/exports/*`

**Key features:**  
- Import flow: preview→token→confirm→revalidation→transaction
- CIN masking in exports: `BK***001` format
- Formats: CSV, XLSX (placeholder), ODS, JSON, XML
- Validation: `FormatInvalide` mentions ODS support status
- Concurrency control: `lockForUpdate` on imports

---

### Journey 5: Academic Evaluation Flow (Enseignant/Admin → Notes et Délibérations)
**Actor:** Enseignant (saisie), Admin (délibération)  
**Entry point:** `/enseignant/notes` or `/admin/evaluation`

1. **Grade entry:** Teacher enters notes for their modules (ECUE affectée active)
2. **Anonymous grading:** Support anonymous entry via CMP-XXXXXX format
3. **Validation:** Grades validated before publication
4. **Délibération:** Admin reviews and validates final decisions in jury session
5. **Publication:** Results published with proper access control (perimètre épreuve)
6. **Distribution:** Students access results via portal (publie=true)

**Touchpoints:**  
- Routes: `/enseignant/notes/*`, `/admin/evaluation/*`, `/admin/notes-epreuves/*`
- Controllers: `NoteEpreuveController`, `EvaluationController`, `SaisieNoteService`, `SessionExamenController`
- Views: `enseignant/notes*`, `admin/evaluation/*`, `etudiant/notes` (read-only)

**Key features:**  
- Anonymous composting: `CMP-XXXXXX`, `UNIQUE epreuve×etudiant`
- Decimal on 20 scale, NOT NULL, constraints `chk_notes_note_0_20`
- `evaluation_notes` - valeur NULL + borne 0..note_max ligne via `SaisieNoteService`
- Compensation semester load-bearing logic
- Deliberation with quorum, validation, and decision publishing