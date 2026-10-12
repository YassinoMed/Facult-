# Baseline — Security Hardening (12/10/2026)

## Contexte
- Dépôt : **pas de Git** (`fatal: not a git repository`) — état Git non vérifiable, aucune modification suivie. Réserve : diff avant/après impossible, seul le contenu disque fait foi.
- Référence : audit du 12/10/2026 (775 routes, Security 63/63, API/Auth 84/84) + `docs/AUDIT-SECURITE.md` (§1-§11) + `docs/SECURITY.md`.
- Environnement de test : isolé SQLite via `phpunit.xml` (`APP_ENV=testing`, `BCRYPT_ROUNDS=4`). Aucune migration/seeder/reset exécuté sur base réelle dans cette mission.

## Versions
- PHP 8.5.11 (cli, Homebrew NTS)
- Laravel Framework 13.33.0 (`composer.json` exige `^13.17`)
- Node v26.0.0, npm 11.12.1
- Dépendances PHP : `laravel/sanctum ^4.0`, `dompdf/dompdf ^3.1`, `openspout/openspout ^5.11`, `livewire/livewire ^4.4`
- Dépendances JS prod : `alpinejs ^3.17.4` seule. Dev : `vite ^8.0.0`, `tailwindcss ^4.0.0`, `@tailwindcss/vite ^4.0.0`, `concurrently ^10.0.3`, `laravel-vite-plugin ^3.1`

## Commandes exécutées (résultats)
- `composer audit` → **0 avis** (PHP, paquets directs + transitifs).
- `npm audit` → **3 vulnérabilités** (1 high, 2 critical) :
  - `shell-quote 1.8.4-1.10.0` critical (GHSA-pqg4-j6r4-53mv, injection via `{ comment }`) — installé `1.9.0` via `concurrently@10.0.5` ; corrigé en `≥1.11.0` (existent : 1.11.0, 1.12.0).
  - `source-map-js 1.0.0-1.2.1` high (GHSA-68fv-2mgg-jv7q, DoS boucle) — installé `1.2.1` via `@tailwindcss/node@4.3.3` et `postcss@8.5.28` ; corrigé en `1.2.2`.
  - `concurrently 9.2.3-9.2.4 || 10.0.0-10.0.5` (chemin) ; `10.0.6` disponible.
- `npm ls concurrently shell-quote source-map-js` → `concurrently@10.0.5/shell-quote@1.9.0`, `source-map-js@1.2.1` (×2 chemins).
- `php artisan route:list --except-vendor` → **775 routes**.
- `php artisan audit:routes-securite` → **authentification OK (48 à revue humaine)** — REVUE = Gates contrôleur attendues, pas d'absence d'auth.
- `php artisan test --compact --filter=Security` → **112 réussis / 347 assertions** (inclut suites `*Security*` + voisins).

## Réserves
- Pas de Git : impossible de prouver l'absence de modifications en cours ; toute correction sera décrite par fichiers + diff de contenu.
- Production non accessible : contrôles prod (`APP_DEBUG`, TLS, `TRUSTED_PROXIES`, secrets) = `NOT_VERIFIED`, checklist dédiée prévue.
- `.env` local (`APP_DEBUG=true`, `SESSION_ENCRYPT=false`) ≠ `.env.example` (`false`/`true`) — dérive locale assumée dev, à ne pas propager.
- `npm audit fix --force` interdit sans autorisation (règle mission §1.6).
