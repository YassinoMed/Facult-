# Cartographie des modules (code réel au 01/10/2026)

Cœur (`app/`) : Http (37 contrôleurs Admin + 8 Api/V1 + 4 Etudiant), Services
(18 racine : calcul, affectation, inscriptions, progression, population,
fich­e, audit, documents, notifications…), Models, Policies (7),
Events/Listeners cœur (2/2), Jobs (5), Commands (15+), Contracts (2),
Support (`Permissions`, `ApiScopes`, `Recherche`, `ModuleRegistry`).

| Module | Tables | Permissions |
|---|---|---|
| Attendance | `presences`, `attendance_justifications` | `attendance.*`, `attendance.justify` |
| StudentDossiers | `student_dossiers`, `student_documents`, `..._versions`, `generated_documents` | `student_dossiers.*`, `student_documents.*` |
| OnlineRequests | `online_requests`, `request_types`, `..._attachments`, `..._documents`, `request_delegations` (LOOP 01) | `demandes.*` (14) |
| Schedule | `schedules`, `schedule_slots/exceptions/changes/imports`, `rooms`, `teachers`, `groups` | `schedules.*` |
| Referentiel | `type_diplomes` → `maquettes` (11 tables, FK restrict) | `referentiel.*`, `maquettes.approve` |
| Enseignement | `types_enseignement`, `enseignements`, `affectations_enseignement`, `sous_groupes`, `enseignement_groupe`, `seuils_service`, `composantes_evaluation` | `enseignements.*` |
| Evaluation | 11 tables `evaluation_*` | `evaluations/sessions/epreuves/surveillances/notes_eval/resultats/deliberations.*` (~34) |
| Announcements | `annonces`, `annonce_lectures`, `campagnes` | `annonces.*`, `campagnes.*` |
| Calendar | `calendrier_evenements` | `calendriers.*` |
| Tasks | `tasks`, `task_evenements` (+ cœur `elements_recents`, `vues_sauvegardees`) | `tasks.*`, `cockpit.view` |
| Finances | `tarifs`, `factures`, `facture_lignes`, `paiements`, `remboursements` | `finances.*` |
| StudentArchive | `student_sensitive_records` (seule table métier) | `student_archive.*` (8) + 6 `sensitive/medical/disciplinary` |

Détail par module : `docs/MODULE-*.md`, `docs/student-archive/architecture.md`.
Dépendances : modules → cœur ; inter-modules documentées (Tasks, Calendar,
StudentArchive, Evaluation consomment Referentiel/Schedule — voir audit).
AUCUN soft delete sauf `student_sensitive_records` (choix assumé, §7).
