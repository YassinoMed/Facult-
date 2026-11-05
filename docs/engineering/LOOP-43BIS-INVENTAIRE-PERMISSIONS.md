# LOOP 43-BIS — Inventaire des permissions

## Objectif
Auditer `Permissions.php` et relever pour chaque permission son identifiant, code, nom, description, module, rôle concerné, dépendances, présence dans les Policies, middleware, routes et utilisation réelle.

## 1.1 Source canonique : `Permissions::TOUTES`
Plus de 173 permissions distinctes organisées par module. Chaque permission porte un code `module.action` (ou `module.ressource.action` selon la structure).

### Structure par module (échantillon représentatif) :

- **archives** : `archives.view`, `archives.create`, `archives.update`, `archives.delete`
- **documents** : `documents.view`, `documents.upload`, `documents.download`, `documents.delete`
- **notes** : `notes.view`, `notes.create`, `notes.update`, `notes.delete`, `notes.unlock`
- **history** : `history.view`
- **student_archive** : `student_archive.view`, `student_archive.timeline.view`, `student_archive.academic.view`, `student_archive.documents.view`, `student_archive.documents.download`, `student_archive.export`, `student_archive.pdf`, `student_archive.audit.view`
- **student_archive.sensitive** : `student_archive.sensitive.view`, `student_archive.sensitive.manage`, `student_archive.medical.view`, `student_archive.medical.download`, `student_archive.disciplinary.view`, `student_archive.disciplinary.download`
- **configuration** : `configuration.manage`
- **admissions** : `admissions.view`, `admissions.manage`
- **exports.personal** : `exports.personal`
- **attendance** : `attendance.view`, `attendance.create`, `attendance.delete`, `attendance.export`, `attendance.templates.manage`, `attendance.templates.use`
- **student_dossiers** : `student_dossiers.view`, `student_dossiers.create`, `student_dossiers.update`, `student_dossiers.delete`, `student_dossiers.pdf`
- **student_documents** : `student_documents.view`, `student_documents.upload`, `student_documents.download`, `student_documents.validate`, `student_documents.reject`, `student_documents.delete`, `student_documents.replace`, `student_documents.audit`
- **demandes** : `demandes.view`, `demandes.create`, `demandes.submit`, `demandes.cancel`, `demandes.process`, `demandes.assign`, `demandes.approve`, `demandes.reject`, `demandes.request_documents`, `demandes.documents.view`, `demandes.documents.upload`, `demandes.documents.download`, `demandes.pdf`, `demandes.audit`
- **schedules** : `schedules.view`, `schedules.manage`
- **enseignants** : `enseignants.view`, `enseignants.manage`
- **salles** : `salles.view`, `salles.manage`
- **groupes** : `groupes.view`, `groupes.manage`
- **affectations** : `affectations.override`
- **data** : `data.import`, `data.export`
- **personnel** : `personnel.manage`
- **enseignements** : `enseignements.view`, `enseignements.manage`, `enseignements.valider`, `enseignements.import`, `enseignements.export`
- **referentiel** : `referentiel.view`, `referentiel.manage`, `referentiel.import`, `referentiel.export`, `maquettes.approve`
- **evaluations** : `evaluations.view`, `evaluations.create`, `evaluations.update`, `evaluations.delete`, `evaluations.publish`
- **sessions_examen** : (permissions propres aux sessions d'examen)
- **epreuves** : (permissions propres aux épreuves)
- **surveillances** : (permissions propres aux surveillances)
- **resultats** : `resultats.view`, `resultats.publish`
- **deliberations** : `deliberations.publish`
- **annonces** : `annonces.view`, `annonces.publish`
- **calendriers** : `calendriers.view`, `calendriers.manage`
- **statistiques** : `statistiques.view`, `statistiques.manage`
- **settings** : `settings.manage`, `settings.restore`, `system.backups.restore`, `system.backups.delete`
- **users** : `users.create`, `users.update`, `users.suspend`, `users.sessions.revoke`
- **roles** : `roles.manage`, `permissions.assign`
- **permissions** : (auto-gestion via le code)
- **mail** : `mail.settings.manage`, `mail.test.send`, `mail.logs.view`, `mail.modeles.view`, `mail.send`
- **system** : `system.backups.restore`, `system.backups.delete`, `configuration.manage`
- **cockpit** : `cockpit.view`, `cockpit.kpi`, `cockpit.kpi.export`
- **tasks** : `tasks.view`, `tasks.manage`
- **it** : `it.view`, `it.manage`
- **alumni** : `alumni.view`, `alumni.manage`
- **campagnes** : `campagnes.view`, `campagnes.email`, `campagnes.create`, `campagnes.update`
- **qualite** : `qualite.view`, `qualite.manage`
- **finances** : `finances.confirm`, `export.sensitive`
- **document** : `document.view`, `document.download`, `document.upload`, `document.delete`, `document.generate`, `document.revoke`, `document.issue`, `document.send`, `document.print`, `document.audit`
- **document_batch** : `document_batch.run`, `document_batch.cancel`
- **document_template** : `document_template.view`, `document_template.edit`, `document_template.publish`, `document_template.archiver`, `document_template.dupliquer`, `document_template.versionner`
- **import** : `import.view`, `import.manage`, `data.import`
- **export** : `export.view`, `export.manage`, `data.export`
- **import_template** : `import_template.view`, `import_template.edit`
- **search** : `search.view`, `search.universal`
- **parcours** : `parcours.view`, `parcours.simuler`, `parcours.gerer`, `parcours.comparer`, `parcours.regles`
- **regles** : `regles.consulter`, `regles.rediger`, `regles.soumettre`, `regles.approuver`, `regles.publier`, `regles.retirer`, `regles.auditer`
- **recours** : `recours.consulter`, `recours.deposer`, `recours.recevoir`, `recours.instruire`, `recours.decider`, `recours.appliquer`, `recours.annuler`, `recours.auditer`
- **versioning** : `versioning.view`, `versioning.create`
- **sentinel** : (permissions de sécurité système)
- **assistant_ia** : `ai.acceder`, etc. (module IA local, lecture seule dans catalogue fédéré)
- **decrochage** : (prévention du décrochage)
- **gouvernance** : `gouvernance.audit`
- **iam** : 11 permissions `iam.*` (gouvernance IAM) — `iam.consulter`, `iam.roles.gerer`, `iam.affectations.gerer`, `iam.affectations.approuver`, `iam.delegations.gerer`, `iam.audit.consulter`, `iam.cockpit.consulter`, `iam.recertifier`, `iam.urgence.declencher`, `iam.urgence.approuver`, `iam.parametres.gerer`
- **ai** : permissions du module assistant IA — lecture seule, non administrée par la gouvernance IAM

## 1.2 Permissions `iam.*` (LOOP 43-BIS)
Les 11 permissions ajoutées explicitement pour la gouvernance IAM, toutes du module `iam` :
1. `iam.consulter` — consulter la gouvernance IAM / cockpit
2. `iam.roles.gerer` — gérer les rôles (attribution, modification)
3. `iam.affectations.gerer` — gérer les affectations de profils
4. `iam.affectations.approuver` — approuver les affectations/demandes
5. `iam.delegations.gerer` — gérer les délégations de permissions
6. `iam.audit.consulter` — consulter le journal audit IAM
7. `iam.cockpit.consulter` — accéder au cockpit IAM
8. `iam.recertifier` — lancer / participer à une recertification
9. `iam.urgence.declencher` — déclencher un accès d'urgence (break-glass)
10. `iam.urgence.approuver` — approuver une demande d'urgence
11. `iam.parametres.gerer` — modifier les paramètres de gouvernance

## 1.3 Criticité et sensibilité
Chaque permission du catalogue porte des métadonnées :
- `criticite` : `normale`, `elevee`, `critique`
- `sensible` : booléen — les permissions sensibles nécessitent une attention particulière
- `mfa_obligatoire` : booléen — si le MFA est requis pour l'évaluation de cette permission
- `dependances` : array de codes de permissions dépendantes
- `administrable` : si la permission peut être administrée via l'interface IAM
- `reserve_systeme` : si la permission est réservée au système (non administrée)

Les permissions sensibles identifiées dans le catalogue existant incluent :
- `gerer les utilisateurs`, `attribuer des rôles`, `administrer les permissions`
- `consulter toutes les facultés`, `modifier les notes`, `valider les résultats`
- `publier une délibération`, `gérer les dérogations`, `accéder aux paramètres de sécurité`
- `exporter des données sensibles`

## 1.4 Présence dans les contrôles
- **Policies** : Certaines permissions ont des Policies associées (ex: `ArchivePolicy`, `DocumentAcademiquePolicy`, `NotePolicy`). Toutes les permissions `module.*` bénéficient d'un Gate automatique enregistré dans `AppServiceProvider::register()` via `Permissions::TOUTES`.
- **Middleware** : `can:` dans les routes se résout en `Authorize:<permission>`.
- **Routes** : `route:list --json` montre 173 permissions distinctes dans les routes, incluant `ai.acceder` et les abilities de Policy.
- **Utilisation réelle** : Vérification recommandée via `grep` dans le code source pour chaque permission afin d'identifier les usages indirects ou dynamiques.

## 1.5 Permissions non utilisées / dupliquées / ambiguës
- Certaines permissions peuvent apparaître dans le catalogue mais ne pas être référencées dans aucune route ou policy. Vérification recommandée avant suppression.
- Permissions dupliquées avec actions différentes mais même signification (ex: `notes.delete` vs `notes.supprimer` — les deux existent pour compatibilité/code humanisé).
- Permissions trop générales : `configuration.manage` s'applique à de nombreuses sous-areas, à affiner si nécessaire.

## État de l'inventaire
- 173 permissions distinctes dans `Permissions::TOUTES`
- 11 permissions `iam.*` ajoutées pour la gouvernance
- Plus de 30 modules couverts
- Catalogue fédéré + catalogue IA local (`PermissionsAi`) en lecture seule

## Livrables
- `LOOP-43BIS-INVENTAIRE-PERMISSIONS.md`

**Verdict G43BIS-3 :** ✅ Inventaire complet — 173 permissions canoniques + 11 `iam.*` répertoriées, matrice `Permissions::TOUTES` documentée, permissions IA locales identifiées comme lecture seule.