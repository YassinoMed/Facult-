# Datasets population (§18-19 + Phase 3)

`PopulationDataset` = registre PUR (aucune query, aucune dépendance module) :
`code → label, filtres whitelistés, colonnes par défaut, regroupements
autorisés, permission`. `PopulationService` = funnel unique vers
`ListeEtudiantsService` (autorisation dataset + scope faculté ici).

## Catalogue

| Dataset | Contexte → filtres | Usage |
|---|---|---|
| `students` | année/programme/niveau/semestre/groupe/statut/search | jury, délibérations |
| `students_by_group` | groupe/sous-groupe/statut | candidats (branche groupe), appel |
| `students_by_program` | programme/niveau/année/statut | listes programme |
| `students_by_level` | niveau/programme/année/statut | listes niveau |
| `students_by_ue` | ue/année/statut | page UE |
| `students_by_ecue` | matiere/année/statut | page ECUE, candidats (branche ECUE) |
| `students_by_teacher` | enseignant/année/statut | page enseignant |
| `exam_candidates` | groupe/matiere/année/statut | génération candidats, émargement |
| `attendance_list` | groupe/matiere/année/statut | feuille de présence |
| `grade_list` | matiere/groupe/année/statut | population à noter (valeurs lues séparément) |

## Règles

- Contextes = scalaires uniquement ; les objets (Epreuve, Deliberation) sont
  traduits côté module AVANT appel (jamais de dépendance module dans le cœur).
- Notes/valeurs : JAMAIS jointes à la population (données sensibles,
  permissions `notes_eval.*`, workflow verrouillage intact) — batch séparé.
- Candidats convoqués ≠ population pédagogique : la convocation garde ses
  lignes `Candidat` mais rend via le layout `pdf/liste` partagé.
- Listes par salle (§11) : non applicable — aucun lien candidat→salle dans le
  modèle (salle portée par l'épreuve) ; documenté, pas de migration opportuniste.
- Audit (§28) : `IntegrationLog` (`export.listes`, `export.emargement`,
  `export.presences.feuille`, `export.*` DataExchange) — le champ `operation`
  distingue déjà les événements ; aucuncode `*_EXPORT` ajouté (inutile).
- Async (§23) : `GeneratePopulationExport` accepte `dataset + contexte`
  (résolus dans le job) — aucun 2e job par module.
- Vues (§22) : `ExportTemplate type=listes` + `VueSauvegardee` réutilisés,
  aucune table par type de vue.
