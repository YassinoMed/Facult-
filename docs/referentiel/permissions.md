# Permissions référentiel

`referentiel.view|manage|import|export`, `maquettes.approve`
(`Permissions::TOUTES` + matrice). Correspondance spec :
create/edit/delete → `manage` (pas de splintering) ; version →
`maquettes.approve` sur transitions approved/active ; import/export via
pipeline `data.*`. Policy `ReferentielPolicy` : permission +
`dansPerimetre()` faculté (IDOR testé). Scope API : `referentiel:read`.

## `archives.update` — fiche étudiante

`archives.update` ne couvre plus seulement le **statut** et l'**affectation
de groupe** : la modification administrative de la fiche d'un étudiant
(identité, contact, rattachement LMD et inscription courante) passe par
`admin.etudiants.modifier` (formulaire) et `admin.etudiants.update`
(enregistrement), avec la **même** permission et le **même** périmètre de
faculté (`EtudiantPolicy::update` : `archives.update` +
`peutAccederFaculte()`).

Règle de non-duplication : le statut du dossier, le groupe, le mot de passe
et l'année d'inscription ne sont **pas** modifiables par ce formulaire —
ils restent délégués à leurs workflows dédiés
(`admin.etudiants.updateStatut`, `admin.etudiants.affectations.changer`,
`admin.etudiants.reinitialiserMotDePasse`, `admin.inscriptions.progresser`).
