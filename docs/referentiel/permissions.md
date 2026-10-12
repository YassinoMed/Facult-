# Permissions référentiel

`referentiel.view|manage|import|export`, `maquettes.approve`
(`Permissions::TOUTES` + matrice). Correspondance spec :
create/edit/delete → `manage` (pas de splintering) ; version →
`maquettes.approve` sur transitions approved/active ; import/export via
pipeline `data.*`. Policy `ReferentielPolicy` : permission +
`dansPerimetre()` faculté (IDOR testé). Scope API : `referentiel:read`.
