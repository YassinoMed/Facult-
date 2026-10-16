# LOOP O — Levée des réserves du verrouillage secondaire

> Autorisation : ordre explicite de l'utilisateur (session du 13/10/2026), à partir de `docs/engineering/loop-n-rapport.md` §5 et `SEC-13`. Aucune boucle suivante lancée. Aucun test de production, aucun envoi réel. Environnement : dev local (`http://localhost:8000`, MySQL `inscription_etudiants`, compte éphémère `e2e.ephemere@universite.test` créé puis supprimé — §3.5).

---

## Étape 1 — Réconciliation des preuves

### 1.1 Documents sources confirmés

- `docs/engineering/loop-n-rapport.md` §5 (EXTENSION Security Session Lock Pro) : présent.
- `docs/registre/registre-canonique.md` : entrée `SEC-13` présente.

### 1.2 Constats B2/B3/B4/B6/B7 → fichiers, contrôles, état

| Constat | Fichiers et contrôles | État au 13/10/2026 |
|---|---|---|
| B2 — `/livewire/update` hors verrou | `AppServiceProvider::boot` (`Livewire::setUpdateRoute` + `secondaire.verrou`, vérifié `route:list`) ; test `test_livewire_update_traverse_le_verrou` | **Corrigé + prouvé** (invités/non-éligibles traversent, garde `user() === null`) |
| B3 — `definir` exige `totp` si MFA, champ absent | `verrouillage/code.blade.php` (bloc `@if($mfaActif)`), `VerrouillageController::code` (`mfaActif`) ; test `test_definition_pin_compte_mfa_exige_totp` (sans → erreur `totp`, faux → erreur, valide → PIN créé) | **Corrigé + prouvé** |
| B4 — `deconnecter` sans middleware | `routes/web.php` (`->middleware('auth')`, 1 ligne) ; `audit:routes-securite` exit 0 ; test `test_audit_routes_sans_critique` ; HTTP §5 | **Corrigé + prouvé** (écart assumé à la note B4 du rapport N, voir §5) |
| B6 — champ écran en clair | `verrouillage/ecran.blade.php` (`type="password"` + bascule `verrou-afficher`) ; assertions `test_inactivite_verrouille_get_et_post` | **Corrigé + prouvé** |
| B7 — page code orpheline | `layouts/app.blade.php` (lien « Code de verrouillage ») ; assertion `test_overlay_present_si_eligible_seulement` | **Corrigé + prouvé** |

### 1.3 Corrections réalisées vs risques acceptés vs non exécuté

- **Réalisées (preuves §1.2 + §6)** : 15 groupes modules couverts, sonde passive, cohérence onglets (ancre serveur, adoption bornée, reprise sous voile), interception soumissions, réauth révocation, Sentinel `SECURITE_VERROUILLAGE`, B2/B3/B4/B6/B7.
- **Risques acceptés (décisions)** : API Sanctum hors verrou (§2), portail enseignant inchangé (§4, décision utilisateur : statu quo), déverrouillage transitif inter-onglets = même session, IdempotencyKey inchangé (SEC-10).
- **Non exécuté** : validation en vrai navigateur (aucun navigateur connecté à la session, aucune suite E2E dans le dépôt) → statut `NOT_EXECUTED` (§3) ; vraie mise en veille (idem) ; production (interdite sans autorisation).

### 1.4 Nouvelles exécutions (réconciliation)

```
VerrouillageSecondaireTest : 18/18, 160 assertions — PASS
vendor/bin/pint --dirty : propre — PASS
audit:routes-securite : exit 0 (« 58 à revue humaine ») — PASS
```

---

## Étape 2 — API Sanctum : inventaire et décision (aucun changement de comportement)

### 2.1 Inventaire (`routes/api.php`, 274 lignes)

- Préfixe `v1` ; publiques : `health`, `ready`, `version`, `webhooks/external-system` (HMAC+horodatage, pas de Sanctum).
- Groupe `auth:sanctum` : ~60 routes. Lectures à scopes fins (`students:read`, `archives:read`, `grades:read`, `documents:read`, `referentiel:read`, `assignments:read`, `versioning:read`) ; écritures sensibles à scopes dédiés : `registrations:write` (`reenroll`), `imports:create` (store/confirm), `schedules:import` (preview/confirmer), `data:exchange`, `assignments:write` (simuler/confirmer), `exports:create`.
- Toutes les routes sanctum portent `throttle:api` (+ `scope:*` + `CheckApiScope` : 403 générique sans divulgation).

### 2.2 Jetons et gardes

- Émission exclusive CLI (`IntegrationTokenCreate` : `createToken('integration-…', $scopes)`, moindre privilège) ; révocation CLI (`IntegrationTokenRevoke`, listage `IntegrationTokens`).
- Expiration `config/sanctum.php` : 90 jours (`SANCTUM_TOKEN_EXPIRATION`).
- `SecondaireVerrou` absent de la pile API (volontaire) : le guard token est stateless, sans session à verrouiller.
- Cas session-cookie sur `/api/*` (Sanctum stateful, `localhost:8000` inclus par défaut) : **aucun appel frontend vers `/api/*`** (grep `resources/views`, `resources/js` : vide) — pas de chemin de contournement praticable aujourd'hui.

### 2.3 Décision documentée (sans modification, sans validation requise car inchangé)

Le verrouillage d'inactivité **ne s'applique pas** à l'API : concept de session humaine vs credential machine (scopes + TTL + throttles). Conséquences assumées : un token volé reste utilisable jusqu'expiration/révocation ; les écritures sensibles API (`reenroll`, `imports/confirm`, `schedules/import/confirmer`, `assignments/confirmer`) ne demandent pas de réauthentification pas-à-pas.
**Décision ouverte** (validation explicite requise avant tout changement) : step-up auth sur les écritures sensibles et/ou TTL raccourci par scope — non implémenté. Adjacent : `SEC-10` V-1 (reenroll cross-faculté, P2 NOT_STARTED) reste le propriétaire du durcissement API.

---

## Étape 3 — Validation navigateur / E2E HTTP

### 3.1 Disponibilité : navigateur NON connecté, aucune suite E2E

- Dépôt : ni Dusk, ni Playwright, ni Cypress, ni Selenium (`composer.json`/`package.json` vérifiés).
- Outil navigateur de la session : `browser.disconnected` (aucun navigateur de bureau connecté).
- **Verdict partiel : validation en vrai navigateur = `NOT_EXECUTED`** (aucune déclaration contraire). Sont exécutés à la place : scénarios HTTP réels contre le serveur dev (§3.2–§3.4) + assertions DOM côté tests PHPUnit (voile, écran, préavis).

### 3.2 E2E HTTP phase A/A2 — connexion, PIN, MFA, reprise (serveur dev local, exécuté)

```
login=302 -> /admin · dashboard=200 (6 occ. « Tableau de bord »)
code_page=200 · avertissement « Aucun code enregistré »=1
definir=302 -> /admin/verrouillage/code · bandeau « Un code est enregistré »=1
mfa_page=200 · secret_len=32 · totp_len=6
mfa_confirmer=302 -> /admin/double-facteur
sessions=200 · session_courante_listee=1
logout=302 -> /admin/connexion · dashboard_apres_logout=302 -> /admin/connexion
relogin=302 -> /admin/connexion/defi (défi MFA exigé) · defi=200
defi_verifier=302 -> /admin · dashboard_apres_mfa=200 (6 occ.)
```

### 3.3 E2E HTTP phase B — inactivité réelle ~6 min (délai configuré 5 min) : EXÉCUTÉ

Session du §3.2 laissée strictement inactive 360 s (aucune requête), puis :

```
dashboard=200 · ecran_occ=3 (« Session verrouillée » : title + h1 + aria)
consigne_recuperation_occ=1 (« Code oublié ? … ») · token_ecran_present=1
sessions_sous_verrou=200 (voie de secours accessible, par décision)
pin_faux=302 (retour avec erreur, sans indice) · pin_bon=302
dashboard=200 · dashboard_ok=6 (« Tableau de bord ») · ecran_inverse=2
  (voile client présent-masqué en DOM : h2 + aria-label — état déverrouillé normal)
```

Preuves : verrou effectif après inactivité réelle côté serveur ; PIN faux refusé sans indice ; reprise **sans reconnexion** après bon PIN ; écran serveur complet avec jeton CSRF et consigne de récupération. Non prouvé ici (pas de JS) : apparition du voile/préavis côté client — `NOT_EXECUTED` (§3.4).

### 3.4 Non couvert (NOT_EXECUTED)

Rendu visuel du voile/préavis, minuterie JS d'inactivité, synchronisation multi-onglets côté client, vraie mise en veille OS, `visibilitychange` réel, comportement tactile/mobile.

### 3.5 Traçabilité et nettoyage

Compte `e2e.ephemere@universite.test` (id 16, super_admin local) : créé pour l'E2E puis **supprimé** (cascade PIN vérifiée : pin=0), sessions purgées (0 restante), scripts `tmp/e2e_loopo_*.sh` et jars **supprimés** après exécution. Base dev rendue à son état (aucun PIN résiduel de test).

---

## Étape 4 — Portail enseignant : risque réel et décision

### 4.1 Inventaire (preuves file:line)

- Authentification **séparée** : table `teachers` + session `enseignant_auth_id` (`EnsureTeacher`), jamais de compte `users` → `SecondaireVerrou` (clé sur `Auth::user()` du guard web) **inapplicable en l'état**.
- Écritures sensibles sans verrou d'inactivité : saisie/import de notes (`Evaluation/Routes/web.php:364-372`, throttles 10/1), signalement d'incidents (`:358`), demandes/réclamations + clôture (`routes/web.php:273+`), changement de mot de passe (`profil/mot-de-passe`), exports CSV/nominatifs.
- Existant : auth dédiée + throttles par route, session 120 min, zéro `wire:poll`.

### 4.2 Risque réel (sans minimisation)

Poste enseignant partagé ou inattenté sans surveillance : un tiers présent physiquement dispose de **jusqu'à 120 minutes** d'accès (saisie de notes = intégrité des évaluations, EXM-01). Probabilité modérée (salles des profs, vidéoprojection), impact fort (notes). Ce n'est pas « inoffensif parce qu'inaccessible » : le portail est accessible à tout enseignant authentifié.

### 4.3 Décision métier demandée et obtenue

Question posée le 13/10/2026 (options : statu quo / étendre le verrou / session plus courte). **Réponse : statu quo documenté.** Aucune suppression, aucun élargissement de permission. Réserve conservée ; toute évolution (adaptation du middleware aux sessions `enseignant_auth_id`, ou durée de session dédiée) exige une nouvelle validation + boucle enregistrée.

---

## Étape 5 — Déconnexion B4 et retour arrière

### 5.1 Écart et modification (1 ligne)

`routes/web.php` : `Route::post('verrouillage/deconnecter')` + `->middleware('auth')`. Écart assumé à la note B4 du rapport N (« conserver sans auth ») : les verrouillés restent authentifiés (bouton opérationnel, prouvé §5.2), les invités sont redirigés vers la connexion (équivalent fonctionnel), et l'audit `audit:routes-securite` (porte `test_audit_routes_sans_critique`) repasse au vert.

### 5.2 Vérifications HTTP (serveur dev local, exécuté)

```
CSRF sans token (deconnecter, verifier) : 419 + 419 — PASS
Invité + token valide sur deconnecter : 302 -> /admin/connexion (auth exigée, aucun logout) — PASS
Déconnexion sous verrou : 302 (test PHPUnit test_session_expiree_… + E2E §3.2 logout) — PASS
```

### 5.3 Retour arrière et effets

Retirer `->middleware('auth')` : restaure le comportement B4 (invités nettoyés sans redirection) **mais** rouvre `SANS AUTH` → `audit:routes-securite` exit 1 → `IndustrialisationTest::test_audit_routes_sans_critique` rouge. Effet sécurité du retrait : surface logout-CSRF (faible, jeton CSRF toujours exigé via groupe `web`).

---

## Étape 6 — Performance et non-régression

### 6.1 Mesure attribuée (test éphémère créé, exécuté, supprimé)

Page module `admin.sentinel.index` avec journal requêtes : **TOTAL=33 dont ATTRIBUEES_VERROU=2** (lecture paramètre `verrouillage.actif` + existence PIN indexée). Coût constant par requête (middleware unique dans le pipeline), indépendant du nombre de lignes : le budget `30 → 32` (`AffectationEnseignantExamenTest:899`, 15 lignes → 31) est justifié par mesure comparable, pas un N+1 par construction.

### 6.2 Nouvelles exécutions (consolidation finale, une seule commande)

```
303/303 tests, 1242 assertions — PASS (12,2 s) :
  VerrouillageSecondaireTest (18) · MfaLoopJTest · MfaTotpServiceTest ·
  SentinelTest · SentinelMetierTest · SentinelConvergenceTest ·
  SettingsSentinelLoopTest · BackupSentinelTest · SecuritePhaseFTest ·
  IndustrialisationTest (9/9) · AffectationEnseignantExamenTest ·
  AffectationAutomatiqueJobTest · ScheduleAccesTest · ScheduleBoardTest ·
  ScheduleSecurityTest · ScheduleTest · EmploiSurveillancesTest ·
  AuthorizationSecurityTest · XssCsrfSecurityTest · DurcissementTransversalTest ·
  ArchiveAuthorizationTest · AttendanceTemplateBuilderTest · BackOfficeTest ·
  ComptesPhaseDTest · AuditLogTest · JournalisationPriveeTest ·
  SensitiveAuditTest · RevocationSessionsTest
vendor/bin/pint --dirty : propre — PASS
audit:routes-securite : exit 0 (« 58 à revue humaine ») — PASS
```

### 6.3 Résultats exacts

Voir §6.2 (303/303). Aucun échec, aucune erreur, aucun skipped imputable aux changements (skips préexistants éventuels inchangés).

---

## Décisions ouvertes et risques résiduels

1. Step-up API / TTL par scope : validation requise (SEC-10 adjacent).
2. Portail enseignant : statu quo validé, réserve conservée.
3. Navigateur/veille/prod : `NOT_EXECUTED` (pas de navigateur connecté, pas de suite E2E, production interdite).
4. Déverrouillage transitif inter-onglets, IdempotencyKey cantonné API : acceptés (LOOP N §5.5).

## Verdict : PASS_WITH_RESERVATIONS

Toutes les réserves traitables sans navigateur ni production sont levées avec preuves (inactivité réelle serveur, MFA, révocation, B2/B3/B4/B6/B7, API documentée, portail enseignant tranché, perf mesurée, 303/303 + pint + audit verts). Réserves restantes : rendu/JS navigateur et vraie veille (`NOT_EXECUTED`, aucun navigateur connecté), production non testée (interdite). Ne pas déclarer la production validée.

