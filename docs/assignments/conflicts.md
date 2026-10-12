# Conflits

Chaque conflit : `{type, gravite, raison}` (+ étudiant/groupe en contexte).
Types : `CAPACITY`, `SCHEDULE`, `FACULTY_SCOPE`, `PROGRAM_MISMATCH`,
`LEVEL_MISMATCH`, `GROUP_INACTIVE`, `REGISTRATION_*`, `ETUDIANT_SUSPENDU`,
`INVALID_SUBGROUP`, `EXISTING_ASSIGNMENT` (info).

Étudiant sans groupe → statut `non_affecte` + raisons par groupe
(« G1 → complet ; G2 → conflit horaire ; … »). Jamais forcé.
