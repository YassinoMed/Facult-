# LOOP-SECURITY-REMEDIATION-01 — Remédiation des vulnérabilités de sécurité

> Boucle : audit → plan → correction → tests → diff → re-validation PoC → rapport.
> Méthode d'audit : **white-box + PoC live** sur l'application servie localement
> (`php artisan serve`), sans Strix (Docker éteint, pas de clé LLM, cloud non
> connecté) — chaque constat est étayé par du code lu ET, quand le vecteur
> s'y prête, par une requête HTTP réelle avant/après.
>
> Contraintes respectées : aucun `migrate:fresh`/`db:wipe`, aucune suppression
> de données, aucune campagne ni e-mail de masse réel, aucune protection
> désactivée pour faire passer un test. Toutes les migrations sont additives.

Verdict : **PASS_WITH_RESERVATIONS** — voir §6 (réserves) et §7 (verdict).

---

## 1. Synthèse

| # | Vulnérabilité | Lot | Correctif | Preuve | Statut |
|---|---|---|---|---|---|
| V1 | Réinitialisation de mot de passe admin cassée (`Route [password.reset] not defined` → 500) | 1 | `ResetPassword::createUrlUsing()` → route existante | PoC live 500 → 302 + notification testée | ✅ Corrigé |
| V2 | Empoisonnement d'URL par `Host` forgé (aucun TrustHosts) | 1 | Middleware `EnsureTrustedHost` (400) + origine canonique | PoC live `Host: evil.test` → 400 | ✅ Corrigé |
| V3 | MFA non obligatoire pour les rôles à privilèges | 2 | `MfaPolicy` + middleware `mfa.obligatoire` + enrôlement forcé | Tests MFA + parcours de connexion | ✅ Corrigé |
| V4 | Cycle de vie MFA non sûr (enrôlement/désactivation sans mot de passe, aucun code de secours) | 2 | Mot de passe exigé, désactivation interdite pour rôles obligatoires, codes de secours hachés | `MfaLoopJTest` 10/10 | ✅ Corrigé |
| V5 | Campagnes/groupes/destinataires sans périmètre faculté (IDOR inter-facultés) | 3 | `CampagnePerimetre` + services scopés + policies + Livewire 404 | `CampagnesPerimetreFaculteTest` 12/12 | ✅ Corrigé |
| V6 | CSV/formula injection dans les exports ad hoc | 4 | `CsvSerializer::neutraliserLigne()` sur tous les `fputcsv` applicatifs | `ExportCsvNeutralisationTest` 3/3 | ✅ Corrigé |
| V7 | Seeders dangereux (comptes à mots de passe connus, exécutables en prod) | 5 | `DemoData::autorisee()` + `ADMIN_INITIAL_PASSWORD` | `SeedersSecurisesTest` 8/8 | ✅ Corrigé |
| V8 | Session non régénérée après vérification OTP (session fixation) | 6 | `session()->regenerate()` après élévation | `ProfilEtIdentiteSecurisesTest` (test discriminant) | ✅ Corrigé |
| V9 | E-mail et date de naissance modifiables sans preuve depuis les espaces personnels | 6 | E-mail étudiant verrouillé (parcours vérifié), date de naissance admin-only, e-mail enseignant protégé par mot de passe | `ProfilEtIdentiteSecurisesTest` 7/7 | ✅ Corrigé |
| V10 | Sondes `/api/v1/ready` et `/api/v1/version` publiques trop bavardes | 7 | Réponse publique réduite à `status`, détail derrière `SUPERVISION_ALLOWED_IPS` | PoC live avant/après | ✅ Corrigé |
| V11 | `X-Powered-By` exposé + CSP permissive | 7 | Masquage de `X-Powered-By`, CSP durcie | PoC live avant/après | ✅ Corrigé |
| — | Exigences de production non documentées | 8 | `docs/engineering/LOOP-SECURITY-PRODUCTION.md` | Checklist vérifiable | ✅ Livré |

---

## 2. Détail par vulnérabilité

### V1 — Le lien de réinitialisation de mot de passe admin provoquait une 500

**Preuve initiale (PoC live, avant correctif).** `POST /admin/mot-de-passe/lien`
avec un e-mail existant provoquait une erreur serveur ; trace applicative :
`RouteNotFoundException: Route [password.reset] not defined`. L'application
n'expose pas les routes `password.*` : la notification Laravel
`ResetPassword` construisait par défaut une URL vers une route inexistante.
Conséquence : réinitialisation impossible **et** 500 sur un endpoint public.

**Correctif.** `AppServiceProvider::boot()` enregistre un générateur d'URL :
`ResetPassword::createUrlUsing()` → `route('admin.mot-de-passe.reinitialiser',
['token' => …, 'email' => …])`. Le lien pointe désormais sur la page de
réinitialisation réellement existante.

**Fichiers.** `app/Providers/AppServiceProvider.php`.

**Tests.** `tests/Feature/Security/OrigineEtResetMotDePasseTest.php` (2 tests
dédiés : route existante + origine canonique), `IndustrialisationTest`
(broker existant), `RevocationSessionsTest`.

**Résultat.** PoC live après correctif : `POST /admin/mot-de-passe/lien` →
`HTTP 302` (retour au formulaire, message neutre), jeton créé, e-mail émis.
Test automatisé : l'URL contient
`/admin/mot-de-passe/reinitialiser/<token>?email=…`. ✅

**Risques résiduels.** Aucun sur ce point. (Note d'exploitation : lors du PoC
live, l'e-mail a été réellement émis via le SMTP configuré dans le `.env` de
dev — cf. §12, exigence « `MAIL_MAILER=log` en dev/staging ».)

### V2 — Empoisonnement des URL absolues par en-tête `Host` forgé

**Preuve initiale.** `curl -H 'Host: evil.test' http://127.0.0.1:8000/` :
la requête était servie normalement et les URL absolues générées (formulaires,
liens) utilisaient `http://evil.test/...` — vecteur d'empoisonnement du lien de
réinitialisation (V1) et des liens de notification.

**Correctif.**
- `config/security.php` : liste `trusted_hosts` = `TRUSTED_HOSTS` (CSV, motifs
  `*.domaine` pour les sous-domaines) + hôte de `APP_URL` + boucle locale.
- `app/Http/Middleware/EnsureTrustedHost.php` : ajouté en **global** (toujours
  actif, y compris en local/test) ; tout autre hôte → `400` avant génération
  d'URL ; si `security.force_canonical_url` (défaut : vrai en production),
  `URL::forceRootUrl()`/`forceScheme()` sur `APP_URL`.
- `bootstrap/app.php` : `$middleware->append(EnsureTrustedHost::class)` **et**
  `trustHosts(at: motifs)` en défense en profondeur.
- `.env.example` : `TRUSTED_HOSTS`, `FORCE_CANONICAL_URL` documentés.

**Tests.** `OrigineEtResetMotDePasseTest` (5 tests : hôte forgé → 400, boucle
locale → 200, wildcard sous-domaines sans apex, suffixe trompeur
`universite.tn.evil.com` → 400, apex listé → 200) ; test d'origine canonique
forcée : le lien de réinitialisation est bien construit sur `APP_URL` même
quand la requête arrive sur un autre hôte.

**Résultat.** PoC live : `Host: evil.test` → `HTTP 400` ; `Host: 127.0.0.1` →
`HTTP 200`. ✅

**Risques résiduels.** L'hôte `localhost` est toujours autorisé (utile en
dev) ; en production, définir une liste `TRUSTED_HOSTS` stricte et vérifier la
configuration du reverse-proxy (`TRUSTED_PROXIES`).

### V3 — MFA non obligatoire pour les rôles à privilèges

**Preuve initiale (white-box).** La MFA existait mais n'était jamais
**imposée** : un compte `super_admin`/`admin_scolarite`/`admin_faculte` se
connectait avec le seul mot de passe et accédait à tout le back-office sans
défi ni enrôlement.

**Correctif.**
- `config/security.php` : `mfa_required_roles` (défaut
  `super_admin,admin_scolarite,admin_faculte`, extensible par le paramètre
  applicatif existant `securite.mfa_obligatoire_admin`).
- `app/Support/MfaPolicy.php` : politique unique `exigePour(User)`.
- `app/Http/Middleware/EnsureMfa.php` (alias `mfa.obligatoire`) : un compte
  soumis à l'obligation sans MFA active est forcé vers l'enrôlement avant tout
  accès ; détection via le marqueur de session `bo_auth_password` posé
  uniquement par le flux de connexion (`AuthController`) / défi réussi, plus
  un flag d'enrôlement obligatoire. Routes exemptées : `admin.mfa.*`,
  déconnexion.
- `bootstrap/app.php` : alias + middleware posé sur le groupe back-office
  (`routes/web.php`) **et** sur `/livewire/update` (AppServiceProvider) pour
  couvrir les requêtes Livewire.
- `AuthController` : défi MFA dans le parcours de connexion.

**Tests.** `MfaLoopJTest` — 10 tests (obligation par rôle, défi exigé,
enrôlement forcé, accès Livewire couvert, extension par paramètre, sessions
non marquées non bloquées).

**Résultat.** 10/10 verts ; sous-ensemble sécurité connexe
(`SecuritePhaseFTest|DurcissementTransversalTest|SeparationProfilsTest|
VerrouillageSecondaireTest|AuditLogTest|ArchiveAuthorizationTest|ArchiveSecurityTest`)
= 89/89 verts après adaptation de 2 tests qui se connectaient avec un rôle
devenu soumis à MFA (passés au rôle non privilégié `agent_scolarite`,
intention d'origine préservée). ✅

**Risques résiduels.** Les sessions back-office **créées avant le
déploiement** ne portent pas le marqueur `bo_auth_password` et ne déclenchent
donc pas l'obligation : procédure de purge des sessions consignée dans
`LOOP-SECURITY-PRODUCTION.md` §4 (inefficacité limitée dans le temps —
première reconnexion).

### V4 — Cycle de vie MFA non sûr (enrôlement/désactivation/codes de secours)

**Preuve initiale (white-box).** L'enrôlement et la désactivation de la MFA
ne demandaient pas le mot de passe courant ; un porteur de session pouvait
désactiver la MFA ; aucun code de secours n'existait (risque de verrouillage) ;
la régénération de secret ne révoquait pas d'éventuels anciens codes.

**Correctif.**
- `MfaController` : enrôlement et activation protégés par mot de passe ;
  désactivation **refusée** pour les rôles à obligation ; régénération du
  secret.
- `app/Services/MfaRecoveryCodeService.php` : codes de secours générés à
  l'activation, **hachés (HMAC)** et stockés dans la colonne chiffrée
  `mfa_recovery_codes` (`encrypted:array`, masquée via `$hidden`) ;
  consommation à usage unique ; régénération protégée par mot de passe
  (`admin.mfa.codes-secours`) qui révoque immédiatement les anciens codes.
- Migration additive `database/migrations/2026_10_17_100000_ajouter_codes_secours_mfa_users.php`.
- `AdministrateurController` : la réinitialisation d'un compte purge ses codes
  de secours.
- Vue `resources/views/admin/mfa/configurer.blade.php` mise à jour.

**Tests.** `MfaLoopJTest` (codes de secours : génération, hachage, usage
unique, régénération, désactivation interdite pour rôle obligatoire,
enrôlement avec mauvais mot de passe refusé).

**Résultat.** 10/10 verts. ✅

**Risques résiduels.** La politique de conservation/rotation des codes de
secours relève de l'exploitation (documentée dans `LOOP-SECURITY-PRODUCTION.md`).

### V5 — Campagnes, groupes et destinataires sans périmètre faculté

**Preuve initiale (white-box).** Les écrans et services de campagne ne
filtraient pas par faculté : un `admin_faculte` pouvait lister/composer des
campagnes d'autres facultés, résoudre des destinataires hors périmètre
(étudiants, enseignants, groupes) et agir sur des campagnes ou groupes
étrangers (IDOR).

**Correctif (en profondeur, 4 couches).**
1. `app/Support/CampagnePerimetre.php` : marqueurs de périmètre
   (`filtres['faculte_id']` pour les campagnes, `criteres['faculte_id']` pour
   les groupes) ; helpers `faculteAutorisee`/`campagneAutorisee`/
   `groupeAutorise`/`marquerFaculte`. Une campagne sans marqueur est GLOBALE
   (invisible pour un `admin_faculte`).
2. Services : `ResolutionDestinatairesService`
   (`requeteEtudiants`/`requeteEnseignants`/`resoudre`/`resoudreGroupe` avec
   `faculteForcee`, refus hors périmètre, listes fixes filtrées) et
   `CampagneCourrielService` (tout le cycle de vie : brouillon, figer,
   planifier, envoyer, annuler, relancer → hors périmètre =
   `DomainException`/`InvalidArgumentException`).
3. Policies : `CampagneCourrielPolicy`, `GroupeDestinatairesPolicy`
   (view/update/envoyer/annuler/relancer/delete).
4. Livewire : `AssistantCompositionCampagne`, `GestionGroupesDestinataires`,
   `TableauDeBordCampagnes`, `SuiviCampagneCourriel` — 404 sur accès hors
   périmètre, faculté forcée dans les filtres, garde anti-falsification dans
   les sauvegardes (jamais de confiance au `faculte_id` client).

**Tests.** `tests/Feature/Security/CampagnesPerimetreFaculteTest.php` — 12
tests / 45 assertions : liste filtrée, compteurs scopés, composition
inter-facultés refusée (404), résolution de destinataires forcée, groupe hors
périmètre refusé, envoi/annulation/relance d'une campagne étrangère refusés,
marquage automatique de la faculté sur les créations. Suites existantes
adaptées : `CampagneCourrielTest` 5/5, `CampagnesLivewireTest` 4/4.

**Résultat.** 12/12 + 9/9 verts. ✅

**Risques résiduels.** Les campagnes historiques sans marqueur faculté sont
traitées comme globales (donc non visibles par les comptes faculté) : si des
campagnes facultaires historiques n'ont pas de marqueur, un administrateur
global devra les réétiqueter.

### V6 — CSV / formula injection dans les exports ad hoc

**Preuve initiale (white-box).** Plusieurs exports écrivaient des valeurs
pilotées par l'utilisateur (noms, messages d'erreur, libellés) via `fputcsv`
sans neutralisation : une cellule commençant par `=`, `+`, `-`, `@`
(tabulation/retour chariot) s'exécute comme formule à l'ouverture dans
Excel/LibreOffice (exfiltration possible via `=HYPERLINK(...)`, appel de
commandes via DDE sur postes non patchés).

**Correctif.** Neutralisation **centralisée** dans `CsvSerializer` :
- `neutraliserFormule()` (existant, préservé) ;
- nouveau `neutraliserLigne(array $cellules)` appliqué à tous les `fputcsv`
  des contrôleurs d'export : `AuditController`, `StatistiquesController`,
  `BiController`, `DataExchangeController` (rapport d'erreurs),
  `AffectationController`, `AutomaticAssignmentController`,
  `MesPresencesController`, `EmploiSurveillanceController`,
  `MesSurveillancesController`, `NoteEpreuveController`, `MesNotesController`,
  `SurveillanceController`. (`ArchiveController`, `AppelController`,
  `PresenceController`, `ImportTemplateService`, `XlsxSerializer` étaient
  déjà couverts — vérifié ligne à ligne.)

**Tests.** `tests/Feature/Security/ExportCsvNeutralisationTest.php` — 3 tests /
10 assertions : neutralisation unitaire des préfixes dangereux (+ idempotence),
export du journal d'audit (nom d'utilisateur `=cmd|' /C calc'!A0` neutralisé),
rapport d'erreurs d'import (valeur `=HYPERLINK(...)` neutralisée).

**Résultat.** 3/3 verts ; inventaire final : plus aucun `fputcsv` non
neutralisé dans `app/` (hors implémentation de `CsvSerializer` lui-même). ✅

**Risques résiduels.** Tout nouvel export doit passer par
`neutraliserLigne()` ; le rôle de garde est documenté
(`LOOP-SECURITY-PRODUCTION.md` §8) et couvert par un test qui peut être étendu.

### V7 — Seeders dangereux exécutables en production

**Preuve initiale (white-box).** `AdminSeeder` créait — sans aucune garde
d'environnement — deux comptes super-admin avec mots de passe connus
(`admin@universite.ac.ma` / `Admin@2026` et `admin@admin.com` / `admin123`).
Les seeders de démonstration (`FsegsDatasetSeeder`, `NotesDemoSeeder`,
`VolumeSeeder` : comptes `Etudiant@123`) ne s'exécutaient qu'hors production,
`ArchiveSeeder` refusait hors local/testing — mais **aucun mécanisme d'opt-in
explicite** n'existait pour un staging de démonstration, et `AdminSeeder`
restait la faille principale.

**Correctif.**
- `app/Support/DemoData.php` : `autorisee()` = local/testing **ou**
  `SEED_DEMO_DATA=true` (config `security.seed_demo_data`) ; `verrou()` pour
  interrompre un seeder interdit avec un message explicite.
- `AdminSeeder` : hors démo, `ADMIN_INITIAL_PASSWORD` **obligatoire**
  (≥ 12 caractères, refuse les mots de passe faibles) ; le compte de
  démonstration secondaire n'est jamais créé hors démo ; avertissement
  explicite quand l'opt-in est utilisé hors local/testing.
- `DatabaseSeeder`, `ArchiveSeeder`, `FsegsDatasetSeeder`, `NotesDemoSeeder`,
  `VolumeSeeder`, `ScheduleSeeder` : garde unifiée `DemoData::autorisee()`.
- `.env.example` : `SEED_DEMO_DATA=false`, `ADMIN_INITIAL_PASSWORD` documentés.

**Tests.** `tests/Feature/Security/SeedersSecurisesTest.php` — 8 tests / 17
assertions : démo refusée hors local/testing sans opt-in, activée par opt-in,
`ArchiveSeeder` refuse en production, `DatabaseSeeder` ne crée ni étudiants ni
comptes de démo en production (facultés de référence conservées),
`AdminSeeder` exige puis valide `ADMIN_INITIAL_PASSWORD` (refus si court),
compte super-admin créé avec le mot de passe fourni et **aucun** compte
`admin@admin.com`, comportement de démo conservé en local.

**Résultat.** 8/8 verts. ✅

**Risques résiduels.** `SEED_DEMO_DATA=true` sur un staging : assumé et
explicitement averti (les mots de passe restent connus).

### V8 — Session non régénérée après vérification OTP (session fixation)

**Preuve initiale (white-box).** Dans le parcours public « dossier existant »
(recherche CIN + naissance → code à usage unique), la vérification du code
plaçait un jeton d'autorisation en session **sans régénérer** l'identifiant de
session : un attaquant pouvait fixer une session, faire vérifier la victime,
puis réutiliser l'identifiant pour accéder au dossier et déclencher un
changement d'e-mail.

**Correctif.** `app/Http/Controllers/DossierExistantController.php` :
`session()->regenerate()` à chaque **élévation** — vérification OTP réussie
(avant pose du jeton, données de session conservées), émission du jeton
`email_change` autonome, et hygiène après changement d'identifiant (e-mail
modifié via le parcours double-code, confirmation autonome).

**Tests.** `tests/Feature/Security/ProfilEtIdentiteSecurisesTest.php` —
`test_verification_otp_dossier_existant_regenere_la_session`. Le test est
**discriminant** : exécuté une fois avec la régénération neutralisée, il
échoue (« Failed asserting that two strings are not identical » sur le jeton
de session) ; restauré, il passe. Il vérifie aussi que l'autorisation survit
(régénération ≠ perte de session).

**Résultat.** Test vert après restauration. ✅

**Risques résiduels.** Aucun identifié sur ce vecteur.

### V9 — E-mail et date de naissance modifiables sans preuve

**Preuve initiale (white-box).**
- Espace étudiant : `ProfilEtudiantRequest` validait `email` **et**
  `date_naissance` ; `ProfilController::update` appliquait
  `$etudiant->update($request->validated())`. Un porteur de session pouvait
  donc changer l'**identifiant de connexion** (e-mail) sans vérification —
  détournement de compte, puis réinitialisation de mot de passe sur la
  nouvelle adresse — et modifier la **date de naissance**, qui est un facteur
  d'identité du parcours de récupération « dossier existant » (CIN + date).
- Espace enseignant : changement d'e-mail (identifiant de connexion) sans
  aucune preuve de possession du compte.

**Correctif.**
- Étudiant : `date_naissance` retirée des règles et des messages ;
  `ProfilController::update` **refuse** tout e-mail différent de l'adresse
  enregistrée (message explicite orientant vers le parcours vérifié à double
  code déjà existant : code à l'ancienne PUIS à la nouvelle adresse) ; liste
  blanche explicite de colonnes (`safe()->only([...])`) ; formulaire mis à
  jour (e-mail en lecture seule + lien vers le parcours, champ date de
  naissance retiré).
- Enseignant : `ProfilEnseignantRequest` exige le **mot de passe actuel**
  (vérifié bcrypt) dès que l'e-mail change ; liste blanche de colonnes ;
  régénération de session après changement d'identifiant ; champ dédié ajouté
  au formulaire.
- Le parcours « corrections de dossier » (admin-demandé) reste inchangé : il
  n'autorise que les champs explicitement réclamés par la scolarité.

**Tests.** `ProfilEtIdentiteSecurisesTest` — 7 tests / 33 assertions :
e-mail étudiant refusé (erreur + base inchangée), coordonnées modifiables sans
toucher à l'identité, `date_naissance` injectée ignorée ; côté enseignant :
e-mail refusé sans mot de passe, refusé avec mauvais mot de passe, accepté
avec le bon, coordonnées modifiables sans mot de passe si l'e-mail ne change
pas ; plus le test de régénération OTP (V8).

**Résultat.** 7/7 verts. ✅

**Risques résiduels.** L'enseignant doit connaître son mot de passe pour
changer d'e-mail (pas de parcours double-code côté enseignants : contact
scolarité en cas d'oubli) — assumé, documenté.

### V10 — Sondes de supervision publiques trop bavardes

**Preuve initiale (PoC live, avant correctif).**

```
GET /api/v1/ready
{"status":"not_ready","sondes":[
  {"nom":"Base de données","ok":true,"detail":"inscription_etudiants"},
  {"nom":"Cache","ok":true,"detail":"database"},
  {"nom":"File d'attente","ok":true,"detail":"3 en attente, 0 échec(s)"},
  {"nom":"Stockage privé","ok":true,"detail":"storage/app/private"},
  {"nom":"Disque","ok":false,"detail":"92 % utilisé"}]}

GET /api/v1/version
{"status":"ok","app_version":"1.0.0","db_version":"1.0.0",
 "build":"2026.10.17.001","environnement":"local","timestamp":"…"}
```

Nom de base, pilote de cache, chemin de stockage, occupation disque,
environnement et versions internes : cartographie d'infrastructure offerte
sans authentification.

**Correctif.** `app/Support/SupervisionAccess.php` (allow-list
`security.supervision_allowed_ips` ← `SUPERVISION_ALLOWED_IPS`) ;
`ReadyController` et `VersioningApiController::version()` ne renvoient
publiquement que `status` + `timestamp` (l'état HTTP 200/503 reste intact pour
les load-balancers) ; le détail n'est servi qu'aux IP déclarées. Les endpoints
détaillés du module versioning restent protégés par Sanctum + scope.

**Tests.** `tests/Feature/Security/SondesEtEntetesSecuriteTest.php` — 6 tests
dédiés aux sondes : public sans détail (et sans nom de base ni chemin), détail
pour IP autorisée, IP hors liste traitée comme publique, version publique
limitée à `status`/`timestamp`, version détaillée pour IP autorisée, logique
d'allow-list (IP vide/inconnue refusée).

**Résultat.** PoC live **après** :

```
GET /api/v1/ready   → {"status":"not_ready","timestamp":"2026-10-17T20:13:32+00:00"}
GET /api/v1/version → {"status":"ok","timestamp":"2026-10-17T20:13:32+00:00"}
```

✅ (les IP de supervision internes conservent le détail).

**Risques résiduels.** Un load-balancer qui exigeait le détail devra être
ajouté à `SUPERVISION_ALLOWED_IPS` (ou vérifier que `status` suffit — c'est le
cas pour un healthcheck classique).

### V11 — `X-Powered-By` (version PHP) et CSP permissive

**Preuve initiale (PoC live, avant correctif).**

```
X-Powered-By: PHP/8.5.11
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval';
 style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; …; base-uri 'self'; … frame-ancestors 'self'
```

Fingerprinting trivial du serveur (version exacte de PHP) sur **toutes** les
réponses, y compris les API.

**Correctif.** `app/Http/Middleware/SecurityHeadersMiddleware.php` :
- suppression de `X-Powered-By` (`headers->remove()` + `header_remove()`,
  double sécurité avec l'exigence `expose_php=Off`/`server_tokens off`) ;
- CSP durcie : `base-uri 'none'` (aucun `<base>` injectable — l'application
  n'en utilise pas), `media-src 'none'` (aucun média embarqué),
  `upgrade-insecure-requests` ajouté **uniquement** en HTTPS ;
- nouvel en-tête `X-Permitted-Cross-Domain-Policies: none`.
- `'unsafe-eval'`/`'unsafe-inline'` sont **conservés** : exigés par Alpine.js
  (embarqué par Livewire 3) et les styles inline Tailwind — les retirer
  casserait l'interface ; le chemin de durcissement (build Alpine CSP) est
  documenté dans `LOOP-SECURITY-PRODUCTION.md` §3.

**Tests.** `SondesEtEntetesSecuriteTest` — en-têtes sans `X-Powered-By`, CSP
contenant `base-uri 'none'`/`media-src 'none'`/`object-src 'none'` et sans
`upgrade-insecure-requests` en HTTP ; `upgrade-insecure-requests` présent en
HTTPS ; `X-Permitted-Cross-Domain-Policies: none`.

**Résultat.** PoC live après : plus aucun `X-Powered-By` sur les réponses
(curl) ; CSP servie :

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval';
 style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:;
 img-src 'self' data:; media-src 'none'; connect-src 'self'; object-src 'none';
 base-uri 'none'; form-action 'self'; frame-ancestors 'self'
X-Permitted-Cross-Domain-Policies: none
```

✅

**Risques résiduels.** `'unsafe-eval'` subsiste tant que la stack Alpine/
Livewire n'est pas reconstruite en mode CSP — risque XSS résiduel **couvé par
l'échappement Blade** et suivi hors boucle.

### Livrable 8 — Documentation des exigences de production

`docs/engineering/LOOP-SECURITY-PRODUCTION.md` : 10 sections de checklist
(environnement/secrets, hôtes & origine canonique, transport/en-têtes, MFA &
purge de sessions, sondes, comptes & seeders, messagerie & files d'attente,
exports CSV, périmètre faculté, checklist de mise en service) — chaque
exigence est vérifiable par une commande ou un test.

---

## 3. Inventaire des fichiers de la boucle

**Créés** — `config/security.php` ; `app/Support/{MfaPolicy,CampagnePerimetre,
DemoData,SupervisionAccess}.php` ; `app/Http/Middleware/{EnsureTrustedHost,
EnsureMfa}.php` ; `app/Services/MfaRecoveryCodeService.php` ; migration
additive `2026_10_17_100000_ajouter_codes_secours_mfa_users.php` ;
`tests/Feature/Security/{CampagnesPerimetreFaculteTest,ExportCsvNeutralisationTest,
OrigineEtResetMotDePasseTest,ProfilEtIdentiteSecurisesTest,SeedersSecurisesTest,
SondesEtEntetesSecuriteTest}.php` ; `docs/engineering/LOOP-SECURITY-PRODUCTION.md` ;
ce rapport.

**Modifiés** — `bootstrap/app.php` ; `app/Providers/AppServiceProvider.php` ;
`app/Models/User.php` ; `app/Http/Controllers/Admin/{AuthController,
MfaController,AdministrateurController,AuditController,StatistiquesController,
BiController,DataExchangeController,AffectationController,
AutomaticAssignmentController}.php` ; `app/Http/Controllers/Api/V1/ReadyController.php` ;
`app/Http/Controllers/DossierExistantController.php` ;
`app/Http/Controllers/Etudiant/ProfilController.php` ;
`app/Http/Controllers/Enseignant/ProfilController.php` ;
`app/Http/Requests/{ProfilEtudiantRequest,ProfilEnseignantRequest}.php` ;
`app/Http/Middleware/SecurityHeadersMiddleware.php` ;
`app/Modules/Versioning/Controllers/Api/VersioningApiController.php` ;
`app/Modules/Attendance/Controllers/MesPresencesController.php` ;
`app/Modules/Evaluation/Controllers/{EmploiSurveillanceController,
MesSurveillancesController,NoteEpreuveController,MesNotesController,
SurveillanceController}.php` ; `app/Services/{CampagneCourrielService,
ResolutionDestinatairesService,Io/CsvSerializer}.php` ;
`app/Policies/{CampagneCourrielPolicy,GroupeDestinatairesPolicy}.php` ;
`app/Livewire/Admin/Campagnes/{AssistantCompositionCampagne,
GestionGroupesDestinataires,TableauDeBordCampagnes,SuiviCampagneCourriel}.php` ;
`routes/web.php` ; `resources/views/admin/mfa/configurer.blade.php` ;
`resources/views/etudiant/modifier.blade.php` ;
`resources/views/enseignant/modifier.blade.php` ;
`database/seeders/{DatabaseSeeder,ArchiveSeeder,AdminSeeder,FsegsDatasetSeeder,
NotesDemoSeeder,VolumeSeeder,ScheduleSeeder}.php` ; `.env.example` ;
`tests/Feature/MfaLoopJTest.php` ; `tests/Feature/ArchiveAuthorizationTest.php` ;
et tests existants adaptés aux contrats durcis (§5.2) :
`tests/Feature/{EspaceEtudiantTest,EnseignantPortailTest,EspaceEnseignantTest,
QualiteApiSupervisionTest,VersioningTest}.php`.

> L'arbre de travail contient par ailleurs des travaux **parallèles de
> l'utilisateur** (DataMesh, Scrutins, SMTP, vues…) qui ne font pas partie de
> cette boucle et n'ont pas été touchés.

## 4. Commandes exécutées (extraits)

```bash
# Tests ciblés de la boucle
php artisan test --compact --filter="MfaLoopJTest"                    # 10/10
php artisan test --compact --filter="CampagnesPerimetreFaculteTest"   # 12/12
php artisan test --compact --filter="ExportCsvNeutralisationTest"     # 3/3
php artisan test --compact --filter="SeedersSecurisesTest"            # 8/8
php artisan test --compact --filter="SondesEtEntetesSecuriteTest"     # 8/8
php artisan test --compact --filter="ProfilEtIdentiteSecurisesTest"   # 7/7
php artisan test --compact --filter="OrigineEtResetMotDePasseTest"    # 7/7
php artisan test --compact --filter="SecuritePhaseFTest|DurcissementTransversalTest|SeparationProfilsTest|VerrouillageSecondaireTest|AuditLogTest|ArchiveAuthorizationTest|ArchiveSecurityTest"  # 89/89
php artisan test --compact --filter="CampagneCourrielTest|CampagnesLivewireTest"  # 9/9

# Suite complète (voir §5)

# Preuves live (avant/après) — serveur local
curl -s http://127.0.0.1:8000/api/v1/ready
curl -s http://127.0.0.1:8000/api/v1/version
curl -s -o /dev/null -D - http://127.0.0.1:8000/api/v1/ready
curl -s -o /dev/null -w "%{http_code}" -H 'Host: evil.test' http://127.0.0.1:8000/
curl -s -b /tmp/cj.txt -X POST http://127.0.0.1:8000/admin/mot-de-passe/lien -d "…"
```

## 5. Bilan des tests

### 5.1 Tests ciblés par correctif (nouveaux)

| Périmètre | Résultat |
|---|---|
| Tests ciblés par correctif (6 fichiers, hors MFA) | 45/45 verts |
| `MfaLoopJTest` (MFA : obligation, enrôlement, codes de secours) | 10/10 verts |
| Sous-ensemble sécurité connexe (Lot A) | 89/89 verts |
| Campagnes (existant + périmètre faculté) | 21/21 verts |

### 5.2 Tests existants adaptés (contrats intentionnellement durcis)

Cinq tests existants verrouillaient les anciens comportements corrigés par la
boucle ; ils ont été adaptés **sans désactiver aucune protection** :

| Test | Ancienne attente | Adaptation |
|---|---|---|
| `EspaceEtudiantTest::test_mise_a_jour_des_coordonnees_reussie` | changement d'e-mail + date de naissance acceptés | e-mail inchangé (verrouillé) ; la protection est prouvée par `ProfilEtIdentiteSecurisesTest` |
| `EnseignantPortailTest::test_profil_affiche_donnees_et_met_a_jour_coordonnees` | e-mail changé sans mot de passe | `current_password` ajouté (nouvelle exigence) |
| `EspaceEnseignantTest::test_profil_modifie_coordonnees_autorisees_uniquement` | idem | idem |
| `QualiteApiSupervisionTest::test_readiness_sans_pii` | sondes détaillées publiques | état public (`status`, `timestamp`) + absence de fuite ; détail couvert par `SondesEtEntetesSecuriteTest` |
| `VersioningTest::test_api_v1_version_public_accessible_sans_token` | versions publiques | état public + absence des clés sensibles |

Ces tests vérifiaient le contrat public QUE la boucle durcit ; leurs intentions
d'origine (mise à jour des coordonnées, absence de PII, accessibilité publique)
restent vérifiées — sur le nouveau contrat.

### 5.3 Suite complète et attribution des échecs

Première suite complète : **3 542 tests — 3 503 verts, 17 échecs, 22 ignorés**
(16 868 assertions). Attribution vérifiée par **baseline réelle** : worktree
détaché sur la révision de départ (`907bf0c`) avec autoloader Composer
régénéré localement, puis exécution des mêmes 17 tests.

- **12 échecs préexistent à la révision de départ** (hors périmètre de la
  boucle) :
  - budgets N+1 : `InscriptionFileTraitementTest` (25 < 25),
    `InscriptionPerformanceFiabiliteTest` (25 < 25),
    `ArchivesFicheLoop3Test` (25 < 25), `PopulationPhase2CompleteTest`
    (23 vs ≤ 21) ;
  - accessibilité : `AccessibiliteParcoursTest` ×2 (source Blade sans
    `aria-invalid`) ;
  - saisie d'identité : `SaisieIdentificationStricteTest` ×4 (messages
    attendus absents du code source) ;
  - parcours : `FaqAideScolariteTest`, `AffectationEnseignantExamenTest`.

  Contre-vérification : retrait temporaire du middleware `mfa.obligatoire` →
  compteur de requêtes **inchangé** (25) sur `ArchivesFicheLoop3Test` ; la
  boucle n'ajoute aucune requête sur ces pages.
- **5 échecs causés par la boucle** — exactement les 5 tests aux contrats
  durcis, tous adaptés (§5.2) et **verts après adaptation**.

Contre-vérification du périmètre de la boucle après adaptation :
**60/60 verts** (55 tests de la boucle — MFA 10, périmètre campagnes 12, CSV 3,
origine/reset 7, profil/identité 7, seeders 8, sondes/entêtes 8 — + les 5 tests
adaptés).

Ré-exécutions complètes suivantes : une **session parallèle modifiait l'arbre de
travail pendant les exécutions** (preuves `stat` : `.env` à 21:44:44,
`app/Notifications/*` à 21:49, `app/Services/DossierExistantService.php` à
21:50:13, `app/Http/Controllers/DossierExistantController.php` à 21:50:26 puis
21:59:01 — les 4 `session()->regenerate()` de la boucle y sont vérifiés
intacts —, `tests/Feature/*` à 21:49–21:56, `bootstrap/cache/{packages,services}
.php` régénéré à 21:57:23 **pendant** l'exécution). Conséquence : les compteurs
fluctuent d'une exécution à l'autre sans lien avec la boucle.

- 2ᵉ exécution : 3 502 verts / 18 échecs = 12 préexistants + 6 liés à ces
  modifications concurrentes ou transitoires :
  - `DossierExistantParcoursTest` ×2 — `ConfirmationMiseAJourDossierExistant`
    modifiée en cours de route (attentes `ShouldQueue` / notification non
    envoyée) ;
  - `EnseignantInterfaceTest::test_fiche_requetes_bornees_sans_n_plus_un`
    (16 vs ≤ 15) — attribution détaillée à la 3ᵉ exécution (restriction locale
    du `.env`) ;
  - `MfaLoopJTest` ×3 — **transitoires** : `MfaLoopJTest.php` modifié à
    21:56:29 et `bootstrap/cache` régénéré à 21:57:23 pendant l'exécution ;
    ces 3 tests passent **10/10 en isolation** sur l'état actuel.

- 3ᵉ exécution (arbre vérifié stable pendant l'exécution — seuls les caches
  `bootstrap/cache/*` régénérés par le boot) : **3 546 tests — 3 511 verts,
  13 échecs, 22 ignorés** (16 922 assertions) :
  - les **12 échecs préexistants** ci-dessus (compteurs N+1 légèrement
    dégradés par les travaux concurrents — 25 → 26 pour la file, 23 → 24
    pour le dashboard — mais **déjà rouges à HEAD**) ;
  - `EnseignantInterfaceTest::test_fiche_requetes_bornees_sans_n_plus_un`
    (16 vs ≤ 15) : **dépend de la restriction locale du `.env`**, pas de la
    boucle. Preuve croisée : (a) le test **passe à HEAD** (baseline worktree,
    15 requêtes) ; (b) avec la politique livrée, il **passe aussi sur la
    révision actuelle** (re-vérifié en surchargeant `MFA_REQUIRED_ROLES` sur
    la même révision) ; (c) en trace SQL, la seule requête ajoutée est le
    repli `select * from parametres where cle =
    'securite.mfa_obligatoire_admin'`, déclenché parce que le rôle du test
    n'est plus dans la liste locale (`super_admin` seul).
  - Les échecs MFA ×3 et notifications ×2 de la 2ᵉ exécution sont **résolus**
    (MFA 10/10 en isolation ; `DossierExistantParcoursTest` vert une fois les
    travaux concurrents stabilisés).

**Conclusion des trois exécutions : aucun échec n'est imputable à la boucle**
— les 12 échecs préexistants + 1 échec sensible au `.env` local restant sont
tous attribués avec preuves croisées.

### 5.4 Formatage

`vendor/bin/pint` global échoue à cause d'une erreur de parse **préexistante**
(`app/Http/Livewire/Admin/Scrutins/Builder.php:172`, travail utilisateur
parallèle) — le formatage a donc été vérifié fichier par fichier sur tous les
fichiers de la boucle (`{"tool":"pint","result":"passed"}`).

## 6. Réserves et suivis hors boucle

1. **Purge des sessions avant mise en service** (V3) : les sessions
   back-office antérieures au déploiement ne déclenchent pas l'obligation MFA
   — procédure obligatoire au déploiement (documentée).
2. **`unsafe-eval`/`unsafe-inline` en CSP** (V11) : requis par Alpine/Livewire ;
   durcissement complet = build Alpine CSP, hors de cette boucle.
3. **E-mail enseignant** (V9) : pas de parcours double-code côté enseignants
   (mot de passe requis ; contact scolarité en cas d'oubli).
4. **Campagnes historiques sans marqueur faculté** (V5) : traitées comme
   globales → invisibles pour les comptes faculté ; réétiquetage possible.
5. **SMTP réel configuré dans le `.env` de dev** : le PoC V1 a émis un e-mail
   réel (`accepted_by_smtp`) et créé un jeton de réinitialisation pour
   `admin.fst@universite.ac.ma` (expiration automatique : 60 min). Exigence
   consignée : `MAIL_MAILER=log`/Mailpit en dev/staging.
6. **`database/seeders/ArchiveSeeder.php`** : conserve des mots de passe de
   démonstration (contexte `DemoData::autorisee()` uniquement) — jamais en
   production réelle.
7. **Pint global** : bloqué par un parse error préexistant hors périmètre
   (travail utilisateur) ; à reprendre quand ce fichier sera corrigé.
8. **12 tests rouges préexistants à la révision de départ** (§5.3) : budgets de
   requêtes N+1 (4), accessibilité formulaire (2), saisie d'identité/date (4),
   FAQ (1), affectation (1). Démontré par baseline — hors périmètre de la
   boucle, à traiter avec les travaux en cours (dont le parse error de
   `Scrutins/Builder.php` qui bloque aussi Pint global).
9. **Session parallèle active pendant la boucle** : l'arbre de travail a été
   modifié par une autre session pendant les exécutions de tests (§5.3) — les
   compteurs de la suite complète fluctuent en conséquence (notifications
   `DossierExistant*`, +1 requête ponctuelle, 3 tests MFA transitoires). Le
   périmètre de la boucle reste vérifié indépendamment : 60/60 verts, MFA
   10/10 en isolation. Relancer la suite complète une fois les travaux
   parallèles stabilisés.
10. **Politique MFA locale restreinte pendant la boucle** : le `.env` de
   développement (`MFA_REQUIRED_ROLES=super_admin`, modification concurrente
   horodatée 21:44:44) et le `setUp` de `MfaLoopJTest` (épinglé à
   `['super_admin']`, horodaté 21:56:29) restreignent la politique locale à
   `super_admin`. Les **défauts livrés restent les trois rôles**
   (`config/security.php` + `.env.example`). Impact mesuré :
   `EnseignantInterfaceTest::test_fiche_requetes_bornees_sans_n_plus_un`
   devient rouge (16 vs ≤ 15) car le repli paramètre de `MfaPolicy` s'exécute
   pour un rôle hors liste — **avec la politique livrée, le test passe**
   (vérifié sur la même révision). Recommandation : réaligner
   `MFA_REQUIRED_ROLES` du `.env` de dev sur la valeur livrée (trois rôles),
   ou assumer la restriction locale et ajuster le budget du test.

## 7. Verdict

**PASS_WITH_RESERVATIONS.** Les 11 vulnérabilités de l'audit sont corrigées
avec preuves (PoC live avant/après pour V1, V2, V10, V11 ; tests discriminants
pour V5, V8 ; tests dédiés pour V3, V4, V6, V7, V9), les 8 livrables sont
fournis, et les réserves (§6) sont toutes documentées avec une procédure ou un
suivi — aucune ne rouvre une vulnérabilité exploitable en l'état sur une
production configurée selon `LOOP-SECURITY-PRODUCTION.md`.

État des tests : périmètre de la boucle **60/60 verts** ; dernière suite
complète sur arbre stable : **3 546 tests — 3 511 verts, 13 échecs**,
intégralement attribués (12 préexistants à la révision de départ + 1 sensible
à la restriction locale du `.env`, vert avec la politique livrée) — **aucun
échec imputable à la boucle** (§5.3).
