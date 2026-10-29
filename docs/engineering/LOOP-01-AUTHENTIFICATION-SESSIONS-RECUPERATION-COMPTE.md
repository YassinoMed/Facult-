# LOOP-01 — AUTHENTIFICATION, SESSIONS ET RÉCUPÉRATION DE COMPTE

**Date d'exécution :** 26/10/2026
**Périmètre :** connexion étudiant/enseignant, limitation des tentatives, fixation et révocation de session, mot de passe oublié, jeton de réinitialisation, en-têtes des pages qui portent le jeton.
**Mode :** audit statique + tests ciblés sur SQLite `:memory:` ; aucune migration, aucun commit, aucun push, aucune donnée réelle modifiée.
**Verdict :** `PASS_WITH_RESERVATIONS`

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture des contrôleurs, services, modèles, middlewares, routes, configuration de session et tests existants ; recherche des chemins de changement de mot de passe ; exécution de tests ciblés avant et après correctif ; non-régression par suites voisines.
- **Environnement :** PHP 8.5.11, Laravel 13.33.0, PHPUnit 12.5.35, SQLite `:memory:` via `phpunit.xml` (garde de `tests/TestCase.php`), cache/session `array`, queue `sync`, mail `array`.
- **Distinction permanente :** un contrôle *lu dans le code* n'est pas un contrôle *exécuté* ; un test ciblé vert ne vaut pas validation de la suite complète. Les éléments non exécutés sont marqués `NOT_EXECUTED` et ne sont jamais comptés comme PASS.
- **Limites :** suite complète non exécutée (4 948 entrées listées par `phpunit --list-tests`), tests MySQL opt-in skippés, aucun parcours navigateur/E2E, aucune vérification sur un environnement de production.
- **Hors périmètre apparu pendant la clôture :** un fichier de tests `tests/Feature/Security/Loop02AutorisationAntiIdorTest.php` (non suivi, apparu après les runs LOOP-01) échoue sur 7 erreurs de fixtures au moment de la clôture. Il est traité séparément au §6.3 et n'est pas une régression LOOP-01.

---

## 1. État initial et périmètre

### 1.1 État Git de référence

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` |
| Entrées `git status --porcelain` (avant ajout de ce rapport) | 804 (367 `M`, 435 `??`, 2 `A`) |
| `git diff --stat` | 367 fichiers, +20 054 / −3 141 |
| `git diff --cached --stat` | 2 fichiers, +4 |
| Commit / push pendant LOOP-01 | aucun |

L'arbre de travail contenait déjà des modifications de loops antérieures. Les hunks attribués à LOOP-01 sont limités aux cinq fichiers du §5 ; certains de ces fichiers portent aussi des changements antérieurs, et le reste du diff n'est pas revendiqué par ce lot.

### 1.2 Surfaces auditées

| Surface | Fichier principal |
|---|---|
| Connexion étudiant | `app/Http/Controllers/Etudiant/ConnexionController.php` |
| Connexion enseignant | `app/Http/Controllers/Enseignant/ConnexionController.php` |
| Mot de passe oublié / reset | `app/Http/Controllers/Auth/PortailMotDePasseController.php`, `app/Services/PortalPasswordResetService.php` |
| Jeton de reset | `app/Models/PortalPasswordResetToken.php`, migration `2026_10_21_200000_creer_tokens_reinitialisation_portails.php` |
| Sessions étudiant/enseignant | `EnsureStudent`, `EnsureTeacher`, `config/session.php` |
| Changement de mot de passe | `Etudiant/ProfilController`, `Enseignant/ProfilController`, `Admin/EtudiantController::reinitialiserMotDePasse` |
| En-têtes | `app/Http/Middleware/SecurityHeadersMiddleware.php` |
| Routes | `routes/web.php` (étudiant : 276-299 ; enseignant : 394-417 ; reset admin : 910) |
| Tests | `tests/Feature/Security/Loop01AuthentificationSessionsTest.php` + suites voisines |

### 1.3 Base de tests initiale

- Fichier LOOP-01 existant avant extension : **13 tests, 77 assertions, PASS** (2 290 ms).
- Première base ciblée élargie : **58 tests, 216 assertions, PASS** (2 965 ms).

---

## 2. Contrôles confirmés

| # | Contrôle | Preuve | État |
|---|---|---|---|
| C1 | Connexion par email ou CIN, normalisation casse | `ConnexionController.php:33-35` | Confirmé |
| C2 | Message d'erreur générique anti-énumération | `ConnexionController.php:47-50` | Confirmé |
| C3 | Temps constant : bcrypt factice si compte absent ou sans mot de passe | `ConnexionController.php:40-45` | Confirmé |
| C4 | Limitation de connexion 5/min par IP | `routes/web.php:279-281` (étudiant), `:397-399` (enseignant) | Confirmé par test 429 |
| C5 | Régénération d'ID de session à la connexion (anti-fixation) | `ConnexionController.php:54` | Confirmé par test |
| C6 | Déconnexion : `invalidate()` + `regenerateToken()` | `ConnexionController.php:74-75` | Confirmé par test |
| C7 | Jeton de reset : 64 caractères aléatoires, seul le SHA-256 est stocké | `PortalPasswordResetService.php:54-66` | Confirmé |
| C8 | Expiration 60 min + usage unique + un seul jeton actif par compte | `PortalPasswordResetService.php:55,59-66,107-114` | Confirmé par tests |
| C9 | Verrou de ligne + comparaison `hash_equals` | `PortalPasswordResetService.php:102-109` | Confirmé |
| C10 | Réponse générique à la demande, quel que soit le compte | `PortailMotDePasseController.php:36-38` | Confirmé par test |
| C11 | Limite par email 3 demandes / 10 min + throttle HTTP 5/min par IP | `PortalPasswordResetService.php:31-46`, `routes/web.php:289-292,296-299` | Confirmé |
| C12 | Changement de mot de passe : mot de passe actuel vérifié, minimum 10 caractères + lettres + chiffres + `uncompromised` (hors testing), max 64, confirmation, différent de l'actuel | `MotDePasseEtudiantRequest.php:24-44`, `MotDePasseEnseignantRequest.php:24-44`, `AppServiceProvider.php:175-177` | Confirmé |
| C13 | Révocation des sessions par `mot_de_passe_version` à chaque requête | `EnsureStudent.php:39`, `EnsureTeacher.php:33-35` | Confirmé par tests |
| C14 | Reset administratif : autorisation `update`, confirmation explicite, mot de passe fort généré, affichage unique, audit sans PII | `Admin/EtudiantController.php:440-472` | Confirmé par tests |
| C15 | Cookies : `http_only`, `same_site=lax`, `secure` par défaut | `config/session.php:172,185,202`, `.env.example:34,38` | Confirmé statiquement |
| C16 | Pages de reset : `Referrer-Policy: no-referrer` + `Cache-Control: no-store` | `SecurityHeadersMiddleware.php:40-54` | Confirmé par test (après correctif D2) |
| C17 | Jeton émis avant un changement de mot de passe neutralisé | `PortalPasswordResetToken.php:42-49`, `Etudiant.php:94-97`, `Teacher.php:78-81` | Confirmé par tests (après correctif D1) |

**Point de configuration à vérifier avant production :** le `.env` local contient `SESSION_ENCRYPT=false` et `SESSION_SECURE_COOKIE=false` (pratique locale). Les valeurs par défaut de `config/session.php` et `.env.example` sont `true` ; la vérification de l'environnement de production reste `NOT_EXECUTED`.

---

## 3. Défauts prouvés

### D1 — Un jeton de réinitialisation émis avant un changement de mot de passe restait consommable

- **Gravité :** élevée (prise de contrôle de compte dans la fenêtre de 60 minutes, si l'attaquant détient l'ancien lien ou la boîte email).
- **Scénario :** un lien de reset est émis ; la victime change ensuite son mot de passe (profil) ou la scolarité le réinitialise ; le lien ancien reste accepté et permet de fixer un nouveau mot de passe.
- **Cause :** le service ne consultait que `used_at` et `expires_at`. Aucun mécanisme ne neutralisait les jetons actifs lors d'une rotation de mot de passe hors du parcours de reset.
- **Preuve avant correctif :** 3 échecs ciblés au total (2 pour D1, 1 pour D2) sur 17 tests (14 PASS, 3 FAIL, 93 assertions, 2 731 ms) :
  - `test_08_jeton_emis_avant_changement_de_mot_de_passe_par_le_profil_est_refuse` — le reset tardif réussit et aucune erreur de session n'est présente ;
  - `test_08_jeton_emis_avant_reinitialisation_administrative_est_refuse` — même comportement après reset administratif ;
  - les deux échecs D1 portent sur l'absence d'erreur de session ; l'assertion sur le mot de passe final n'est pas atteinte.
- **Correctif :** `PortalPasswordResetToken::invaliderActifs()` + événements `updated` sur `Etudiant` et `Teacher` (§5.1). Couvre le profil étudiant, le profil enseignant, le reset administratif et le service de récupération.

### D2 — Les pages de réinitialisation n'interdisaient ni le Referer complet ni la mise en cache

- **Gravité :** moyenne (le jeton circule dans le chemin de l'URL).
- **Scénario :** la page `/…/mot-de-passe/reinitialiser/{token}` renvoyait la politique globale `strict-origin-when-cross-origin`. Une requête same-origin peut transmettre l'URL complète, donc le jeton, via `Referer` (journaux, ressources, analytics). Le middleware ne posait aucun `Cache-Control: no-store` : le lien pouvait rester dans le cache ou l'historique d'un poste partagé.
- **Preuve avant correctif :** `test_09_pages_de_reinitialisation_interdisent_referer_et_cache` échouait avec `strict-origin-when-cross-origin` au lieu de `no-referrer`.
- **Correctif :** en-têtes ciblés sur les quatre routes de reset étudiant/enseignant, sans changer la politique des autres pages (§5.2).

---

## 4. Critères d'acceptation

| # | Critère | Résultat |
|---|---|---|
| CA1 | Un jeton émis avant un changement de mot de passe (profil étudiant, profil enseignant, reset administratif) est refusé ; le mot de passe courant reste inchangé | **PASS** — 3 tests |
| CA2 | Jeton inconnu, expiré, déjà utilisé ou associé à un autre email refusé avec un message générique | **PASS** — tests dédiés |
| CA3 | Les pages de reset renvoient `Referrer-Policy: no-referrer` et `Cache-Control: no-store` ; les autres pages conservent `strict-origin-when-cross-origin` | **PASS** — test dédié |
| CA4 | Connexion limitée à 5/min, erreur générique, régénération de session | **PASS** — tests dédiés |
| CA5 | Déconnexion : session invalidée, ID et jeton CSRF régénérés | **PASS** — test dédié |
| CA6 | Changement de mot de passe : sessions concurrentes invalidées, session courante conservée | **PASS** — tests étudiant/enseignant/admin |
| CA7 | Aucune régression sur les suites ciblées d'authentification, de sécurité et de portails | **PASS partiel** — 181/181 et 35/35 ; suite complète `NOT_EXECUTED` |
| CA8 | Aucun secret, mot de passe, jeton ou PII dans les rapports et journaux applicatifs | **PASS** — inspection statique + tests sans journalisation de PII |
| CA9 | Aucune migration, aucun changement de route, aucune dépendance, aucun commit/push | **PASS** — état Git vérifié |

Le caractère partiel de CA7 et les limites du §6.3 justifient le verdict `PASS_WITH_RESERVATIONS`.

---

## 5. Correctifs appliqués

### 5.1 D1 — invalidation centralisée des jetons à la rotation du mot de passe

| Fichier | Changement |
|---|---|
| `app/Models/PortalPasswordResetToken.php:42-49` | Nouvelle méthode `invaliderActifs(string $guard, string $email): int` : renseigne `used_at` sur tous les jetons encore actifs du couple guard/email. La ligne est conservée pour garder la trace de l'usage unique. |
| `app/Models/Etudiant.php:94-97` | Événement `updated` : si `password` a changé, invalidation des jetons `etudiant` de l'email. |
| `app/Modules/Schedule/Models/Teacher.php:78-81` | Événement `updated` symétrique pour le guard `enseignant`. |

Propriétés préservées :

- un seul jeton actif par `(guard, email)` (contrainte unique en base) ;
- expiration 60 min et usage unique inchangés ;
- le reset administratif et le profil passent tous deux par `update(['password' => …])`, donc par l'événement ;
- aucun nouveau chemin de contournement : le service de reset consomme le jeton avant de changer le mot de passe.

### 5.2 D2 — en-têtes ciblés sur les pages qui portent le jeton

`app/Http/Middleware/SecurityHeadersMiddleware.php:40-54` :

- détection des quatre routes `etudiant.mot-de-passe.reinitialiser`, `etudiant.mot-de-passe.enregistrer`, `enseignant.mot-de-passe.reinitialiser`, `enseignant.mot-de-passe.enregistrer` ;
- `Referrer-Policy: no-referrer` sur ces routes, `strict-origin-when-cross-origin` ailleurs ;
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` sur ces routes uniquement.

Aucune route, aucun schéma, aucune migration, aucune dépendance modifiée.

### 5.3 Tests ajoutés/étendus

`tests/Feature/Security/Loop01AuthentificationSessionsTest.php` (18 tests, 115 assertions) couvre :

1. connexion valide email/CIN, invalide, compte sans mot de passe ;
2. rate limiting 429 à la 6e tentative ;
3. régénération de session après authentification ;
4. invalidation des sessions concurrentes après changement de mot de passe (profil et reset administratif) ;
5. demande générique pour email inconnu ; jeton valide puis rejoué ; jeton invalide ; jeton expiré ; jeton d'un email utilisé pour un autre ;
6. déconnexion : session, ID et jeton CSRF ;
7. dix routes protégées inaccessibles sans authentification ;
8. jeton émis avant changement de mot de passe : profil étudiant, reset administratif, profil enseignant ;
9. en-têtes `no-referrer` + `no-store` sur les pages de reset étudiant/enseignant.

---

## 6. Preuves d'exécution

### 6.1 Avant correctif

```text
php artisan test --compact tests/Feature/Security/Loop01AuthentificationSessionsTest.php
→ 17 tests, 14 passed, 3 failed, 93 assertions, 2 731 ms
```

Les 3 échecs sont ceux du §3 (deux sur le jeton, un sur les en-têtes).

### 6.2 Après correctif

```text
php artisan test --compact tests/Feature/Security/Loop01AuthentificationSessionsTest.php
→ 18 tests, 18 passed, 115 assertions, 2 236 ms — PASS
```

```text
php artisan test --compact --filter="EspaceEtudiantTest|PortailMotDePasseTest|RevocationSessionsTest|RevocationSessionsEnseignantTest|SecuritePhaseFTest|ProfilEtIdentiteSecurisesTest|OrigineEtResetMotDePasseTest|DurcissementTransversalTest|Loop01AuthentificationSessionsTest|SondesEtEntetesSecuriteTest|AccessibilitePortailsTest|AdminModificationEtudiantTest|EtudiantsRegistreTest|ExperienceInscriptionLoopTest|AdminCreationEtudiantTest|XssCsrfSecurityTest"
→ 181 tests, 181 passed, 881 assertions, 10 142 ms — PASS
```

```text
vendor/bin/phpunit tests/Feature/Security --filter='^(?!.*Loop02AutorisationAntiIdorTest)' --no-coverage
→ 170 tests, 166 passed, 4 skipped, 544 assertions, 8 455 ms — PASS
```

```text
php artisan test --compact tests/Feature/EspaceEnseignantTest.php tests/Feature/DossierExistantAutonomeTest.php
→ 35 tests, 35 passed, 216 assertions, 4 124 ms — PASS
```

```text
vendor/bin/pint --test app/Models/PortalPasswordResetToken.php app/Models/Etudiant.php \
  app/Modules/Schedule/Models/Teacher.php app/Http/Middleware/SecurityHeadersMiddleware.php \
  tests/Feature/Security/Loop01AuthentificationSessionsTest.php
→ PASS
```

### 6.3 Tests non exécutés et point LOOP-02

| Élément | Statut |
|---|---|
| Suite complète (4 948 entrées listées) | `NOT_EXECUTED` |
| Tests MySQL opt-in (4) | `NOT_EXECUTED` — skippés : `ArchivesConcurrenceMysqlTest::test_serialisation_double_suppression`, `ArchivesConcurrenceMysqlTest::test_suppression_autre_faculte_refusee`, `ArchivesStatsMysqlTest::test_registre_200_avec_stats_sur_mysql`, `ArchivesVolumetrieBenchTest::test_benchmark_registre` |
| Parcours navigateur / E2E | `NOT_EXECUTED` |
| Vérification production (cookies `secure`, `SESSION_ENCRYPT`, HTTPS, proxies) | `NOT_EXECUTED` |
| `tests/Feature/Security` avec le fichier LOOP-02 | **7 erreurs** dans `Loop02AutorisationAntiIdorTest` au moment de la clôture (fixtures : colonnes `NOT NULL`, `request_types.code` en doublon, classe `App\Modules\Attendance\Models\Slot` absente). Fichier non suivi, hors périmètre LOOP-01, à traiter à l'ouverture de LOOP-02. |

Aucun de ces éléments n'est compté comme PASS.

---

## 7. Non-régression et hygiène

- **Périmètre de non-régression :** 181 tests ciblés (authentification, reset, sessions, en-têtes, portails, inscription, XSS/CSRF) + 35 tests enseignant/dossier existant + 170 tests de la suite `Security` hors fichier LOOP-02.
- **Formatage :** Pint `--test` vert sur les cinq fichiers du lot.
- **Sécurité des journaux :** le service de reset journalise uniquement `guard` en cas d'échec SMTP, jamais l'email ni le jeton (`PortalPasswordResetService.php:80-84`).
- **Pas de régression de contrôle :** aucun middleware de sécurité désactivé, aucune route supprimée, aucune exception ajoutée aux gardes.
- **Pas de données réelles :** SQLite `:memory:` uniquement ; aucune migration exécutée ; aucun `migrate:fresh`/`db:wipe`.

---

## 8. Risques résiduels et réserves

| # | Risque | Niveau | Recommandation |
|---|---|---|---|
| R1 | Jeton keyé par email, pas par identifiant de compte : après un changement d'adresse, un ancien jeton reste en base jusqu'à expiration ; si l'adresse est réattribuée dans les 60 min, le jeton pourrait viser le nouveau titulaire. | Faible | Purger les jetons lors d'un changement d'email ou keyer par `(guard, account_id)`. |
| R2 | Pas de verrouillage par compte : `throttle:5,1` est par IP ; une attaque distribuée peut répartir les tentatives. | Moyen | Backoff par compte, CAPTCHA ou supervision des échecs ; ne pas ajouter de lockout dur sans étude du risque de déni de service. |
| R3 | Le jeton reste dans l'URL : `no-referrer`/`no-store` réduisent la fuite, mais l'historique, un HAR, un proxy ou une capture d'écran peuvent le conserver. | Moyen | Échange one-time par POST ou cookie court ; à arbitrer hors LOOP-01. |
| R4 | Suite complète, MySQL, E2E et production non exécutés. | Élevé pour la confiance | Exécuter la suite complète et les tests MySQL opt-in avant de lever les réserves. |
| R5 | Envoi d'email synchrone : le temps de réponse d'une demande de reset peut différer entre compte existant et inexistant (canal temporel non mesuré). | Faible | Envoi en file ou mesure/traitement du timing ; à vérifier avant de conclure à l'absence de canal. |
| R6 | Fichier `Loop02AutorisationAntiIdorTest.php` non suivi avec 7 erreurs de fixtures. | Bloquant pour LOOP-02 | Corriger les fixtures à l'ouverture de LOOP-02, sans le confondre avec une régression LOOP-01. |
| R7 | `.env` local : `SESSION_ENCRYPT=false`, `SESSION_SECURE_COOKIE=false`. | Faible en local, à vérifier en production | Contrôler les valeurs de production (`.env.example` et `config/session.php` sont à `true`). |

---

## 9. État Git final et conformité

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` |
| Commit / push | aucun |
| Migration / `migrate:fresh` / `db:wipe` | aucun |
| Fichiers LOOP-01 modifiés | `SecurityHeadersMiddleware.php`, `Etudiant.php`, `Teacher.php` |
| Fichiers LOOP-01 non suivis | `PortalPasswordResetToken.php`, `Loop01AuthentificationSessionsTest.php` |
| Diff total de l'arbre | préexistant, non revendiqué par LOOP-01 |

Le rapport lui-même est le seul fichier ajouté à `docs/engineering/` par ce lot.

---

## 10. Verdict

**`PASS_WITH_RESERVATIONS`**

- Les deux défauts prouvés (D1 jeton encore valide après rotation ; D2 absence de `no-referrer`/`no-store`) sont corrigés et couverts par des tests qui échouaient avant le correctif.
- Les contrôles existants (throttle, anti-énumération, régénération/invalidation de session, politique de mot de passe, autorisation du reset administratif) sont confirmés.
- La non-régression ciblée est verte (181/181 et 35/35), mais la suite complète, les tests MySQL opt-in, l'E2E et la configuration de production restent `NOT_EXECUTED`.
- Le fichier de tests LOOP-02 apparu pendant la clôture échoue sur ses fixtures ; il est hors périmètre LOOP-01 et doit être traité à l'ouverture du lot suivant.

**Pour lever les réserves :** exécuter la suite complète et les tests MySQL opt-in, rejouer la non-régression sur un environnement de production représentatif, puis statuer sur R1-R3 et R7.
