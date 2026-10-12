# Sources de vérité (lecture : colonne `Qui écrit`)

| Donnée | Source | Écrivains |
|---|---|---|
| Étudiant | `etudiants` (`id` canonique ; `num_inscription` fonctionnel ; `cin` pièce) | Admin, imports, OCR (brouillon) |
| Programme/Niveau/Semestre/UE/ECUE | `programmes/niveaux/semestres_lmd/unites_enseignement/matieres` (FK restrict, `ordre`, maquettes versionnées) | Référentiel, imports |
| Année | `annees_universitaires` (`libelle` UQ, `active` = courante, `suivante()`) | Config académique |
| Inscription acad. | `inscriptions_academiques` (UQ étudiant+année) | `InscriptionService`, progression, imports |
| Inscription péda. | `inscriptions_pedagogiques` (garde maquette ACTIVE) | `InscriptionService`, génération candidats (lecture centrale) |
| Notes officielles | `notes` (UQ semestre+matière+session, `locked_at`) | Saisie épreuve (sync), imports |
| Résultats | `evaluation_decisions` (+ `inscriptions/semestres.decision` dénormalisés calculés) | Jury/délibération |
| Groupe | `groups` + `affectations_groupe` (historisé ; `etudiants.groupe_id` = cache) | `AffectationService` (10 contraintes), imports |
| Documents | `documents_academiques` (cœur) / `student_documents`+versions (GED) / `generated_documents` / pièces demandes | Uploads validés (MIME réel), générateurs PDF |
| Audit | `historique_academique` (immuable) + `IntegrationLog` (hash seul) | `AuditService`, listeners |
| Notifications | `notifications` (service unique, batch 500, purge 180j) | Tous modules (producteurs) |

Lectures : policies + scope faculté + permissions ; historique via
`situations_etudiant`, `evaluation_notes_historique`, archive.
Calculs : `AcademicCalculationService` unique (seuils 10/8, centièmes).
Progression : `AcademicProgressionService` (dry-run + idempotent, 0 table).
