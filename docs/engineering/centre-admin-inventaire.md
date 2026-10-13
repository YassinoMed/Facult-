# Centre d'administration — Inventaire de l'existant (Phase A, 12/10/2026)

Lecture seule. États : EXISTANTE / PARTIELLE / ABSENTE / BLOQUÉE.

## 1. Paramétrage global — EXISTANTE

`Parametre` + `ParametreVersion` (append-only) + `ParametreService`
(cache + `afterCommit`, verrou, optimiste) + `SettingDefinition` (9 clés :
6 institutionnelles + 3 sentinel) + `ParametreController`
(index/update/restaurer) + policy (`settings.view/manage`, super_admin seul)
+ 3 routes `admin.parametres.*` + sidebar Données + UI (recherche, types,
défauts, erreurs nommées, historique, dirty-state). Historique :
`parametre_versions` + log `parametre.modifie` (sans secret). Concurrence
prouvée MySQL (race + panne). Tests : 24 suites Settings. Risque : P3
résiduels (LOOP H). Décision : aucune B sans preuve.

## 2. Messagerie SMTP / e-mails — PARTIELLE vers ABSENTE

Config `mail.php` + `.env.example` (`MAIL_MAILER=log`, host local,
FROM `hello@example.com`) : **aucun SMTP réel configuré**. Zéro `Mailable`,
zéro `Mail::to/send` dans `app/` (grep vide). Canal externe =
`CanalNotificationExterneSimule` (zéro PII, pannes injectables, mémoire
d'inspection — pas d'envoi réseau). Notifications in-app via
`CommunicationDispatcher` + gabarits versionnés (`admin.notifications.*`,
`templates`). Journal d'envoi externe : inexistant (canal simulé).
Risque : tout « test d'envoi » serait mensonger sans SMTP (interdit §C).
Décisions : fournisseur SMTP, politique de rétention, secrets en coffre.

## 3. Comptes / utilisateurs — EXISTANTE

`AdministrateurController` (index/create/store/edit/update/destroy,
`reinitialiser`, `toggleStatut`, garde dernier super-admin) : 8 routes
`admin.administrateurs.*`. Sessions actives : `SessionsController`
(index/destroy, `auth` + throttle 10/1). Mot de passe : `Password::min(10)
->letters()->numbers()->uncompromised()` (hors tests),
`password_timeout` 1h, régénération session. Invitations : absentes
(comptes créés par admin). Historique sécurité : `historique_academique` +
audit. Risque : réinitialisation par gestionnaire (pas d'auto-servi —
assumé, traçé). Décision : invitation/RBAC étendu si besoin.

## 4. Rôles / autorisations — EXISTANTE (matrice en code)

`Permissions::TOUTES` + matrice (`super_admin` tout, `admin_scolarite`
large, `admin_faculte` scopé, `consultation` lecture). Policies par
ressource + scope→404. Permissions proposées inexistantes :
`settings.history.view`, `settings.restore`, `settings.diagnostics.view`,
`users.*`, `roles.*`, `mail.*`, `audit.view` — **non créées** (décision
requise). Protections : dernier super-admin, anti auto-escalade (rôle non
mass-assignable, `definirRole`), IDOR faculté (19 tests §11).

## 5. Sécurité / authentification — EXISTANTE

Login générique + throttle 5/min, temps constant côté étudiant, `regenerate`
+ `invalidate`, CSRF web, Sanctum scopes + 90j, headers (CSP documentée),
uploads (MIME réel + privé + UUID), webhooks HMAC+replay. **MFA absente**
(grep vide : pas de TOTP/WebAuthn). Rate limiting par route. Secrets :
env/coffre, jamais en paramètres (garde service). Risque : résidus §11
(CSP, timing admin, sonde `verifier`).

## 6. Notifications — EXISTANTE (in-app)

`NotificationService` (batch 500, dédup, purge pilotée par
`notifications.purge_jours` — LOOP E), préférences par utilisateur,
gabarits versionnés + CRUD, dispatcher 7 événements, centre + cloche.
Files : `database` (prod) + jobs (`Executer*`, `Generate*`) + Sentinel +
`file/verification/documents` (à déclarer au worker, cf. guide).
Relances : retry jobs standard. Canaux non configurés : SMTP absent (§2).

## 7. Services / intégrations — EXISTANTE (lecture)

Santé : `MaintenanceController`, `SystemeController`, `SupervisionService`,
Sentinel (11 contrôles + cockpit), `audit:routes-securite`. Sauvegardes :
planifiées + chiffrées + roundtrip prouvé + Sentinel fraîcheur. Cache :
`CACHE_STORE` env (défaut `database`) ; invalidation paramètres prouvée.
Imports/exports : DataExchange + scopes + throttles + XXE/CSV. Diagnostics :
scheduler (`sauvegarde:evaluer` 15 min, purges hebdo) ; erreurs expurgées
(`JournalisationPrivee`, logs sans PII).

## 8. Historique / audit / restauration — EXISTANTE

`AuditService` (acteur/IP/UA/avant-après, sans secret), `historique_academique`
(immuable, scopé), `parametre_versions` + restauration=nouv. version,
versions documents/maquettes/gabarits, `IntegrationLog` (empreintes).
Accès journaux : `admin.audit.*` (export scopé). Concurrence : locks +
4-eyes + one-shot + anti-replay. Échecs : rollback testé (concurrence),
erreurs génériques.

## 9. Diagnostics / maintenance — EXISTANTE

`admin.maintenance.*` (index, purge cache), `admin.system.backups.*`
(créer/vérifier/tester/restaurer), `admin/sentinel/*`, supervision,
`sauvegarde:evaluer`, `documents:purger-orphelins`, `notifications:purger`.
État prod : `NOT_VERIFIED` (checklist ops, DEC-06).

## Doublons écartés

Aucun second moteur : paramètres (`ParametreService`), audit
(`AuditService`), notifications (`NotificationService`+dispatcher), backups
(`BackupService`), recherche, exports CSV — chaque famille a une source
unique. Enregistrement : entrée sidebar `Paramètres` ajoutée (route existante).
