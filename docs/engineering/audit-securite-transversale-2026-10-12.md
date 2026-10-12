# Audit transversal de sécurité — 12/10/2026 (LOOP 2)

Références : `docs/engineering/registre-canonique.md`, `matrice-preuves-risques.md`,
`registre-contradictions.md`, `decisions-humaines.md`, `docs/AUDIT-SECURITE.md` (§1-§11),
`docs/SECURITY.md`. Environnement : dev local uniquement. Fichiers applicatifs
modifiés : **aucun**.

## 1. Résumé exécutif et verdict : `PASS_WITH_RESERVATIONS`

Les 7 points ont été vérifiés preuve par preuve : **aucune vulnérabilité
critique ou élevée confirmée**. Deux comportements transversaux sont confirmés
par design (`SessionExamen`, CSP Alpine) et deux résidus latents restent
théoriques (`FinancePolicy::manage`, timing login admin). La production reste
`NOT_VERIFIED` (inchangé, DEC-06).

## 2. Matrice des constats

| ID | Point | Classe | Gravité | Preuve | Remédiation |
|---|---|---|---|---|---|
| T-01 | `FinancePolicy::manage()` sans scope | NOT_REPRODUCED (aucun chemin restreint) | Faible (latente) | `FinancePolicy.php:34-37` ; seuls `super_admin`/`admin_scolarite` ont `finances.manage` (`Permissions.php:335` vs restreints `:481` = `view` seul) ; `GedFinancesBiTest` 11/11 | Scoper si la matrice évolue (R-SEC-01) |
| T-02 | CSP `unsafe-inline`/`unsafe-eval` | CONFIRMED (requis, pas une faille) | Faible (XSS portée par Blade) | Header live vérifié (`curl -I`, `connect-src` inclus) ; 69 vues `x-data` + 17 `<script>` inline ; commentaire `SecurityHeadersMiddleware.php:41-50` | DEC-04 (`@alpinejs/csp`, dépendance + refonte) |
| T-03 | Énumération temporelle login admin (`Auth::attempt` sans hash fictif) | CONFIRMED (résidu mitigé) | Faible | `Admin/AuthController.php:33` (message générique) ; throttle `connexion.login` 5/min ; étudiant à temps constant (`ConnexionController.php:38`) ; `regenerate()` aux 3 logins | Optionnel : hash fictif (touche l'auth — décision) |
| T-04 | `SessionExamen` transverse (transiter/verrouiller/surveillants accessibles inter-facultés) | CONFIRMED (design) | Moyenne bornée (intégrité planning, pas de PII) | `EvaluationPolicy.php:98-107` (`SessionExamen => null`) ; Gates présentes mais sans périmètre ; routes `sessions/{session}/transiter|verrouiller|surveillants-auto` | Scoper si sessions départementales (décision métier) |
| T-05 | Uploads/téléchargements | CONFIRMED sûr | — | `MIMES_PAR_EXTENSION` (`DocumentService.php:62,207`), UUID + disque `local`/`private` `serve=false`, download gated + 404, `FileUploadSecurityTest` vert | Rien |
| T-06 | Webhooks/imports/exports | CONFIRMED sûr | — | HMAC avant anti-replay + fenêtre timestamp + request-id borné (`WebhookVerifier.php:30-46`) ; XXE refusé ; CSV neutralisé ; `ApiWebhookTest` vert | Rien |
| T-07 | En-têtes + config dev/prod | CONFIRMED séparées | — | `.env` (`APP_DEBUG=true`, `SESSION_ENCRYPT=false`) vs `.env.example` (`false`/`true`/`SECURE=true`) ; prod `NOT_VERIFIED` | Checklist ops (DEC-06) |

## 3. Tests et commandes

- `GedFinancesBiTest` 11/11 ; bloc `ApiWebhook|FileUpload|ImportExport|XssCsrf|ArchiveSecurity` **38/38**.
- `curl -I /admin/connexion` : CSP complète + `X-Frame-Options` constatés (preuve live §T-02).
- Lecture : `FinancePolicy`, `EvaluationPolicy::dansPerimetre`, `AuthController`, `WebhookVerifier`, `DocumentService`, `Permissions.php:335/481`, `routes` finances/sessions.
- Dépendances manquantes : aucune pour ce périmètre (MySQL/navigateur non requis ici).

## 4. Non reproduits / non exécutés

- Aucune exploitation `finances.manage` possible avec la matrice actuelle (T-01).
- Aucun contournement timing login démontré au-delà du résidu (T-03).
- Navigateur réel : non requis pour ces 7 points (revue code + headers live).

## 5. Décisions humaines

DEC-04 (CSP), DEC-06 (prod) inchangées ; proposition : qualifier T-04 (accepter le transverse ou scoper).

## 6. Contradictions

Aucune nouvelle. C3 corroborée (777 routes mesurées pendant l'audit).

## 7. Prochaines actions

Sur autorisation : scoper `FinancePolicy::manage` + `SessionExamen` (preuves d'abord),
hash fictif login admin, checklist prod avec l'exploitant.
