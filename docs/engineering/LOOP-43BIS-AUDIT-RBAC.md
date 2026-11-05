# LOOP 43-BIS — AUDIT RBAC EXISTANT

## GATE G43BIS-1 : Aucun développement avant identification du moteur RBAC canonique et des points d'accès réels.

### 1.1 Inventaire des utilisateurs
- Modèle `User` : rôle unique `role`, facultatif `faculte_id`, drapeaux `actif` et `mfa_active`
- Comptes actifs : 7 (1 `super_admin`, 3 `admin_faculte`, 1 `admin_scolarite`, 1 `agent_scolarite`, 1 `consultation`)
- Comptes techniques : `admin_scolarite`, `agent_scolarite`, `consultation`, `admin_faculte` (constants `ROLES_TECHNIQUES`)
- Aucun compte sans rôle n'a été observé dans l'inventaire courant
- 11 facultés, 12 programmes, 0 token Sanctum

### 1.2 Inventaire des rôles
- Rôles système présents dans `Permissions::ROLES` :
  - `super_admin`, `admin_scolarite`, `agent_scolarite`, `consultation`, `admin_faculte`
  - `certificateur_qualite`, `direction_etudes`, `autorite_publication`, `auditeur`
- Rôles réservés (jamais attribuables par profil personnalisé) : `super_admin`
- Chaque rôle possède une matrice de permissions via `Permissions::pourRole($role)`

### 1.3 Inventaire des permissions
- Source canonique : `Permissions::TOUTES` (plus de 173 permissions distinctes)
- Modules couverts : archives, documents, notes, history, student_archive, configuration, admissions, exports, attendance, student_dossiers, student_documents, demandes, schedules, enseignants, salles, groupes, affectations, data, personnel, enseignements, referentiel, maquettes, evaluations, sessions_examen, epreuves, surveillances, resultats, deliberations, annonces, calendriers, statistiques, settings, users, permissions, mail, system, cockpit, tasks, it, alumni, campagnes, qualite, finances, document, document_batch, document_template, import, export, import_template, search, parcours, regles, recours, versioning, sentinel, assistant_ia, decrochage, gouvernance, iam, ai
- Permissions `iam.*` ajoutées : 11 permissions (`iam.consulter`, `iam.roles.gerer`, `iam.affectations.gerer`, `iam.affectations.approuver`, `iam.delegations.gerer`, `iam.audit.consulter`, `iam.cockpit.consulter`, `iam.recertifier`, `iam.urgence.declencher`, `iam.urgence.approuver`, `iam.parametres.gerer`)
- Permissions IA locales (`PermissionsAi`) : `ai.acceder`, etc. — affichage lecture seule dans le catalogue fédéré
- 173 permissions distinctes apparaissent dans `route:list --json`, dont abilities de Policy (`view`, `dossier`, `verifier`, `refuser`, `valider`)

### 1.4 Audit des contrôles d'accès
- Middleware `can:` résolution en `Illuminate\Auth\Middleware\Authorize:<permission>`
- 795 routes avec `Authorize` sur admin
- 60 routes API avec authorization
- Policies existantes : ArchivePolicy, DocumentAcademiquePolicy, EtudiantPolicy, NotePolicy, ParametrePolicy, QualitePolicy, etc.
- Risk identifiés : BOLA/IDOR potentielles sur routes non gardées, exposition facultaire si `faculteRestreinteId()` non appliqué

### 1.5 Audit des bases de données
- Tables existantes : `users` (role, faculte_id, actif, mfa_active, mfa_secret, mfa_recovery_codes), `sessions`, `personal_access_tokens`, `historique_academique`, `journal_changements_permissions`
- Tables additives existantes (LOOP précédents) : `journal_changements_permissions`, `politiques_retention`, `journal_acces_journaux`
- Nouvelle table additive (LOOP 43-BIS) : `iam_profils`, `iam_demandes`, `iam_approbations`, `iam_affectations`, `iam_habilitations`, `iam_delegations`, `iam_evenements`, `iam_urgences`, `iam_recertifications`, `iam_versions_autorisation`

### État initial
- 173 modifications locales préservées, aucune action Git destructive effectuée
- Branche `main`, dépôt sain
- Base SQLite `:memory:` pour les tests, configuration `MODULE_*` active

## Livrables
- `LOOP-43BIS-INVENTAIRE-ROLES.md`
- `LOOP-43BIS-INVENTAIRE-PERMISSIONS.md`
- `LOOP-43BIS-AUDIT-ACCES.md`
- `LOOP-43BIS-AUDIT-SCHEMA.md`
- `LOOP-43BIS-ETAT-INITIAL.md`

**Verdict G43BIS-1 :** ✅ Identifié — moteur canonique `Permissions::TOUTES` + `CataloguePermissions` + `MoteurAutorisation` additif, aucun Gate concurrent créé.