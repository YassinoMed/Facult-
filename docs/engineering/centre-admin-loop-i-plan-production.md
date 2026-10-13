# LOOP I — Plan production (12/10/2026)

## Pré-mise en service (ordre)

1. Trancher R-I-01/02/03 (SMTP, MFA, permissions) : périmètre e-mails
   réellement requis ou assumer `NOT_CONFIGURED` en prod (interdire toute
   promesse d'envoi).
2. `composer audit` + `npm audit` 0, `npm run build`, suites Settings 43/43
   (2 skips MySQL documentés), voisins verts, Pint chantier vert.
3. `migrate:status` : 143+ migrations `Ran` ; sauvegardes chiffrées +
   roundtrip + drill (cf. `production-checklist.md`).
4. Secrets : SMTP/env en coffre, jamais en paramètres (garde service) ;
   rotation `APP_KEY` (chiffrement SMTP[msg]).
5. Cocher `production-checklist.md` avec l'exploitant (TLS, TRUSTED_PROXIES,
   workers files, rotation logs, Sentinel).

## Post-déploiement

- Surveiller `parametre.cache_invalidation_echec` et `mail.test_echec`.
- Rejouer les gates MySQL en recette (`SETTINGS_CONC_MYSQL=1`).
- R-I-08/R-I-09 : résorber dette Pint et recette navigateur.
