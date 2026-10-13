# LOOP M — CI/CD et sécurité (13/10/2026)

CI (`ci.yml`) : checkout, PHP 8.4, `composer install`, `composer audit --no-dev`
(bloquant), `pint --test` (bloquant), `php artisan test --compact`, Node 22,
`npm audit --audit-level=high` (bloquant), `npm ci` + build. Aucune
désactivation silencieuse, aucun secret, aucun déploiement. Runs distants :
NOT_VERIFIED (pas d'accès). Secrets : `.env` ignoré, jamais commité (vérifié
au push). Migrations : 147 `Ran`, 0 en attente. Autorisations : inchangées
par LOOP M (16 permissions LOOP J déjà auditées). Gate CI inchangée :
PASS_WITH_REMEDIATION maintenu jusqu'à preuve d'exécution distante.
