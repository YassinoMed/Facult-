# Audit Phases 25–32 — Industrialisation finale

Date : 2026-09-30. Méthode : 2 subagents d'exploration + vérifications directes.
Règle : EXTEND → REUSE → REFACTOR → CREATE. Aucun doublon toléré.

## 1. Architecture existante (réutilisée)

| Domaine | Composant | Fichier |
|---|---|---|
| Layout | `layouts/app.blade.php` (drawer mobile, navbar, cloche, toasts, palette) | `resources/views/layouts/` |
| Navigation | `_navigation.blade.php` (domaines, ModuleRegistry, permission-gated) | idem |
| Composants | 17 composants DS (btn, modal, tabs, palette, timeline, task-list…) | `resources/views/components/` |
| Scoping | `faculteRestreinteId()` + `peutAccederFaculte()` (~60 usages) | `app/Models/User.php:128-143` |
| Auth | guard `web` session chiffrée, Sanctum 90j + scopes, throttles | `config/auth.php`, `config/sanctum.php` |
| Headers | CSP/COOP/CORP/HSTS conditionnel, X-Frame, nosniff | `SecurityHeadersMiddleware.php` |
| Scheduler | 10 jobs planifiés | `routes/console.php` |
| Logs | clés à points + `X-Request-ID` (API), `integration_logs` immuable | `config/logging.php` |
| Maintenance | 6 sondes + purge cache, super_admin | `MaintenanceController.php` |
| Qualité | `anomalies_qualite` + `qualite:auditer`, 3 détecteurs | `AgregateurQualite.php` |
| API | 21+ routes v1, enveloppe `{message,code,request_id}`, OpenAPI + test de dérive | `routes/api.php`, `docs/openapi.yaml` |
| Supervision | sondes, `supervision:controler` (cooldown), ready 200/503 | `SupervisionService.php` |

## 2. Composants réutilisables (par phase)

- P25 : layout responsive, drawer, toasts, palette Ctrl+K, `x-tabs`/`x-modal`.
- P26 : `Faculte`, `Departement`, scoping mono-faculté, ciblage annonces/calendrier.
- P27 : broker `passwords.users` + table `password_reset_tokens` (orphelins), table `sessions`, Sanctum + commandes tokens.
- P28 : `SecurityHeadersMiddleware`, `Recherche::contient`, policies, `tests/Feature/Security/` (44 tests).
- P29 : `composer.json` scripts, `phpunit.xml` (sqlite/memory), Pint (défaut, pas de `pint.json`), Vite build.
- P30 : `failed_jobs`, `MaintenanceController`, `GUIDE-DEPLOY §7` (worker/supervisor/cron).
- P31 : `SupervisionService`, `MaintenanceController`, `QualiteController`, `TaskService`, `AnomalieQualite`.
- P32 : suites PHPUnit par domaine, `route:list`, OpenAPI drift test.

## 3. Doublons détectés

| Fonctionnalité | Ancien | Nouveau | Décision |
|---|---|---|---|
| Icônes PWA | aucun | manifest + PNG générés GD | CREATE (assets, pas de code métier) |
| Campus/bâtiment | aucun champ | `rooms.campus/batiment/etage` + `faculte_id` | EXTEND table module (additif, nullable) |
| Reset mot de passe | broker orphelin | routes + vues + notif | CREATE branché sur broker existant |
| Sessions actives | aucune UI | page listage/révocation | CREATE (table `sessions` existante) |
| MFA/TOTP | absent | — | BACKLOG (dépendance externe interdite sans approbation) |
| CI | absent | `.github/workflows/ci.yml` + `pint.json` | CREATE (fichiers seuls) |
| Backup | 0 code | `sauvegarde:*` + doc DR | CREATE (mysqldump/tar + manifest + verify) |
| /admin/system | Maintenance + Qualité + Cockpit séparés | cockpit agrégé réutilisant les 3 | CREATE façade (0 table) |

## 4. Risques

- R1 : `Room` sans rattachement → filtres campus vides tant que non renseignés (nullable, pas de backfill inventé).
- R2 : backup mysqldump absent de certains envs → échec explicite + doc, jamais de faux succès.
- R3 : SW mal scopé casserait CSRF/uploads → cache restreint aux GET same-origin non-API, versionné.
- R4 : reset password sans MAIL configuré (log par défaut) → testé avec `Notification::fake`, doc d'activation.
- R5 : révocation sessions étudiantes (`etudiant_auth_id` hors guard) → scope admin uniquement, documenté.

## 5. Recommandations

1. Ne pas toucher au scoping mono-faculté (~60 call-sites) ; multi-rattachement = backlog.
2. Ne pas ajouter de dépendances (TOTP, backup spatie, workbox) sans approbation.
3. Toute route ajoutée : `auth`/`admin` ou `super_admin` + throttle + test IDOR.
4. Docs : `docs/security/security-architecture.md`, `docs/operations/disaster-recovery.md`, `docs/final/PROJECT-READINESS-REPORT.md` (Phase 32).

## 6. Fichiers concernés (créés/modifiés)

Créés : `public/manifest.webmanifest`, `public/icons/*`, `public/sw.js`, vues offline, `Rooms` additif, `PasswordReset*` (routes+vues), `SessionsController`+vue, `.github/workflows/ci.yml`, `pint.json`, `Sauvegarde*` commands, `SystemController`+vue, docs (audit, security, operations, final).
Modifiés : `layouts/app.blade.php` (meta+PWA), `SalleController`+form+`RoomDefinition`, `routes/web.php`, `routes/console.php`, `.env.example` (SANCTUM explicite), `composer.json` (script lint).

## 7. Migrations nécessaires

- `rooms` : `campus`, `batiment`, `etage`, `faculte_id` (nullables + index).
- Aucune pour PWA/sessions/reset/backup (tables `sessions`, `password_reset_tokens` existantes).

## 8. Impact modules existants

- Schedule : `Room` enrichie (fillable + validation + explorer affichage, sans changer les conflits).
- DataExchange : `RoomDefinition` + colonnes (export élargi, pas de rupture).
- Auth : reset branché sur broker `users` ; sessions lues depuis table `sessions`.
- Aucun changement RBAC/scopes/API existants.
