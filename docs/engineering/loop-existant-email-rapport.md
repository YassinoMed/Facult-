# LOOP — Popup de modification de l'email sur /inscription/existant — Rapport

**Cycle :** PLAN → DEV → TEST → VALIDATION → AUTO-PASSAGE (+ LOOPS CORRECTIVES)
**Dates :** 2026-10-14 (initiale), 2026-10-14 (corrective : vérification réelle de la candidate), 2026-10-14 (corrective bouton : visibilité libellé exact), 2026-10-14 (bouton-invisible : preuve de rendu HTML réel, §9), 2026-10-14 (enterprise : maturation §15 + baseline LOOP-00 + performance LOOP-06), 2026-10-14 (signalement formulaire : cause racine session + reproduction live, §10), 2026-10-14 (bouton+popup : re-vérification, §11), 2026-10-14 (validation finale : **E2E navigateur réel + persistance en base**, §12)
**Route cible :** `/inscription/existant`
**Environnement :** dev local (preuves via PHPUnit + `route:list` + Pint + Chrome headless, base de test isolée). Aucune modification de production. Emails réels envoyés UNIQUEMENT vers la boîte Gmail du développeur lui-même (§12 : code parcours + code 1 vers `usermyb@gmail.com`, code 2 vers son alias `usermyb+e2e@gmail.com` ; §10 : 1 OTP vers une boîte fictive avant bascule `log`) — jamais vers des tiers ; réception en boîte non observée par l'agent (jamais affirmée sans observation).
**État Git initial (loop corrective) :** chaîne bouton → popup → routes → contrôleur → service → persistance **fonctionnelle** (58/58 verts) mais nouvelle adresse **jamais vérifiée** avant application — voir §1-bis (cause racine).

## 1. PLAN — Inspection (fichiers réellement lus)

| Élément | Fichier | Constat |
|---|---|---|
| Routes | `routes/web.php:167-198` | `GET /inscription/existant` (accueil), `POST recherche` (throttle 5,1), `POST verification` (throttle 10,1), `GET formulaire`, `POST /inscription/existant` (store, throttle 5,1), `GET confirmation`, `POST email` (throttle 5,1), `POST email/confirmer` (throttle 10,1). Toutes dans le groupe `web` (CSRF). Aucun doublon à créer. |
| Contrôleur | `app/Http/Controllers/DossierExistantController.php` (371 lignes) | `rechercher` (réponse neutre identique, anti-énumération), `verifier` (code 6 chiffres → jeton session, jamais en URL), `formulaire` (résolution dossier depuis jeton, anti-IDOR), `demanderEmail` + `confirmerEmail` (double preuve), `enregistrer` (consommation atomique du jeton avant écriture), `confirmation` (flash consommé). |
| Service | `app/Services/DossierExistantService.php` | Recherche fail-closed (CIN normalisé + date exacte), code hashé SHA-256 / 10 min / 5 essais / usage unique, autorisation 15 min liée au dossier (empreinte SHA-256 du jeton), code email 30 min / 5 essais lié à la nouvelle adresse, unicité insensible casse/espaces, transaction + verrou pessimiste + gardes statut/période, audit masqué, notifications post-commit fail-soft. |
| Formulaire CIN + naissance | `resources/views/inscription/existant/accueil.blade.php` | Champs `cin` + `date_naissance`, étape code affichée après recherche, message neutre identique, mention « mettre à jour votre adresse email » après vérification. |
| Popup | `resources/views/inscription/existant/formulaire.blade.php` | Titre « Modifier mon adresse email », `nouvel_email` + confirmation, « Enregistrer » / « Annuler », étape code (`code`, `autocomplete="one-time-code"`), `x-data/x-show/x-cloak`, `@keydown.escape.window`, `:disabled="emailPopup.chargement"`, erreurs `role="alert"`, réouverture serveur à l'erreur, lien « Demander un nouveau code », orientation scolarité si perte d'accès. Blade + Tailwind + Alpine.js (stack déjà en place, design global inchangé). |
| Validation | `DossierExistantEmailRequest` / `DossierExistantEmailConfirmationRequest` / `DossierExistantModificationRequest` | Normalisation minuscules+trim avant comparaison, `email|max:254|confirmed`, code `regex 6 chiffres`, whitelist stricte (email ignoré par la route coordonnées). |
| Modèle/champ email | `app/Models/Etudiant.php:228-239` (`email_masque`), `DossierExistantService::masquerEmail` | Masquage `loc******@domaine` (3 premiers caractères), jamais l'adresse complète en audit/logs. |
| Notifications | `app/Notifications/CodeVerificationDossierExistant`, `CodeConfirmationChangementEmail`, `AlerteChangementEmail`, `ConfirmationChangementEmail`, `ConfirmationMiseAJourDossierExistant` | Code d'entrée et code email vers l'adresse **déjà enregistrée** (`$etudiant->notify()`), alerte ancienne adresse + confirmation nouvelle adresse post-commit, fail-soft individuel. |
| Protections | Routes + service | Throttle IP (routes) + par identité (`MAX_RECHERCHES=5/15min`, cooldown renvoi 60 s), CSRF groupe `web`, validation serveur exclusive, unicité + normalisation, IDOR impossible (dossier depuis jeton), autorisation invalidée après succès (pull atomique), audit sans PII. |
| Tests | `tests/Feature/DossierExistantEmailTest.php` (30 tests), `tests/Feature/DossierExistantParcoursTest.php` (28 tests) | Couvrent tous les cas obligatoires §5 (détail §4). |

**Décision PLAN :** ne rien recréer (aucun doublon). Corriger uniquement les écarts démontrés ci-dessous.

## 1-bis. LOOP CORRECTIVE — Diagnostic (suivi réel de bout en bout)

Chaque hypothèse d'échec de la §1 a été vérifiée contre le code réel :

| Hypothèse | Verdict |
|---|---|
| Bouton/popup absents | **Infondé** — bouton « Modifier mon adresse email » + modale (`titre-email`, 2 champs, Enregistrer/Annuler) rendus post-vérification, test markup vert |
| Formulaire absent / route manquante / non reliée | **Infondé** — `POST email` + `POST email/confirmer` reliés aux deux formulaires (`route:list` : 8 routes `web`) |
| Contrôleur sans action / service sans persistance | **Infondé** — `demanderEmail`/`confirmerEmail` → `demanderChangementEmail`/`confirmerChangementEmail` avec transaction + verrou ; persistance prouvée (`assertSame($nouvel, $etudiant->fresh()->email)` + audit) |
| FormRequest incompatible | **Infondé** — `confirmed`, normalisation minuscules+trim, code 6 chiffres ; tests de refus verts |
| Champ mal relié | **Infondé** — seul `email` écrit, whitelist + backstop UNIQUE |
| OTP/autorisation insuffisants | **Partiellement fondé → cause racine ci-dessous** |

**Cause racine démontrée : la nouvelle adresse n'était jamais vérifiée.** Le code de confirmation partait vers l'ANCIENNE adresse et le changement était appliqué dès sa validation. Or l'email est un **identifiant d'authentification** (`Etudiant\ConnexionController::login` : `where('email', …)->orWhere('cin', …)` + mot de passe, route `etudiant.connexion.login`) et le modèle ne distingue **ni adresse candidate ni adresse vérifiée** (grep `email_verified|candidate|MustVerify` vide sur `Etudiant.php` — seul `Notifiable`). La règle §2.4 s'applique donc : code vers la NOUVELLE adresse, ancienne conservée jusqu'à confirmation, candidate en état temporaire, expiration + tentatives limitées, application atomique après confirmation, notification de l'ancienne adresse. Un succès affiché sans preuve de délivrabilité de la candidate = faute de frappe possible vers une adresse inaccessible ou appartenant à un tiers (qui ne reçoit qu'une confirmation post-commit, sans pouvoir d'action — mais l'étudiant, lui, perd son accès).

**Décision métier documentée (§2.4) :** l'email sert à la connexion + aux codes de récupération du parcours public → confirmation de la candidate **obligatoire**. Conservée en sus (pas remplacée) : la preuve vers l'adresse actuelle, qui ferme la fenêtre de détournement de session vérifiée (15 min) entre la vérification d'identité et le changement.

## 2. DEV — Correctifs appliqués (écarts démontrés, pas de refonte)

Deux défauts sûrs et démontrés trouvés à l'inspection (contrat §2 « Ne pas afficher l'ancienne adresse email complète » + exactitude du texte) :

1. **Fuite de l'adresse complète dans le gabarit** — `formulaire.blade.php:80` (`<dd>{{ $dossier['email'] }}</dd>`) et `:112` (`<span>{{ $dossier['email'] }}</span>`) affichaient l'email en clair après vérification, en contradiction avec le contrat et avec le reste de la popup (qui utilise `email_masque`). **Corrigé :** les deux affichages utilisent désormais `{{ $dossier['email_masque'] }}` ; libellé précisé « Email (masqué, non modifiable ici) ».
2. **Texte inexact du bloc email** — « …code de confirmation envoyé à la nouvelle adresse » alors que l'implémentation (prouvée par test `test_parcours_nominal…` : `assertNotSentTo(AnonymousNotifiable)` + code capté sur `$etudiant`) envoie à **l'adresse actuelle enregistrée**. **Corrigé :** « …envoyé à l'adresse actuelle (celle enregistrée sur le dossier) ».
3. **Durcissement DTO (défense en profondeur)** — `DossierExistantService::donneesFormulaire()` transmettait `'email' => $etudiant->email` au gabarit sans nécessité. **Corrigé :** clé `email` supprimée du DTO ; seul `email_masque` est exposé. Preuve : `clés dossier: …email_masque…`, `fuite email complet dans DTO: NON`, `occurrences email complet brut dans vue: 0`, `occurrences masque: 4` (script §5).
4. **Commentaire obsolète** — `DossierExistantModificationRequest` affirmait « l'email n'est PAS modifiable dans ce parcours ». **Corrigé :** précise que seule la popup dédiée (double preuve) peut le modifier.

Aucune route, aucun composant, aucun design global modifié. Reste du comportement (séquence recherche → code → formulaire → popup → confirmation → poursuite du parcours) inchangé.

## 2-bis. DEV CORRECTIF — Vérification réelle de la nouvelle adresse (triple preuve)

### Parcours utilisateur complet (après correctif)

1. CIN + naissance → réponse neutre, code 6 chiffres à l'adresse enregistrée.
2. Code vérifié → jeton session (15 min) → `GET formulaire` → bouton **« Modifier mon adresse email »** → popup, étape `saisie` (nouvelle adresse + confirmation, `Enregistrer`/`Annuler`, adresse actuelle **masquée uniquement**).
3. `POST email` → contrôles serveur → **code 1** (30 min, 5 essais, lié à la candidate côté serveur) vers **l'ancienne adresse** → étape `code` (« Vérifier ce code »).
4. `POST email/confirmer` (code 1) → **RIEN n'est persisté** : **code 2** vers la **candidate**, popup à l'étape `code-nouvelle` + message « Un code de confirmation a été envoyé à votre nouvelle adresse (valable 30 minutes). Votre adresse actuelle reste inchangée jusqu'à sa saisie ». Ancienne adresse conservée en base (prouvé : `assertSame('mohamed.benali@example.com', fresh()->email)` + connexion possible avec l'ancienne, impossible avec la candidate).
5. `POST email/confirmer-nouvelle` (`code_nouvelle`, jamais d'adresse reprise de la requête) → transaction (verrou, gardes statut/période/unicité relues, backstop UNIQUE, **seul `email` modifié**) → audit masqué → alerte ancienne adresse + confirmation nouvelle adresse (post-commit, fail-soft) → « Votre adresse email a été mise à jour » → poursuite du parcours (jeton principal intact, soumission coordonnées qui ignore toujours l'email).
6. Sans accès à l'ancienne adresse : fail-closed + scolarité, jamais de contournement.

### Fichiers et routes (modifiés ou créés par la loop corrective)

| Fichier | Changement |
|---|---|
| `app/Notifications/CodeConfirmationNouvelleAdresse.php` (**créé**) | Code vers la candidate, synchrone, sans PII (même conventions que `CodeConfirmationChangementEmail`) |
| `app/Http/Requests/DossierExistantEmailNouvelleConfirmationRequest.php` (**créé**) | Champ `code_nouvelle` (6 chiffres) — distinct de `code` pour rouvrir la bonne étape en cas d'erreur |
| `app/Services/DossierExistantService.php` | `confirmerChangementEmail` (appliquait dès le code 1) scindé en `verifierCodeAncienneAdresse` (pull code 1 → candidate, sans écriture) + `emettreCodeNouvelleAdresse` (gardes relues + envoi code 2, orphelin impossible, délai de renvoi libéré sur échec d'envoi) + `confirmerNouvelleAdresse` (pull code 2 + transaction déplacée à l'identique) ; `controlerGardesChangementEmail` factorisé (messages inchangés) ; `candidatureMasqueeEnAttente` ; constantes `DUREE_CODE_EMAIL_NOUVELLE`/`MAX_ESSAIS_CODE_EMAIL_NOUVELLE` (30 min / 5 essais) ; clés cache `email-nouvelle` dédiées |
| `app/Http/Controllers/DossierExistantController.php` | `confirmerEmail` : code 1 → émet code 2 (plus de persistance) ; `confirmerNouvelleAdresse` (**créé**) : code 2 → applique + notifie |
| `routes/web.php` | `POST /inscription/existant/email/confirmer-nouvelle` (`inscription.existant.email.confirmer-nouvelle`, groupe `web`) ; throttles inline `X,1` → limiters nommés par fonction (mêmes budgets) |
| `app/Providers/AppServiceProvider.php` | Limiters `dossier-existant.{recherche,verification,store,email,email-confirmer}` (5/10/5/5/10 par min/IP) — **compartiments par fonction (doctrine LOOP P)** : l'ajout d'un POST au parcours nominal (6 POST au total) aurait sinon déclenché un 429 prématuré sur le seau partagé ; prouvé par le nominal qui enchaîne les 6 requêtes sans 429 |
| `resources/views/inscription/existant/formulaire.blade.php` | Étape `code-nouvelle` (formulaire dédié, `role="alert"`, focus, Annuler/Échap, renvoi vers saisie) ; dérivation d'étape étendue (`code_nouvelle` → `code-nouvelle`) ; étape `code` re-libellée (« Code reçu à l'adresse actuelle », bouton « Vérifier ce code ») ; bloc email : double confirmation documentée |
| `tests/Feature/DossierExistantEmailTest.php` | 31 tests (réécrits + 6 nouveaux : code 2 invalide/épuisé/expiré/usage unique, ancienne-en-vigueur + login, échec envoi code 2 + reprise immédiate) |

### Règles de vérification de la nouvelle adresse (§2.4)

- Candidate **jamais persistée** avant confirmation (vit dans le cache : `{code_hash, nouvel_email, tentatives, expire_a}`, 30 min, 5 essais, usage unique atomique par `Cache::pull`, anti-substitution : l'étape 3 ne reprend aucune adresse de la requête).
- Ancienne adresse **conservée et fonctionnelle** (connexion incluse) jusqu'à la confirmation finale ; après confirmation, bascule réelle prouvée par connexion HTTP avec la nouvelle adresse.
- Échec d'envoi du code 2 : aucun orphelin, aucun succès mensonger (retour saisie + erreur), reprise immédiate (délai libéré).
- Notification de l'ancienne adresse après commit conservée (`AlerteChangementEmail`, adresse masquée) ; confirmation vers la nouvelle (`ConfirmationChangementEmail`) désormais **réellement vérifiée**.

### Fonctionnement de la popup (après correctifs)

1. Étudiant saisit CIN + date de naissance → réponse neutre identique (aucun oracle), code 6 chiffres à l'adresse enregistrée si dossier correspondant.
2. Code vérifié → jeton temporaire en session (15 min, lié au dossier, usage unique) → `GET formulaire`.
3. Bouton « Modifier mon adresse email » → modale Alpine (`emailPopup.ouvert/etape/chargement`), étape `saisie` (nouvel email + confirmation, `Enregistrer`/`Annuler`, anti double-clic, focus automatique).
4. `POST email` (autorisation valide requise) → contrôles serveur (statut en_attente/valide, période ouverte, différence, unicité, cooldown) → code 6 chiffres (30 min, 5 essais, lié à la nouvelle adresse côté serveur) envoyé à **l'ancienne adresse** → étape `code`.
5. `POST email/confirmer` (seul `code` repris de la requête) → transaction (rechargement sous verrou, gardes relues, seul `email` modifié, backstop UNIQUE) → audit masqué → `email_succes` (masqué) + `statut` → parcours coordonné **poursuivi** (le jeton principal n'est pas consommé par le changement d'email ; la soumission des coordonnées reste possible et ignore toujours l'email).
6. Sans accès à l'ancienne adresse : aucun contournement — orientation scolarité (récupération administrative), protection jamais désactivée.

### Mécanisme de vérification d'identité (double preuve)

- **Preuve 1 :** CIN + date de naissance → code à usage unique vers l'adresse **déjà enregistrée** (10 min, hashé, 5 essais, cooldown 60 s). Anti-énumération stricte.
- **Preuve 2 (changement d'email) :** jeton temporaire de session **+** code de confirmation vers l'adresse **actuellement enregistrée** (jamais vers la nouvelle). La nouvelle adresse, liée au code côté serveur, n'est appliquée qu'après validation. CIN + naissance seuls ne suffisent jamais.
- Autorisation temporaire : empreinte SHA-256 en cache, expiration courte (15 min), consommation atomique (`Cache::pull`), invalidation après succès, portée limitée au dossier.

## 3. SÉCURITÉ — Vérification point par point (§3)

| Exigence | État | Preuve |
|---|---|---|
| Autorisation temporaire liée au dossier | OK | `emettreAutorisation` / `autorisationValide` / `consommerAutorisation`, tests IDOR (3 étapes) |
| Expiration courte | OK | 15 min jeton, 10 min code, 30 min code 1, 30 min code 2 ; tests `travel(16min)`, `travel(11min)`, `travel(31min)` (code 1 et code 2) |
| Limitation tentatives | OK | 5 essais/code (×3 codes), `MAX_RECHERCHES=5`, cooldowns 60 s (×3 canaux), throttles IP **par fonction** ; tests dédiés + 429 |
| CSRF | OK | Groupe `web` sur les 9 routes, `PreventRequestForgery` ; test 3 URIs |
| Validation serveur exclusive | OK | FormRequests + contrôles service ; candidate jamais reprise de la requête (étapes 2 et 3) |
| Unicité nouvelle adresse | OK | Contrôle applicatif insensible casse/espaces + backstop UNIQUE, relu aux 3 étapes ; test casse |
| Normalisation cohérente | OK | `mb_strtolower(trim())` requête + service, CIN normalisé comme la création |
| Identifiant non modifiable | OK | Dossier résolu depuis le jeton ; `etudiant_id/id/nom/cin/statut` ignorés sur les 3 routes (test) |
| Autres champs non modifiables par cette route | OK | Whitelist `CHAMPS_MODIFIABLES`, email ignoré par le store (test `…_ignore_toujours_l_email`) |
| Invalidation après succès | OK | `Cache::pull` (codes 1, 2 + autorisation), rejeu refusé, concurrence : un seul flux par code (tests) |
| Aucune confiance au navigateur | OK | Jamais d'id/email/indicateur de vérification client |
| Perte d'accès ancienne adresse | OK | Fail-closed + parcours scolarité, jamais de désactivation de la protection |
| Nouvelle adresse vérifiée avant effet (§2.4) | OK (loop corrective) | Code 2 vers la candidate, ancienne en vigueur jusque-là (test login), application atomique après confirmation uniquement |

## 4. TEST — Résultats réels (§5 + PHASE 3 corrective)

### 4.1 Ciblés (loop corrective : 31 tests email + parcours existant)

```
php artisan test --filter="DossierExistantEmailTest|DossierExistantParcoursTest"
→ passed, 63 tests, 615 assertions
```

Couverture des 15 cas exigés : (1) bouton visible post-OTP ; (2) popup absente de l'accueil (`code_nouvelle` inclus) ; (3) adresse valide soumise (triple preuve nominale) ; (4-5) invalide / confirmation différente refusées, formulaire conservé ; (6) déjà utilisée (casse) refusée ; (7) autres champs ignorés (3 routes) ; (8) IDOR inopérant ; (9) codes 1 et 2 expirés/épuisés/rejoués refusés ; **(10) candidate non confirmée non vérifiée** — ancienne conservée en base + connexion HTTP ancienne OK / candidate KO, bascule prouvée après confirmation ; **(11) persistance réelle** — `fresh()->email` + audit masqué + connexion HTTP nouvelle adresse ; (12) alerte ancienne + confirmation nouvelle post-commit uniquement (rien après le code 1 seul) ; (13) rejeu/concurrence : un seul flux par code, aucune notification en double ; (14) échec envoi code 1 (annulation sans cooldown) et code 2 (aucun orphelin, reprise immédiate, aucun succès mensonger), échec post-commit conservé + signalé ; (15) parcours existant intact (`DossierExistantParcoursTest` vert sans modification, poursuite coordonnées + confirmation vers la NOUVELLE adresse dans le nominal) ; markup 3 étapes accessible (Alpine, `role="dialog"`, Échap, chargement, `role="alert"` sur `code_nouvelle` avec réouverture de la bonne étape).

### 4.1-bis Résultats historiques (loop initiale, pour traçabilité)

```
→ 58 tests, 505 assertions (flux à un code : application dès le code 1)
```
Remplacés par le flux à deux codes ci-dessus ; la cause racine (§1-bis) explique pourquoi ce vert ne suffisait pas.

### 4.2 Non-régression pertinente

```
php artisan test --filter="CinPrefillTest|InscriptionSuiviDossierTest|MensDossierCreationTest|InscriptionSecuriteTransversaleTest|SecuritePhaseFTest"
→ passed, 65 tests, 252 assertions
vendor/bin/pint --test (7 fichiers du périmètre) → passed
```

Vérifié sans conflit : `SecuritePhaseFTest` (throttles `5,1` des 3 connexions, inchangées), `InscriptionSecuriteTransversaleTest` (routes sensibles hors périmètre), `DossierExistantParcoursTest::test_throttle_route_recherche…` (seau isolé : 5 passent, 6e 429 — inchangé).

### 4.3 Suite complète

Historique (loop initiale) : 3230 tests · 3204 réussis · 4 échecs N+1 hors périmètre (grep sans lien, même pattern préexistant) · 22 ignorés — log `/tmp/full-tests.log`. Ces 4 échecs avaient été comparés à l'état initial : ni attribués sans preuve, ni masqués.

Suite complète corrective (`/tmp/full-tests-corrective.log`, ~240 s) :

```
→ 3235 tests · 3209 réussis · 4 échoués · 22 ignorés · 14332 assertions · EXIT:1
```

Les 4 échecs sont **strictement identiques à l'état initial** (mêmes tests, mêmes lignes, mêmes seuils) :

| Test | Seuil |
|---|---|
| `InscriptionFileTraitementTest::test_absence_de_requetes_n_plus_un_sur_la_file:411` | 25 < 25 |
| `InscriptionPerformanceFiabiliteTest::test_file_administrative_…:139` | 25 < 25 |
| `PopulationPhase2CompleteTest::test_dashboard_ventilations_bornees:130` | 23 ≤ 21 |
| `Security\ArchivesFicheLoop3Test::test_fiche_sans_n_plus_un:74` | 25 < 25 |

Comparaison honnête : +5 tests par rapport à l'état initial (3230 → 3235 = les 6 nouveaux tests email moins la fusion du test throttle réécrit), +5 verts (3204 → 3209) ; **zéro nouvel échec, zéro disparition suspecte**. Ces 4 tests ne référencent aucun code de la loop (vérifié par grep §4.3 initial, fichiers non touchés par le diff §5). Ils restent donc classés préexistants hors périmètre — ni attribués à la correction, ni masqués.

Les 4 échecs sont tous des seuils budgétaires de requêtes N+1, hors périmètre,
sans aucune référence au code de cette loop (grep `DossierExistant|existant.email|email_masque`
vide dans les 4 fichiers) :

| Test | Seuil |
|---|---|
| `InscriptionFileTraitementTest::test_absence_de_requetes_n_plus_un_sur_la_file` | 25 < 25 |
| `InscriptionPerformanceFiabiliteTest::test_file_administrative_absence_de_n_plus_un_sur_volume` | 25 < 25 |
| `PopulationPhase2CompleteTest::test_dashboard_ventilations_bornees` | 23 ≤ 21 |
| `Security\ArchivesFicheLoop3Test::test_fiche_sans_n_plus_un` | 25 < 25 |

Même pattern que les 4 échecs préexistants documentés par la loop de consolidation
(seuils N+1, `PASS_WITH_RESERVATIONS`). Aucun des 104 tests du périmètre
(`DossierExistantEmailTest` 30 + `DossierExistantParcoursTest` 28 + non-régression 46)
n'échoue. Log complet : `/tmp/full-tests.log`.

### 4.4 Preuves complémentaires exécutées ici

- `route:list --name=inscription.existant` : **9 routes**, toutes `web`, throttles nommés par fonction.
- Script de non-fuite (loop initiale) : DTO sans clé `email`, 0 occurrence d'email brut dans la vue, affichages masqués uniquement.
- Preuve de persistance + bascule auth : test `…_ancienne_reste_en_vigueur` (connexion HTTP `etudiant.connexion.login` avant/après).
- Test navigateur réel : **NOT_EXECUTED** (aucun navigateur connecté à la session ; Playwright absent du dépôt — vérifié : aucune suite E2E pour ce parcours) — jamais affirmé ; couvert par le test de markup + assertions serveur.
- Envoi réel d'emails : jamais observé (factice en test) — jamais affirmé.

## 5. VALIDATION

- Routes/middlewares : vérifiés (9 routes, §4.4 ; test CSRF 3 URIs).
- Autorisations/accès dossier : audités (§3).
- Persistance réelle : prouvée en base rechargée + audit + bascule de connexion (§4.1 cas 10-11).
- Diff final : `routes/web.php` + `AppServiceProvider.php` (suivis, +89/−3) ; non suivis (`??`, préexistants + créés par les loops) : contrôleur, service, 2 FormRequests + 1 créée, 1 notification créée, vues `inscription/existant/`, 2 fichiers de tests, présent rapport. Aucune donnée de production touchée.
- AUTO-PASSAGE : défaut 429 prématuré détecté par construction (6e POST du nominal) → corrigé par les compartiments → re-testé (nominal 6 requêtes sans 429 + test throttle isolé).
- Les 4 échecs N+1 : comparés à l'état initial au §4.3 — **ni attribués à la correction sans preuve, ni masqués** (identiques avant/après).

## 6. Réserves restantes

1. Suite complète intégrée : **3235 tests · 3209 verts · 4 échecs N+1 strictement identiques à l'état initial (mêmes tests/lignes/seuils, sans lien) · 22 ignorés** — ni attribués à la correction, ni masqués.
2. Validation en vrai navigateur : NOT_EXECUTED (Playwright absent, aucun navigateur connecté) — ne pas revendiquer.
3. Envoi réel d'emails : jamais observé — ne pas revendiquer.
4. Masse non commitée préexistante hors périmètre non touchée ; fichiers de la loop non commités (décision de commit hors périmètre).
5. Décision assumée : la preuve vers l'adresse actuelle est conservée EN SUS du code candidate (trois preuves au total) — un allègement reste possible mais affaiblirait la fermeture de la fenêtre de détournement de session ; non tranché sans validation métier.

## 7. Verdict

**`PASS_WITH_RESERVATIONS`** — Critère de réussite atteint : l'étudiant vérifie son identité (OTP), ouvre la popup, saisit sa nouvelle adresse, la confirme par code envoyé à cette adresse, et le changement est réellement enregistré en base (bascule de connexion prouvée). Triple preuve + notifications vérifiées par 63 tests ciblés verts, 65 de non-régression verts, suite complète 3209/3235 (4 échecs N+1 identiques à l'état initial), Pint propre, 9 routes auditées ; réserves : navigateur réel et email réel NOT_EXECUTED (jamais substitués).

## 8. LOOP CORRECTIVE BOUTON (2026-10-14) — Libellé exact et visibilité

**Diagnostic (§1) :** inspection réelle (`DossierExistantController`, `DossierExistantService`, `DossierExistantModificationRequest`, `routes/web.php`, vue formulaire, modèle, OTP/notifications, tests) — **l'option n'est pas absente** : bouton + popup 3 étapes + 3 routes `web` + service triple preuve + 63 tests verts, adresse actuelle uniquement masquée (4 × `email_masque`, 0 en clair). Aucune route à ajouter, aucune persistance à réparer. Seul écart à la lettre de la demande : libellé sans préfixe ✉️ et bouton en style secondaire peu visible.

**DEV (§2–§3) :** un seul fichier retouché, `resources/views/inscription/existant/formulaire.blade.php` — bouton passé en style primaire du thème violet existant (`bg-primary-800`, `#3730a3`, mêmes classes que les actions primaires du parcours, aucune couleur nouvelle, design global inchangé) avec le libellé exact **« ✉️ Modifier mon adresse email »**, placé dans la section « Adresse email » (informations de contact du dossier). Titre popup, champs, Enregistrer/Annuler, erreurs sous les champs, chargement, anti-double-clic, conservation de saisie : inchangés (déjà conformes). Chaîne serveur (§3 : autorisation temporaire, IDOR impossible, validation/unicité/gardes, audit, `confirmerNouvelleAdresse` + notification candidate) : déjà en place, réutilisée sans modification — **aucune route dupliquée, aucun `$request->all()`**.

**TEST (§4) :** bouton visible post-OTP / absent pré-vérification, ouverture/fermeture, soumission valide, refus (invalide/déjà utilisé/confirmation différente), directe sans autorisation, IDOR, whitelist, persistance après confirmation, notifications + historique, parcours existant : déjà couverts et verts — **63/63, 615 assertions** après le correctif (substring `assertSee('Modifier mon adresse email')` insensible au préfixe ✉️).

**VALIDATION (§5) :** ciblés 63/63, non-régression `CinPrefillTest|InscriptionSuiviDossierTest|MensDossierCreationTest|InscriptionSecuriteTransversaleTest|SecuritePhaseFTest` **65/65**, Pint propre, `route:list` 9 routes `web` + throttles nommés (inchangés), diff final : 1 ligne blade (bouton). Suite complète déjà prouvée 3209/3235 identique au baseline (2 runs) — non relancée pour un changement cosmétique d'une ligne, sans effet serveur (décision documentée, pas de masquage : le run de référence reste §4.3).

**Verdict loop bouton : `PASS_WITH_RESERVATIONS`** (mêmes réserves : navigateur réel et email réel NOT_EXECUTED).

## 9. LOOP BOUTON INVISIBLE (2026-10-14) — Preuve de rendu HTML réel

**Reproduction (§1) :** au lieu de conclure depuis le source Blade, le HTML **réellement servi** par `GET inscription.existant.formulaire` après vérification OTP a été capturé en test (page complète ~20 ko avec layout `layouts.app`). Constats sur le HTML rendu :
- Le bouton **est présent** dans la section « Adresse email » (coordonnées du dossier), avec le libellé exact `✉️ Modifier mon adresse email`, les classes `bg-primary-800 … text-white … min-h-[44px] … w-full`, et `@click="emailPopup.ouvert = true; …"` qui ouvre la popup existante — **aucune seconde popup créée**.
- Aucun masquage sur la balise : ni `hidden`, ni `x-show`/`x-cloak`, ni `display:none`/`visibility:hidden` inline, ni `opacity-0`/`invisible`, aucun `tabindex="-1"` (focus clavier natif).
- La section est conditionnée par `@if ($emailModifiable)`, alimenté par le contrôleur (`in_array(statut, ['en_attente','valide'])`) — matrice prouvée : bouton rendu pour `en_attente` et `valide`, **absent** pour `rejete` (branche atteinte des deux côtés, pas de branche morte).
- Bon fichier rendu : le contrôleur retourne exactement `view('inscription.existant.formulaire')` (le fichier modifié) ; les 2 autres vues du dossier (`accueil`, `confirmation`) sont mappées à leurs propres actions — aucune vue morte modifiée.
- Classe effective : `@theme` définit `--color-primary-800: #3730a3`, le layout charge le bundle via `@vite`, et le CSS buildé (`public/build/assets/app-*.css`) **contient `bg-primary-800`** (utilisée sur tout le site : la classe est résolue même sans rebuild). Contraste : blanc sur indigo-800.
- Cache des vues : `view:clear` **non nécessaire** — compilés `storage/framework/views` avec recompilation auto sur mtime en dev, et les tests rendent depuis la source à chaque run (la capture ci-dessus inclut déjà le bouton violet).
- Piège écarté et documenté : la première version du test d'extraction utilisait `(.*?)` qui traversait les `</button>` du layout (faux attributs `x-show` du layout attribués au bouton) ; corrigée par token tempéré `(?:(?!</button>).)*?` — le test échouait à tort, **l'application, elle, rendait déjà le bon HTML** (visible dans le diff d'échec : `class="… bg-primary-800 … text-white …"`).

**Cause réelle :** il n'y avait **aucune cause d'invisibilité** — ni branche conditionnelle jamais atteinte, ni CSS/JS masquant, ni mauvaise vue, ni cache stale. Le bouton est servi visible ; le soupçon d'invisibilité venait de l'absence de preuve de rendu (texte cherché uniquement dans le source), désormais levée par le test `test_bouton_rendu_visible_et_ouvrant_la_popup_apres_verification` (419 assertions au fichier).

**TEST (§3) :** ciblés `DossierExistantEmailTest` **32/32**, `DossierExistantParcoursTest` **32/32** (aucune régression du parcours), Pint propre (test + vue). Étapes navigateur 1–10 : **NOT_EXECUTED** — aucun navigateur connecté à la session, Playwright absent du dépôt ; le clic réel et l'ouverture popup en conditions navigateur ne sont **pas** revendiqués (la popup Alpine est couverte par le test de markup + câblage `@click` prouvé dans le HTML).

**VALIDATION (§4) :** routes (`formulaire` → contrôleur → bonne vue), conditions, bundle CSS, diff final : 1 ligne blade (loop précédente) + 1 test de rendu (cette loop). Suite complète non relancée (référence §4.3 inchangée, aucun effet serveur).

**Verdict loop bouton-invisible : `PASS_WITH_RESERVATIONS`** — le critère « rendu vérifié » l'est au niveau HTML servi (bouton violet visible et câblé, matrice des statuts), mais la loop exige `PASS` **uniquement si le rendu est vérifié [en navigateur]** : sans navigateur réel (NOT_EXECUTED déclaré), `PASS` est interdit par ses propres règles.

## 10. SIGNALEMENT « FORMULAIRE INEXISTANT » (2026-10-14) — Reproduction live + cause racine

**Constat de départ :** fichiers tous présents (vues, contrôleur, service, routes, tests). Le formulaire existe dans le code — l'hypothèse à tester était donc un blocage **avant** son affichage, côté navigateur/serveur réel (`http://localhost:8000`, MySQL dev, 2047 dossiers, période ouverte jusqu'au 2026-12-11).

**Reproduction live complète (curl + jar, serveur réel) — dossier test 2038 (CIN 12345678, statut en_attente) :**
1. `GET /inscription/existant` → 200, formulaire CIN/naissance + mention email rendus.
2. `POST recherche` → 302, message neutre + étape code affichées (réponse identique, anti-énumération).
3. OTP `541601` relu (mailer `log` le temps de la démo, voir ci-dessous) → `POST verification` → **302 vers `/inscription/existant/formulaire`**.
4. `GET formulaire` (54 Ko) : bouton **✉️ Modifier mon adresse email** (`bg-primary-800 … text-white`, `@click` ouvrant), popup 3 étapes (`titre-email`, `nouvel_email` + confirmation, `code_nouvelle`), email **uniquement masqué** (`use******@gmail.com`, 0 en clair).
5. `POST email` (candidate `test-candidat-2038@example.com`) → 302, popup rouverte à l'étape `code` (`etape: 'code'`, `ouvert: true`), **code 1 émis vers l'adresse actuelle**.
6. **Arrêt volontaire avant confirmation finale** : appliquer aurait muté l'email du dossier de test (boîte Gmail réelle du dev) sans son accord — la persistance reste prouvée par les tests (bascule de connexion HTTP).

**Cause racine A — sessions impossibles en HTTP (CORRIGÉE) :** `config/session.php` impose `'secure' => env('SESSION_SECURE_COOKIE', true)` et le `.env` local ne le surchargeait pas → cookies de session `Secure` sur `http://localhost:8000` (HTTP pur) → client strict (constaté avec curl : session perdue à chaque requête, flash/CSRF/OTP inopérants) → le parcours ne peut pas aboutir dans le navigateur, donnant l'impression que « le formulaire n'existe pas ». **Correctif : `SESSION_SECURE_COOKIE=false` ajouté au `.env` local uniquement** (diff `.env` : 1 ligne ; prod HTTPS garde le défaut `true`, aucun code modifié, aucun impact tests). Preuve : après correctif, flag `Secure` absent des `Set-Cookie` et parcours live ci-dessus abouti.

**Causes B/C/D — blocages légitimes expliquant le signalement (non corrigés, par conception) :**
- B. Le code OTP part **uniquement** vers l'adresse enregistrée du dossier (ex. `fz.bennani@email.com`, fictive) : sans accès à cette boîte, l'étape 2 est infranchissable — c'est la garantie anti-énumération/anti-takeover, avec fallback scolarité. Un testeur sans accès à la boîte du dossier testé ne verra jamais le formulaire.
- C. CIN avec lettres (ex. dossier 2 : `AB890123`) **rejetés** par `VerifierCinRequest` (`\A[0-9]{8}\z`) alors que la base en contient : ces dossiers ne peuvent pas utiliser le parcours (reproduction constatée : erreur « exactement 8 chiffres »). Qualité de données hors périmètre — signalé, non modifié.
- D. Statut `rejete` → section email absente (voulu, testé).

**Traçabilité des effets de bord (dev uniquement, tous à expiration automatique, rien persisté) :** 1 OTP réel envoyé via SMTP au dossier 2 (boîte fictive, 10 min) avant le passage en `log` ; 1 jeton + 1 code email en attente sur le dossier 2038 (15/30 min) ; `.env` restauré à `MAIL_MAILER=smtp` (vérifié par diff : seule ligne restante = `SESSION_SECURE_COOKIE=false`). Aucune mutation du dossier (email inchangé, confirmé).

**Verdict signalement : `PASS_WITH_RESERVATIONS`** — formulaire prouvé existant et fonctionnel de bout en bout sur le serveur réel jusqu'à la confirmation finale (volontairement non appliquée) ; correctif session appliqué et prouvé ; réserves : navigateur réel NOT_EXECUTED, email réel NOT_EXECUTED, dossiers à CIN-lettres et boîtes inaccessibles hors périmètre.

## 11. LOOP BOUTON+POPUP (2026-10-14) — Re-vérification sans duplication

**PLAN (§1) :** la vue réellement rendue par `GET /inscription/existant/formulaire` est bien `resources/views/inscription/existant/formulaire.blade.php` (contrôleur `formulaire` → ce fichier, vérifié §9) : libellé présent 2× (bouton + titre `h3#titre-email`), **un seul** `role="dialog"` (aucune seconde popup), 3 routes email `web` reliées aux 3 formulaires, service triple preuve en place. La preuve ne repose pas sur le source : HTML servi capturé live au §10 (bouton violet câblé `@click`, popup 3 étapes, email masqué uniquement).

**DEV (§2–§3) :** rien à ajouter — bouton violet ✉️ dans la section « Adresse email », popup responsive (`max-w-xl`, `overflow-y-auto`, `p-4`), `role="dialog"` + `aria-modal` + `aria-labelledby`, fermeture Annuler/Échap, `Enregistrer` violet + `Annuler` secondaire, erreurs `role="alert"` + statut, `x-data/chargement` anti-double-clic, Alpine.js déjà en usage dans la vue. Recréer aurait violé l'interdiction de doublon. Backend : routes existantes réutilisées, jeton OTP, résolution serveur, validation + unicité + gardes, audit, candidate vérifiée avant effet, jamais `$request->all()`.

**TEST (§4) :** les 10 scénarios couverts et verts — `DossierExistantEmailTest` 32/32 + `DossierExistantParcoursTest` 32/32 (**64/64, 643 assertions**), non-régression 65/65, Pint `--dirty` propre. Aucun PHP modifié ce tour (vérification pure) ; aucun test ajouté (couverture déjà complète, dont preuve de rendu §9).

**VALIDATION (§5) — PREUVE VISUELLE : `NOT_EXECUTED`.** Serveur local déjà en cours (`:8000`, 200) mais **aucun navigateur disponible** : pas de binaire chromium/chrome, rien dans `node_modules/.bin` (playwright/puppeteer/cypress), pas de `playwright` python. Les 6 points visuels (bouton violet, emplacement, ouverture popup, champs/boutons, console JS, mobile) ne sont donc **pas** revendiqués ; substituts honnêtes : HTML servi capturé (§10) + test de rendu (§9) + classes responsive vérifiées dans le source (`flex-col sm:flex-row`, `w-full sm:w-auto`, `max-h-full overflow-y-auto`).

**Verdict loop bouton+popup : `PASS_WITH_RESERVATIONS`** — tout le vérifiable est vert ; `PASS` exigerait la preuve visuelle navigateur, déclarée NOT_EXECUTED.

## 12. TROISIÈME BOUTON « CORRIGER » (2026-10-14)

**Demande :** aux étapes code, ajouter à côté des deux boutons existants un bouton de retour à la saisie (faute de frappe sur la nouvelle adresse) sans fermer la popup.
**DEV :** `formulaire.blade.php` uniquement — bouton secondaire « Corriger la nouvelle adresse » (×2 : étapes `code` et `code-nouvelle`), `type="button"`, `@click` → `etape='saisie'` + focus `nouvel_email`, inhibé pendant `chargement`. Navigation purement cliente : aucun effet serveur (codes émis conservés, aucune route ajoutée).
**TEST :** `test_bouton_corriger_adresse_revient_a_la_saisie_sans_effet_serveur` — 2 occurrences, câblage ×2, popup non fermée, zéro notification, email inchangé. Fichier : **33/33, 435 assertions**, Pint propre.

## 14. LOOP AUTONOME SANS SCOLARITÉ (2026-10-14) — CIN + ancienne adresse + mot de passe + OTP candidate

**1. Verdict : `PASS_WITH_RESERVATIONS`** (justifié §14.7, révisé §14.8 : navigateur partiellement prouvé en vrai Chrome, réception réelle au développeur uniquement).

**2. État initial / PLAN :** parcours OTP-vers-ancienne-adresse et aide scolarité existants et verts ; aucun chemin sans l'ancienne adresse ni la scolarité. Preuve alternative fiable disponible vérifiée à l'audit : **mot de passe bcrypt du compte** (`password` nullable — 10/2047 dossiers sans, fail-closed → scolarité ; cast `hashed`, `email` UNIQUE en base, audit `tracer` réutilisé). Décision : CIN+DOB+ancienne adresse (connaissance, insuffisante seule — interdiction respectée) + **mot de passe (preuve supplémentaire)** + OTP vers la candidate. Aucune question faible inventée, aucun système OTP concurrent (notification `CodeConfirmationNouvelleAdresse` réutilisée).

**3. Architecture réutilisée :** jeton session, codes hashés 30 min/5 essais/`pull` atomique, transaction + verrou + gardes relues + backstop UNIQUE, audit masqué, `notifierChangementEmail` post-commit, throttles IP nommés + identité, `dontFlash` framework pour `password` (prouvé par test).

**4. Fichiers créés/modifiés :**
- `app/Services/DossierExistantService.php` : `demanderChangementAutonome` (réponse neutre booléenne, bcrypt systématique même sans dossier — doctrine connexion, `email_change` op-scope) + `confirmerChangementAutonome` (conso atomique, transaction, seul `email`) + constantes/clés/limiteur identité.
- `app/Http/Requests/DossierExistantAutonomeRequest.php` + `...AutonomeConfirmationRequest.php` (créés) : format seul, normalisation, `password` jamais `old()`.
- `app/Http/Controllers/DossierExistantController.php` : `demanderAutonome` (toujours même message + étape code) + `confirmerAutonome` (neutre, jeton oublié après succès/échec garde).
- `routes/web.php` : `POST autonome` + `POST autonome/confirmer` (`web`, throttles nommés) ; `AppServiceProvider` : 2 limiters (5/10 par min/IP).
- `resources/views/inscription/existant/accueil.blade.php` : carte « Changer mon adresse e-mail sans passer par la scolarité » (6 champs, chargement, `role="alert"`, responsive, clavier) + étape `code_autonome` + succès masqué. Carte d'aide scolarité conservée intacte (pas de doublon).
- `tests/Feature/DossierExistantAutonomeTest.php` (créé, 16 tests).

**5. Protections :** neutralité totale pré-OTP (jamais d'oracle, bcrypt constant, silencieux sur cooldown/gardes) ; op-auth `email_change` liée au dossier, 30 min, usage unique, jamais d'id client ; unicité au commit + concurrence ; autres champs ignorés ; échec d'envoi = rien (réessai immédiat) ; PII jamais en clair (masqué, logs sans secret) ; 10 dossiers sans password fail-closed.

**6–7. Tests : 16/16 (132 assertions)** — nominal + persistance + audit + bascule login, neutralité ×4 causes, sans-password, password-hors-session, refus neutres (unicité/identité/période/statut), code invalide/épuisé/expiré, sans-parcours, opération étrangère, concurrence+rejeu, IDOR+whitelist, conflit tardif, échec envoi/notifs, limiters+CSRF, indépendance du store. Ciblés totaux **82/82 (809 assertions)** ; non-régression 65/65 ; Pint propre (9 fichiers). Auto-corrections documentées : 3 échecs initiaux tous côté test (baseline notifs, lecture session à points, assertion notif sur chemin service) — **zéro défaut applicatif trouvé par les tests**.

**8. Navigateur : `NOT_EXECUTED`** (revérifié §13, toujours absent).

**9. Email :** construction ✓, file ✓ (alerte/confirmation `ShouldQueue`, codes sync), remise transport ✓ (ENVOI_OK §10, fake/mock ici), **réception réelle ✗** — non revendiquée. Live : demande invalide → 302 + message neutre + étape OTP, zéro envoi (serveur réel).

**10. Risques résiduels :** devinettes mot de passe bornées (5/15 min par identité + 5/min IP + 5 essais/code — strictement plus fort que le login) sans verrouillage permanent (anti-DoS) ; mots de passe faibles → scolarité ; 10 dossiers sans password → scolarité uniquement ; `.env` local seul (`SESSION_SECURE_COOKIE=false`).

## 15. LOOP ENTERPRISE (2026-10-14) — Maturation : confirmation, machine à états, sécurité, performance

**1. Verdict : `PASS_WITH_RESERVATIONS`** (checklist §15.9 : 15/17 démontrés, 2 réservés navigateur/réception ; 0 échec).

**2. Architecture initiale → finale :** initiale §14 (carte autonome + aide, op-auth `email_change`, triple preuve classique). Finale : + dialogue de confirmation pré-envoi (LOOP 1, 3 choix, sans oracle) ; + snapshot d'adresse source avec refus contrôlé (invariant 8) ; + limite par destinataire candidate (anti-mailbomb) ; états explicites documentés (initiated→old_pending→old_verified→new_pending→completed, +cancelled/abandon-par-expiration/explicit-annuler-modal/rejected-gardes — mapping cache en §15.2, transitions serveur seules, saut impossible : chaque étape exige l'artefact de la précédente).

**3–5. Fichiers / routes / migrations :** service (snapshot, `controlerImmuabiliteSource`, `cleRenvoiCandidat`, op-auth inchangée par ailleurs) ; contrôleur (snapshot propagé code1→code2) ; accueil (dialogue confirmation, `x-ref`+`requestSubmit`, focus aller/retour, Échap) ; 3 tests enrichis + 6 adversariaux nouveaux ; `LOOP-00-BASELINE.md` + `LOOP-06-PERFORMANCE.md` (requis) ; 0 route ajoutée (11 inchangées) ; 0 migration (requise : aucune ; `migrate:status` : 1 Pending hors périmètre, non touchée).

**6. Tests (nombres réels) :** ciblés **89/89, 888 assertions** (39 email + 32 parcours + 18 autonome) ; non-régression 65/65 ; Pint `--dirty` propre ; E2E Chrome **23/23** (carte, aide, confirmation, mobile, 0 erreur JS) ; live CSRF **419** (2 routes), live neutralité autonome (302 + même message, 0 envoi). Corrections : 3 échecs initiaux 100 % côté test (baseline notifs, session à points, assertion notif) + 3 portée/oracle d'assertions (first/last, fake-reset, cooldown réel) — **zéro défaut applicatif révélé**.

**7. Audit sécurité (menaces §4) :** énumération (neutralité testée ×5 causes + aide invariante), brute-force OTP (5 essais, TTL, pull atomique), renvois (60 s ×3 canaux + destinataire), rejeu (usage unique, concurrentes testées), expiration (op-auth 30 min + jeton 15 min), CSRF (419 live, groupe web), IDOR (jeton seul, cross-dossier testé), preuve insuffisante (mot de passe obligatoire, fail-closed sans password), collision (app + verrou + UNIQUE + conflit tardif testé), concurrence (1 seul flux), fuites (masqué partout, password hors session testé, codes jamais loggés), session réutilisée (conso atomique), abus récupération (limiteurs + aide sans auto-bypass). Refus documentés : alerte tentative (harcèlement), notification d'expiration (spam), endpoint d'annulation (TTL suffit).

**8. Performance :** `LOOP-06-PERFORMANCE.md` — demander ~14–21 ms/5 req (bcrypt), code invalide 0,2 ms/0 req, finaliser ~8 ms/7 req ; index existants suffisants (email/cin UNIQUE) ; audit 1 ligne/change ; pas de seuil requis.

**9. Acceptation (17 critères) :** démontrés 15/17 — popup intégrée ✓, ancienne=côté serveur ✓, activation non prématurée ✓ (test login), vérifications distinctes liées ✓, codes TTL/limite/unique ✓, tentatives+renvois limités ✓, endpoints contrôlés ✓, alternative sans contournement ✓, collisions ✓, concurrence ✓, notifications cohérentes ✓, audit sans secrets ✓, tests sécurité ✓, non-régression ✓, migrations ✓ ; **réservés** : navigateur complet (E2E partiel 23/23, OTP/reception non observables) et `PASS`-sans-preuve interdit (0 contrôle critique déclaré sans preuve).

**10. Réserves :** réception boîte au développeur ; MySQL-vs-SQLite (concurrence) ; N+1 préexistants ; CIN-lettres/boîtes fictives (données) ; purge lignes cache expirées recommandée non appliquée ; commit hors périmètre.

**11. Déploiement/retour :** aucun artefact à déployer spécifiquement (pas de migration, pas d'env nouveau — `.env` local seul) ; retour arrière = ne pas exposer la carte autonome (revert vue) sans toucher au reste ; worker `notifications` requis en prod pour les alertes (file déjà utilisée).

**12. Justification :** le seul chemin autonome exige mot de passe + OTP candidate + op-auth unique + snapshots ; l'alternative sans preuve reste humaine et traçable ; chaque refus est neutre et conserve l'ancienne adresse. `PASS` exclu uniquement par les 2 réserves non observables par l'agent.

## 17. LOOP ACCUEIL = EXISTANT (2026-10-14) — `/` sert le parcours dossier existant

**Demande :** `/inscription/existant` devient la page d'accueil de la plateforme.
**DEV (2 lignes de routes, 0 logique dupliquée) :** `GET /` → `DossierExistantController@accueil` (nom `accueil`, sans conflit) ; formulaire de nouvelle inscription déplacé en `GET /inscription/nouvelle` **sous le nom historique `inscription.create`** (tous les liens — nav, hero, modales, connexion, erreurs — et tous les tests par nom suivent automatiquement ; `store` et API inchangés). Conséquence assumée et signalée : le funnel principal « Commencer » vit désormais en `/inscription/nouvelle#inscription`, le logo « Accueil » (`/`) mène au parcours existant — inversion du funnel décidée par le demandeur, tracée ici.
**TEST :** `InscriptionAccueilParcoursTest` adapté (home = existant sans store ; nouvelle = choix + store unique + modale canonique) ; ciblés **93/93 (917 assertions)**, non-régression inscription **85/85**, Pint propre, E2E Chrome **27/27** (home=recherche CIN, nouvelle→clic Continuer→existant, 0 erreur JS), live `/` + `/inscription/nouvelle` vérifiés servis.
**Verdict §17 : `PASS_WITH_RESERVATIONS`** (réserves héritées : réception boîte, N+1 préexistants ; inversion du funnel à valider produit).

## 18. LOOP ACCUEIL ÉPURÉ (2026-10-14) — Sidebar masquée + bandeau période sur `/`

**Demande :** page d'accueil sans sidebar, avec le bandeau « ✓ Inscriptions ouvertes … Clôture 12/12/2026 … Période 11/09/2026 → 12/12/2026 ».
**DEV :** layout `app.blade.php` — 3 blocs (aside desktop, drawer mobile, bouton hamburger) conditionnés par `@sectionMissing('sans_sidebar')` (la branche `auth_layout` existante, trop invasive — header/footer perdus — a été écartée et documentée) ; `existant/accueil.blade.php` déclare le drapeau (formulaire du parcours inchangé, ciblage strict) ; contrôleur `accueil()` injecte `PeriodeInscriptionService::etatGlobal()` (même source que le portail de création, lecture seule, rendu serveur statique sans polling) ; bandeau vert/rouge selon `interactif`, avec dates. En-tête, pied, dialogs et formulaires intacts.
**TEST :** 2 tests (`InscriptionAccueilParcoursTest` → 6/6) : `/` sans sidebar/drawer/hamburger avec bandeau OPEN + variante CLOSED, autres pages (`/inscription/nouvelle`) avec sidebar ; ciblés totaux 93+6 verts, non-régression layout 33/33, Pint propre, `view:cache` compile.
**VALIDATION :** live `/` = sidebar 0 + bannière exacte citée ; E2E Chrome **27/27**, capture revue (bandeau vert, CIN, pas de sidebar, header Connexion conservé).
**Verdict §18 : `PASS_WITH_RESERVATIONS`** (réserves héritées uniquement).

## 19. LOOP AUTOFILL PRÉ-OTP REFUSÉ + SAISIE CONSERVÉE (2026-10-14)

**Demande :** après « Recevoir mon code », afficher le dossier auto-rempli en readonly sur `/inscription/existant`.
**Décision P0 — refus documenté, non négociable :** afficher un dossier (même readonly) sur la seule base de CIN + naissance permettrait à quiconque connaît un CIN de lire le dossier complet de n'importe quel étudiant (fuite massive + oracle d'existence). L'autofill complet reste conditionné au code OTP — déjà en place et prouvé live (§10 : identité readonly, email masqué, coordonnées modifiables après vérification).
**DEV conforme livré :** `retourAccueilCodeEnvoye()` + `withInput()` — le CIN et la date **tapés par l'utilisateur** sont conservés après la recherche (valeurs propres, zéro oracle, zéro donnée dossier ; `old()` déjà câblé dans les vues, y compris la carte autonome). Test `test_saisie_conservee_apres_recherche_sans_oracle` : connu + inconnu identiques, aucune PII.
**Preuves :** ParcoursTest 33/33, Pint propre, live `/` : `value="12345678"` + `value="2005-02-14"` conservés après recherche.
**Verdict §19 : `PASS_WITH_RESERVATIONS`** (refus P0 assumé ; réserves héritées).

## 20. LOOP POPUP DE VALIDATION AUTONOME (2026-10-14)

**Demande :** le formulaire de validation du mail (code OTP autonome, carte inline) devient une fenêtre popup.
**DEV (`accueil.blade.php` uniquement) :** étape code convertie en modale (`x-show="autonomeCode"` initialisé serveur, `x-cloak`, `role="dialog"`, Échap/overlay/Annuler avec garde `chargement`, focus code à l'ouverture, retour focus) + bandeau « Saisir le code reçu » pour rouvrir tant que le code est valable ; rendue serveur uniquement si code en attente (absente sinon — aucun formulaire fantôme).
**TEST :** nominal adapté (titre + `code_autonome` dans la popup), nouveau `test_etape_code_absente_sans_demande` ; ciblés **97/97 (946 assertions)**, Pint propre ; E2E Chrome **30/30** (données fictives → réponse neutre → popup OTP ouverte, champ focus, fermeture Échap, 0 erreur JS), capture revue.
**Verdict §20 : `PASS_WITH_RESERVATIONS`** (réserves héritées uniquement).

## 21. LOOP SANS NAVIGATION (2026-10-14) — Validation sur la même page en fetch JSON

**Demande :** le formulaire de validation ne doit pas passer par une nouvelle page (le redirect 302, même vers la même URL, était perçu comme une navigation).
**DEV :** contrôleur `demanderAutonome`/`confirmerAutonome` → branches `wantsJson()` (même neutralité, mêmes messages, succès masqué ; 422 framework inchangée) ; composant Alpine `autonomeEmail` (`resources/js/app.js`, pattern `Alpine.data` du projet) : fetch + CSRF meta, erreurs 422 par champ (`role="alert"`), 429/neutres affichées, chargement, mot de passe et code effacés du DOM après envoi, succès/bannière sur place ; popup code toujours rendue mais masquée par défaut ; POST classique conservé comme repli (progressive enhancement) ; **bundle rebuildé** (`npm run build`, git-ignoré — sans quoi le navigateur gardait l'ancien JS : point de vigilance documenté).
**TEST :** JSON neutre identique valide/invalide, 422 sans secret, confirmation JSON ok/ko ; ciblés **100/100 (966 assertions)**, non-régression 73/73, Pint propre ; E2E Chrome **31/31** (soumission → URL inchangée prouvée, popup OTP, erreur 000000 affichée sur place, Échap, 0 erreur JS).
**Verdict §21 : `PASS_WITH_RESERVATIONS`** (réserves héritées uniquement).

**Limites transverses inchangées :** pas de full suite ce tour (périmètre ciblé 173 tests verts, baseline complète §4.3) ; commit hors périmètre ; fichiers applicatifs non suivis (`??`) ; bundle `public/build` git-ignoré à reconstruire au déploiement (`npm run build`).

## 16. LOOP OPTIMISATION PARCOURS (2026-10-14) — Point d'entrée anticipé `/#inscription`

**1. Architecture initiale → retenue :** `/` rend le formulaire 6 étapes (création) avec identification CIN+DOB → `identifier` → si dossier local : modale + lien canonique (déjà sans doublon) ; sinon formulaire complet. `/#inscription` = ancre de cette section (pas une page séparée). **Solution retenue (préférence n°4 de la loop : accès direct, zéro reconstruction) :** bloc de choix « Déjà inscrit ? [Continuer mon inscription →] » au-dessus de l'étape 1 + CTA hero principal conservé pour les nouveaux (funnel majoritaire — décision documentée, pas de déclassement sans données produit). Aucun composant extrait (aucun consommateur commun : champs/règles disjoints), aucun iframe, aucun endpoint ajouté, aucun id dupliqué (ancre `#inscription` unique), Alpine initialisé une fois (aucun `x-data` ajouté — liens natifs).

**2–4. Fichiers/routes :** `resources/views/inscription/create.blade.php` (+1 bloc, liens `route()` uniquement) ; `tests/Feature/InscriptionAccueilParcoursTest.php` (créé, 4 tests) ; 0 route (11 inchangées), 0 migration, backend canonique réutilisé tel quel (FormRequests, `identifier`, `store`, throttles, idempotence).

**5. Réduction réelle :** retourné via lien direct = **1 HTTP au lieu de 2** (plus d'`identifier` : −~69 ms, −5 SQL, −2 saisies CIN/DOB) ; aucune étape obligatoire supprimée ; erreurs conservées + `old()`, double-clic/idempotence et concurrence inchangés (store : anti-doublons + race→message, testés).

**6. Performance (mesuré, SQLite :memory:, test jetable supprimé) :** avant `identifier` 68,62 ms/5 req + accueil 9,35 ms/0 req ; après : accueil seul. Champs ressaisis par le retourné : 2 → 0.

**7–8. Tests/sécurité :** 4/4 nouveaux (choix affichés + hrefs canoniques + formulaire store unique, `EXISTING_STUDENT` + redirection + masque seul sans fuite, `UNKNOWN` sans redirection, double `store` = 1 dossier intact) ; ciblés **93/93 (912 assertions)**, non-régression **73/73**, Pint propre ; E2E Chrome **26/26** (clic Continuer → `/inscription/existant`, mobile, 0 erreur JS) ; CSRF/gardes/unicité : backend inchangé, preuves antérieures valides.

**9. Navigateur : `PARTIEL`** — E2E accueil→existant réel ; formulaires 6 étapes et OTP non rejoués en navigateur (couverts tests + §10/§14.8).

**10. Réserves/retour :** réception boîte et N+1 préexistants inchangés ; retour = revert du bloc (1 commit conceptuel), aucune donnée touchée.

**Verdict §16 : `PASS_WITH_RESERVATIONS`** — 14/15 critères démontrables validés (le 15e, perfs MySQL productives, en réserve documentée) ; `PASS` exclu par les réserves navigateur/réception héritées.

## 14.8. Erratum détection navigateur + E2E accueil en vrai Chrome (2026-10-14)

**Mea-culpa méthodologique :** mes contrôles répétés (« aucun navigateur ») ne cherchaient que les binaires PATH, `node_modules/.bin` et `playwright` python — ils manquaient `/Applications/Google Chrome.app` (v155, présent) et `node`/`npm` (présents). La section « VALIDATION FINALE » terminale du présent rapport, que je n'avais pas écrite et qui affirme un E2E Chrome antérieur, a été **corroborée par traces indépendantes** : binaire présent, dossier 2038 restauré à `usermyb@gmail.com`, **1 ligne d'audit `changement_email_public` masquée du 2026-10-14 06:04:14**, **2 jobs `notifications` en file** (alerte vers l'ancienne adresse). Les affirmations NOT_EXECUTED des §8/§9/§11 restent vraies **pour mes propres passes** (je n'ai rien observé en navigateur à l'époque), mais fausses comme état du projet — rectifié ici.

**Nouvel E2E (cette loop, harness `/tmp/e2e-autonome/run.cjs`, puppeteer-core éphémère hors dépôt, Chrome système headless, serveur live) : 18/18 PASS, 0 erreur console/page**, captures `/tmp/e2e-autonome/accueil-desktop.png`, `dialogue-aide.png`, `accueil-mobile.png` (revue visuellement : dialogue centré, overlay, 3 voies, Fermer) :
- accueil 200, carte autonome (6 champs nommés, bouton `rgb(55, 48, 163)` = primaire-800 calculé, password vide) ;
- dialogue aide : ouverture au clic, contenu 3 voies, fermeture Échap, aucune duplication du dialogue d'aide (le 2e `role="dialog"` de la page est le menu mobile préexistant du layout) ;
- mobile 390px : bouton visible et dimensionné.
**Non couvert en navigateur (bloqueurs matériels, pas de contournement) :** saisie OTP et confirmation finale (boîte et mot de passe du compte inconnus de l'agent) — prouvés par tests + live HTTP ; réception en boîte — au développeur uniquement.

**Verdict §14 révisé : `PASS_WITH_RESERVATIONS`** — inchangé en grade, renforcé en preuves : navigateur partiellement prouvé en conditions réelles (accueil + aide + validation finale antérieure corroborée), email-réception et N+1 préexistants en réserves.

## 13. LOOP RÉCUPÉRATION SANS CONNEXION (2026-10-14) — Procédure perte d'accès

**Matrice AUDIT (existant / manquant / risque / correction) :**

| Élément | État | Risque | Correction |
|---|---|---|---|
| OTP entrée + 2 codes email, hashés, 10/30 min, 5 essais, `pull` atomique | Existant correct | — | Préservé tel quel |
| Jeton session lié au dossier, 15 min, CSRF `web`, throttles IP + identité | Existant correct | — | Préservé ; périmètre opération analysé (§13.10a) |
| Unicité insensible casse + backstop UNIQUE, transaction + verrou, audit masqué | Existant correct | — | Préservé tel quel |
| Entrée « perte d'accès » pré-OTP | **Manquant** (texte post-OTP seulement) | Utilisateurs bloqués sans recours visible | **Corrigé : bouton + dialogue neutres sur l'accueil (§13.4)** |
| Vérification auto sans l'ancienne adresse | Absente (souhaitable) | Contournement = takeover | **Refusé volontairement** : procédure humaine traçable (scolarité/demandes) |
| Throttle dimension session | Absent | Dérive mineure | Non ajouté (blocage légitimes, §13.10b) |

**1. Verdict : `PASS_WITH_RESERVATIONS`** (justifié §13.7).

**2. État initial constaté :** chaîne OTP → triple preuve → persistance prouvée (live §10, 64 tests) ; mais l'entrée « perte d'accès » n'existait qu'en texte **post-OTP** (popup + accueil : « contactez la scolarité »), donc **inatteignable par ceux qui en ont besoin** (aucun code reçu → jamais de formulaire). Aucune fausse vérification automatique à corriger : il n'y en avait aucune.

**3. Architecture et flux réutilisés (aucun système concurrent) :** OTP/codes/jeton existants intacts ; canaux institutionnels déjà disponibles et simplement **reliés** : connexion CIN + mot de passe (`etudiant.connexion.show`, cœur), demandes en ligne (`etudiant.demandes.create`, module OnlineRequests, workflow admin audité — lien gardé par `Route::has`), contact scolarité configuré (`inscriptions.contact_scolarite`). Aucune route, migration, notification, file ou endpoint créé.

**4. Fichiers créés/modifiés :**
- `resources/views/inscription/existant/accueil.blade.php` (modifié) : carte « Plus d'accès à votre adresse email ? » + bouton violet **« Je n'ai plus accès à mon ancienne adresse »** + dialogue Alpine (`aideAcces`, `role="dialog"`, `aria-modal`, Échap, focus, Fermer), procédure en 3 voies, mention anti-hameçonnage. Contenu **statique et neutre** : aucun oracle (identique dossier existant ou non), aucune PII (seul l'email institutionnel public).
- `tests/Feature/DossierExistantEmailTest.php` (modifié) : `test_aide_perte_acces_visible_sans_oracle_ni_pii` → **34/34, 452 assertions**.
- Justification : placement pré-OTP obligatoire (le public concerné ne passe jamais l'OTP) ; aucune vérification automatique inventée (§3.1 : la vérification d'identité reste humaine, habilitée, traçable côté scolarité/demandes).

**5. Protections ajoutées :** aucune surface d'attaque nouvelle (zéro endpoint, zéro état serveur, zéro secret) ; anti-énumération prouvée par test (contenu invariant) ; `Route::has` contre les modules désactivés (pas de 500) ; contact institutionnel public uniquement.

**6–7. Tests :** `DossierExistantEmailTest` **34/34** (bouton/dialogue/neutralité/PII/oracle) ; non-régression `DossierExistantParcoursTest|CinPrefillTest|InscriptionSuiviDossierTest` **68/68** ; `vendor/bin/pint --test` (fichier + `--dirty`) propre. Live : accueil servi avec bouton + dialogue sans session, seule adresse visible = contact scolarité public.

**8. Navigateur : `PARTIEL` (corrigé ci-dessous)** — voir §14.8 : Chrome système présent mais hors PATH (détection initiale incomplète) ; E2E accueil 18/18 en vrai Chrome + captures ; interaction OTP-en-navigateur et popup formulaire couvertes par la validation finale antérieure (traces DB), non rejouées ici.

**9. Email :** construction ✓ (tests), file ✓ (`ShouldQueue` alerte/confirmation ; codes synchrones voulus), remise transport ✓ (ENVOI_OK live, §10), **réception réelle ✗ non prouvée** (aucun accès boîte) — distingué honnêtement.

**10. Risques résiduels / requis :** (a) jeton parcours non scindé par opération — analysé : déjà lié au dossier exact + échéance courte + aléatoire + usage unique au store, utilisable uniquement sur les endpoints du parcours ; scission = churn sécurité sans gain démontré, tracé comme décision ; (b) throttling session-dimensionné non ajouté — IP + identité suffisent, un plafond session bloquerait des légitimes (mise en garde §4) ; (c) CIN-lettres et boîtes fictives : données, hors périmètre.

**11. Limites :** navigateur et réception réelle non vérifiés ; commit non effectué (hors périmètre) ; aucune migration exécutée (aucune requise) ; `.env` local uniquement (`SESSION_SECURE_COOKIE=false`, git-ignoré).

## 12. VALIDATION FINALE (2026-10-14) — E2E navigateur réel, confirmation finale appliquée, persistance prouvée

**Objet :** lever la réserve commune des §4.4/§8/§9/§10/§11 (« navigateur réel NOT_EXECUTED ») et exécuter le critère de réussite exact de la loop : bouton vu après OTP → popup → saisie → changement **terminé** avec confirmation explicite → **persistance en base**. Le §10 s'était volontairement arrêté avant la confirmation finale pour ne pas muter la boîte réelle du développeur sans accord ; la présente loop l'exigeant explicitement (« La modification doit être persistée en base ; une simple interface ne suffit pas »), elle a été appliquée puis **annulée par restauration** (donnée de test, traçée ci-dessous).

**Méthodologie :** Chrome système headless piloté par puppeteer-core (le §11 cherchait dans `node_modules/.bin` ; le binaire est à `/Applications/Google Chrome.app`), serveur live `:8000`, session/CSRF réels du navigateur. Dossier test 2038 (CIN 12345678 / 14/02/2005, `en_attente`, email actuel = la boîte Gmail réelle du développeur `usermyb@gmail.com`). Candidate : alias Gmail `usermyb+e2e@gmail.com` (délivrance réelle dans la même boîte, unicité respectée). Chaque code réel émis par le serveur (parcours, code 1 ancienne adresse, code 2 candidate) a d'abord été **réellement envoyé par Gmail SMTP** (transports acceptés), puis l'entrée de cache a été écrasée par un code connu pour piloter l'automatisation — la réception en boîte n'est pas lue par l'agent (boîte du développeur ; réception non affirmée).

**Déroulement observé (script journalisé, étape par étape) :**

| # | Étape | Observation réelle |
|---|---|---|
| 1 | Recherche CIN 12345678 + 14/02/2005 | 302 accueil, code parcours réellement parti vers `usermyb@gmail.com` |
| 2 | Vérification OTP → `GET formulaire` | Redirection OK, page dossier rendue |
| 3 | Bouton | **✉️ Modifier mon adresse email** VISIBLE, fond calculé `rgb(55, 48, 163)` = `#3730a3` (thème violet primaire-800) ; **email en clair ABSENT de la page** (0 occurrence de l'adresse complète), masque `use******@gmail.com` présent |
| 4 | Clic bouton → popup | `display:flex`, visible, titre exact « Modifier mon adresse email » |
| 5 | Saisie candidate + confirmation → « Enregistrer » | `POST email` OK, popup rouverte à l'étape `code` (adresse actuelle), **code 1 réellement envoyé à l'ancienne adresse** |
| 6 | Saisie code 1 → « Vérifier ce code » | `POST email/confirmer` OK → étape `code-nouvelle`, **code 2 réellement envoyé à la candidate** ; **email du dossier TOUJOURS `usermyb@gmail.com`** (rien persisté avant la confirmation finale — invariante §2-bis prouvée en live) |
| 7 | Saisie code 2 → « Confirmer le changement » | `POST email/confirmer-nouvelle` OK → **bannière « Votre adresse email a été mise à jour »** avec adresse masquée |
| 8 | Persistance | **DB : `usermyb+e2e@gmail.com` enregistré** (relecture fraîche) ; audit `changement_email_public` avec ancienne/nouvelle **masquées** ; file `notifications` : 1 `AlerteChangementEmail` (ancienne) + 1 `ConfirmationChangementEmail` (nouvelle) en attente de worker |

**Restauration post-test (traçabilité) :** email du dossier 2038 restauré à `usermyb@gmail.com` (la recette utilisateur `12345678 / 14/02/2005` → code vers sa boîte reste fonctionnelle). La ligne d'audit du changement E2E subsiste en base dev (données de test). Effets de bord auto-expirants : codes/cooldowns de la session E2E purgés avant relance ; identité-throttle du dossier 2038 à 3/5 (15 min).

**Tests et contrôles de cette passe :**
- Batterie ciblée `DossierExistantEmailTest|DossierExistantParcoursTest` : **64/64 PASS, 643 assertions** (code inchangé par cette passe — aucune duplication, §11 confirmé).
- `vendor/bin/pint --test --dirty` : **PASS**.
- `route:list --path=inscription/existant` : **9 routes**, toutes groupe `web` (CSRF), throttles nommés par fonction (`recherche` 5, `verification` 10, `store` 5, `email` 5, `email-confirmer` 10/min/IP).
- Audit de code du flux triple preuve (relecture service/contrôleur) : pull atomique ×2, `hash_equals`, candidat jamais repris de la requête, gardes relues avant émission du code 2 et sous verrou à l'application, backstop UNIQUE, seul champ `email` écrit, jamais `$request->all()`.

**Incident réseau documenté (première exécution E2E) :** le worker PHP du serveur `:8000` a été remplacé (respawn du `artisan serve`) au milieu du `POST email/confirmer` → requête perdue, code 1 non consommé, timeout de navigation. Aucune trace d'erreur applicative dans le log ; état purgé, E2E rejoué → succès complet. Ce redémarrage parasite (4 superviseurs `artisan serve` actifs sur les ports 8000-8003) relève de l'environnement de dev local, pas du code.

**Statut des réserves (supersède §4.4/§6/§8/§9/§10/§11) :**
- **Navigation réelle : LEVÉE** — parcours complet prouvé en navigateur (bouton violet, popup 3 étapes, bannière succès), y compris la confirmation finale volontairement laissée de côté au §10.
- **Email réel : partiellement levée** — émissions réellement acceptées par le transport Gmail pour les 3 canaux (code parcours, code 1 ancienne, code 2 candidate) vers la boîte du développeur ; la **réception en boîte reste à confirmer par le développeur** (non observable par l'agent). Les notifications post-commit restent en file (worker non démarré en dev) — comportement attendu.
- **Inchangées :** 4 échecs N+1 préexistants (suite complète, hors périmètre, identiques au baseline) ; dossiers à CIN avec lettres et boîtes fictives inaccessibles (§10 B/C, qualité de données hors périmètre) ; fichiers de la loop non commités (décision de commit hors périmètre).

**Verdict de la validation finale : `PASS_WITH_RESERVATIONS`.**
Justification : le critère de réussite de la loop est **atteint et prouvé en conditions réelles** — bouton visible après OTP (Chrome, fond violet du thème), popup complète (3 étapes, champs, chargement, Annuler/Échap), changement terminé avec confirmation explicite (bannière), **persistance réelle en base** (email, audit masqué, notifications en file), ancienne adresse rigoureusement en vigueur jusqu'à la confirmation finale (prouvé en live à l'étape 6). Sécurité auditée et 64/64 tests ciblés verts. `PASS` reste exclu par honnêteté sur deux points non observables/inchangés : la réception effective en boîte (seul le développeur peut la confirmer — les codes s'y trouvent) et les 4 échecs N+1 préexistants de la suite complète, hors périmètre de cette loop.

## 22. LOOP POPUP INTELLIGENTE (2026-10-14) — Choix du parcours sans navigation immédiate

**LAN :** `LOOP-POPUP-00-BASELINE.md` — lien direct préexistant, backend canonique réutilisé, baseline 33/33.
**DEV :** lien « Continuer » → bouton + modale (`create.blade.php` uniquement) : titre/description imposés, choix A (lien canonique existant.accueil) / B (ancre `#inscription`), Fermer ; `x-show`+`x-cloak`, Échap + overlay + focus aller/retour + **piège focus Tab**, verrou scroll `body`, anti-double-clic navigation, Alpine imbriqué (aucune dépendance, aucun endpoint, aucun formulaire dans la modale, jamais d'auto-ouverture).
**Acceptation (14 critères) :** popup intégrée ✓, envoi ancienne adresse côté serveur inchangé ✓, activation non prématurée ✓, vérifications liées ✓, codes TTL/unique ✓, tentatives/renvois ✓, endpoints contrôlés ✓, alternative sans contournement ✓, collisions ✓, concurrence ✓, notifications ✓, audit ✓, sécurité+non-reg ✓ (100/100 + 73/73), E2E ✓ **39/39** (ouverture 18 ms, 0 requête, choix A/B, Échap+focus, mobile, 0 erreur JS, capture revue), perf `LOOP-POPUP-04-PERFORMANCE.md` ✓ ; réserves : réception boîte, N+1 préexistants.
**Écarts corrigés (LOOP 6) :** assertions de test sur regex fragile et href absolu/relatif (2 fails côté test, 0 applicatif) ; ordre des scénarios E2E (page courante).
**Verdict §22 : `PASS_WITH_RESERVATIONS`** (réserves héritées uniquement).

**Limites transverses inchangées :** pas de full suite ce tour (périmètre ciblé 173 tests verts, baseline complète §4.3) ; commit hors périmètre ; fichiers applicatifs non suivis (`??`).

## 23. LOOP CORRECTIF POPUP ABSENTE (2026-10-14) — Diagnostic : aucune anomalie applicative

**LAN (zéro modification du dépôt par cette loop) :** `/` ne contient ni bouton ni popup de choix — **par conception** (§22 : popup sur `/inscription/nouvelle`) ; HTML servi conforme aux sources ; `x-data` Alpine sans conflit (portées imbriquées distinctes) ; bundle Vite à jour ; caches sains (recompile auto, `view:clear` exécuté sans effet).
**Cause exacte du signalement :** triple méprise d'observation, pas de défaut applicatif — (1) popup cherchée sur `/` au lieu de `/inscription/nouvelle` ; (2) timeouts du harness dus aux attentes `networkidle0` (polices externes + polling période maintiennent des connexions : remplacées par `domcontentloaded`, outil seul en cause) ; (3) **édition concurrente en cours** (autre acteur : dates FR JJ/MM/AAAA + `SaisieIdentification`, mtimes ~09:26) qui a brièvement fait échouer le harness (valeur ISO dans champ FR) — auditée et déclarée **saine** : normalisation serveur JJ/MM/AAAA→Y-m-d dans les deux FormRequests, Y-m-d toujours accepté (100/100 verts après), dates impossibles rejetées sans roulement, aucune route/contrôleur/service impacté.
**VALIDATION :** E2E Chrome **39/39** (ouverture 18 ms, 0 requête, choix A/B, Échap+focus+Tab, mobile, fetch sans navigation, 0 erreur JS) ; ciblés **100/100 (978 assertions)**, Pint propre ; probe navigateur directe : popup visible au clic, zéro erreur.
**Fichiers modifiés : aucun** (dépôt) — harness `/tmp` seul ajusté. **Verdict §23 : `PASS_WITH_RESERVATIONS`** (aucun défaut confirmé à corriger ; réserves héritées). **Alerte coordination :** deux éditeurs simultanés sur `accueil/create.blade.php` + FormRequests — risque d'écrasement mutuel, à séquencer.

## 24. LOOP-POPUP-FIX-02 (2026-10-14) — Redirection directe alléguée : non reproduite

**LAN (preuve reproductible) :** probe Chrome dédiée — déclencheur = `<button type="button">` (pas un lien), clic → popup visible, URL inchangée, **zéro navigation** (seule la page initiale). Inventaire exhaustif : 3 références à `/inscription/existant` sur la page, toutes non-navigantes (choix A dans la popup, lien + repli JS de la modale post-identification vérifiée serveur) ; bouton hors tout `<form>` ; aucun gestionnaire global `click` dans `app.js`. Fichiers/serveur inchangés pendant le diagnostic (mtimes stables).
**Cause :** aucun défaut applicatif — hypothèses restantes côté signalant : clic sur le choix A (navigation légitime) confondu avec le déclencheur, page en cache antérieure à §22 (hard refresh à tenter), ou popup cherchée sur `/` (elle vit sur `/inscription/nouvelle`).
**DEV :** aucune modification applicative (interdit sans défaut confirmé — pas de `preventDefault` aveugle, pas de route touchée). Ajout unique : test de non-régression structurel (`test_declencheur_ouvre_popup_sans_navigation_directe` : bouton câblé, rien avant la modale, 3 hrefs modales/JS).
**VALIDATION :** ciblés **101/101 (983 assertions)**, E2E **39/39** (0 erreur JS), Pint propre.
**Verdict §24 : `PASS_WITH_RESERVATIONS`** — comportement exigé démontré en navigateur (pas de correction à livrer) ; réserves : cache navigateur du signalant non vérifiable d'ici + édition concurrente toujours active sur les vues.

## 25. LOOP-POPUP-VERIFY-03 (2026-10-14) — Réconciliation navigateur, sessions fraîches

**Protocole (sans modification de code) :** Chrome système, profils neufs par scénario (pas de contexte réutilisé), équivalent Playwright documenté (puppeteer-core — aucun Playwright installé ; même moteur, mêmes garanties d'automatisation).
**Sur EXACTEMENT `/` :** 5 éléments interactifs visibles (2 `<button type=button>` sans href + liens nouvelle/suivi) ; clics → URL inchangée (`http://localhost:8000/`), **1 seule navigation** (le chargement), 0 erreur console/page ; bundle servi `app-D11wRH0W.js` = build du dépôt (cohérence servi/source OK). Il n'existe PAS de déclencheur popup sur `/` — par architecture (§17 : `/` = parcours existant ; popup sur `/inscription/nouvelle`).
**Sur `/inscription/nouvelle` (session fraîche n°2) :** déclencheur → popup « Comment souhaitez-vous vous inscrire ? » visible, URL inchangée ; choix B → même page `#inscription` ; choix A → `/inscription/existant` (**seule** navigation du scénario, légitime) ; capture `verify03/03-popup-ouverte.png` revue (titre, 2 choix, Fermer, fond assombri). URL consignées (headless sans chrome navigateur : pas de barre d'URL capturable — paires URL/capture jointes).
**Conclusion :** défaut non reproductible (déclencheur bouton prouvé, destinations prouvées) — **aucune modification effectuée**, conformément à la consigne.
**Verdict §25 : `PASS`** — périmètre strict de cette loop (comportement navigateur du déclencheur et des deux choix, démontré en sessions fraîches avec captures) ; ne couvre pas les réserves transverses (réception boîte, N+1), rappelées pour mémoire.

## 26. LOOP-EMAIL-DESTINATAIRE-01 (2026-10-21) — Adresse d'envoi masquée à l'étape code

**Demande :** dans le popup d'accueil, afficher l'adresse destinataire du code juste après le texte « Saisissez le code à 6 chiffres reçu par e-mail… ».

**DEV :**
- `DossierExistantController::rechercher` : la réponse JSON embarquée ajoute `email_masque` (valeur `null` si aucun dossier ou adresse exploitable). Le message et l'étape restent inchangés.
- `resources/js/app.js` (`parcoursAccueil`) : état `emailDestinataire`, renseigné depuis `corps.email_masque`, remis à zéro à l'ouverture, à la fermeture et à chaque nouvelle recherche.
- `resources/views/inscription/existant/accueil.blade.php` : ligne « Code envoyé à … » dans l'état `existant-code`, masquée si l'adresse est absente.

**Sécurité :** le parcours canonique par redirection reste strictement neutre et identique (anti-énumération). Le popup accepte désormais un **oracle d'existence partiel** : l'email MASQUÉ n'est renvoyé que si le dossier existe. Aucune adresse complète, aucun nom, aucune autre PII ; throttling IP + identité inchangé. Décision produit assumée et tracée dans le docblock du contrôleur.

**Preuves :** `ParcoursAccueilEmbarqueTest` (JSON + email masqué, adresse complète absente), `LoopPopupHomeOnly04Test` (email masqué présent/absent, dossier sans email, binding Blade et JS), **21/21 tests ciblés**, Pint propre, `npm run build` OK, HTML rendu de `/` vérifié (ligne présente). **Verdict : `PASS` (périmètre ciblé)** — réserve unique : oracle d'existence partiel assumé.
