# 🔐 Sécurité — Mesures, contrôles et risques résiduels

> Ce document n'affirme **pas** que l'application est « 100 % sécurisée ».
> Il dresse la liste des protections implémentées, des contrôles qui les
> vérifient, des risques résiduels et des configurations serveur requises.
>
> 📋 **Évaluation indépendante** : voir `docs/AUDIT-SECURITE.md` — audit complet
> (24-25/09/2026) : confirmation des mesures ci-dessous, constats nouveaux
> (P0/P1 corrigés : seeders verrouillés, expiration des tokens, second facteur
> CIN+date, HSTS, RBAC destroy, purge des imports, limite XLSX, `/tmp` propre)
> et plan de remédiation P2/P3 restant ; **vague 04/10/2026 (§11)** : isolation
> faculté en **écriture** prouvée et corrigée (15 constats, 19 tests).

---

## 1. Protections implémentées

| Domaine | Protection | Contrôle / test |
|---|---|---|
| **Authentication** | Sessions étudiant (email/CIN + bcrypt, régénération d'ID) et personnel scolarité (guard `web`, table `users`, rôle RBAC) ; messages d'erreur génériques anti-énumération | `EspaceEtudiantTest`, `ArchiveAuthorizationTest::connexion_echouee_message_generique` |
| **Authorization** | 5 rôles × 15 permissions (Gates) + 5 Policies ; isolation par faculté pour `admin_faculte` (policies + scoping) ; invités redirigés (`redirectGuestsTo`) ; étudiant toujours résolu depuis sa session (jamais depuis un ID client) | `ArchiveAuthorizationTest::matrice_des_permissions`, `test_session_etudiant_n_atteint_jamais_le_back_office`, `SeparationProfilsTest` |
| **IDOR / BOLA** | Aucune route étudiant paramétrée par identifiant libre ; téléchargement étudiant vérifie l'appartenance (404 silencieux) ; admin soumis aux policies par ressource | `DocumentAcademiqueTest::etudiant_ne_peut_telecharger_que_ses_documents`, smoke test HTTP (doc d'autrui → 404) |
| **Isolation par faculté (écritures, 04/10/2026)** | Cible scopée avant toute écriture (scope → 404) : planning (groupe), examen/épreuve/délibération (programme/groupe), génération d'épreuves, modalités ECUE, seuils de service, enseignement (ECUE), règles d'évaluation, événements calendrier (create/update/delete cohérents), salles (salle globale = partagée), rattachement/réinscription/progression (programme cible), item de simulation d'affectation, téléchargement lié à un dossier sensible ; annonces étudiant filtrées par audience+faculté au show | `FaculteScopeEcritureTest` (19 tests — prouvés rouges avant correction) |
| **CSRF** | Jetons CSRF sur tous les formulaires ; vérifié : POST sans token → **419** | Smoke test HTTP (§ CSRF) — note : auto-désactivé dans PHPUnit (`runningUnitTests`), d'où la vérification HTTP réelle |
| **Rate limiting** | Inscription 10/min · connexion étudiant 5/min · connexion admin 5/min · consultation dossier 60/min · mutations 10-30/min · upload/download 10-20/min | `ArchiveSecurityTest::rate_limiting_sur_la_connexion_admin` (429), smoke HTTP (302×4 → 429) |
| **XSS** | Échappement Blade systématique (`{{ }}`) ; aucune donnée utilisateur en `{!! !!}` ; CSP en complément | Revue de code + CSP |
| **SQL Injection** | Eloquent / Query Builder paramétrés exclusivement ; aucune concaténation SQL de données utilisateur | Revue de code |
| **Mass assignment** | `$fillable` sur tous les modèles ; `role` (User), `statut`/`num_inscription` (Etudiant) NON mass-assignables — attribution exclusive via `User::definirRole()` / `User::creerCompteBackOffice()` / `Etudiant::definirStatut()` (validées) ; écritures via `$request->validated()` ; champs critiques (coefficient, crédits, etudiant_id) **dérivés serveur** | `GradeTest::mass_assignment_les_champs_critiques_sont_ignores`, `AcademicRegistrationTest::moyennes_ne_sont_pas_modifiables_directement`, rôle injecté → ignoré (défaut `consultation`) |
| **Session fixation** | `regenerate()` à chaque connexion (étudiant + scolarité) **et après chaque changement de mot de passe** ; invalidation complète à la déconnexion | Code review + tests de déconnexion |
| **Mots de passe** | `Password::defaults()` : min 10 caractères, lettres + chiffres, `uncompromised()` (haveibeenpwned) hors tests ; messages FR dédiés ; hints des formulaires alignés | `InscriptionEtudiantsTest::test_student_registration_fails_with_weak_or_mismatched_password` (frontière 9/10) |
| **Énumération temporelle** | Login étudiant à temps constant : bcrypt (fictif mais valide) exécuté même si le compte n'existe pas ; message générique unique | `EspaceEtudiantTest`, `ArchiveAuthorizationTest::connexion_echouee_message_generique` |
| **Recherches LIKE** | `App\Support\Recherche::contient()` : `%`, `_`, `\` neutralisés (addcslashes) sur les recherches admin (registre + archives) | `RechercheTest`, `ArchiveTest::test_recherche_neutralise_le_joker_like_pourcent` |
| **Privilege escalation** | Rôle en colonne `enum` (4 valeurs) ; matrice permissions en classe finale immuable côté code ; aucun endpoint de modification de rôle exposé | `Permissions::ROLES` |
| **File upload** | Stockage privé hors `public/` ; UUID interne ; double validation extension + MIME réel + taille 10 Mo ; liste blanche stricte (pdf/jpg/png/doc/docx/xls/xlsx/txt) ; SHA-256 | `DocumentAcademiqueTest` (php refusé, 12 Mo refusé) |
| **Path traversal** | Chemins construits uniquement côté serveur (id + uuid) ; le `chemin` est `$hidden` (jamais exposé) ; téléchargement via `Storage::download` (pas d'URL directe) | Revue + `test_upload_valide` |
| **Security headers** | X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy + COOP/CORP (`same-origin`) + **CSP** (`default-src 'self'`, `object-src 'none'`, `frame-ancestors 'self'`…) ; `X-XSS-Protection` obsolète supprimé ; `target="_blank"` avec `rel="noopener noreferrer"` ; **HSTS** émis sur connexion chiffrée | `ArchiveSecurityTest::les_headers_de_securite_sont_presents`, `InscriptionEtudiantsTest::test_security_headers_are_present` |
| **Audit logging** | Toute action sensible tracée (acteur, IP, UA, ancienne/nouvelle valeur) ; journal en écriture seule ; jamais de secrets | `AuditLogTest` (dont `le_journal_ne_contient_jamais_de_secrets`) |
| **Concurrence / Race conditions** | `DB::transaction` sur toutes les opérations multi-tables ; `lockForUpdate` sur les notes (verrouillage pessimiste), transitions et corrections workflowisées ; unicités SQL en dernier rempart | `GradeTest` (unicité par session), `AcademicRegistrationTest` (double inscription) |
| **Notes critiques** | `locked_at` : un agent ne peut plus modifier ; seuls `super_admin`/`admin_scolarite` corrigent ou verrouillent ; motif de correction journalisé | `GradeTest::note_verrouillee_immodifiable_par_un_agent`, `test_admin_verrouille_puis_deverrouille` |
| **Minimisation des données** | CIN masquée dans les listes de recherche (`BK***345`) ; journal consultable par rôles autorisés uniquement | `ArchiveTest::registre_liste_les_dossiers_avec_cin_masquee` |
| **Pré-remplissage par CIN** | Endpoint dédié POST (CIN jamais en URL) + CSRF + `throttle:10,1` ; **second facteur obligatoire** (date de naissance exacte, réponse identique sinon) ; DTO whitelist de 13 champs — jamais password/hash/remember_token/statut ; validation regex stricte anti-injection ; anti-doublon métier ; aucune écriture lors de la vérification | `CinPrefillTest` (23+4 tests) |
| **Scan d'identité (CIN/passeport)** | Upload temporaire privé supprimé après OCR (minimisation) ; MIME réel + anti-polyglot + path traversal refusés ; OCR interchangeable, jamais une preuve d'identité (validation humaine obligatoire) ; MRZ ICAO checksums ; BD fail-closed (n° + date extraite) ; jamais password/hash/contenu OCR dans les réponses ni les logs | `IdentityScanTest` (23) + `IdentityParsersTest` (18) |
| **OCR arabe (CIN tunisienne)** | Noms propres **translittérés** (jamais traduits) ; normalisation Unicode/arabe + chiffres arabo-indiques ; originaux arabes conservés en réponse (arabe-only : aucune charge latine ne survit) ; comparaison BD par translittération normalisée (tolérance OCR bornée) ; texte OCR non fiable : JSON strict, `x-text` (jamais `{!! !!}`), requêtes paramétrées | `ArabicIdentityTest` (27) + `ArabicCinScanTest` (11) |
| **Error handling** | Erreurs 404 pour ressources inexistantes (jamais 500) ; invités redirigés sans fuite d'URL ; APP_DEBUG=false requis en production (cf. §3) | `ArchiveSecurityTest::ids_falsifies_dans_les_routes_sont_rejetes` |
| **Open Redirect** | Aucune redirection basée sur des paramètres clients | Revue de code |
| **HTTP Parameter Pollution** | Validation stricte des paramètres (listes blanches, `whereNumber`) | Tests API |
| **CSV Injection / Formula Injection** | Écrits CSV/XLSX neutralisés : valeurs débutant par `= + - @` préfixées d'une apostrophe — **tous canaux** y compris l'export CSV du dossier (`CsvSerializer::neutraliserFormule`) | `ApiExportTest::export_csv_profil_administratif…`, `ArchiveTest::test_export_csv_neutralise_les_formules` |
| **Replay attacks (webhooks)** | X-Request-ID unique 24 h (cache) + X-Timestamp (fenêtre ±300 s) ; **HMAC vérifié AVANT consommation anti-replay** (une signature invalide ne brûle jamais un request-id) ; format request-id borné (`^[A-Za-z0-9_-]{1,64}$`, sinon UUID serveur) | `ApiWebhookTest::rejeu_du_meme_request_id_refuse`, `::test_signature_invalide_ne_brule_pas_le_request_id`, `WebhookVerifierTest` (3) |
| **Webhook forgery** | HMAC-SHA256 obligatoire du corps brut — jamais d'URL nue acceptée | `ApiWebhookTest::signature_invalide_refusee` |
| **XXE** | DTD/entités rejetées avant parsing, `LIBXML_NONET`, aucune substitution d'entité | `ApiImportTest::xml_avec_attaque_xxe_refuse` |
| **Oversized payload / formats malformés** | 10 Mo max (FormRequest + service), JSON/XML invalides → 422 sans détail technique | `ApiImportTest` |
| **Pagination abuse** | `per_page` borné à 100 ; paramètres numériques uniquement | `ApiAuthenticationTest` |
| **Excessive data exposure** | API Resources à surface contrôlée, profils d'export restreints, `chemin` masqué, CIN absente des listes | `ApiStudentsTest`, `ApiExportTest` |
| **Token abuse** | Un token par intégration, scopes moindre privilège (modèles d'import exigent `imports:create`), révocation immédiate ; rôle `--role` validé (`super_admin` interdit aux comptes techniques, conflit de rôle → refus) ; préfixe `uniregistre` (secret scanning) | `ApiAuthenticationTest::token_revoque_est_immediatement_invalide`, `IntegrationTokenCreateTest` (4), `ImportTemplateTest::test_api_modeles_refuses_sans_scope_imports_create` |
| **Scopes API** | 403 générique (`error: insufficient_scope`, sans divulgation des scopes) | `ApiAuthenticationTest` |
| **Disque privé** | `serve => false` : aucune route framework `/storage/{path}` — téléchargements exclusivement via contrôleurs gated | Revue (`Storage::url` absent du disque privé) |
| **Concurrence import** | Transition atomique `pending → processing` (`WHERE status='pending'`, 0 ligne = déjà traitée) | `ApiImportTest` |
| **CIN en API** | **Décision documentée comme acceptée** : le CIN complet est la clé de réconciliation des SI externes (scope `students:read` restreint) ; les listes web, elles, masquent (`cin_masquee`) | `ApiStudentsTest`, `ArchiveTest::registre_liste_les_dossiers_avec_cin_masquee` |
| **Ownership rapports** | Prévisualisation ET confirmation web d'un rapport réservées à son propriétaire (404 silencieux, comme `erreurs()` et l'API) | `IntegrationPreviewTest` (3) |

---

## 2. Tests de sécurité automatisés

- **CSRF** : vérifié par smoke test HTTP (419) — PHPUnit court avec CSRF auto-désactivé.
- **Rate limiting** : 429 après dépassement (test unitaire + HTTP).
- **IDOR** : étudiant A → document B = 404 ; admin invité → redirection.
- **Isolation faculté en écriture** : `tests/Feature/Security/FaculteScopeEcritureTest.php` (19) — création/modification/suppression cross-faculté refusées (404/403) sur plannings, examens, épreuves, délibérations, règles, calendrier, salles, rattachement, réinscription, progression, items de simulation, téléchargement lié sensible ; listings évaluation filtrés ; annonces étudiant par audience.
- **Mass assignment** : injection `coefficient`/`credits`/`etudiant_id` ignorée ; `role` ignoré.
- **Upload** : `.php` refusé, fichier > 10 Mo refusé, stockage privé vérifié.
- **RBAC** : matrice rôle × capacité (200/302 vs 403) + effet de bord interdit.
- **Journal** : aucune trace de secret ; ancienne/nouvelle note tracées.
- **Headers** : CSP et consorts présents sur les réponses.

---

## 3. Risques résiduels & configuration serveur requise

| Risque | Statut | Recommandation |
|---|---|---|
| `script-src 'unsafe-inline' 'unsafe-eval'` dans la CSP | Nécessaire pour le moteur d'expressions Alpine.js et les styles inline | En production durcie : compiler Alpine en build CSP (`@alpinejs/csp`) et externaliser les styles ; documenté dans `SecurityHeadersMiddleware` |
| Contraintes numériques (0 ≤ note ≤ 20) arbitrées par la validation, pas par des CHECK SQL | Compatibilité SQLite (tests) | Optionnel : ajouter les CHECK en production MySQL via migration dédiée |
| Mots de passe de démonstration seedés | Attendu en développement | **Obligatoire** en production : rotation par compte + politique de complexité + idéalement 2FA |
| Absence de HTTPS dans l'environnement local | Développement | Production : TLS obligatoire (HSTS, redirection 301), cookies `secure`, `trusted proxies` à configurer |
| Login admin : énumération temporelle résiduelle (hash non calculé si compte inconnu) | Mitigé (message générique + throttle 5/min ; login étudiant à temps constant) | Accepté ; optionnel : hash factice côté `Auth::attempt` si exigé |
| `SessionExamen` transversale (sans faculté) : transitions/attribution de surveillants accessibles à tout `admin_faculte` habilité | Design assumé ; la génération d'épreuves est, elle, scopée sur le programme cible (04/10/2026) | Scoper par année/programme si les sessions deviennent départementales |
| `FinancePolicy::manage` non scopée | Latent : `finances.manage` absent des rôles restreints (04/10/2026) | Ajouter le scope à `manage($objet)` si la matrice évolue |
| Sonde de conflits d'épreuves (`epreuves/verifier`) sans périmètre | Faible (révèle l'existence de créneaux occupés inter-facultés) | Borner si usage inter-facultés non désiré |
| Pas de réinitialisation de mot de passe (scolarité) auto-servie | Flux admin dédié existant (réinitialisation par un gestionnaire) | Prévoir notification email ciblée |
| Rate limiting par IP (session unique partagée possible) | Standard Laravel | Ajuster les seuils (`throttle:x,1`) selon la taille réelle des cyber-salles |
| Verrouillage des notes journalisé mais l'export CSV ne contient pas le journal | Par design (journal réservé) | Prévoir un export d'audit séparé pour les inspecteurs |
| Prévisualisation d'import (`import_reports.preview`) : PII conservées 168 h (même rétention que les imports, purge `exports:purge`) | Documenté et assumé (phase de validation humaine) | Réduire la rétention via `INTEGRATION_IMPORT_RETENTION_HOURS` si exigé |
| Rate limiting API par IP/token — un client légitime pressé peut atteindre 60/min | Standard Laravel | Ajuster `INTEGRATION_THROTTLE_*` selon les systèmes réels |
| Webhook secret partagé unique (`INTEGRATION_WEBHOOK_SECRET`) | Un secret pour tous les émetteurs actuels | Prévoir un secret **par émetteur** (table `webhook_endpoints`) si plusieurs systèmes externes |
| Sauvegardes | À la charge de l'exploitant | `mysqldump` quotidien + sauvegarde de `storage/app/private/archives` (cf. ARCHIVES.md §9) — données sensibles : chiffrement au repos recommandé |
| Sessions longues | `SESSION_LIFETIME=120` | Ajuster selon la politique ; ajouter `idle_timeout` applicatif si exigé |

---

## 4. Réponse aux incidents (procédure suggérée)

1. **Détecter** : surveiller `historique_academique` (consultations anormales,
   rafales de téléchargements) et les logs Laravel (`storage/logs`).
2. **Contenir** : désactiver le compte suspect (reset du rôle), resserrer le
   rate limiting, faire tourner `APP_KEY` si compromission de session sérieuse.
3. **Investiguer** : le journal d'audit fournit acteur, IP, horodatage, anciennes
   et nouvelles valeurs — base de reconstitution fiable.
4. **Restaurer** : réimporter la dernière sauvegarde cohérente ; l'intégrité des
   documents se vérifie par hash SHA-256 (`hash_fichier` / `sha256`).
5. **Retour d'expérience** : consigner dans ce document toute mesure ajoutée.
