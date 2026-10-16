# LOOP N — Verrouillage secondaire intelligent des sessions après inactivité

> Workflow `DISCOVER → BASELINE → AUDIT → PLAN → IMPLEMENT → TEST → VERIFY → REPORT → GATE`.
> Rapport unique de la boucle (audit, preuves, tests, verdict).

---

## 1. DISCOVER — État des lieux (13/10/2026)

### 1.1 Architecture existante (faits vérifiés, rien supposé)

| Domaine | Fait constaté |
|---|---|
| Routes | `routes/web.php` (1156 lignes) : portails publics, `etudiant.*` (session `etudiant_auth_id`, middleware `etudiant.auth`), `enseignant.*` (session `enseignant_auth_id`, middleware `enseignant.auth`), back-office `admin.*` (guard `web`, middlewares `auth` + `admin` = `EnsureBackOffice`) |
| Middlewares (alias, `bootstrap/app.php`) | `etudiant.auth`/`student` → `EnsureStudent`, `enseignant.auth` → `EnsureTeacher`, `admin` → `EnsureBackOffice`, `faculty.admin`, `super_admin`, `scope`, `idempotency`, `module`, **`secondaire.verrou` → `SecondaireVerrou` (déjà enregistré)** |
| Modèle compte back-office | `users` : rôles RBAC `super_admin`, `admin_scolarite`, `agent_scolarite`, `consultation`, `admin_faculte` (`App\Support\Permissions`, matrice en code). `password` cast `hashed`, `mfa_secret` `encrypted` |
| Profils de la mission vs rôles réels | « Administrateur / Emploi du temps / Agent administratif » ne correspondent pas à des rôles distincts de la matrice : **aucun rôle « emploi du temps » n'existe**. Les trois profils métier sont des comptes back-office du registre `users`. Décision D1 (§6). |
| Sessions | Driver `database` (`.env`, `config/session.php`), table `sessions` avec `user_id` ; gestion self-service (`SessionsController`), kill-switch super-admin (`AdministrateurController::purgerSessions`), révocation sur reset/suspension (AUTH-01) |
| MFA | TOTP (LOOP J) : `MfaController`, `AuthController::verifierDefi`, `MfaTotpService`, tests `MfaLoopJTest` (12 tests verts au baseline) |
| Paramètres | Système centralisé : `parametres` + `parametre_versions`, `ParametreService` (cache, contrôle optimiste, audit), `SettingDefinition` (catalogue typé), `ParametreController` + routes `admin.parametres.*` (permissions `settings.view/manage/restore`) |
| Layout/UI | `layouts/app.blade.php` (Alpine + Livewire ESM via Vite), composants `x-btn`, `x-page-header`, `x-alert`, thème clair/sombre existant (`class="dark"` + `localStorage theme`) |
| Livewire | Un composant métier admin : `App\Livewire\AttendanceTemplateBuilder` (page `modules.attendance.templates-admin`). Endpoint par défaut `/livewire/update` hors groupe middleware `admin`. |
| API | `routes/api.php` : Sanctum stateless + `scope:`, journal `ApiExchangeLogger` — indépendante de la session web |

### 1.2 Implémentation LOOP N déjà présente (antérieure à cette session, datée du 13/10)

Le plan `docs/engineering/loop-n-plan.md` existe. Sont **déjà livrés** (constatés, non supposés) :

- Migration `2026_10_13_073956_creer_verrouillage_pins` (table `verrouillage_pins` : `user_id` unique FK cascade, `pin_hash`, `echecs`, `bloque_until` ; seeds insert-if-missing des 6 paramètres `verrouillage.*`, catégorie `securite`). `migrate:status` : **Ran**.
- `SettingDefinition` : 6 clés (`actif` bool défaut 1 ; `delai_minutes` 5..480 déf. 15 ; `preavis_secondes` 0..300 déf. 60 ; `pin_longueur` 6..8 déf. 6 ; `tentatives_max` 3..10 déf. 5 ; `temporisation_secondes` 10..300 déf. 30).
- `App\Models\VerrouillagePin` (pin_hash caché).
- `App\Services\VerrouillageService` : éligibilité (paramètre global + PIN existant), délais bornés, `verifier()` (bcrypt + temporisation progressive 30 s × dépassement, plafond 15 min, reset sur succès), `definir()`, `purger()` (auditée), `initialiserSession()` (activité + éligibilité, appelé uniquement aux connexions).
- `App\Http\Controllers\VerrouillageController` : `code` (setup), `definir` (mot de passe actuel + TOTP si MFA + `digits:N` + `confirmed`, throttle 10/min), `verifier` (throttle 5/min), `verrouiller` (manuel), `deconnecter`.
- `App\Http\Middleware\SecondaireVerrou` : serveur juge `derniere_activite` (session) ; verrouillé → GET = écran (200, sans données) / JSON ou non-GET = 423 ; `toucher()` anti-bruit (≥ 60 s).
- Routes `admin.verrouillage.{code,definir,verifier,verrouiller,deconnecter}` (vérifiées via `route:list`).
- Vues `verrouillage/code.blade.php`, `verrouillage/ecran.blade.php` ; composant `components/verrouillage-surveillance.blade.php` (Alpine, **aucun heartbeat réseau**, `localStorage` inter-onglets `secondaire-derniere-activite`, avertissement avec action explicite, reload à expiration — le serveur tranche) ; inclusion conditionnée par `session('secondaire_eligible')` + `@php($sansSurveillance = true)` sur l'écran (anti-boucle).
- Câblage : middleware dans le groupe `['auth','admin','secondaire.verrou']` (ligne 402) ; `initialiserSession` aux 2 flux de connexion back-office (`AuthController::login` + `verifierDefi`) ; purge PIN + sessions à la suspension (`toggleStatut`) et au reset super-admin (`reinitialiser`, porte de secours PIN oublié).

## 2. BASELINE — Exécuté avant toute modification

```
php artisan test --filter="MfaLoopJTest|RevocationSessionsTest" --compact
→ {"result":"passed","tests":12,"passed":12,"assertions":57,"duration_ms":892}
```

`php artisan route:list` : PASS (les 5 routes `admin.verrouillage.*` chargées, pas d'erreur de syntaxe).
Migration : Ran. Aucun test `Verrouillage*` existant (`tests/` : 351 fichiers Feature, 0 couvrant la fonctionnalité).

## 3. AUDIT — Écarts constatés (preuves à l'appui)

### P0 — Bloquants fonctionnels

- **B1. Exemptions du middleware inopérantes.** Les routes sont nommées `admin.verrouillage.*` (groupe `name('admin.')`), or `SecondaireVerrou::EXEMPTS` contient `verrouillage.*` : `Str::is('verrouillage.*', 'admin.verrouillage.verifier')` = **faux**. Conséquence : une fois verrouillé, `POST admin.verrouillage.verifier` est intercepté (non-GET, non exempt) → `abort(423)` → **verrouillage définitif jusqu'à expiration de session**. Le déverrouillage serait impossible en production.
- **B2. Composant Livewire hors périmètre.** `/livewire/update` (groupe `web` uniquement) permettrait à une session verrouillée d'agir via le composant admin `AttendanceTemplateBuilder`. Un appel HTTP direct ne doit pas contourner le verrou (exigence n° 3).
- **B3. Setup PIN impossible pour un compte MFA.** `VerrouillageController::definir` exige `totp` quand `mfa_active`, mais `code.blade.php` ne rend **aucun champ `totp`** → validation 422 systématique pour ces comptes.

### P1 — Conformité / hygiène

- **B4.** `routes/web.php` l. 392 : deux instructions sur une ligne (`->name('verrouillage.deconnecter');    Route::post(...)`). Syntaxiquement valide mais illisible ; la route `deconnecter` porte volontairement sans `auth` (session expirée = invité pouvant se déconnecter) — à conserver, à commenter. **[Ajourné §5.5 : `auth` finalement appliqué — équivalent fonctionnel, audit `routes-securite` vert.]**
- **B5.** Drapeau `secondaire_eligible` de session potentiellement obsolète (purge PIN / désactivation globale en cours de session) → overlay persistant sans effet serveur (cosmétique). Synchronisation à prévoir au filtre du middleware.
- **B6.** Champ code de l'écran de verrouillage en clair (pas de `type="password"` ni bascule afficher/masquer, exigence §7).
- **B7.** Page `admin.verrouillage.code` orpheline : aucun lien dans la navigation (page inaccessible sans URL directe).

### Décisions documentées (pas de changement de code, justification)

- **D1. Périmètre profils** : verrouillage uniforme sur tout compte back-office authentifié (5 rôles, aucun privilège ajouté, policies/middlewares existants inchangés). Les trois profils métier cités (Administrateur / Emploi du temps / Agent administratif) n'ont pas de rôle dédié dans `Permissions::ROLES` ; les portails étudiant/enseignant n'ont **pas** de compte `users` → hors périmètre (décision déjà tranchée dans `loop-n-plan.md`, confirmée : le middleware s'appuie sur `Auth::user()` du guard web).
- **D2.** Reset self-service du mot de passe (`MotDePasseController`) : sessions révoquées (contexte coupé, AUTH-01) ~~mais **PIN conservé**~~ → **[Ajourné §5.4 : le PIN est finalement PURGÉ au reset self-service (`purger(..., 'reinitialisation_mot_de_passe')`) — posture plus stricte : tout changement de mot de passe invalide le contexte de déverrouillage, y compris le secret.]** La porte de secours « PIN oublié » = redéfinition autonome (mot de passe + TOTP) ou purge super-admin (audit `verrouillage.pin_purge`).
- **D3.** API Sanctum : canal machine stateless à scopes, hors verrouillage de session web (pas de session à verrouiller). Une requête `/api/*` ne prolonge ni n'est couverte par le verrou — cohérent avec l'exigence n° 5.
- **D4.** « Sans PIN défini, jamais de verrouillage effectif » (garde anti-blocage de `estEligible`) conservée : verrouillage massif impossible après activation globale.
- **D5.** Données non enregistrées : l'URL ne change jamais ; GET verrouillé reçoit l'écran (200) ; POST verrouillé reçoit 423 — le formulaire reste dans l'historique navigateur (retour arrière = bfcache). Résidu documenté §9.

## 4. PLAN — Interventions (delta minimal, aucune refonte)

1. `SecondaireVerrou` : exemptions corrigées → `admin.verrouillage.verifier`, `admin.verrouillage.deconnecter`, `admin.connexion.logout`, `etudiant.connexion.logout`, `enseignant.connexion.logout`, `admin.connexion.defi*`. **Setup NON exempté** (`code`/`definir` exigent l'état déverrouillé — plan initial). Synchronisation du drapeau `secondaire_eligible` à chaque passage (B5).
2. `routes/web.php` : réparation de la ligne 392 + commentaire justifiant l'absence d'`auth` sur `deconnecter` (B4).
3. `AppServiceProvider::boot` : `Livewire::setUpdateRoute()` avec middleware `secondaire.verrou` ajouté (B2) — invités non impactés (garde `user() === null`).
4. `verrouillage/ecran.blade.php` : `type="password"` + bascule Alpine afficher/masquer ; attributs ARIA conservés (B6).
5. `verrouillage/code.blade.php` : champ TOTP conditionnel (`mfa_active`) + état « code déjà défini » ; contrôleur `code()` fournit l'état (B3).
6. `layouts/app.blade.php` : lien « Code de sécurité » dans le menu compte back-office (B7).
7. Tests `tests/Feature/VerrouillageSecondaireTest.php` : couverture §8 de la mission (3 profils, sécurité, non-contournement, Livewire, CSRF).
8. `php artisan test` ciblé + non-régression adjacente ; `vendor/bin/pint` sur les fichiers touchés.

Risques résiduels (détail §9) : PIN = secret faible par construction (temporisation progressive + throttle + bcrypt compense partiellement) ; désactivation globale « douce » (plus de nouveau verrou, les sessions verrouillées restent verrouillées jusqu'à déverrouillage/expiration — comportement conservateur assumé).

---

## 5. EXTENSION — Security Session Lock Pro (13/10/2026, même boucle, pas de boucle nouvelle)

### 5.1 Validations préalables (contraintes LOOP respectées)

| Question | Réponse validée |
|---|---|
| Les 3 profils (aucun rôle « Emploi » n'existe, cf. D1 §1.1) | Tous : couverture uniformisée sur les **5 rôles back-office** (`super_admin`, `admin_scolarite`, `agent_scolarite`, `consultation`, `admin_faculte`), sans privilège ajouté |
| Récupération PIN oublié | Les deux voies **existantes** : redéfinition autonome par mot de passe (+ TOTP si MFA) hors verrou + purge super-admin ; consigne ajoutée sur l'écran verrouillé |
| Livrable | Rapport ici + suites ciblées (pas de suite complète) |

Aucune permission, aucun rôle, aucune règle métier créés.

### 5.2 Audit avant (preuves file:line, rien supposé)

**P1 — Contournement serveur.**
- `secondaire.verrou` appliqué au seul groupe `routes/web.php:409` : les **15 groupes admin des modules** (`app/Modules/*/Routes/web.php`, ex. Evaluation `:27`, Schedule `:17`, Sentinel `:13`) servaient les sessions verrouillées sans écran ni 423 (preuve par test : `test_routes_modules_couvertes_par_le_verrou` RED avant correctif).
- B2 ouvert : `/livewire/update` sans le middleware (vérifié `route:list` : seul `web` + `RequireLivewireHeaders`) — un composant admin restait actionnable sous verrou.
- `POST /admin/verrouillage/deconnecter` sans aucun middleware → `audit:routes-securite` en échec (exit 1, `SANS AUTH`), test `test_audit_routes_sans_critique` rouge.

**P1 — Perte de données.** POST classique sous verrou (voile local en retard : nouvel onglet, délai abaissé) → 423, corps perdu, aucun rejeu (aucune idempotence web).

**P2 — Cohérence onglets/veille.**
- Nouvel onglet : `derniere = Date.now()` = plein délai offert même si le serveur est expiré.
- `resterConnecte()` réarmait le minuteur même sur 423 (reset aveugle).
- Onglet voilé ignorant les synchros d'activité (verrou fantôme) ; déverrouillage transitif sans PIN (même session — choix documenté, pas une faille).
- Horloge client fausse = minuteur faux (`Date.now()` seul).

**P2 — Divers.** B3 ouvert : `definir` exige `totp` si MFA mais `code.blade.php` sans champ → 422 systématique pour ces comptes. B6 ouvert : champ écran en clair, sans bascule. B7 ouvert : page code orpheline (aucun lien). `preavis=0` et plancher 300 s non documentés côté page.

**Conformes (non retouchés).** PIN bcrypt + `hidden`, throttle 10/1–5/1–30/1, temporisation progressive plafonnée (`VerrouillageService:130-150`), incrément atomique, `prolonger()` sans réanimation, logs sans PII (`acteur_id` + IP uniquement), paramètres validés (`SettingDefinition:70-99`, plages + historique `ParametreVersion`), Gates `settings.*` + throttles, API Sanctum à scopes (canal machine, hors session web — décision D3 conservée), séparation verrou/session/auth (aucun `logout` sous verrou).

### 5.3 Après — delta implémenté

| # | Fichier(s) | Changement |
|---|---|---|
| 3 | 15 × `app/Modules/*/Routes/web.php` | `+ 'secondaire.verrou'` sur chaque groupe `['auth','admin',…]` (portails `etudiant`/`enseignant` exclus par décision) |
| 3 | `SecondaireVerrou.php` | Mode `passif` (`handle(..., string $mode)`) : verrou imposé, `toucher()` sauté ; docblock |
| 3 | `Evaluation/Routes/web.php:213` | Sonde `runs/{run}/status` (polling 2,5 s) en `secondaire.verrou:passif` (pas de keep-alive) |
| 3 | `AppServiceProvider::boot` | `Livewire::setUpdateRoute()` + `secondaire.verrou` (B2 fermé ; invités/non-éligibles traversent) |
| 3 | `routes/web.php` | `auth` sur `verrouillage/deconnecter` (audit vert ; verrouillés authentifiés non impactés — **écart assumé à B4 §3**, voir §5.5) |
| 1 | `verrouillage-surveillance` (composant + `layouts/app.blade.php:357`) | Horloge ancrée serveur (`:ancreServeur="time()"`) ; adoption activité inter-onglets bornée (anti-délai-offert, anti-fossile) ; `tic()` immédiat au démarrage ; `resterConnecte()` ne réarme que sur 204 (message sur 423) ; reprise inter-onglets sous voile via `prolonger` (204 → levé, 423 → maintenu) ; interception des soumissions après expiration (voile + renvoi manuel, DOM intact) |
| 9 | `affectations-index.blade.php`, `affectations-runs.blade.php` | 423 des sondes → `location.reload()` (écran serveur) au lieu d'ignoré silencieux |
| 5 | `SessionsController::destroy` + `admin/sessions/index.blade.php` | Révocation d'autrui exige le mot de passe actuel (réauthentification adaptée, pattern `mfa.desactiver`) ; révocable même sous verrou (voie de secours) |
| 4 | `verrouillage/code.blade.php` + `VerrouillageController::code` | Champ TOTP conditionnel (`mfaActif`, B3 fermé) ; état d'enregistrement + délai + longueur affichés |
| 4 | `verrouillage/ecran.blade.php` | `type="password"` + bascule afficher/masquer (B6 fermé) ; anti-double-clic ; consigne « Code oublié ? » (déconnexion → reconnexion → redéfinition) |
| — | `layouts/app.blade.php` (menu compte) | Lien « Code de verrouillage » (B7 fermé) |
| 7 | `Sentinel/Services/Checks/VerrouillageSecuriteCheck.php` + `config/sentinel.php` | Contrôle `SECURITE_VERROUILLAGE` (verrou désactivé → constat ; codes bloqués → info ; comptes uniquement, zéro PII) |
| 8 | — | Vérifié conforme, inchangé : plages, Gates, historique, throttles (preuves §5.2) |

### 5.4 Tests — résultats

```
VerrouillageSecondaireTest : 18/18 (5 rôles × écran+423 ; modules ; sonde passive
  200-sans-toucher + 423-sans-toucher ; révocation sous verrou ± mot de passe ;
  Sentinel ; Livewire ; MFA+TOTP ; états page ; français sans clé brute)
Voisins : MfaLoopJTest + MfaTotpServiceTest + SentinelTest/Metier/Convergence/
  SettingsLoop/Backup + SecuritePhaseFTest (81/81) ; IndustrialisationTest (9/9,
  audit:routes-securite exit 0) ; AffectationEnseignantExamenTest +
  AffectationAutomatiqueJobTest (43/43) ; Schedule* (74/74) ;
  Authorization/XssCsrf/Durcissement/ArchiveAuthorization (51/51) ;
  AttendanceTemplateBuilder + BackOffice + ComptesPhaseD (25/25) ;
  AuditLog + JournalisationPrivee + SensitiveAudit + RevocationSessions (18/18)
vendor/bin/pint --dirty : propre (1 normalisation d'imports hors sujet acceptée)
```

Ajustement assumé : budget perf page affectations `30 → 32` requêtes (`AffectationEnseignantExamenTest:899`) — surcoût **borné et constant** du middleware (+1 existence PIN indexée, paramètre cachée), indépendant des 15 lignes : pas un N+1 par construction (middleware unique/requête).

### 5.5 Décisions ouvertes, écarts et risques résiduels

- **B4 vs audit** : `deconnecter` passe avec `auth` (écart au §3 « à conserver sans auth »). Justification : les verrouillés restent authentifiés (aucune régression du bouton), les invités sont redirigés vers la connexion (équivalent fonctionnel), l'audit repasse au vert. Retour arrière : retirer `->middleware('auth')` (1 ligne) + accepter l'échec d'audit.
- **Portails enseignant/étudiant** : hors verrou (D1). Un enseignant peut définir un PIN (route `auth` seule) sans effet sur ses routes : état incohérent possible mais inoffensif — réserve : interdire ou câbler ultérieurement (décision requise, non implémentée).
- **API Sanctum** : hors verrou par construction (D3). Un token volé n'est pas mitigé par le verrou d'inactivité — réserve assumée (TTL 90 j + scopes, AUTH-06).
- **Déverrouillage transitif inter-onglets** : même session = même droit ; `localStorage` falsifiable en console mais sans effet serveur (réimposition à la prochaine requête du groupe). Documenté, non corrigé.
- **Veille/navigateurs/production : NON VÉRIFIÉS.** Le composant a été relu ligne à ligne (timers, `visibilitychange`, propagation) mais aucun navigateur réel, aucune mise en veille réelle, aucun environnement de production n'a été exercé dans cette session — **réserve explicite**.
- **Idempotence web** : `IdempotencyKey` reste cantonné à 3 POST API (TOCTOU connu, SEC-10) ; les opérations du verrou sont naturellement idempotentes (`updateOrCreate`, reset `echecs`) + throttles + boutons désactivés. Non étendu (décision).

### 5.6 Retour arrière (par changement, indépendants)

1. Groupes modules : retirer `'secondaire.verrou', ` (15 lignes, `sed` inverse documenté).
2. `passif` : retirer le paramètre + la mention sur `runs.status`.
3. Livewire : supprimer le bloc `setUpdateRoute` (+ import).
4. `deconnecter` : retirer `->middleware('auth')` (réouvre l'échec d'audit — assumé).
5. Sessions : retirer la validation `password` + le champ (restaure la révocation en 1 clic).
6. Client : revenir au composant précédent (supprimer `ancreServeur`, interception, reprise).
7. Sentinel : retirer la classe + les 2 lignes de `config/sentinel.php`.
8. Vues B3/B6/B7/écran : suppressions locales.
9. Budget perf : `32 → 30` (redevient rouge tant que le middleware couvre les modules).

### 5.7 Verdict : PASS_WITH_RESERVATIONS

Fonctionnel et couvert par preuves + tests cibles et voisins verts. Réserves : navigateurs/veille/production non exercés (§5.5), portail enseignant incohérent mais inoffensif, API hors verrou par décision.

---

## 6. VERIFY — Validation indépendante de fin de boucle (13/10/2026, ~11:15)

Exécutions réelles de cette session (aucune affirmation sans exécution) :

| Vérification | Commande | Résultat mesuré |
|---|---|---|
| Cible LOOP N | `php artisan test --filter="VerrouillageSecondaireTest" --compact` | **PASS** — 18/18 tests, 160 assertions |
| Voisins auth/MFA/paramètres/sentinel | `--filter="VerrouillageSecondaireTest\|MfaLoopJTest\|MfaTotpServiceTest\|RevocationSessionsTest\|SettingsContratLoopBTest\|SentinelTest\|SentinelMetierTest\|SentinelConvergenceTest"` | **PASS** — 90/90, 371 assertions |
| Module Livewire + affectations + schedule + API auth | `--filter="AffectationEnseignantExamenTest\|AffectationAutomatiqueJobTest\|ScheduleAccesTest\|ScheduleVerrouConcurrenceTest\|AttendanceTemplateBuilderTest\|ArchiveAuthorizationTest\|ApiAuthenticationTest"` | **PASS** — 109/109, 380 assertions |
| Sécurité transversale | `--filter="XssCsrfSecurityTest\|DurcissementTransversalTest\|SecuritePhaseFTest\|IndustrialisationTest\|JournalisationPriveeTest\|AuditLogTest\|SensitiveAuditTest"` | **PASS** — 37/37, 150 assertions |
| Audit routes back-office | `php artisan audit:routes-securite` | **PASS** — exit 0 (« Routes admin : authentification OK », 58 à revue humaine — seuil informationnel préexistant, hors LOOP N) |
| Style | `vendor/bin/pint --test` (fichiers LOOP N + routes modules + vues) | **PASS** — aucun écart |

Câblage vérifié au moment du GATE (relectures naïves) : EXEMPTS du middleware restreintes à `verifier`/`deconnecter` + déconnexions + défi MFA (B1 fermé, préfixe `admin.` correct) ; mode `passif` présent et appliqué à la sonde `runs/{run}/status` ; `Livewire::setUpdateRoute` actif (`AppServiceProvider` l. 160-167) ; 15 groupes `app/Modules/*/Routes/web.php` porteurs de `secondaire.verrou` ; `admin.verrouillage.deconnecter` sous `auth` (routes/web.php l. 396) ; champ TOTP présent sur la page de réglage ; écran autonome en `type="password"` + bascule ; lien menu « Code de verrouillage » ; contrôle `SECURITE_VERROUILLAGE` enregistré (`config/sentinel.php` l. 41) ; entrée registre `SEC-13` conforme à la structure, sans doublon.

### Statuts par phase (LOOP workflow)

| Phase | Statut | Preuve |
|---|---|---|
| DISCOVER | PASS | §1 (tableaux file:line vérifiés) |
| BASELINE | PASS | §2 (12/12 avant tout changement) |
| AUDIT | PASS | §3 + §5.2 (écarts avec preuves, reproductions RED avant correctif) |
| PLAN | PASS | §4 + `loop-n-plan.md` (antérieur au code final) |
| IMPLEMENT | PASS | §5.3 + mes corrections B1/B5 (middleware) |
| TEST | PASS | `VerrouillageSecondaireTest` 18/18 (3 profils RBAC + consultation implicitement via garde uniforme, MFA, temporisation, Livewire, modules, Sentinel, français des messages) |
| VERIFY | PASS | §6 (mesures de cette session) |
| REPORT | PASS | Ce document + registre (`SEC-13`) |
| GATE | **PASS_WITH_RESERVATIONS** | Réserves §5.5 : navigateur réel / veille-machine / production **NOT_EXECUTED** (aucune validation opérationnelle); portail enseignant peut détenir un PIN sans effet (réserve assumée, décision produit à trancher hors loop) ; API Sanctum hors verrou (décision D3) ; suite complète (3 000+ tests) non rejouée en fin de boucle — cibles + voisins seulement, **PASS_WITH_RESERVATIONS** confirmé, jamais PASS prod. |
