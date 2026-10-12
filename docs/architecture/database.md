# Base de données (~60 migrations)

## Conventions vérifiées

- FK `restrict` (référentiels, jamais de cascade destructive) / `cascade`
  (lignes dépendantes : notes, présences, candidatures).
- Uniques métier : `etudiants(cin, num_inscription, email)`,
  `inscriptions(etudiant, annee)`, `notes(semestre, matiere, session)`,
  `affectations` via statuts, `annees(libelle)`, `programmes(faculte, code)`.
- Index : requêtes listes/filtres (`idx_etudiants_faculte_statut`,
  `idx_etudiants_faculte_prog_niveau`, `idx_affectations_annee_statut`…),
  vérifiés `EXPLAIN` (benchmark 3000 : 0,48 s/13 req).
- Enums SQL (statuts fermés) vs `string` + machines `TRANSITIONS` serveur
  (évaluation, inscriptions) ; PHP enums : `NiveauConfidentialite`,
  `TypeDossierSensible` uniquement.
- Timestamps partout ; `locked_at`/`verrouillee_at` (notes) ;
  `expires_at` (documents, exports) sans suppression auto ;
  **1 seul soft delete** : `student_sensitive_records`.
- JSON : `form_data`, `data` notifs, `regles_appliquees/anomalies`.
- Pas de relations polymorphes, pas d'UUID sauf sensible.

## Règle

Nouvelle table uniquement si responsabilité non portable (cf. §7 :
`student_sensitive_records` justifiée, fichiers restés GED).
Doublons examinés : `archives_etudiants` vs dossiers GED vs situations vs
historiques — verdict : À CONSERVER (sémantiques distinctes, voir audit).
