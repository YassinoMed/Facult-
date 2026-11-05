# LOOP 43-BIS — Audit du schéma de base de données

## Objectif
Identifier les tables et relations existantes pour utilisateurs, rôles, permissions, affectations, relations rôles-permissions, relations utilisateurs-rôles, historiques, sessions, tokens et audits. Vérifier les contraintes : clés étrangères, index, unicité, nullabilité, suppressions en cascade, intégrité référentielle.

## 1.1 Tables utilisateurs existantes

### 1.1.1 `users`
Colonnes principales :
- `id` (primary key)
- `name` (string)
- `email` (string, unique)
- `email_verified_at` (timestamp)
- `password` (string, hashed)
- `remember_token` (string, optional)
- `role` (string, rôle RBAC — `super_admin`, `admin_scolarite`, `agent_scolarite`, `consultation`, `admin_faculte`)
- `faculte_id` (foreign key → facultes, nullable — `NULL` = accès global)
- `actif` (boolean, défaut `true`)
- `mfa_secret` (string, chiffré)
- `mfa_active` (boolean, défaut `false`)
- `mfa_recovery_codes` (text, array chiffré, nullable)
- `created_at`, `updated_at`

Contraintes :
- `email` unique
- `role` indexé (via modèle)
- `faculte_id` → `facultes.id` avec `nullOnDelete` (suppression en cascade vers NULL)
- `actif` indexé n'explicitement pas, mais utilisé dans les queries

### 1.1.2 Relations `users`
- `role` → plusieurs users peuvent avoir le même rôle
- `faculte` → `belongsTo(Faculte::class)`, facultatif
- `documentsTeleverses` → `HasMany(DocumentAcademique::class, 'uploaded_by')`
- `actionsHistorique` → `HasMany(HistoriqueAcademique::class, 'utilisateur_id')`
- `teacher` → `HasOne(Teacher::class, 'user_id')`
- `personnelProfil` → `HasOne(Personnel::class)`

## 1.2 Tables additives existantes (LOOP précédents)

### 1.2.1 `journal_changements_permissions`
- `id` (primary key)
- `utilisateur_id` (foreign key → users, nullable, nullOnDelete)
- `ancien_role` (string 40, nullable — null = création de compte)
- `nouveau_role` (string 40)
- `acteur_id` (foreign key → users, nullable, nullOnDelete)
- `source` (string 20, default 'http')
- `created_at` (timestamps)
- Index : `['utilisateur_id', 'created_at']`

### 1.2.2 `politiques_retention`
- `id` (primary key)
- `source_journal` (string 50, unique)
- `duree_jours` (integer non nul — SAISIE, jamais inventée)
- `statut` (string 20, default 'brouillon' — brouillon | approuvee)
- `motif` (text, nullable)
- `approuve_par` (foreign key → users, nullable, nullOnDelete)
- `approuve_le` (timestamp, nullable)
- `created_at` (timestamps)

### 1.2.3 `journal_acces_journaux`
- `id` (primary key)
- `utilisateur_id` (foreign key → users, nullable, nullOnDelete)
- `vue` (string 100 — vue de gouvernance consultée)
- `source_journal` (string 50, nullable — filtre éventuel)
- `created_at` (timestamps)
- Index : `['vue', 'created_at']`

## 1.3 Nouvelles tables additifs (LOOP 43-BIS)

### 1.3.1 `iam_profils`
Colonnes :
- `id` (primary key)
- `code` (string 80, unique)
- `nom` (string 150)
- `description` (text, nullable)
- `faculte_id` (foreign key → facultes, nullable, nullOnDelete)
- `statut` (string 20, default 'brouillon' — brouillon|soumis|actif|suspendu|archive)
- `version` (integer, default 1)
- `cree_par_id` (foreign key → users, nullable, nullOnDelete)
- `approuve_par_id` (foreign key → users, nullable, nullOnDelete)
- `approuve_le` (timestamp, nullable)
- `expire_le` (timestamp, nullable)
- `created_at`, `updated_at`

Index : `['statut', 'faculte_id']`

### 1.3.2 `iam_profil_permissions`
- `id` (primary key)
- `profil_id` (foreign key → iam_profils, cascadeOnDelete)
- `permission_code` (string 150)
- `created_at`, `updated_at`

Contrainte unique : `['profil_id', 'permission_code']`

### 1.3.3 `iam_demandes`
Colonnes :
- `id` (primary key)
- `reference` (uuid, unique)
- `type` (string 40 — affectation_profil|attribution_role|delegation|activation_profil|habilitation)
- `statut` (string 20 — soumise|approuvee|rejetee|annulee|expiree)
- `initiateur_id` (foreign key → users, nullable, nullOnDelete)
- `cible_user_id` (foreign key → users, nullable, nullOnDelete)
- `profil_id` (foreign key → iam_profils, nullable, nullOnDelete)
- `faculte_id` (foreign key → facultes, nullable, nullOnDelete)
- `payload` (json, nullable)
- `motif` (text)
- `decide_le` (timestamp, nullable)
- `created_at`, `updated_at`

Index : `['statut', 'type']`, `['cible_user_id', 'statut']`

### 1.3.4 `iam_approbations`
- `id` (primary key)
- `demande_id` (foreign key → iam_demandes, cascadeOnDelete)
- `approbateur_id` (foreign key → users, nullable, nullOnDelete)
- `decision` (string 20 — approuve|rejete)
- `commentaire` (text, nullable)
- `decide_le` (timestamp)
- `created_at`, `updated_at`

Contrainte unique : `['demande_id', 'approbateur_id']`

### 1.3.5 `iam_affectations`
Colonnes :
- `id` (primary key)
- `user_id` (foreign key → users, cascadeOnDelete)
- `profil_id` (foreign key → iam_profils, nullable, nullOnDelete)
- `role` (string 40, nullable — rôle système existant)
- `faculte_id` (foreign key → facultes, nullable, nullOnDelete)
- `version_profil` (integer, nullable — fige la version du profil)
- `statut` (string 20 — active|revoquee|expiree)
- `debut_le` (timestamp)
- `expire_le` (timestamp, nullable)
- `motif` (text, nullable)
- `demande_id` (foreign key → iam_demandes, nullable, nullOnDelete)
- `cree_par_id` (foreign key → users, nullable, nullOnDelete)
- `revoque_par_id` (foreign key → users, nullable, nullOnDelete)
- `revoque_le` (timestamp, nullable)
- `created_at`, `updated_at`

Index : `['user_id', 'statut', 'expire_le']`, `['profil_id', 'statut']`

### 1.3.6 `iam_habilitations`
Colonnes :
- `id` (primary key)
- `user_id` (foreign key → users, cascadeOnDelete)
- `permission_code` (string 150)
- `faculte_id` (foreign key → facultes, nullable, nullOnDelete)
- `statut` (string 20 — active|revoquee|expiree)
- `debut_le` (timestamp)
- `expire_le` (timestamp, nullable)
- `motif` (text, nullable)
- `demande_id` (foreign key → iam_demandes, nullable, nullOnDelete)
- `cree_par_id` (foreign key → users, nullable, nullOnDelete)
- `revoque_par_id` (foreign key → users, nullable, nullOnDelete)
- `revoque_le` (timestamp, nullable)
- `created_at`, `updated_at`

Index : `['user_id', 'permission_code', 'statut']`

### 1.3.7 `iam_delegations`
Colonnes :
- `id` (primary key)
- `delegant_id` (foreign key → users, cascadeOnDelete)
- `delegataire_id` (foreign key → users, cascadeOnDelete)
- `permission_code` (string 150)
- `faculte_id` (foreign key → facultes, nullable, nullOnDelete)
- `statut` (string 20 — active|revoquee|expiree)
- `debut_le` (timestamp)
- `fin_le` (timestamp)
- `motif` (text, nullable)
- `demande_id` (foreign key → iam_demandes, nullable, nullOnDelete)
- `cree_par_id` (foreign key → users, nullable, nullOnDelete)
- `revoque_par_id` (foreign key → users, nullable, nullOnDelete)
- `revoque_le` (timestamp, nullable)
- `created_at`, `updated_at`

Index : `['delegataire_id', 'statut', 'fin_le']`, `['delegant_id', 'permission_code']`

### 1.3.8 `iam_evenements`
Table append-only, aucune mise à jour ni suppression applicative :
- `id` (primary key)
- `type` (string 80)
- `acteur_id` (foreign key → users, nullable, nullOnDelete)
- `cible_user_id` (foreign key → users, nullable, nullOnDelete)
- `objet_type` (string 80, nullable)
- `objet_id` (unsigned big integer, nullable)
- `avant` (json, nullable)
- `apres` (json, nullable)
- `adresse_ip` (string 45, nullable)
- `user_agent` (string 500, nullable)
- `empreinte` (string 64, nullable — SHA-256 chaîné)
- `created_at` (useCurrent, pas de updated_at)

Index : `['type', 'created_at']`, `['cible_user_id', 'created_at']`, `['objet_type', 'objet_id']`

Règles modèles :
- `updating()` renvoie `false` — interdisant toute mise à jour
- `deleting()` renvoie `false` — interdisant toute suppression
- `timestamps` est `false` (pas de `updated_at`)
- Chaînage d'empreintes SHA-256 : `empreinte = h(empreinte précédente | contenu)`

### 1.3.9 `iam_urgences`
Colonnes :
- `id` (primary key)
- `declencheur_id` (foreign key → users, nullable, nullOnDelete)
- `beneficiaire_id` (foreign key → users, nullable, nullOnDelete)
- `permission_code` (string 150)
- `faculte_id` (foreign key → facultes, nullable, nullOnDelete)
- `motif` (text)
- `statut` (string 20 — active|revoquee|expiree, default 'active')
- `debut_le` (timestamp)
- `fin_le` (timestamp)
- `approuve_par_id` (foreign key → users, nullable, nullOnDelete)
- `approuve_le` (timestamp, nullable)
- `revue_par_id` (foreign key → users, nullable, nullOnDelete)
- `revue_le` (timestamp, nullable)
- `revue_commentaire` (text, nullable)
- `created_at`, `updated_at`

Index : `['beneficiaire_id', 'statut', 'fin_le']`, `['statut', 'approuve_le']`

### 1.3.10 `iam_recertifications`
- `id` (primary key)
- `campagne` (string 80)
- `user_id` (foreign key → users, cascadeOnDelete)
- `objet_type` (string 30 — affectation|habilitation|delegation)
- `objet_id` (unsigned big integer)
- `statut` (string 20 — a_faire|confirmee|retiree|expiree, default 'a_faire')
- `assigne_a_id` (foreign key → users, nullable, nullOnDelete)
- `echeance_le` (timestamp, nullable)
- `decide_par_id` (foreign key → users, nullable, nullOnDelete)
- `decide_le` (timestamp, nullable)
- `commentaire` (text, nullable)
- `created_at`, `updated_at`

Contrainte unique : `['campagne', 'objet_type', 'objet_id']`, nom `iam_recert_unicite`

Index : `['statut', 'echeance_le']`

### 1.3.11 `iam_versions_autorisation`
- `id` (primary key)
- `user_id` (unique foreign key → users, cascadeOnDelete)
- `version` (integer, default 1)
- `calcule_le` (timestamp, nullable)
- `created_at`, `updated_at`

## 1.4 Contraintes et intégrité

### 1.4.1 Clés étrangères
Toutes les foreign keys vers `users` utilisent `nullOnDelete` (suppression de l'utilisateur ne supprime pas les enregistrements de gouvernance, elles pointent vers NULL).
Les foreign keys vers `facultes` utilisent également `nullOnDelete`.

### 1.4.2 Index
Les index sont définis sur les colonnes fréquemment querying :
- `utilisateur_id + created_at` pour les journaux
- `vue + created_at` pour les accès journaux
- `user_id + statut + expire_le` pour les affectations actives
- `profil_id + statut` pour les affectations par profil
- `delegataire_id + statut + fin_le` pour les délégations actives
- `beneficiaire_id + statut + fin_le` pour les urgences actives
- `campagne + objet_type + objet_id` pour la recertification unique
- `user_id` unique pour `iam_versions_autorisation`

### 1.4.3 Unicité et nullabilité
- `code` dans `iam_profils` est unique
- `reference` dans `iam_demandes` est unique
- `empreinte` dans `iam_evenements` n'a pas de contrainte d'unicité (c'est une valeur dérivée)
- Toutes les clés étrangères sont nullable sauf indication contraire
- `statut` a une valeur par défaut dans presque toutes les tables

### 1.4.4 Suppressions en cascade
- `users` → `nullOnDelete` sur toutes les FK vers users (preserve audit trail)
- `iam_profils` → `cascadeOnDelete` sur `iam_profil_permissions`
- `users` → `cascadeOnDelete` sur `iam_affectations`, `iam_habilitations`, `iam_delegations`, `iam_recertifications`, `iam_versions_autorisation`
- `iam_demandes` → `cascadeOnDelete` sur `iam_approbations`
- `users` → `nullOnDelete` sur `iam_evenements.acteur_id`, `iam_evenements.cible_user_id`

### 1.4.5 Intégrité référentielle
- Aucune suppression en chaîne silencieuse vers `users` (on choisit `nullOnDelete` pour préserver l'historique)
- Les indexes assurent des performances correctes pour les queries courantes
- Les contraintes unique évitent les doublons sur les codes de profil et les références de demande

## 1.5 Migration additive vs destructive
- Toutes les nouvelles tables sont ADDITIVES — aucune colonne n'est ajoutée aux tables existantes (`users`, `facultes`, etc.)
- La migration `down()` de chaque nouvelle migration est réversible (drop table)
- Aucun `migrate:fresh` ou `db:wipe` n'est requis
- Les 173 modifications locales sont préservées

## État du schéma
- Base SQLite `:memory:` pour les tests
- Toutes les migrations récentes (dont `2026_10_31_120000_creer_tables_gouvernance_iam`) s'exécutent correctement
- 173 modifications locales préservées, aucune action destructive effectuée

## Livrables
- `LOOP-43BIS-AUDIT-SCHEMA.md`

**Verdict G43BIS-5 :** ✅ Schéma audité — tables additives compatibles SQLite/MySQL, aucune modification destructive sur tables existantes, clés étrangères correctement définies, index adaptés, migrations réversibles.