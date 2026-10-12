# Séparation des profils — Étudiant / Administrateur de faculté / Super Administrateur

> Refonte de l'authentification et de l'autorisation (§1-21) : chaque profil
> dispose d'espaces, permissions, routes, données et responsabilités
> strictement séparés. Un étudiant n'est jamais un administrateur ; un
> administrateur de faculté n'accède jamais aux données d'une autre faculté.

---

## 1. Architecture des profils

```text
                         APPLICATION
                              │
              ┌───────────────┴───────────────┐
              │                               │
        ESPACE ÉTUDIANT                 ESPACE ADMIN
              │                               │
   Table `etudiants` (session          Table `users` (guard web Laravel
   `etudiant_auth_id`, email/CIN      + rôle RBAC en base)
   + mot de passe bcrypt)                   │
              │                     ┌─────────┴─────────┐
       SON dossier uniquement       │                   │
       /etudiant/*          Scolarité centrale      Admin faculté
       (lecture seule             (global)           (SA faculté)
       sauf coordonnées              │                   │
       et mot de passe)        super_admin         admin_faculte
                              (global + gestion     (users.faculte_id)
                              des administrateurs)
```

**Séparation physique** :

| | Étudiant | Personnel back-office |
|---|---|---|
| Table | `etudiants` | `users` |
| Authentification | email **ou CIN** + mot de passe (bcrypt, `hashed`), session `etudiant_auth_id`, régénération d'ID de session | `/admin/connexion` (`Auth::attempt`, guard `web`), `throttle:5,1` |
| Middleware profil | `EnsureStudent` (alias `etudiant.auth` / `student`) | `EnsureBackOffice` (alias `admin`) + `EnsureFacultyAdmin` (`faculty.admin`) + `EnsureSuperAdmin` (`super_admin`) |
| Pré-remplissage CIN | ✅ (`/inscription/verifier-cin`, second facteur date de naissance) | ❌ — le CIN d'un admin n'est jamais recherché comme étudiant (tables distinctes, aucun CIN dans `users`) |

Un étudiant connecté qui tente `/admin` est un invité pour le guard `web`
→ redirection vers `/admin/connexion`. Un admin n'a jamais de session
`etudiant_auth_id` → l'Espace Étudiant lui est fermé.

## 2. Rôles RBAC (`users.role`, matrice `App\Support\Permissions`)

| Rôle | Périmètre | Permissions clés |
|---|---|---|
| `super_admin` | Toutes les facultés | Toutes (`Permissions::TOUTES`) + gestion des administrateurs (`/admin/administrateurs`) |
| `admin_scolarite` | Global | Archives/notes/documents complets, `configuration.manage`, `notes.unlock` |
| `agent_scolarite` | Global | Consultation + saisie notes/dossiers (sans suppression ni configuration) |
| `consultation` | Global, lecture seule | `archives.view`, `documents.view/download`, `notes.view`, `history.view` |
| **`admin_faculte`** | **SA faculté uniquement** (`users.faculte_id`) | archives.view/create/update, documents.*, notes.view/create/update, `history.view`, `exports.personal` — **sans** `configuration.manage`, `notes.unlock`, `archives.delete` (« ne pas tout donner à tous ») |

Colonnes ajoutées (`2026_09_25_100001_add_faculte_scope_to_users_table`) :
`faculte_id` (FK nullable vers `facultes` — NULL = global) et `actif`
(compte désactivé = refusé à la connexion ET par le middleware, même avec
des identifiants valides).

⚠️ Le rôle `admin_faculte` a été ajouté à l'enum de
`2026_09_24_100001_add_role_to_users_table.php` : une base déjà migrée
doit être reconstruite (`php artisan migrate:fresh --seed`) — workflow
standard du projet.

## 3. Middlewares (§7-8) — le rôle vient TOUJOURS de la base

| Middleware | Alias | Rôle |
|---|---|---|
| `EnsureStudent` | `etudiant.auth`, `student` | Session étudiante valide + dossier existant |
| `EnsureBackOffice` | `admin` | Guard `web` + rôle RBAC valide + compte `actif` + `admin_faculte` rattaché à une faculté |
| `EnsureFacultyAdmin` | `faculty.admin` | Strictement `admin_faculte` (dashboard `/admin/ma-faculte`) |
| `EnsureSuperAdmin` | `super_admin` | Strictement `super_admin` (gestion des administrateurs) |

Exemple interdit (et impossible) : `role=admin` envoyé par le navigateur.
Le rôle, le statut et la faculté sont lus en base à CHAQUE requête via
`User::role`, `User::estActif()`, `User::faculteRestreinteId()`.

## 4. Isolation par faculté (§6, anti-IDOR/BOLA)

Deux niveaux complémentaires, tous serveur :

1. **Policies** (`App\Policies\*`) : chaque action sur une ressource
   vérifie `permission RBAC` **ET** `User::peutAccederFaculte($faculteId)`.
   Concerne : `EtudiantPolicy`, `ArchivePolicy`, `NotePolicy`,
   `DocumentAcademiquePolicy`, `InscriptionAcademiquePolicy`. Un ID
   manipulé dans l'URL → **403** (web) ou **404** (API, sans fuite
   d'existence).
2. **Requêtes scopées** : `EtudiantController@index`, `ArchiveController@index`,
   `ExportController::options` et `DashboardFaculteController` bornent
   TOUTES les requêtes à `faculteRestreinteId()`. Le filtre client
   `?faculte_id=` est ignoré pour un admin_faculte (périmètre imposé).

Exports/imports (§16) : `ExportController` (web) et `ExportApiController`
(API) **écrasent** le filtre faculté demandé par le client avec la faculté
de rattachement (`bornerFaculte()`). Le web multicritères exige de toute
façon `configuration.manage` (absent d'admin_faculte) ; les tokens API
sont scopés en plus. Le Super Admin conserve les exports globaux.

API (§17) : authentification ≠ autorisation. Même avec un token Sanctum
valide, un admin_faculte n'obtient que SA faculté :
`StudentApiController` scope l'index et renvoie **404** sur toute
ressource hors périmètre (BOLA).

## 5. Routes (§7, §14)

- **Étudiant** (`/etudiant/*`, middleware `etudiant.auth`) :
  `profil`, `profil/modifier`, `archives`, `inscriptions`, `notes`,
  `documents`, `historique`, téléchargement de SES documents. Tout est
  résolu depuis la session — jamais depuis un ID client.
- **Back-office** (`/admin/*`, middleware `['auth', 'admin']`) : registre,
  archives, exports, intégrations — chacun garde ses `can:` permission.
- **Admin faculté** : `/admin/ma-faculte` (dashboard scopé, §14).
- **Super admin** : `/admin/administrateurs` (CRUD comptes, rattachement
  faculté, activation) — le super admin ne peut pas se désactiver lui-même.

L'étudiant ne peut jamais (et c'est testé) : modifier une note, une
décision, des crédits, supprimer son dossier, consulter le dossier d'un
autre, accéder au back-office.

## 6. Parcours CIN & anti-doublons (§11-13)

- Pré-remplissage : inchangé, réservé au parcours étudiant — cf.
  [`docs/PREFILL-CIN.md`](PREFILL-CIN.md). Endpoint public avec second
  facteur (date de naissance), DTO whitelist sans mot de passe/hash.
- Doublons : unicité SQL du CIN + `Rule::unique` + transaction à
  l'inscription finale. Le CIN d'un admin (table `users`, sans CIN)
  n'est jamais recherché comme étudiant.
- Architecture académique : une nouvelle année universitaire crée une
  **`InscriptionAcademique`** rattachée à l'**étudiant existant** (dossier
  académique permanent) — jamais un nouvel étudiant.

## 7. Audit (§18)

- Journal académique (`AuditService` → `historique_academique`) :
  consultations, dossiers, inscriptions, notes, documents (upload,
  download, suppression) — déjà en place, avec acteur, IP, UA, anciennes
  et nouvelles valeurs.
- Événements administratifs (`admin.created`, `admin.permission_changed`) :
  journal applicatif structuré (acteur, rôle, cible, faculté, IP).
- **Jamais** dans les journaux : mot de passe, hash, token, clé API.

## 8. Sécurité vérifiée (§19)

CSRF (groupe `web`), XSS (échappement Blade), SQL injection (Eloquent
paramétré), mass assignment (`$fillable`/`#[Fillable]` strict +
`validated()`), IDOR/BOLA (policies + scoping + 404 API), escalade de
privilèges (rôles en base, `StoreAdministrateurRequest`, middleware
super_admin), fixation de session (régénération aux deux connexions),
brute force (`throttle:5,1`), énumération (messages génériques),
inter-facultés (§4), exports/imports non autorisés (§16).

## 9. Tests (`tests/Feature/SeparationProfilsTest.php` — 17 tests)

- **Étudiant** : accès à son espace ✓ ; back-office ✗ (redirection) ;
  document d'un autre étudiant ✗ (404) ; création/modification de note ✗.
- **Admin faculté** : back-office + dashboard ✓ ; étudiants/notes/dossiers
  de SA faculté ✓ ; autre faculte ✗ (403, y compris filtres client
  ignorés) ; export API limité à SA faculté ✓ ; fiche hors périmètre en
  API ✗ (404) ; gestion des administrateurs ✗ (403) ; exports/imports
  web globaux ✗.
- **Super admin** : toutes les facultés ✓ ; création d'admin_faculte
  rattaché + immédiatement scopé ✓ ; auto-désactivation ✗.
- **Statut/rôle depuis la base** : compte désactivé refusé (connexion ET
  middleware) ; admin_faculte orphelin refusé ; mass assignment
  (`actif`, `id`, rôle hors matrice) impossible ; un admin_faculte ne
  peut pas créer de super_admin.
- **§11** : le CIN d'un administrateur n'est jamais un étudiant.

Comptes de démonstration (seeders, locaux/test uniquement) :
`admin.fst@universite.ac.ma` / `admin.fmp@universite.ac.ma` —
mot de passe `Faculte@2026`, chacun rattaché à sa faculté (FST / FMP).
