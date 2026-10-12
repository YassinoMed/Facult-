# Screen Inventory — University Management Laravel Application

*Conditional deliverable for ui_bearing=true, multi_user=true*

## User Type: Étudiant (Student)

### Public Registration (No Auth)
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| Registration Start | `/inscription` | Entry point for new registration | CIN + date de naissance verification | Alpine.js wizard (6 steps) |
| Identification Check | `POST /inscription/identifier` | Verify CIN + birth date format | Fail-closed validation | InscriptionController |
| FAQ | `/inscription/faq` | Help and context | Static content + targeted help | inscription/faq |
| Success Page | `/inscription/success` | Confirmation after submission | Post-submission state | inscription/success |
| Status Tracking | `/etudiant/suivi` | Track dossier status | Polled or manual refresh | etudiant/suivi |

### Student Portal (Authenticated)
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| Dashboard | `/etudiant/dashboard` | Overview of dossier status, notifications | Auth student, filter by student_id | etudiant/dashboard |
| Profile | `/etudiant/profil` | View/edit personal information | Read/write personal data, documents list | etudiant/profil |
| My Inscriptions | `/etudiant/inscriptions` | List of academic inscriptions | Read-only, filtered by student | etudiant/inscriptions |
| My Documents | `/etudiant/mon-dossier` | View necessary documents checklist | DocumentsRequisService verified | etudiant/mon-dossier |
| My Notes | `/etudiant/notes` | View published grades | Read-only, published only | etudiant/notes |
| History | `/etudiant/historique` | Academic history | Read-only, chronological | etudiant/historique |
| Corrections | `/etudiant/corrections` | View correction requests | Read-only if any | etudiant/corrections |
| Search | `/etudiant/recherche` | Search across entities | Filtered by student scope | etudiant/recherche |
| Receipts | `/etudiant/recepisse` | Generate PDF receipt | GeneratedDocument, PDF output | etudiant/recepisse |
| Appointments | `/etudiant/rendez-vous` | Request corrective sessions | Create/view/delete appointments | etudiant/rendez-vous |

**Data security:**
- CIN masked in exports (`BK***001`)
- Sensitive documents restricted by permission
- No cross-student visibility except via portal

---

## User Type: Enseignant (Teacher)

### Authentication
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| Login | `/enseignant/connexion` | Teacher authentication | Email/matricule + password | enseignant/connexion |
| Dashboard | `/enseignant/dashboard` | Overview of teacher schedule and tasks | Filtered by teacher_id | enseignant/dashboard |

### Schedule & Assignments
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| My Schedule | `/enseignant/emploi-du-temps` | Weekly/monthly schedule | Filtered by teacher_id, exceptions | enseignant/emploi-du-temps |
| My Surveillances | `/enseignant/surveillances` | Surveillance duties | Filtered by teacher_id, status | enseignant/surveillances |
| My Presences | `/enseignant/presences` | Student presence records | Read-only for assigned modules | enseignant/presences |
| My Requests | `/enseignant/demandes` | Reclamation requests | Create/view/delete requests | enseignant/demandes |
| My Complaints | `/enseignant/reclamations` | Teacher complaints | Create/view/delete complaints | enseignant/reclamations |

### Resources & Documents
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| My Resources | `/enseignant/mes-documents` | Pedagogical resources | Read-only for assigned modules | enseignant/documents |
| Profile | `/enseignant/profil` | Update personal info | Read/write personal data | enseignant/profil |
| Edit | `/enseignant/modifier` | Edit profile information | Read/write limited fields | enseignant/modifier |

**Data security:**
- Teacher resources scoped to assigned modules
- No access to other teachers' data
- Presence records read-only (administrative workflow)

---

## User Type: Admin Utilisateur (Admin User)

### Administration Dashboard
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| Dashboard | `/admin/dashboard` | System overview, KPIs | Aggregated data, filters by argument | dashboard.blade.php |
| User Management | `/admin/administrateurs` | Admin user management | Full CRUD for admin users | admin/administrateurs |
| Faculty Management | `/admin/facultes` | Faculty/school management | Full CRUD for faculties | admin/facultes |
| Group Management | `/admin/groupes` | Student group management | Full CRUD, capacity management | admin/groupes |
| Teacher Management | `/admin/enseignants` | Teacher user management | Full CRUD, module assignment | admin/enseignants |
| Student Management | `/admin/etudiants` | Student records management | Full CRUD, groups, documents | admin/etudiants |

### Enrollment & Registrations
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| Admissions | `/admin/admissions` | Admission decisions | Process admission requests | admin/admissions |
| Inscriptions | `/admin/inscriptions` | Enrollment management | Process student registrations | admin/inscriptions |
| Registration Periods | `/admin/periodes` | Period management | Create/update enrollment periods | admin/periodes |
| Registration Calendar | `/admin/calendrier` | Academic calendar | View/manage calendar events | admin/calendrier |
| CIN Verification | `/admin/cin-verification` | CIN verification | Verify CIN format and status | admin/cin-verification |
| Student Archive | `/admin/archives` | Historical records | View archived records | admin/archives |
| Student Dossiers | `/admin/dossiers` | Complete student files | View/manage complete dossiers | admin/dossiers |

### Data Exchange (Import/Export)
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| Data Import | `/admin/donnees` | Import preview+confirm | Preview, validate, confirm, execute | admin/donnees |
| Data Export | `/admin/exports` | Export data | Select filters, format, generate | admin/exports |
| Import Templates | `/admin/import-templates` | Import templates | Manage import mappings | admin/import-templates |
| Import History | `/admin/import-history` | Import history | View past import results | admin/import-history |

### Evaluation & Assessment
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| Evaluations | `/admin/evaluations` | Assessment management | Create/manage evaluations | admin/evaluations |
| Examination Sessions | `/admin/examens` | Exam sessions | Manage exam sessions, planning | admin/examens |
| Grades by Test | `/admin/notes-epreuves` | Grade management | Enter/validate grades | admin/notes-epreuves |
| Deliberations | `/admin/deliberations` | Jury decisions | Manage deliberations, publishing | admin/deliberations |
| Attendance Templates | `/admin/attendance/*` | Attendance templates | Manage attendance templates | module_attendance views |
| Schedule | `/admin/schedule/*` | Timetable management | Manage teaching schedules | module_schedule views |

### Academic Structure
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| Programs | `/admin/programmes` | Program management | Manage academic programs | admin/programmes |
| Degrees | `/admin/diplomes` | Degree management | Manage degree types | admin/diplomes |
| Course Units | `/admin/unites-enseignement` | UE management | Manage course units | admin/unites-enseignement |
| Campus | `/admin/campus` | Campus management | Manage campus locations | admin/campus |
| Referentiels | `/admin/referentiels` | Reference data | Manage academic referentials | admin/referentiels |

### Analytics & Reporting
| Screen | Route | Purpose | Data Flow | Key Components |
|--------|-------|---------|-----------|----------------|
| BI Dashboard | `/admin/bi` | Business intelligence | Aggregated KPIs, dashboards | admin/bi |
| Statistics | `/admin/statistiques` | System statistics | Various statistics | admin/statistiques |
| Audit Logs | `/admin/audit` | System audit | View audit logs by action | admin/audit |
| Queue Management | `/admin/queue` | Background jobs | Manage job queue | admin/queue |
| Webhooks | `/admin/webhooks` | Webhook management | Manage webhook integrations | admin/webhooks |

**Data security:**
- All admin routes require permission checks
- faculteId() scoping on all queries
- CIN masking in exports (BK***001)
- Audit logging for sensitive actions
- CSRF protection on all forms

---

## Screen Patterns & Conventions

### Common Patterns
- **Public routes:** `/inscription/*`, `/inscription/verification`, `/inscription/faq` (no auth)
- **Student routes:** `/etudiant/*` require `student_auth` middleware
- **Teacher routes:** `/enseignant/*` require `enseignant.auth` middleware + module activation
- **Admin routes:** `/admin/*` require permission checks + faculteId() scoping

### Responsive Design
- Blade templates with Tailwind CSS
- Alpine.js for interactive components
- Mobile-responsive layouts for student and teacher portals

### Accessibility
- ARIA attributes on critical components
- Focus management on important actions
- Screen reader friendly error messages

### Security Features
- CIN masking in UI (`BK***001` format)
- Permission-based column masking in exports
- Audit logging without PII
- Throttled endpoints (5/min auth, 30/min read)