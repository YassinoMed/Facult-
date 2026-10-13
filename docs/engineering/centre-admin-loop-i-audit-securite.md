# LOOP I — Audit sécurité (12/10/2026)

## Accès (vérifié code + tests)

- Paramètres : `settings.view` (index+historique) / `settings.manage`
  (update+restaurer, throttles 20/1 et 10/1), super_admin seul —
  `ParametreController:27,65,117`, `ParametrePolicy`, `web.php:920-925`.
  Restreint 403 lecture/écriture/restauration (tests B/F).
- Rôles : `super_admin` middleware (`web.php`, `roles.index`) ; matrice en
  code, lecture seule, attribution via comptes (gardes anti-escalade,
  dernier super-admin).
- Comptes : `super_admin` middleware ; auto-suppression 403 ;
  dernier super-admin 403 ; reset jamais journalisé en clair.
- Journal : `can:history.view`, scopé faculté (`AuditController:20-37`),
  invité redirigé ; reset log sans mot de passe (test G).
- Versions : aucune route UPDATE/DELETE sur `parametre_versions`
  (création service seule) ; restauration = nouvelle version, 404 croisée.

## Secrets et journaux

- SMTP : cast `encrypted` + `$hidden`, brut jamais en clair (test),
  diagnostic sans mot de passe, `mail:tester` expurgé (classe seule loggée).
- Logs : `parametre.modifie` (clé/avant/après), `cache_invalidation_echec`
  (clé+erreur, sans valeur), `admin.password_reset` (ids+IP).
- `.env` : non lu, non affiché (contrainte respectée).

## Concurrence et cache

- `lockForUpdate` + optimiste `attendu` (422/409 logique `concurrence`) ;
  race MySQL prouvée RED→GREEN ; panne cache : écriture sauvée + warning.
- `attendu` obligatoire UI+serveur ; restauration explicite (motif, version).

## Écarts

Aucune vulnérabilité confirmée. Réserves : panne Redis non testée en
panne réelle (stub), race sans listener non rejouée, MFA/invitations absents
(décisions), 17 permissions candidates non créées.
