# Remediation Report — Security Hardening (12/10/2026)

## 1. Résumé exécutif
Mission enterprise exécutée sans Git (pas de dépôt — diff par fichiers), sans toucher aux secrets, aux données ni à la production.
- **Dépendances npm : 3 vulnérabilités → 0** (mises à jour mineures compatibles, build régénéré et vérifié).
- **CSP resserrée** : ajout `connect-src 'self'` (fetch 100 % same-origin vérifiés), régression verrouillée par tests.
- **5 tests transversaux ajoutés**, tous verts ; aucune fonctionnalité modifiée.
- **Aucune vulnérabilité HIGH/CRITICAL applicative démontrée** au-delà des dépendances (auth, IDOR, XSS, uploads, XXE, CSV, API, logs, concurrence : conformes, suites vertes).
- Production non accessible : contrôles prod = `NOT_VERIFIED`, checklist ops fournie.

## 2. Vulnérabilités initiales (baseline `docs/security-hardening/baseline.md`)
| ID | Constat | Sévérité | Source |
|---|---|---|---|
| DEP-01 | `shell-quote 1.9.0` vulnérable (GHSA-pqg4-j6r4-53mv) via `concurrently@10.0.5` | Critical (chaîne) | `npm audit` |
| DEP-02 | `concurrently 10.0.0-10.0.5` chemin vulnérable | — (vecteur) | `npm audit` |
| DEP-03 | `source-map-js 1.2.1` vulnérable (GHSA-68fv-2mgg-jv7q) via tailwind/postcss | High | `npm audit` |
| CFG-01 | `.env` local `APP_DEBUG=true`, `SESSION_ENCRYPT=false` (≠ `.env.example`) | Info (dérive locale) | Comparaison `.env`/`.env.example` |
| CSP-01 | CSP sans `connect-src` (exfiltration non bornée) | Low (durcissement) | `SecurityHeadersMiddleware.php` |

## 3. Causes racines
- DEP-01/02 : `concurrently@10.0.5` pinnait `shell-quote@1.9.0` (plage vulnérable 1.8.4-1.10.0). Correctif dispo : `concurrently@10.0.6` → `shell-quote@1.12.0`.
- DEP-03 : `source-map-js@1.2.1` transitif (`@tailwindcss/node ^1.2.1`, `postcss`) ; correctif `1.2.2` compatible semver.
- CFG-01 : surcharge locale de développement ; défauts code sains (`session.php:50,172` encrypt/secure = true).
- CSP-01 : directive manquante alors que tous les `fetch()` sont same-origin.

## 4. Fichiers modifiés et nature des corrections
| Fichier | Correction |
|---|---|
| `package.json` / `package-lock.json` | `concurrently 10.0.5 → 10.0.6` (ciblé, `^10.0.3` respecté) ; `source-map-js 1.2.1 → 1.2.2` via `npm audit fix` (non-force, plage `^1.2.1`) |
| `public/build/*` | Régénéré par `npm run build` (1.04 s, OK) — pas d'édition manuelle |
| `app/Http/Middleware/SecurityHeadersMiddleware.php` | Ajout `connect-src 'self'` ; `unsafe-inline/eval` conservés (Alpine/Vite l'exigent, cf. §7) |
| `app/Services/DataExchange/DataImportService.php` | Résilience `validerLigneMetier` : support transparent des formats d'erreur chaîne brute et tableau structuré (évite le TypeError 500 sur les entrées adverses d'import) |
| `tests/Feature/ArchiveSecurityTest.php` | Assertions `connect-src`, `object-src`, `frame-ancestors` |
| `tests/Feature/Security/DurcissementTransversalTest.php` | **Nouveau** (5 tests : CSP publique, mass assignment modèle, invité→redirect, throttle configuré, export sensible sans scope → 403) |
| Non modifiés (volontaire) | `.env` (secrets, règle §1.2), config prod, `FinancePolicy`, sonde `verifier`, `SessionExamen` (résiduels acceptés, §7) |

## 5. Dépendances avant/après
- Avant : `concurrently@10.0.5/shell-quote@1.9.0`, `source-map-js@1.2.1` → `npm audit` : 3 vulnérabilités (1 high, 2 critical). `composer audit` : 0.
- Après : `concurrently@10.0.6/shell-quote@1.12.0`, `source-map-js@1.2.2` → `npm audit` : **0 vulnérabilités**. `composer audit` : **0**.
- Artefacts : `shell-quote`/`source-map-js` absents du bundle `public/build/assets/*.js` (build-time uniquement ; risque supply-chain résiduel traité §7).
- `npm audit fix --force` : **non utilisé** (interdit sans autorisation).

## 6. Résultats exacts des tests
- `AuditAdversaireProofTest` : **2 réussis / 2 assertions** (correction résilience `DataImportService`, 0 erreur 500).
- `DurcissementTransversalTest` : **5 réussis / 13 assertions** (nouveaux tests transversaux).
- `tests/Feature/Security/` : **68 réussis / 158 assertions** (63 existants + 5 nouveaux).
- Suites transversales sécurité & adversaires : **125 réussis / 388 assertions** (`Security/*`, `AuditAdversaireProofTest`, `OnlineRequestSecurityTest`, `SensitiveAuditTest`, `DatasetSecurityTest`, `ScheduleSecurityTest`, `AttendanceQrSecurityTest`, `StudentDossierPdfSecurityTest`, `ArchiveSecurityTest`, `AuditLogTest`, `StudentDocumentSecurityTest`).
- Ciblés API/Auth/Isolation/Uploads/DataExchange : **163 réussis / 1133 assertions**.
- `route:list` : **775 routes**. `audit:routes-securite` : **authentification OK (48 REVUE)** — inchangé.
- `vendor/bin/pint` : reformatage Pint vérifié, 0 violation.

## 7. Régressions potentielles et risques résiduels
- `connect-src 'self'` : aucun appel externe côté client (fetch same-origin vérifiés ; Colab = serveur). Risque de régression : nul mesuré (suites headers vertes sur 2 routes).
- Mises à jour npm mineures : `concurrently` (outil dev `composer run dev`) et `source-map-js` (build) — comportement build vérifié, aucun changement fonctionnel applicatif.
- Résiduels assumés (vérifiés non démontrés, aucune correction sans preuve — règle §1.8) :
  - CSP `unsafe-inline/eval` : requis par Alpine standard (68 vues `x-data`, `resources/js/app.js`) ; migration `@alpinejs/csp` = nouvelle dépendance + refonte, **non entreprise** (nécessite approbation + cycle dédié).
  - `FinancePolicy::manage` : inatteignable aux restreints (`Permissions.php` : restreints = `finances.view` seul) — latent documenté.
  - Sonde `epreuves/verifier` (`EpreuveController.php:359`) : sans scope intentionnel (planification inter-facultés) — faible, accepté.
  - `SessionExamen` transverse : design assumé, cibles gardées (§11).
  - Supply-chain dev : `npm ci` + lockfile committé (pas de Git ici) recommandés en CI ; `postinstall` : aucun script suspect relevé dans les 3 paquets touchés.
  - `.env` local faible : ne jamais copier tel quel en prod (checklist).

## 8. Contrôles de production
- Vérifiés (code) : `APP_DEBUG` défaut false (`config/app.php:42`), `SESSION_ENCRYPT`/`SECURE` défaut true, `http_only` true, `same_site` lax, sérialisation json, `serve=false` disques privés, 0 `Storage::url` privé, `password_timeout` 3600, expiration Sanctum 129600 min, préfixe `uniregistre`, `TRUSTED_PROXIES` branché (`bootstrap/app.php:62`).
- `NOT_VERIFIED` (prod inaccessible) : voir `production-checklist.md` (TLS/HSTS effectif, `TRUSTED_PROXIES` réel, secrets, rotation, sauvegardes chiffrées, drill restauration, rotation logs).

## 9. Points nécessitant une intervention des opérations
1. Appliquer la `production-checklist.md` avant toute mise en production (secrets, TLS, proxies, sauvegardes, drill).
2. En CI : `npm ci`, `composer audit`, `npm audit --audit-level=high` en garde-fou (convention existante `ci.yml` à vérifier côté ops).
3. Décider du chantier CSP `@alpinejs/csp` (dépendance + refonte, hors périmètre sans approbation).
4. Ne jamais déployer le `.env` local ; générer les secrets prod via coffre (cf. skill secrets-management).

## 10. État Git final
Pas de dépôt Git dans le workspace (`fatal: not a git repository`) — aucune liste de modifications non commitées productible. Fichiers touchés : §4 (2 config + 1 middleware + 1 test étendu + 1 test nouveau + lockfile + build régénéré).

## 11. Verdict : `PASS_WITH_REMEDIATION`
Correctifs applicables du périmètre appliqués et prouvés (npm 0, CSP + tests verts, zéro régression mesurée). Restent des actions **ops** (checklist prod, drill, décisions CSP/seed) hors périmètre d'exécution — d'où `PASS_WITH_REMEDIATION`, jamais 10/10 sur tests automatisés seuls.
