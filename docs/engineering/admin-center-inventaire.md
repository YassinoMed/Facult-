# Centre d'administration — Inventaire de l'existant (Phase A, 13/10/2026)

> **Mode : AUDIT ONLY** — aucun code modifié. Toutes les affirmations sont vérifiées au
> code (fichier/ligne) ou exécutées ce jour. Légende : ✅ EXISTANT · ◐ PARTIEL · ❌ ABSENT · ⛔ BLOQUÉ (décision).

## 1. Paramétrage global — ✅ EXISTANT (mature, LOOP B→G)

| Aspect | Mécanisme | Preuve |
|---|---|---|
| Données | Table `parametres` (34 clés, 7 catégories : etablissement, academique, presences, sauvegardes, notifications, interface, sentinel) — **aucun secret** | tinker 13/10 |
| Registre | `SettingDefinition::catalogue()` : type, portée (global seule admise), défaut, règles, sensibilité, propriétaire | `app/Services/SettingDefinition.php` |
| Service | `ParametreService` : cache `rememberForever` + double invalidation (immédiate + `afterCommit` try/catch), `lockForUpdate`, contrôle optimiste `attendu`, versions append-only, `restaurer` = nouvelle version chaînée | `app/Services/ParametreService.php` (LOOP E vérifié 13/10) |
| Routes/perm. | `admin.parametres.index` (`can:settings.view`) · `update` (`can:settings.manage` + `throttle:20,1`) · `restaurer` (`can:settings.manage` + `throttle:10,1`) + double Gate contrôleur | `routes/web.php:920-925`, `ParametreController:27,49,98` |
| UI | Vue groupée par catégorie, recherche locale, historique par clé (diff ancien→nouveau), motif, `attendu` caché (en vol co-édition) | `resources/views/admin/parametres/index.blade.php` |
| Tests | `Settings{Contrat,Definitions,Interface,Integration,Versions,Historique}Loop*Test` + course MySQL gated : **22/22 verts (124 assertions) le 13/10** + course 1/1 scratch | exécuté 13/10 |

**Écarts vs cahier** : comparaison avant application = partielle (diff à l'historique + no-op détecté) ; confirmation des changements sensibles = motif câblé (en vol) ; « état de configuration » = défauts affichés, pas d'indicateur global.

## 2. Messagerie SMTP et e-mails — ◐ PARTIEL / ❌ sur l'envoi réel

| Aspect | État | Preuve |
|---|---|---|
| Modèles de messages | ✅ **`NotificationTemplate` versionnés + CRUD UI** (`admin/notifications/gabarits`, `can:configuration.manage`, `throttle:20,1`) | `routes/web.php:470-474`, migrations `2026_10_01_130058`, `2026_10_05_000002` |
| Envoi e-mail réel | ❌ **AUCUN** — `MAIL_MAILER=log`, zéro `Mail::` dans `app/` | `.env`, grep 13/10 |
| Architecture canal | ✅ **`CanalNotificationExterneInterface` + driver SIMULÉ** (aucun réseau local/test, PII bloquées, injection d'erreurs pour retry) + job `AcheminerNotificationExterneJob` sur file `notifications` | `app/Services/CanalNotificationExterneSimule.php` |
| Config SMTP admin | ❌ absente (env uniquement) — cohérent avec la règle « secrets jamais en tables/versions » | `config/mail.php` |
| Test d'envoi / journal / diagnostic mail | ❌ absents | grep vide |

**Décision requise (DH-AC-01)** : driver SMTP réel derrière l'interface existante ; secrets en env uniquement ; ne JAMAIS annoncer un envoi sans preuve (canal simule le garantit en dev).

## 3. Comptes et utilisateurs — ✅ EXISTANT (fort)

- **CRUD complet** `AdministrateurController` : index/create/store/update/destroy/**reinitialiser**/**statut** (activation/suspension) — groupe **`super_admin`** (`routes/web.php:491+`), garde dernier super-admin (AGENTS hardening), création validée `User::creerCompteBackOffice` (rôle via `definirRole`).
- **Réinitialisation** : self-service broker 60 min (throttle, message générique, rota `remember_token`) + reset par super_admin (`Str::password` serveur, flash unique, audit sans PII).
- **Sessions actives** : `SessionsController` index/destroy (`auth` + `throttle:10,1`) — **self-scoped** (`where user_id`, refus session courante) — `routes/web.php:363-366`.
- **Rattachement organisationnel** : `faculte_id` posé à la création, exigé par `EnsureBackOffice` pour `admin_faculte`.
- **Écarts** : invitations ❌ ; historique de sécurité **par compte** ❌ (l'audit central existe — §8) ; ⚠️ **AUTH-01 (P2)** : reset ne révoque pas les sessions existantes (cookie volé survit — fix triviale à autoriser, DH-10).
- **Tests** : `EspaceEtudiantTest`, `ArchiveAuthorizationTest`, suites Security 68/68 (13/10).

## 4. Rôles et autorisations — ✅ EXISTANT (immuable par design)

- **5 rôles** : `super_admin` (TOUTES), `admin_scolarite`, `agent_scolarite`, `consultation`, `admin_faculte` — `Permissions.php:11-17,281+`.
- **Catalogue immuable en classe finale**, `hasPermission`, `definirRole` validé (rôle hors matrice refusé), **aucun endpoint de modification de rôle** (escalade impossible), dernier super-admin protégé, scoping `faculteRestreinteId` + convention scope→404.
- Permissions pertinentes existantes : `settings.view/manage` (`Permissions.php:206-207`), `history.view` (audit), `configuration.manage` (gabarits), granularité sauvegardes view≠create≠verify≠restore≠delete.
- **Écarts** : matrice lecture seule UI ❌ ; **toutes les permissions candidates du cahier = À DÉCIDER** (voir matrice dédiée). Anti-escalade : vérifié (§3-4) ; auto-attribution : impossible par construction.

## 5. Sécurité et authentification — ✅ EXISTANT (fort) · MFA ⛔

- Mots de passe `Password::defaults()` (min 10, lettres+chiffres, `uncompromised()` hors tests), cast `hashed`, `$hidden` — 3 modèles.
- Sessions chiffrées/Secure/HttpOnly/Lax (120 min), `regenerate()` systématique, `AUTH_PASSWORD_TIMEOUT=3600`, tokens Sanctum CLI-only 90 j préfixés.
- Throttles : logins 5/min ×3 voies, mutations 10-30/min, reset.
- Temps constant étudiant/enseignant ; admin = P3 résiduel (AUTH-02).
- **MFA/2FA : ❌ ABSENT — décision DH-18 en attente** (⛔ pas de création sans approbation).
- Journalisation sans PII, `X-Request-ID` filtré, CSP (`unsafe-inline` résiduel AUTH-04).
- Tests : audit auth 12/10 (§A.1-A.10), Security 68/68.

## 6. Notifications — ✅ EXISTANT (riche)

- `NotificationService` (centre + cloche + batch + dédup par clé), `notification_preferences` (obligatoires préservés), `CommunicationDispatcher` (fail-soft), `CanalNotificationExterneSimule` (§2), `notifications:purger` (hebdo).
- Écarts : canal e-mail réel (dépend §2) ; suivi des tentatives = partiel (résilience testée via injection d'erreurs, pas d'UI de suivi).

## 7. Services et intégrations — ✅ EXISTANT

- Tokens API CLI-only (`integration:token-create` : rôles techniques validés, `super_admin` interdit aux comptes techniques, scopes whitelist, préfixe secret-scanning) ; webhooks HMAC + anti-replay 300 s ; DataExchange (scope `data:exchange`) ; modules `MODULE_*` env + middleware `module:` ; `/api/v1/ready` + `SupervisionService`.
- Écart : pas d'UI de gestion des tokens (**décision de design** — CLI volontaire, à documenter dans la spec, pas à « corriger » par défaut).

## 8. Historique, audit et restauration — ✅ EXISTANT (riche)

- `parametre_versions` **append-only** (convention testée), restauration = nouvelle version (motif explicite), jamais d'effacement d'historique.
- `AuditLog` (acteur, IP hachée, avant/après, zéro secret — testé), `HistoriqueAcademique`, `IntegrationLog` (hash du contenu), `ArchiveConsultee`.
- **Centre audit** : `admin/audit` + export CSV (`can:history.view`, `throttle:10,1`) — `routes/web.php:896-899`.
- Concurrence : optimiste `attendu` (settings), 4-eyes, `lockForUpdate` systématique ; rollback = callbacks jetés (LOOP E §E1.4).
- Écarts : audit **par compte** (filtre), journal e-mail (dépend §2).

## 9. Diagnostics et maintenance — ✅ EXISTANT (riche)

- `MaintenanceController` (purge cache `throttle:5,1`, scheduler + remédiation, purge logs — super_admin) ; `SystemeController` `/admin/system` (super_admin) ; **Sentinel** (11 contrôles, incidents ack/resolu/ignore, `sentinel:controler` 15 min, cockpit scopé) ; `QualiteController` (anomalies) ; supervision `X-Request-ID` + heartbeat 5 min ; commandes planifiées (purges, SLA, audit qualité, sauvegardes 1 h).
- Écart : vue « santé » consolidée unique ❌ (3 surfaces éclatées : maintenance/système/sentinel) ; diagnostics e-mail (dépend §2).

## Doublons identifiés (règle de non-duplication)

| Fonctionnalité envisagée | Mécanisme EXISTANT à réutiliser | Verdict |
|---|---|---|
| Modèles d'e-mails | `NotificationTemplate` (CRUD versionné) | **RÉUTILISER** — jamais de 2e système |
| Envoi/journal/relance e-mail | `CanalNotificationExterneInterface` + job + file `notifications` | **Brancher un driver réel sur l'interface** |
| Sessions actives/révocation | `SessionsController` (self-scoped) | **Étendre** (kill-switch AUTH-01), ne pas recréer |
| Historique/comparaison/restauration | `parametre_versions` + `restaurer` | **Étendre UX seulement** |
| Diagnostics | Maintenance + Système + Sentinel | **Navigation commune**, pas de 4e moteur |
| Matrice rôles/permissions | `Permissions.php` (source immuable) | **UI lecture seule générée du code** |
| Paramètres modules propriétaires (`sauvegarde.*`, `sentinel.*`) | Hors registre générique (LOOP D D-C-03) | **Maintenir hors registre** |

## Risques de sécurité transverses documentés (campagne 12-13/10)

P1 : **SEC-12 XSS stockée maquettes** (résoudre AVANT d'élargir la surface admin) · P2 : AUTH-01 (sessions/reset), SEC-10 V-1 (réinscription cross-faculté), SEC-11 V1/V2 (présence), uploads enseignant · P3 : résiduels `docs/SECURITY.md` §3. **Aucun ne doit être aggravé par ce chantier.**
