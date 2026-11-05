# Audit Complet de l'Existant IAM/RBAC — UniRegistre

**Projet :** UniRegistre  
**Composant :** Authentification, Rôles, Permissions, Périmètres et Gouvernance IAM  
**Date d'audit :** 31 Octobre 2026  
**Référence Loop :** LOOP GLOBAL IAM/RBAC  

---

## 1. Architecture Existante de l'Authentification

### 1.1 Configuration (`config/auth.php`)
- **Guards :**
  - `web` : session cookie standard Laravel, provider `users`.
  - `sanctum` : tokens d'accès API personnels (`PersonalAccessToken`).
- **Provider :**
  - Modèle Eloquent central : `App\Models\User`.
- **Mécanismes MFA :**
  - Intégré dans `User` (`mfa_secret`, `mfa_active`, `codes_secours_mfa`).
  - Géré par `Admin\MfaController` (TOTP Google Authenticator + codes de secours à usage unique).
- **Sessions & Sécurité :**
  - Verrouillage secondaire par code PIN (`verrouillage_pins`).
  - Purge des sessions actives (`admin.sessions.destroy`, `Admin\SessionsController`).

### 1.2 Résolution des Rôles et Permissions sur `User`
1. **Rôle direct :** `User->role` (chaîne de caractères représentant le rôle système principal).
2. **Matrice canonique :** `App\Support\Permissions::pourRole($user->role)` définit les permissions inhérentes au rôle système.
3. **Moteur d'autorisation unifié :** `App\Support\Iam\MoteurAutorisation` :
   - Méthode `hasPermission(string $permission)` sur `User` délègue à `(new MoteurAutorisation)->autorise($user, $permission)`.
   - Si le moteur n'est pas instanciable, repli transparent sur la matrice canonique `Permissions::pourRole($user->role)`.
   - Résolution cumulative : permissions de la matrice du rôle système + profils personnalisés actifs (`iam_profils` via `iam_affectations`) + habilitations unitaires (`iam_habilitations`) + délégations actives (`iam_delegations`) + accès d'urgence (`iam_urgences`).
4. **Calcul dynamique et cache :**
   - Évaluation dynamique par requête avec cache statique en mémoire (`MoteurAutorisation::$cache`).
   - Clé de cache : `$user->id . ':' . $user->role . ':' . $user->faculte_id . ':' . count($matrice)`.
   - Invalidation immédiate sur mutation via `MoteurAutorisation::marquerChangement($userId)` et `MoteurAutorisation::invalider($userId)`.
   - Compteur persistant de version : `iam_versions_autorisation` (permettant la détection d'obsolescence multi-processus/sessions).

---

## 2. Audit des Rôles Existants

### 2.1 Rôles Système (`App\Support\Permissions::ROLES`)

| Rôle | Portée par défaut | Description & Responsabilité | Nombre de permissions |
|---|---|---|---|
| `super_admin` | Globale | Administration générale plateforme, accès total non restreint | Toutes (~300+) |
| `admin_scolarite` | Globale | Gestion centrale des étudiants, maquettes, inscriptions, diplomation | ~180 |
| `agent_scolarite` | Globale | Saisie opérationnelle et gestion des dossiers étudiants | ~90 |
| `consultation` | Globale | Consultation transversale en lecture seule | ~35 |
| `admin_faculte` | Facultaire (`faculte_id`) | Administration et scolarité déléguée d'un établissement | ~140 |
| `certificateur_qualite` | Globale | Contrôle qualité et conformité des épreuves/examens | ~20 |
| `direction_etudes` | Globale | Décision pédagogique et validation des jurys | ~30 |
| `autorite_publication` | Globale | Scellement et publication officielle des délibérations | ~15 |
| `auditeur` | Globale | Inspection des journaux d'audit et conformité (lecture seule) | ~25 |

### 2.2 Rôles Personnalisés (`iam_profils`)
- **Stockage :** Table `iam_profils`.
- **Caractéristiques :**
  - `code` : identifiant technique unique (convention `perso_*`).
  - `nom` : libellé lisible.
  - `description` : justification et périmètre métier.
  - `faculte_id` : nullable (si renseigné, profil restreint à cette faculté).
  - `statut` : `brouillon`, `soumis`, `actif`, `suspendu`, `archive`.
  - `version` : incrémentée à chaque modification pour geler les droits des affectations antérieures.
- **Liaison permissions :** Table `iam_profil_permissions` (`profil_id`, `permission_code`).
- **Affectations :** Table `iam_affectations` (`user_id`, `profil_id`, `statut`, `debut_le`, `expire_le`, `version_profil`).

---

## 3. Audit du Catalogue des Permissions

### 3.1 Sources de Vérité
1. **Source canonique des codes :** `App\Support\Permissions::TOUTES`.
2. **Métadonnées et structuration :** `App\Support\Iam\CataloguePermissions`.
   - Organise l'ensemble des permissions en 58 domaines / modules fonctionnels (`archives`, `notes`, `evaluations`, `examens`, `finances`, `admissions`, `presences`, `groupes`, etc.).
   - Attributs par permission : `module`, `ressource`, `action`, `nom`, `description`, `critique`, `mfa_requis`, `administrable`.
3. **Protection des permissions réservées :**
   - Les permissions du module `iam.*` (`iam.admin`, `iam.roles`, `iam.audit`) et `system.*` sont protégées : elles ne peuvent être assignées arbitrairement à un profil personnalisé.

---

## 4. Audit des Contrôles d'Autorisation et Périmètre Facultaire

### 4.1 Mécanismes de Contrôle Côté Serveur
- **Gates :** Déclarés dans `AppServiceProvider` pour chaque permission issue de `Permissions::TOUTES` via `Gate::define($perm, fn($user) => $user->hasPermission($perm))`.
- **Policies :** Implémentées par domaine (`ParametrePolicy`, `DossierPolicy`, `EvaluationPolicy`, etc.).
- **Middlewares :** Middleware standard `can:permission_code` protégeant les routes web et api.

### 4.2 Périmètre Facultaire (`faculteRestreinteId()`)
- `User::faculteRestreinteId()` renvoie l'identifiant de la faculté si le rôle est `admin_faculte` ou un rôle restreint, et `null` pour les administrateurs globaux.
- Dans `MoteurAutorisation::autorise($user, $permission, $faculteId)` :
  - Si un contexte facultaire `$faculteId` est spécifié, la permission doit couvrir cette faculté (soit via portée globale `null`, soit via portée spécifique `$faculteId`).
  - Sans contexte explicite, une permission restreinte n'est reconnue que si le compte lui-même est restreint à cette faculté.

---

## 5. Audit des Workflows Sensibles et Révocations

### 5.1 Sécurité à Quatre Yeux
- Tables : `iam_demandes` et `iam_approbations`.
- Règles fondamentales :
  1. `initiateur_id !== approbateur_id` : aucun demandeur ne peut approuver sa propre requête.
  2. Quorum paramétrable dans `config/iam.php` (`approbateurs_requis`).
  3. Demande validée strictement conforme à son payload d'origine (non altérable).
  4. Non-rejeu garanti par statut terminal (`approuvee`, `rejetee`, `expiree`).

### 5.2 Traçabilité & Audit Append-Only
- Table : `iam_evenements`.
- Enregistrement par `App\Services\Iam\ServiceAuditIam` :
  - Type d'action, acteur, cible, objet modifié, état avant, état après.
  - Empreinte cryptographique SHA-256 chaînée à l'événement précédent.
  - Adresse IP et User-Agent capturés sans aucune donnée sensible en clair (jamais de mot de passe, de secret TOTP ou de token).

---

## 6. Synthèse des Écarts et Besoins pour le CRUD Rôles

1. **Contrôleur et Routes existants :**
   - Actuellement, `RoleController` n'expose que `index` (matrice statique des rôles système) et `attribuer` (attribution de rôle système via `AttributionRoleService`).
   - Il manque les contrôleurs et les routes complètes pour :
     - Consultation détaillée d'un rôle (système ou personnalisé).
     - Création d'un rôle personnalisé (`iam_profils`).
     - Modification des caractéristiques d'un rôle personnalisé.
     - Désactivation / suspension / archivage d'un rôle personnalisé.
     - Attribution et retrait de permissions avec interface hiérarchique par cases à cocher.
     - Gestion des affectations utilisateurs pour les rôles personnalisés.
     - Soumission et validation des demandes à quatre yeux pour les opérations critiques.
2. **Décision architecturale :**
   - Conserver l'unicité du moteur `MoteurAutorisation` et du catalogue `CataloguePermissions`.
   - Ne créer aucun système d'authentification ou d'autorisation parallèle.
   - S'appuyer sur les modèles Eloquent `ProfilIam`, `ProfilPermissionIam`, `AffectationIam`, `DemandeIam`, `ApprobationIam`, `EvenementIam`.

