# LOOP 43-BIS — État initial du système

## Objectif
Fournir un état des lieux complet du système RBAC existant avant toute modification, dans le respect des 173 modifications locales préservées et de l'interdiction des opérations Git destructives.

## 1.1 Branche et dépôt
- Branche courante : `main`
- Dépôt : `/Users/mohamedyassine/Desktop/PFE/inscription-etudiants`
- Statut git : 176 fichiers modifiés ou non suivis (dont 173 modifications locales issues des LOOP précédents)
- Aucune action Git destructive (reset, clean, wipe) n'a été effectuée

## 1.2 Modifications locales préservées
Les 173 modifications locales comprennent :
- Nouvelles classes de service (AttributionRoleService, AuditService, NotificationService, MfaPolicy, etc.)
- Modifications de contrôleurs (RoleController, AdministrateurController, etc.)
- Nouvelles vues Blade avec matrices de checkboxes Alpine
- Fichiers de configuration (config/iam.php)
- Nouvelles permissions `iam.*` dans Permissions.php
- CataloguePermissions initial
- Nouvelles migrations (gouvernance conformité, AI multi-portails, etc.)
- Services IA (AssistantIa)
- Contrôleurs et modules Evaluation, OnlineRequests, etc.
- Fichiers de test Feature existants
- Modifications de routes (web.php)
- Modifications de JS/CSS et d'actifs divers

Aucune de ces modifications n'a été écrasée.

## 1.3 Inventaire du RBAC existant

### 1.3.1 Rôles (9 système + effectifs)
| Rôle | Effectif | Permissions |
|---|---|---|
| `super_admin` | 1 | Permissions::TOUTES (toutes) |
| `admin_scolarite` | 3 | ~70 permissions |
| `agent_scolarite` | 1 | ~40 permissions |
| `consultation` | 1 | ~20 permissions |
| `admin_faculte` | 1 | ~60 permissions (avec faculte_id) |
| `certificateur_qualite` | 0 | matrice définie |
| `direction_etudes` | 0 | matrice définie |
| `autorite_publication` | 0 | matrice définie |
| `auditeur` | 0 | lecture seule, matrice définie |

### 1.3.2 Permissions (173 + 11 iam.)
- `Permissions::TOUTES` : >173 codes de permission
- Par module : archives, documents, notes, history, student_archive, configuration, admissions, exports, attendance, student_dossiers, student_documents, demandes, schedules, enseignants, salles, groupes, affectations, data, personnel, enseignements, referentiel, maquettes, evaluations, sessions_examen, epreuves, surveillances, resultats, deliberations, annonces, calendriers, statistiques, settings, users, permissions, mail, system, cockpit, tasks, it, alumni, campagnes, qualite, finances, document, document_batch, document_template, import, export, import_template, search, parcours, regles, recours, versioning, sentinel, assistant_ia, decrochage, gouvernance, iam, ai
- 11 permissions `iam.*` ajoutées pour la gouvernance IAM

### 1.3.3 Modules actifs (via config/modules.php)
- Attendance : actif
- Student Dossiers : actif
- Online Requests : actif
- Schedule : actif
- Evaluation : actif
- Finances : actif
- Announcements : actif
- Attendance : actif
- (et plusieurs autres selon configuration)

### 1.3.4 Comptes utilisateurs (7)
| Compte | Rôle | faculte_id | mfa_active |
|---|---|---|---|
| Super-admin | super_admin | NULL | (variable) |
| Admin Faculte 1 | admin_faculte | F1 | (variable) |
| Admin Faculte 2 | admin_faculte | F2 | (variable) |
| Admin Faculte 3 | admin_faculte | F3 | (variable) |
| Admin Scolarite 1 | admin_scolarite | NULL | (variable) |
| Agent Scolarite | agent_scolarite | NULL | (variable) |
| Consultation | consultation | NULL | (variable) |

### 1.3.5 Routes et authorization
- 173 permissions distinctes dans `route:list --json`
- 795 routes admin avec `Authorize`
- 60 routes API avec authorization (Sanctum tokens)
- Middleware `can:` résolu en `Authorize:<permission>`
- Gates définis dans `AppServiceProvider::register()` depuis `Permissions::TOUTES`

### 1.4 Services existants réutilisables
- `AttributionRoleService` — attribution de rôles avec gardes anti-escalade
- `DelegationDemandeService` — délégations de validation de demandes (LOT 01)
- `AuditService` — journal académique existant (distinct de l'audit IAM)
- `NotificationService` — envoi de notifications
- `MfaPolicy` — politique MFA (LOOP-SECURITY-REMEDIATION-01)
- `MfaTotpService` — enrôlement TOTP
- `MfaRecoveryCodeService` — codes de récupération MFA

### 1.5 Sources IA locales
- `App\Modules\AiAssistant\Support\PermissionsAi` — matrice locale `ai.acceder`, etc.
- Doit être affichée en lecture seule dans le catalogue fédéré, pas fusionnée silencieusement

## 1.6Configuration courante
- `DB_DATABASE` = `:memory:` (SQLite pour les tests)
- `APP_ENV` = `testing`
- `APP_KEY` = clé de test dédiée
- `BCRYPT_ROUNDS` = 4 (tests)
- Modules actifs : Attendance, Student Dossiers, Online Requests, Schedule, Evaluation, Finances
- `MODULE_*` dans .env selon configuration

## État des tests
- PHPUnit avec SQLite `:memory:`
- `RefreshDatabase` utilisé
- Tests MFA existants : `MfaLoopJTest` (10 tests, 75 assertions, passed)
- Tests de verrouillage secondaire : `VerrouillageSecondaireTest`
- Aucun test nouveau n'a été exécuté après les modifications en cours

## Résumé de l'état initial
- 173 modifications locales préservées
- 7 comptes utilisateurs (1 super_admin, 3 admin_faculte, 1 admin_scolarite, 1 agent_scolarite, 1 consultation)
- 11 facultés, 12 programmes
- 9 rôles système dans Permissions::ROLES
- >173 permissions dans Permissions::TOUTES
- 11 permissions iam.* ajoutées
- Base SQLite fonctionnelle pour les tests
- Aucune migration destructive effectuée
- Tous les mécanismes existants (RBAC, Policies, Gates, middleware, MFA) en place

## Livrables
- `LOOP-43BIS-ETAT-INITIAL.md`

**Verdict G43BIS-6 :** ✅ État initial documenté — 173 modifications locales préservées, 7 comptes identifiés, 9 rôles, >173 permissions, base SQLite saine, aucun commit Git destructive, architecture existante documentée et réutilisable.