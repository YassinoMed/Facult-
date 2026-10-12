# 🔍 Audit de sécurité — Rapport d'évaluation et remédiation

> **Date** : 24-25 septembre 2026
> **Périmètre** : application complète (contrôleurs, FormRequests, Policies, middleware,
> vues Blade, JS, modèles, migrations, seeders, services d'import/export, jobs,
> configuration, dépendances).
> **Méthode** : revue de code manuelle systématique (~140 fichiers), vérification des
> affirmations de `docs/SECURITY.md`, `composer audit` + `npm audit` (0 CVE connue),
> analyse des 4 axes : applicatif, données, infrastructure, dépendances.
>
> Ce rapport complète `docs/SECURITY.md` (mesures implémentées & risques résiduels
> déclarés) : il en **confirme l'essentiel**, documente les constats nouveaux et
> trace les corrections appliquées.

---

## 1. Verdict global

**Niveau de sécurité applicative nettement au-dessus de la moyenne** pour un
système de gestion de dossiers étudiants : aucun SQL injection, aucune XSS
exploitable, CSRF exhaustif, anti-IDOR systématique, RBAC fail-closed,
webhooks HMAC timing-safe, journal d'audit sans secrets, XXE et injection CSV
neutralisés.

Les faiblesses constatées se concentrent sur trois axes :

1. **Configuration de déploiement** (debug, cookies, HSTS, proxies, tokens perpétuels) ;
2. **Rétention de données personnelles** (fichiers d'import, fichiers orphelins, `/tmp`) ;
3. **Quelques incohérences RBAC / exposition de PII** (suppression d'étudiant,
   pré-remplissage CIN à facteur unique).

Les correctifs P0/P1 listés ci-dessous ont été **appliqués le 25/09/2026** —
la suite de tests passe intégralement (223 tests, 905 assertions, dont 27
tests dédiés au pré-remplissage CIN).

---

## 2. Constats élevés (P0) — corrigés ✅

| # | Constat | Emplacement | Statut |
|---|---|---|---|
| P0-1 | **Identifiants seedés en dur** : comptes scolarité (`super_admin`/`Admin@2026`, etc.) et 25 étudiants partageant `etudiant123`. Quiconque lit le code source connaît les mots de passe. | `ArchiveSeeder.php`, `DatabaseSeeder.php` | ✅ **Corrigé** : verrou d'environnement — les seeders de démonstration lèvent une `RuntimeException` hors `local`/`testing` ; les facultés (données de référence, sans credential) restent seedables. |
| P0-2 | **Tokens API perpétuels** : `sanctum.expiration = null` + `createToken()` sans `expiresAt` → un token volé reste valide indéfiniment. | `config/sanctum.php`, `IntegrationTokenCreate.php` | ✅ **Corrigé** : expiration par défaut de **90 jours** (`SANCTUM_TOKEN_EXPIRATION`, en minutes) + date d'expiration affichée à la création du token. |
| P0-3 | **`APP_DEBUG=true` par défaut** dans `.env.example` (copié tel quel par `composer setup`) → stack traces exposées en production. | `.env.example` | ✅ **Corrigé** : `APP_DEBUG=false` par défaut + commentaire explicite. |
| P0-4 | **Oracle de PII non authentifié** : `POST /inscription/verifier-cin` retournait nom, prénom, email, téléphone, date de naissance, sexe, adresse complète, matricule — contre le seul CIN (identifiant semi-public), abrité uniquement par le rate limit. | `StudentLookupService.php`, `VerifierCinRequest.php`, `app.js`, `create.blade.php` | ✅ **Corrigé** : **second facteur obligatoire** — la date de naissance doit correspondre EXACTEMENT ; réponse identique si date erronée ou CIN inconnu (aucune énumération) ; fail-closed pour un étudiant sans date connue ; aucune requête réseau côté client sans le couple complet. 4 nouveaux tests. |
| P0-5 | **Triptyque HTTPS incomplet** : cookie de session sans flag `Secure` (variable jamais définie), **aucun HSTS**, `trustProxies` non configuré → derrière un reverse proxy, rate limiting par IP, audit et anti-replay webhook voient l'IP du proxy (contournable via `X-Forwarded-For`). | `config/session.php`, `SecurityHeadersMiddleware.php`, `bootstrap/app.php` | ✅ **Corrigé** : `SESSION_SECURE_COOKIE=true` dans `.env.example`, en-tête **HSTS** émis sur toute connexion chiffrée (`Strict-Transport-Security: max-age=31536000; includeSubDomains`), `TRUSTED_PROXIES` configurable via env et branchée dans `bootstrap/app.php`. |
| P0-6 | **Contournement RBAC sur la suppression d'étudiant** : `DELETE admin/etudiants/{etudiant}` exigeait `can:archives.update` mais la cascade BD détruit dossier, inscriptions, semestres, notes et documents → un `agent_scolarite` (sans `archives.delete`) pouvait anéantir un dossier complet. | `routes/web.php`, `EtudiantController::destroy` | ✅ **Corrigé** : route passée à `can:archives.delete` (cohérence avec `admin.archives.destroy`). |

## 3. Constats moyens (P1) — corrigés ✅

| # | Constat | Emplacement | Statut |
|---|---|---|---|
| P1-7 | **Fichiers d'import jamais purgés** : contrairement aux exports (168 h), les fichiers d'import pleins de PII s'accumulaient indéfiniment dans `storage/app/private/integrations/imports/`. | `ImportOrchestrator.php`, `PurgeExpiredExports.php` | ✅ **Corrigé** : `expires_at` posé à la création (rétention `INTEGRATION_IMPORT_RETENTION_HOURS`) ; **fichier supprimé immédiatement après exécution** (confirmé ou échoué) ; la commande planifiée `exports:purge` purge désormais aussi les imports abandonnés (fichier + statut `expired` si `pending`) + journalisation. |
| P1-8 | **Fichiers physiques orphelins** : la suppression d'un étudiant via `admin/etudiants` cascadait les lignes `documents_academiques` mais laissait les fichiers PII sur disque (contrairement à `ArchiveController::destroy` qui les supprime). | `EtudiantController::destroy` | ✅ **Corrigé** : suppression des documents physiques dans la même transaction que la cascade BD. |
| P1-9 | **Aucune limite de lignes dans `XlsxParser`** : un XLSX de quelques Mo peut se décompresser en plusieurs Go (bombe ZIP, ratio ~1000:1) — parsing synchrone dans la requête HTTP → épuisement CPU/RAM du worker. | `XlsxParser.php` | ✅ **Corrigé** : plafond dur `INTEGRATION_IMPORT_MAX_ROWS` (10 000 lignes de données par défaut), message métier clair au dépassement, `finally` garantissant la fermeture du reader. |
| P1-10 | **Fichiers temporaires `/tmp` avec PII** jamais supprimés, nom prévisible (`uniqid`), création sans `O_EXCL` (symlink possible sur hôte mutualisé). | `ImportOrchestrator::reconstruireFichier` | ✅ **Corrigé** : `tempnam()` (mode exclusif, droits 0600, nom imprévisible) + suppression systématique dans un bloc `finally` (succès, échec ou exception). |

## 4. Constats moyens (P2) — corrigés le 28/09/2026 ✅

| # | Constat | Emplacement | Statut |
|---|---|---|---|
| P2-11 | **`'serve' => true`** sur le disque privé : active les routes framework `GET/PUT /storage/{path}` — protégées par URL signée, mais forçables si `APP_KEY` fuit. L'application n'utilise que `Storage::download()`. | `config/filesystems.php:36` | ✅ **Corrigé** : `'serve' => false` (vérifié : aucun `Storage::url()`/`temporaryUrl()` sur le disque privé). |
| P2-12 | **Routes `import-templates` sans vérification de scope** : tout token valide, quel que soit son scope, liste/télécharge les modèles d'import. | `routes/api.php` (`templates.*`) | ✅ **Corrigé** : `scope:imports:create` exigé sur les 3 routes + test `test_api_modeles_refuses_sans_scope_imports_create` (403 sans le scope). |
| P2-13 | **Anti-replay consommé avant le HMAC** : requêtes non authentifiées remplissent le cache de clés `webhook:replay:*` arbitraires ; un webhook à signature invalide « brûle » son `X-Request-ID` 24 h. | `WebhookVerifier.php:42-50` | ✅ **Corrigé** : ordre présence → format → timestamp → **HMAC** → anti-replay. Tests : `test_signature_invalide_ne_brule_pas_le_request_id` (HTTP + unitaire), `test_rejeu_legitime_toujours_refuse_apres_succes`. |
| P2-14 | **`--role` non validé** dans `integration:token-create` (l'enum SQL fait fail-closed, mais `super_admin` est accepté pour un compte technique) + `firstOrCreate` ignore le rôle si le compte existe déjà. | `IntegrationTokenCreate.php` | ✅ **Corrigé** : validation via `Permissions::roleValide()`, `super_admin` interdit (`User::ROLES_TECHNIQUES`), compte existant au rôle différent → refus explicite. 4 tests `IntegrationTokenCreateTest`. |
| P2-15 | **`X-Request-ID` client non validé** (longueur/format) : stocké en colonne `string(64)` → erreur 500 possible avec un header surdimensionné, contenu arbitraire en BD. | `ApiExchangeLogger.php:23` | ✅ **Corrigé** : regex `^[A-Za-z0-9_-]{1,64}$`, sinon UUID serveur ; même borne côté `WebhookVerifier` (défense en profondeur). Tests : normalisation HTTP (202 + UUID), rejet unitaire sans pollution du cache. |
| P2-16 | **CSP `'unsafe-inline' 'unsafe-eval'`** : le XSS n'est pas atténué par CSP (risque assumé, documenté). | `SecurityHeadersMiddleware.php` | ⏳ **Reporté (moyen terme)** : migrer vers le build Alpine CSP + nonces/hashes ; externaliser le `<script>` inline de la vue exports. Risque résiduel inchangé (échappement Blade systématique). |

## 5. Constats faibles (P3) — corrigés le 28/09/2026 ✅ (sauf mention)

| # | Constat | Emplacement | Statut |
|---|---|---|---|
| P3-17 | **Mass assignment latent** : `role` dans `#[Fillable]` de `User`, `statut`/`num_inscription` dans `$fillable` d'`Etudiant` — aucune voie HTTP ne l'exploite aujourd'hui (vérifié : aucun `$request->all()`), mais un futur code non filtré suffirait (auto-validation, prédiction du n° d'inscription). Retirer ces colonnes des fillables et les setter via méthodes dédiées. | `User.php`, `Etudiant.php` | ✅ **Corrigé** : `User::definirRole()` + `User::creerCompteBackOffice()` ; `Etudiant::definirStatut()` (+ `STATUTS`) ; `num_inscription` auto-généré uniquement. Contrôleurs, commande token, seeders et 21 fixtures de test migrés. Vérifié : rôle injecté via `User::create` désormais ignoré (défaut `consultation` → 403). |
| P3-18 | `ArchiveController::export` (CSV du dossier) : `fputcsv` brut **sans** neutralisation de formules — seul canal d'export contournant `CsvSerializer::neutraliserFormule` (données admin/import, risque faible, mais contredit le §33 de `docs/SECURITY.md`). | `Admin/ArchiveController::export` | ✅ **Corrigé** : `CsvSerializer` injecté, toutes les colonnes texte neutralisées + test `test_export_csv_neutralise_les_formules`. |
| P3-19 | `IntegrationController::preview` : pas de contrôle de propriété du rapport (contrairement à `download`/`erreurs`/`confirm`) — un `admin_scolarite` peut prévisualiser les rapports d'un autre. | `Admin/IntegrationController.php` | ✅ **Corrigé** : même contrôle que `erreurs()` (propriétaire uniquement, 404 silencieux) + `IntegrationPreviewTest` (2 tests). |
| P3-20 | **CIN complet en API** : `StudentResource` expose le CIN à tout token `students:read`. Décision d'interopérabilité acceptable (le CIN est la clé de réconciliation des SI externes, scope restreint) — à documenter explicitement comme accepté, ou masquer par défaut + scope dédié. | `StudentResource.php` | ✅ **Décision documentée comme acceptée** (cf. `docs/SECURITY.md` §1) : CIN = clé de réconciliation inter-SI, scope `students:read` restreint, listes web masquées (`cin_masquee`) — surface distincte et volontaire. |
| P3-21 | **Politique de mots de passe faible** : min 8, lettre+chiffre (`etudiant123` conforme) ; pas de vérification de compromission. Recommandé : `Password::min(10)->uncompromised()`. | `InscriptionRequest.php`, `MotDePasseEtudiantRequest.php` | ✅ **Corrigé** : `Password::defaults()` = min 10 + lettres + chiffres + `uncompromised()` hors tests (aucun appel réseau en test) ; appliqué aux 3 flux (inscription, changement étudiant, création admin) + messages FR + hints des vues. Test frontière 9 vs 10 caractères. |
| P3-22 | Changement de mot de passe sans invalidation des autres sessions ; confirmation d'import non atomique (`pending → processing` en check-then-act) ; `%`/`_` non échappés dans les recherches LIKE ; énumération temporelle au login étudiant ; race sur `genererNumInscription` (dernier rempart : contrainte unique). | Divers | ✅ **Corrigé** : `session()->regenerate()` après changement de mot de passe ; transition atomique `WHERE status='pending'` (0 ligne = déjà traitée) ; `App\Support\Recherche::contient()` (addcslashes `%_\\`) sur les 2 recherches + tests ; bcrypt fictif valide au login si compte introuvable (temps constant). Race `genererNumInscription` : inchangée (contrainte unique en dernier rempart, documentée). |
| P3-23 | Divers : `required_scopes` divulgués en 403 ; `SANCTUM_TOKEN_PREFIX` vide (secret scanning inexploitable) ; `X-XSS-Protection` obsolète ; pas de COOP/CORP/COEP ; `target="_blank"` sans `rel="noopener"` ; `password_timeout` 3 h ; `SESSION_ENCRYPT=false` ; PII dans `import_reports.preview` (rétention 168 h à documenter) ; `{!! $etudiant->statut_badge !!}` sûr par construction mais fragile. | Divers | ✅ **Corrigé** : 403 sans détail (`error: insufficient_scope`) ; préfixe `uniregistre` par défaut (config + `.env.example`) ; `X-XSS-Protection` supprimé, COOP/CORP `same-origin` ajoutés (COEP volontairement absent : casserait fonts.googleapis) ; `rel="noopener noreferrer"` sur les liens externes ; `password_timeout` 3 h → 1 h ; `SESSION_ENCRYPT=true` par défaut ; rétention preview 168 h documentée (`docs/SECURITY.md` §3). `statut_badge` : conservé — HTML 100 % statique généré par `match` sur enum SQL (aucune entrée utilisateur), documenté comme tel. |

---

## 6. Points forts vérifiés (conclusions de l'audit)

| Domaine | Vérification |
|---|---|
| Injection SQL | 100 % paramétré (Eloquent/Query Builder) — aucune concaténation d'entrée utilisateur |
| XSS | `{{ }}` systématique (68+ interpolations) ; seules 3 sortes `{!! !!}` = HTML statique généré serveur (`match` sur enum) ; pas d'`eval`/`innerHTML`/`x-html` |
| CSRF | 37/37 formulaires POST avec `@csrf` ; AJAX avec `X-CSRF-TOKEN` ; l'API n'accepte que des bearer tokens (aucune surface CSRF `/api/*`) |
| IDOR | Étudiant TOUJOURS résolu depuis la session ; appartenance stricte sur documents/rapports ; 404 silencieux ; `whereNumber` sur les routes paramétrées |
| Mass assignment | `$fillable`/`#[Fillable]` sur les 14 modèles ; aucun `$request->all()` dans les contrôleurs ; coefficient/crédits/moyennes dérivés serveur |
| Upload | Disque privé (`storage/app/private`) ; UUID internes ; double validation extension + MIME réel + taille ; SHA-256 ; téléchargement exclusivement via contrôleur gated |
| Path traversal | Chemins 100 % serveur (id + uuid) ; `chemin` masqué (`$hidden`) ; aucun `storage_path()`/`realpath()` sur entrée utilisateur |
| XXE | DTD/entités rejetées avant parse ; `LIBXML_NONET` ; pas de `LIBXML_NOENT` ; `resolveExternals=false` |
| Injection CSV/formules | Neutralisation `= + - @` sur TOUS les canaux (CSV, XLSX, rapports d'erreurs, modèles) — sauf P3-18 |
| Désérialisation | Aucun `unserialize()` ; `json_decode` borné (profondeur 64) ; `serializable_classes=false` |
| Webhooks | HMAC-SHA256 sur corps brut, `hash_equals` (timing-safe), fenêtre ±300 s, anti-replay 24 h — sauf ordonnancement P2-13 |
| RBAC | Matrice immuable 5 rôles × 15 permissions (dont `admin_faculte` scopé à sa faculté) ; enum SQL (défaut = rôle le moins privilégié) ; fail-closed ; Gates + Policies par ressource |
| Journal d'audit | Acteur/IP/UA/ancienne-nouvelle valeur ; jamais de secrets ; `IntegrationLog` = empreintes SHA-256 uniquement |
| Rate limiting | Granulaire par route (5 à 60/min web, dédié API/webhooks) + quotas délégués à la config |
| Concurrence | `DB::transaction` sur les opérations multi-tables ; `lockForUpdate` sur les notes ; contraintes uniques en dernier rempart ; anti-TOCTOU volontaire sur les imports (re-parse du fichier stocké) |
| Dépendances | `composer audit` : 0 avis ; `npm audit` : 0 vulnérabilité |
| Tests | Suite de sécurité dédiée : `ArchiveSecurityTest`, `ArchiveAuthorizationTest`, `ApiAuthenticationTest`, `ApiWebhookTest`, `CinPrefillTest` (27 tests), `AuditLogTest`, IDOR, mass assignment, headers… |

---

## 7. Corrections appliquées — détail technique (25/09/2026)

| Fichier | Changement |
|---|---|
| `config/sanctum.php` | `expiration` : `null` → 90 jours par défaut (env `SANCTUM_TOKEN_EXPIRATION`) |
| `app/Console/Commands/IntegrationTokenCreate.php` | Affichage de la date d'expiration du token créé |
| `database/seeders/ArchiveSeeder.php` | `RuntimeException` hors `local`/`testing` |
| `database/seeders/DatabaseSeeder.php` | Étudiants de démo + `ArchiveSeeder` ignorés hors `local`/`testing` (facultés conservées) |
| `.env.example` | `APP_DEBUG=false` ; `SESSION_SECURE_COOKIE=true` ; `TRUSTED_PROXIES`, `SANCTUM_TOKEN_EXPIRATION`, `INTEGRATION_IMPORT_RETENTION_HOURS`, `INTEGRATION_IMPORT_MAX_ROWS` documentés |
| `app/Http/Middleware/SecurityHeadersMiddleware.php` | HSTS sur connexion chiffrée |
| `bootstrap/app.php` | `$middleware->trustProxies(at: env('TRUSTED_PROXIES'))` |
| `routes/web.php` | `etudiants.destroy` : `can:archives.update` → `can:archives.delete` |
| `app/Http/Controllers/Admin/EtudiantController.php` | `destroy()` : suppression des fichiers physiques dans la transaction |
| `app/Http/Requests/VerifierCinRequest.php` | Second facteur : `date_naissance` requis (`date`, `before:today`) |
| `app/Services/StudentLookupService.php` | `trouverPourPrefill(cin, date)` : recherche paramétrée CIN + date EXACTE, fail-closed |
| `app/Http/Controllers/InscriptionController.php` | `verifierCin()` branché sur le second facteur |
| `resources/js/app.js` | Aucune requête sans le couple CIN + date ; état `attente_date` ; anti-course sur les deux champs |
| `resources/views/inscription/create.blade.php` | Message `attente_date` ; la saisie de la date relance la recherche |
| `resources/views/inscription/create.blade.php` | **Correctif robustesse** : 9 expressions `:class` `preliles.includes(...)` avaient un littéral vide à UNE seule quote (`: '"` au lieu de `: ''"`) → `Invalid or unexpected token` bloquant l'initialisation Alpine de tout le formulaire (défaut préexistant, seul le champ `adresse` était correct). NB : `x-data="inscriptionForm(@js(old('sexe', '')))"` était correct dès l'origine — `Js::from()` enveloppe les chaînes de quotes simples et échappe `\u0022`/`\u0027`, donc l'attribut à doubles quotes est sûr pour toute valeur ; une passe en quotes simples a été testée puis **reversée** (les `''` internes fermaient l'attribut prématurément) |
| `config/integrations.php` | `import_retention_hours` (168 h) + `import_max_rows` (10 000) |
| `app/Services/Io/ImportOrchestrator.php` | `expires_at` à la création ; `tempnam()` exclusif ; `finally` : suppression du fichier temporaire ET du fichier source après exécution |
| `app/Services/Io/XlsxParser.php` | Plafond de lignes + `finally` sur la fermeture du reader |
| `app/Console/Commands/PurgeExpiredExports.php` | Purge étendue aux imports expirés + journalisation `import.expired` |
| `tests/Feature/CinPrefillTest.php` | 23 → 27 tests (second facteur : manquant, future, erronée, étudiant sans date) |
| `docs/PREFILL-CIN.md` | Flux, endpoint, table de sécurité et tests mis à jour |

**Validation** : 223 tests, 905 assertions — tous verts (dont 17 tests `SeparationProfilsTest` ajoutés indépendamment). Style Pint : conforme.

---

## 8. Risques résiduels & plan de remédiation restant (mis à jour le 28/09/2026)

P2-11 à P2-15 et P3-17 à P3-23 traités (cf. §4-§5 et §9). Reste :

| Priorité | Actions | Références |
|---|---|---|
| **P2+ (moyen terme)** | CSP : build Alpine CSP + nonces, externaliser le `<script>` inline | P2-16 |
| **Durcissement futur** | Race `genererNumInscription` (contrainte unique en dernier rempart) ; `statut_badge` `{!! !!}` sûr par construction (HTML statique issu d'un `match` sur enum) ; CIN complet en API = décision d'interopérabilité documentée | P3-22/23, P3-20 |

**Configuration serveur requise en production** (inchangée, cf. `docs/SECURITY.md` §3) :
TLS + HSTS (désormais émis par le middleware), cookies `secure` (désormais par défaut),
`TRUSTED_PROXIES` à définir, secret webhook fort **par émetteur**, rotation des
mots de passe des comptes administratifs, sauvegardes chiffrées de
`storage/app/private/archives`.

---

*Document généré dans le cadre de l'évaluation de sécurité du projet. Toute mesure
ajoutée suite à cet audit devrait être consignée ici (§7) conformément à la
procédure de retour d'expérience de `docs/SECURITY.md` §4.*

---

## 9. Vague de remédiation P2/P3 — 28/09/2026

| Fichier | Changement |
|---|---|
| `config/filesystems.php` | Disque `local` (privé) : `'serve' => false` (P2-11) |
| `routes/api.php` | `scope:imports:create` sur les 3 routes `templates.*` (P2-12) |
| `app/Services/Io/WebhookVerifier.php` | Ordre HMAC avant anti-replay + borne du `X-Request-ID` (P2-13/P2-15) |
| `app/Http/Middleware/ApiExchangeLogger.php` | Normalisation du `X-Request-ID` (regex 1-64, sinon UUID serveur) (P2-15) |
| `app/Console/Commands/IntegrationTokenCreate.php` | Validation du rôle (`Permissions::roleValide`), `super_admin` interdit, refus si compte existant au rôle différent (P2-14) |
| `app/Models/User.php` | `role` retiré du `#[Fillable]` ; `definirRole()` validée, `creerCompteBackOffice()`, `ROLES_TECHNIQUES` (P3-17) |
| `app/Models/Etudiant.php` | `statut`/`num_inscription` retirés du `$fillable` ; `definirStatut()` + `STATUTS` (P3-17) |
| `AdministrateurController`, `EtudiantController`, `StudentsImporter`, `ArchiveSeeder`, `DatabaseSeeder` | Migrés vers les méthodes dédiées (P3-17) |
| `Admin/ArchiveController::export` | `CsvSerializer` injecté, formules neutralisées (P3-18) |
| `Admin/IntegrationController::preview` + `::confirm` (web) | Contrôle de propriété (P3-19, aligné sur API/ `erreurs()`) |
| `InscriptionRequest`, `MotDePasseEtudiantRequest`, `StoreAdministrateurRequest`, `AppServiceProvider` | `Password::defaults()` : min 10 + lettres + chiffres + `uncompromised()` hors tests, messages FR (P3-21) |
| `Etudiant/ProfilController::updatePassword` | `session()->regenerate()` après changement (P3-22) |
| `Etudiant/ConnexionController::login` | Bcrypt fictif valide si compte introuvable (temps constant) (P3-22) |
| `app/Support/Recherche.php` + 2 contrôleurs admin | Échappement LIKE `%_\\` (P3-22) |
| `ImportOrchestrator::confirmer` | Transition atomique `WHERE status='pending'` (P3-22) |
| `CheckApiScope` | 403 sans détail (`error: insufficient_scope`) (P3-23) |
| `SecurityHeadersMiddleware` | `X-XSS-Protection` supprimé, COOP/CORP `same-origin` (P3-23) |
| `config/sanctum.php`, `.env.example`, `config/auth.php` | Préfixe `uniregistre`, `SESSION_ENCRYPT=true`, `password_timeout` 1 h (P3-23) |
| `welcome.blade.php`, vues `profil`/`create` | `rel="noopener noreferrer"`, hints « 10 caractères » (P3-21/P3-23) |
| Tests | `IntegrationPreviewTest` (3), `IntegrationTokenCreateTest` (4), `WebhookVerifierTest` (3 unitaires), `RechercheTest` (3 unitaires) ; `ApiWebhookTest` (+2), `ImportTemplateTest` (+1), `ArchiveTest` (+2), `InscriptionEtudiantsTest` (+2 cas) ; headers figés (2 suites) ; test injection SQL OCR réaligné sur le design fail-closed §21 |
| `docs/SECURITY.md` | Lignes durcies : mass assignment, mots de passe, headers, scopes modèles, webhooks, tokens, CIN API (décision acceptée), rétention preview 168 h |
| IA VLM désactivée | `identity.ocr.hfvlm.active=false` (`IDENTITY_HFVLM_ENABLED`) : moteur `''` sans exécuter le modèle, défaut `.env.example` → driver `null`, test réel esquivé + test kill-switch (`HfVlmOcrEngineTest` : 6 passés, 1 esquivé) ; docs `IDENTITY-SCAN.md` + `AGENTS.md` |

**Validation** : suite complète verte le 28/09/2026 — **335 tests, 1267 assertions, 0 échec** (`php artisan test --compact`), style Pint conforme.

---

## 10. Vague hardening — 29/09/2026 (audit ciblé routes/uploads/parsers)

Audit en 3 volets (routes+autorisation, uploads+imports, XSS+mass assignment+SQL+secrets+auth+headers).
Faux positif relevé et écarté : `ScheduleImportController::preview` — le périmètre est déjà
re-vérifié au `confirmer` côté service sur le groupe du fichier (`ScheduleImportService.php:121-128`) ;
la preview ne persiste rien. Test de non-régression ajouté (`IdorSecurityTest`).

### Critique — isolation faculté (écriture cross-faculté fermée)

| Fichier | Correction |
|---|---|
| `routes/web.php` (facultés) | 6 routes `facultes.*` → `can:configuration.manage` (un `admin_faculte` ne crée/renomme/supprime plus aucune faculté ; tableau `ma-faculte` inchangé) |
| `Admin/ArchiveController::update` | `Gate::authorize('update', $dossier)` manquant ajouté (show/edit/destroy l'avaient) |
| `Admin/NoteController::store`, `InscriptionAcademiqueController::store`, `DocumentAcademiqueController::store` | `Gate::authorize('update', <parent>)` — créer = droit de modification scopé sur le parent (anti-IDOR `{inscription}`, `{dossier}`) |
| `Admin/ArchiveController::store` + `::create` | `Gate create` + `peutAccederFaculte(etudiant)` ; liste scopée par `faculteRestreinteId()` |
| `StoreDossierRequest`, `StoreNoteRequest`, `StoreInscriptionAcademiqueRequest`, `StoreDocumentAcademiqueRequest`, `UpdateDossierRequest` | `authorize()` renforcé avec périmètre faculté (échec avant validation — défense en profondeur) |
| `Policies/EtudiantPolicy::delete` | `archives.update` → `archives.delete` (mauvaise permission vérifiée) |

### Haute — fuites cross-périmètre

| Fichier | Correction |
|---|---|
| `Admin/AuditController::index` | `whereHas(etudiant.faculte_id)` si restreint (PII d'autres facultés) |
| `Admin/IntegrationController::storeExport` | filtre `faculte` forcé au périmètre si restreint (défense en profondeur) |
| `Admin/DataExchangeController::erreurs` | propriété exigée si restreint (`404` sinon, comme les imports legacy) ; `historique` scopé à ses rapports si restreint |
| `DataExchange/Definitions/GroupDefinition::persister` | refus `faculte` hors périmètre de l'auteur (anti-IDOR d'écriture via CSV) |

### Moyenne — formulaires, recherche, API, admin

| Fichier | Correction |
|---|---|
| `routes/web.php` | GET `enseignants/salles/groupes` create/edit → `can:*.manage` |
| `Admin/RechercheController` | section Étudiants (CIN, email) gatée `can:archives.view` comme les autres sections |
| `Api/V1/StudentApiController::index` | `Recherche::contient()` (jokers `%_` neutralisés, comme le web) |
| `Admin/AdministrateurController::toggleStatut` | garde dernier super-admin actif (anti-verrouillage back-office) |
| `Admin/TableauDeBordController` | `$importsRecents` scopé faculté via `schedule.groupe` |

### Uploads / parsers

| Fichier | Correction |
|---|---|
| `Services/DocumentService` | cohérence stricte extension↔MIME (`MIMES_PAR_EXTENSION`), signatures magiques (PDF/JPEG/PNG/ZIP Office/OLE), download `nomTelechargementSecurise()` + `nosniff` |
| `Services/Io/ImportOrchestrator::preparer` | contrôle MIME réel avant parse (comme `DataImportService`) |
| `Services/Io/CsvParser|JsonParser|XmlParser` | plafonds `import_max_rows` + 200 colonnes (anti-DoS mémoire) |
| `Services/Io/XlsxParser` | plafond 200 colonnes (lignes déjà plafonnées) |
| `Services/DataExchange/DataImportService::parserXml` | simple parse + `resolveExternals/substituteEntities=false` (fini le double parse) |
| `Modules/Schedule/Import/XmlScheduleImporter` | mêmes flags XXE explicites |
| `Modules/StudentDossiers/DocumentStorageService::stocker` | segments de chemin blanchis (`annee` regex `^\d{4}-\d{4}$`, catégorie alphanum) |
| `config/session.php` | défauts code `encrypt=true`, `secure=true` (secure-by-default, plus seulement via `.env.example`) |

### Tests de sécurité — `tests/Feature/Security/` (44 tests, 88 assertions)

`AuthorizationSecurityTest` (6), `IdorSecurityTest` (8), `FileUploadSecurityTest` (7),
`ImportExportSecurityTest` (8), `ApiSecurityTest` (7), `XssCsrfSecurityTest` (8) :
RBAC par rôle, IDOR A→A/B→403, uploads malveillants, plafonds parsers, XXE/SSRF,
401/403/422/404 API, throttle, LIKE, XSS stocké/réfléchi, CSRF (comparaison + structurel —
Laravel bypass le CSRF en tests HTTP par design).

**Validation 29/09/2026** : **566 tests, 2191 assertions, 0 échec, 1 skip pré-existant** ;
`composer audit` : 0 avis ; `npm audit` : 0 vulnérabilité ; `npm run build` OK ; Pint conforme.

### Résiduel honnête

- Staging d'import en session serveur (jusqu'à 5000 lignes) : saturation possible selon driver —
  migration vers staging fichier privé non faite (pattern `ImportOrchestrator` existant à généraliser).
- Temp Colab `/tmp` prévisible + PII briève : impact faible (supprimé en `finally`), non corrigé.
- CSP `unsafe-inline/eval` conservés (requis Alpine/Vite) ; `statut_badge` `{!! !!}` sûr par construction.
- `migrate:fresh --seed` non exécuté (règle projet : destructif sur BDD dev ; RefreshDatabase SQLite couvre les migrations).

---

## 11. Vague audit sécurité complet — 04/10/2026 (isolation faculté en écriture)

Audit manuel complet (routes × middleware × policies × contrôleurs × services, revue statique
guidée par la recherche + tests HTTP de reproduction) : **chaque vulnérabilité a été PROUVÉE
par un test rouge avant correction** — 18 tests rouges écrits d'abord, 19/19 verts après
correction minimale, sans aucune modification du périmètre des permissions existantes.

### Vulnérabilités prouvées et corrigées

| ID | Titre | Sévérité | Route / fichier | Preuve (avant) | Correction |
|---|---|---|---|---|---|
| SEC-001 | Création d'emploi du temps sur le groupe d'une autre faculté | HIGH | `POST /admin/emplois-du-temps` (`ScheduleController::store`) | `admin_faculte` A + `groupe_id` B → 302 + planning créé | `abort_unless(peutAccederFaculte(groupe), 404)` avant `create` |
| SEC-002 | Création d'examen sur le programme d'une autre faculté | HIGH | `POST /admin/evaluations/examens` (`ExamenController::store` + `EvaluationPolicy::create` sans contexte) | POST `programme_id` B → 302 + examen créé | Garde 404 sur programme/groupe cibles |
| SEC-003 | Épreuve injectée dans l'examen d'une autre faculté | HIGH | `POST /admin/evaluations/examens/{examen}/epreuves` (`EpreuveController::store`) | POST sur examen B → 302 + épreuve créée | Garde 404 via `Examen::faculteId()` (source unique) |
| SEC-004 | Génération d'épreuves vers un programme d'une autre faculté | HIGH | `POST /admin/evaluations/sessions/{session}/generer` (session transversale par design, cible non scopée) | POST `programme_id` B → 302 (atteint le service) | Garde 404 sur la faculté du programme cible |
| SEC-005 | Modalités d'évaluation (composantes/responsable) d'un ECUE d'une autre faculté | HIGH | `GET|PUT|PATCH|DELETE /admin/enseignements/evaluation/*` (`Enseignement/EvaluationController` : zéro contrôle hors `ue_id`) | GET edit B → 200 ; PUT/PATCH/DELETE → 302 | `abortHorsPerimetre($matiere)` via `Matiere::faculteId()` sur les 4 actions |
| SEC-006 | Listings évaluation non scopés (examens/épreuves/délibérations + compteurs du tableau de bord) | MEDIUM | 4 index (`Evaluation/*Controller`) | HTML liste « EXAM-SECRET-B » de la faculté B | Scopes `pourFaculteId` sur Examen/Epreuve/Deliberation + compteurs (`whereHas('epreuve')` pour les notes) ; sessions = transversales assumé |
| SEC-007 | Délibération créée sur le programme d'une autre faculté | MEDIUM | `POST /admin/evaluations/deliberations` | 302 + brouillon créé | Garde 404 programme/groupe cibles |
| SEC-008 | Règles d'évaluation d'une autre faculté créées/basculées | MEDIUM | `POST /admin/evaluations/regles`, `PATCH .../bascule`, `DELETE .../regles/{regle}` | 302 + règle créée / basculée | Garde 404 (store + basculer + destroy) |
| SEC-009 | Événement calendrier publié/supprimé cross-faculté | MEDIUM | `POST /admin/calendrier` + `DELETE .../{evenement}` (`CalendrierPolicy::delete` sans scope, contrairement à `update`) | DELETE événement B → supprimé ; POST `faculte_id` B → publié | Garde 404 sur cibles (faculté/programme/groupe) ; `delete` alignée sur `update` (→ 403) |
| SEC-010 | Rattachement / réinscription / progression vers le programme d'une autre faculté | MEDIUM | `PATCH /admin/etudiants/{e}/rattachement` (écriture prouvée en BD), `POST /admin/etudiants/{e}/reinscriptions` + `inscriptions/{i}/reinscrire|progresser` (cible B acceptée jusqu'à la couche métier) | 302 + écriture cross-faculté | Garde 404 sur `programme_id` cible dans les 4 contrôleurs (convention `transferer`) |
| SEC-011 | Annonce « personnel »/« enseignants »/autre faculté lisible par un étudiant par ID | MEDIUM | `GET /etudiant/annonces/{annonce}` (`MesAnnoncesController::show` : `estVisible()` seul, sans audience/faculté) | 200 étudiant A sur annonce `personnel` et sur annonce faculté B | `Annonce::visiblePourEtudiant()` (miroir du filtre d'index) → 404 |
| SEC-012 | Salle d'une autre faculté modifiée/supprimée | MEDIUM | `SalleController::update/destroy/store` | 302 + update exécuté | Garde 404 (`faculte_id` null = partagée, convention existante) ; store forcé à SA faculté, cible explicite étrangère → 404 |
| SEC-013 | Item d'une simulation d'affectation d'une autre faculté modifié | LOW | `PATCH /admin/affectations/automatique/items/{item}` (pas de `verifierScope`) | 302 + item passé à `modifie` (prouvé en BD) | `verifierScope` sur la simulation de l'item |
| SEC-014 | Lien étudiant↔document non vérifié sur le téléchargement d'un dossier GED lié à un dossier sensible | LOW (latent : permission réservée aux rôles globaux) | `SensitiveRecordController::telechargerDossier` (contrairement à `telechargerGed/telechargerDemande`) | 200/fichier servi pour le document d'un AUTRE étudiant | `abort_unless(document.dossier.etudiant_id === record.etudiant_id, 404)` |
| SEC-015 | `FinancePolicy::manage` sans périmètre sur `payer` | LOW (non prouvable : `finances.manage` absent de la matrice `admin_faculte`) | `FinanceController::payer` | — | Aucune (décision : latent documenté ; s'activera avec la matrice) |

### Points vérifiés CONFORMES (preuves par lecture + suites existantes)

- **Authentification** : login admin (message générique, guest, regenerate, logout invalide) ; login étudiant à temps constant (bcrypt fictif, `ConnexionController`) ; reset mot de passe broker `users` 60 min + throttle 5/min ; admin_faculte sans faculté refusé à la connexion et au middleware.
- **API v1** : matrice routes×scopes×throttles complète (`routes/api.php` relu ligne à ligne : aucune route authentifiée sans `scope:` ; `health`/`ready` sans PII ; webhooks HMAC + anti-replay + throttle dédié ; CIN masquée sauf scope — décision documentée ; `Idempotency-Key` sur les écritures).
- **Mass assignment** : aucun `->all()` (tous `->validated()`) ; rôle/statut/num_inscription hors fillables (P3-17).
- **Injection SQL** : tous les `selectRaw/orderByRaw` statiques (revue exhaustive), LIKE échappé (`Recherche::contient`).
- **XSS** : `{!! !!}` = `statut_badge` (enum statique) + sandbox modèles (`e()` pré-appliqué au service, testé) ; aucun `x-html/innerHTML/eval`.
- **CSRF / sessions / cookies / headers** : suites existantes + `SecurityHeadersMiddleware` (HSTS, COOP/CORP, CSP documentée) ; session `encrypt/secure/http_only/same_site=lax`.
- **Fichiers/GED/imports** : signatures MIME réelles, whitelist extensions, plafonds parsers, disque privé non servi, anti-traversal (chemins serveur), CSV neutralisé, XXE refusé, stream gated (suites `FileUploadSecurityTest`, `ImportExportSecurityTest`, `IdorSecurityTest`).
- **Concurrence** : `lockForUpdate`/`DB::transaction`/transactions atomiques sur chemins critiques (délibérations, inscriptions, corrections 4-eyes, exceptions one-shot) — suites `ReinscriptionTest`, `CentreResolutionTest`, `PeriodeInscription*Test`.
- **Modules** : tous les groupes de routes portent `auth, admin, module:xxx` (404 module inactif).
- **Dépendances** : `composer audit` 0 avis ; `npm audit` 0 vulnérabilité (04/10/2026).
- **Routes dev** : `/_boost/browser-logs` désactivé hors `local`/debug (vérifié dans `BoostServiceProvider`).
- **`audit:routes-securite`** : authentification OK sur toutes les routes admin.

### Tests ajoutés

`tests/Feature/Security/FaculteScopeEcritureTest` — **19 tests** (18 vulnérabilités prouvées
rouges → vertes + contrôles positifs : création planning propre faculté acceptée, annonce
publique lisible). Non-régression : suites Security (63), Schedule (61), Évaluation/
Délibération/Enseignement/Transverses (59), Affectations/Réinscriptions/Périodes (77),
StudentArchive (51), Inscriptions/CentreRésolution/Documents/MENS/NotesImport (60),
Archives/Espace étudiant (41), API (64), Unit (72) — toutes vertes ; Pint conforme.

### Dette assumée (documentée, non exploitable sans changement de matrice ou design transversal)

- `SessionExamen` transversal par design : `transiter`/`surveillants-auto` volontairement non
  scopés (pas de faculté sur une session) ; seule la *cible* de génération est désormais gardée.
- `EpreuveController::verifier` (sonde de conflits) : révèle l'existence d'épreuves hors
  périmètre — faible, conservé (usage planificationnel inter-facultés légitime).
- `FinancePolicy::manage` : scope latéral inatteignable aujourd'hui (`finances.manage` absent
  des rôles restreints) — à durcir si la matrice évolue.
- Énumération temporelle du login admin (bcrypt non exécuté si compte inexistant côté
  `Auth::attempt`) : mitigée par le message générique + throttle 5/min — résiduel accepté.
