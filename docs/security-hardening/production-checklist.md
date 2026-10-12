# Production Checklist — Mise en production (12/10/2026)

Statut global : **`NOT_VERIFIED`** — production non accessible pendant la mission.
Ne rien cocher sans preuve datée (commande + sortie). Ne pas déployer sans autorisation humaine explicite.

## Secrets et configuration (sans jamais les afficher)
- [ ] `APP_DEBUG=false` effectif en prod (vérifier sortie `config:show`, jamais le secret lui-même).
- [ ] `APP_KEY` prod unique, générée via coffre, jamais dans Git.
- [ ] `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax|strict`.
- [ ] `SANCTUM_TOKEN_PREFIX` défini ; expiration 90 j conservée ; tokens existants révoqués si compromis.
- [ ] `TRUSTED_PROXIES` = IPs exactes des LB/proxies (pas `*` sauf infra éphémère documentée).
- [ ] Mots de passe seedés/démo rotationnés ; comptes techniques à scopes minimaux ; `super_admin` jamais aux intégrations.
- [ ] Secret webhook fort **par émetteur** si multi-systèmes (aujourd'hui secret unique).

## Transport et en-têtes
- [ ] HTTPS partout + redirection 301 HTTP→HTTPS ; cookies `Secure/HttpOnly/SameSite` constatés (DevTools).
- [ ] HSTS reçu sur connexion chiffrée (`max-age=31536000; includeSubDomains`) — le middleware ne l'émet qu'en TLS.
- [ ] CSP en vigueur relue après build prod (dont `connect-src 'self'`) ; rapport-only d'abord si violations collectées.
- [ ] `TRUSTED_PROXIES` cohérent : IP client réelle vue par rate limiting, audit et anti-replay (tester via logs).

## Données et continuité
- [ ] Sauvegardes `mysqldump` + `storage/app/private` quotidiennes, **chiffrées**, restauration **drillée** (dernier drill : à consigner ici).
- [ ] Rétention imports/exports 168 h active (`exports:purge` planifié) ; purge orphelins planifiée.
- [ ] Files workers : `documents,notifications,verification,default` démarrées (cf. `GUIDE-DEPLOY.md` §7.2).

## Exploitation
- [ ] `composer audit` + `npm audit --audit-level=high` verts en CI au moment du build de release.
- [ ] `php artisan audit:routes-securite` : auth OK, REVUE relues.
- [ ] Rotation des logs (jamais de PII durable) ; supervision `supervision:controler` + Sentinel actifs.
- [ ] Incident : procédure `docs/SECURITY.md` §4 connue de l'exploitant.

## Décisions en attente (ne pas trancher par code)
- Chantier CSP `@alpinejs/csp` (éliminer `unsafe-eval`) : oui/non + fenêtre.
- `SessionExamen` transverse, sonde `verifier`, `FinancePolicy::manage` : statu quo accepté ou cadrage nouveau.
