# LOOP P — Corrections sécurité, validation navigateur et clôture SEC-14

> Référence : `docs/engineering/loop-o-rapport.md` (LOOP O, SEC-14).
> Registre : `docs/registre/registre-canonique.md` → nouvelle entrée **SEC-15**.
> Environnement : dev local macOS (PHP 8.5, Laravel 13, MySQL dev pour PHPUnit ;
> serveur E2E isolé `sqlite:/tmp/loop-p-e2e/e2e.sqlite` sur `127.0.0.1:8123`,
> base détruite après exécution). Navigateur réel : Google Chrome stable piloté
> par Playwright (`playwright-core`, canal `chrome`, headless).
> Aucun test en production, aucune notification réelle, aucun dossier étudiant
> réel manipulé. Aucun secret dans ce rapport.
>
> État Git initial : arbre **déjà modifié non commité** (travail LOOP N/O en
> cours : 42 fichiers, branche `main`). Consigné sans initialiser ni toucher
> à l'historique. Les modifications LOOP P sont listées au §5 (6 fichiers).

---

## 1. Résumé exécutif

| Domaine | État initial | État final | Preuve |
|---|---|---|---|
| Middleware serveur (`/livewire/update`) | Middleware présent mais **inopérant** (500 / contournement) | Corrigé : ordre `web` → verrou + fail-safe | Test RED→GREEN `test_livewire_update_bloque_sous_verrou` |
| PIN et MFA | Partiellement couverts | Récupération sous verrou prouvée (mot de passe toujours exigé) | `test_recuperation_sous_verrou_exige_mot_de_passe` |
| Limitation des tentatives | Budgets partagés entre routes (429 prématurée démontrée) | Compartiments par fonction | `test_throttles_verrouillage_isoles_par_fonction` + mise à jour `test_seuil…` |
| Voile JavaScript | Non exécuté | **12/12 PASS en vrai navigateur (2 passages)** | Suite `/tmp/loop-p-e2e/e2e.mjs` (transcrite §8) |
| Minuterie et inactivité | Serveur prouvé (LOOP O) ; client non exécuté | Client prouvé (préavis, voile, interception) | Scénarios 3, 4, 6 (Chrome réel) |
| Multi-onglets | Non exécuté | PASS (propagation + déverrouillage transitoire même session) | Scénario 8 (2 pages, `storage` réel) |
| Révocation / expiration | Tests PHPUnit uniquement | + navigateur (session détruite → connexion) | Scénario 10 |
| API Sanctum | Décision ouverte | TTL 90 j **prouvé** ; step-up = `BLOCKED_DECISION` | `test_token_expire_apres_duree_configurée` + analyse §9 |
| Portail enseignant | Réserve acceptée (statu quo) | **Inchangé** (34/34 verts), proposition rédigée | §10, décision requise |
| Performance | Budget 32 (LOOP O) | Inchangé, voisins verts | `AffectationEnseignantExamenTest` 28/28 |
| PHPUnit complet | 303/303 (LOOP O) | **3 168 tests · 3 142 réussis · 4 échoués (préexistants, budgets requêtes, §12) · 22 ignorés · 13 712 assertions** | §12 |
| Production | `NOT_VERIFIED` | `NOT_VERIFIED` | Hors périmètre |

**Verdict : PASS_WITH_RESERVATIONS** (voir §14).

## 2. État initial (LAN, avant toute modification)

Documents lus intégralement : `loop-o-rapport.md`, `loop-n-rapport.md`,
`registre-canonique.md`, `routes/web.php`, `routes/api.php`,
`AppServiceProvider.php`, `SecondaireVerrou`, `VerrouillageController`,
vues `verrouillage/*`, `verrouillage-surveillance`, `layouts/app`,
`VerrouillageSecondaireTest`, `ConnexionController` (enseignant),
`CheckApiScope`, `IntegrationTokenCreate/Revoke`, `config/sanctum.php`.

| Domaine | État précédent | Preuve requise par LOOP P |
|---|---|---|
| Verrou serveur | Partiellement validé (présence middleware) | Comportement réel sous verrou |
| Voile et minuterie JS | Non exécutés | Navigateur réel |
| Synchronisation multi-onglets | Non exécutée | Deux contextes navigateur |
| Retour après veille | Non exécuté | Navigateur (+ veille réelle si possible) |
| API Sanctum | Décision ouverte | Analyse de menace + tests |
| Portail enseignant | Réserve acceptée (statu quo) | Décision métier explicite |
| Production | Non vérifiée | Hors périmètre (`NOT_VERIFIED`) |

Baseline mesurée avant modification :

- `VerrouillageSecondaireTest` : **18/18, 160 assertions — PASS**.
- Chrome réel disponible (`/Applications/Google Chrome.app`) ; `playwright-core`
  installable (réseau OK) ; aucun framework E2E dans le dépôt (confirmé).
- Serveur applicatif : `php artisan serve` ; base E2E **isolée** (sqlite dédiée,
  migrée à neuf : 142 migrations, 6 paramètres `verrouillage.*`, 1 compte
  éphémère `super_admin`) — jamais la base dev.

## 3. Défauts confirmés (tous reproduits avant correction)

### P0-1 — `/livewire/update` : verrou inopérant (ordre des middlewares)

- **Reproduction** : nouveau test `test_livewire_update_bloque_sous_verrou`
  (session verrouillée + `POST /livewire/update` avec headers Livewire).
- **Résultat** : `500 — RuntimeException: Session store not set on request`
  (`SecondaireVerrou.php:81`), au lieu du 423 attendu.
- **Cause racine** : `Livewire::setUpdateRoute(...->middleware('secondaire.verrou'))`
  puis Livewire ajoute `web` **après** (`HandleRequests.php`) → ordre
  d'exécution `secondaire.verrou` → `web` → `RequireLivewireHeaders`.
  Le verrou s'exécutait donc **avant** le démarrage de session :
  en test authentifié → 500 ; en production (session cookie, store vide
  non démarré, `user()` null) → **traversée silencieuse**, le composant
  `AttendanceTemplateBuilder` restait actionnable sous verrou.
  Le test LOOP N/LOOP O (`test_livewire_update_traverse_le_verrou`) ne
  vérifiait que la **présence** du middleware, pas son effet.
- **Portée** : contournement serveur avéré du verrou par canal Livewire.

### P1-2 — Throttles à clé partagée (budgets inter-routes)

- **Reproduction** : le nouveau test de récupération échouait par `429` dès le
  3e appel au vérificateur alors que la limite documentée est 5/min.
- **Cause racine** : `throttle:5,1` numérique → clé = `sha1(user_id)` **sans
  route ni préfixe** (`ThrottleRequests::resolveRequestSignature`). Toutes les
  routes `throttle:5,1` (et même `:10,1`/`:30,1`, même clé, seuils différents)
  du même compte partagent le compteur. Preuve corollaire : l'ancien test
  « 4 passages puis 429 » mesurait en fait 4 vérificateurs + 1 définition.
- **Effet** : limite anti-brute-force non déterministe (disponibilité ;
  aucun contournement possible — la limite ne peut que se durcir).

### P2-3 — Voile client : session expirée (419) traitée comme « code incorrect »

- **Cause** : `deverrouiller()` ne rechargeait que sur 423 ; un 419 (cookie de
  session/CSRF expiré) affichait « Code incorrect », invitant à réessayer
  sans issue. Lecture de code + contrat `expectsJson` (aucun navigateur
  requis pour le diagnostic ; preuve navigateur au §8, scénario 9/10).

### Observations analysées SANS défaut (aucune modification)

- **Réglage du PIN joignable sous verrou** (`code`/`definir` hors groupe
  verrouillé) : c'est la porte de secours « code oublié » documentée —
  le mot de passe (+ TOTP si MFA) reste exigé (prouvé §4). Conforme.
- **Pages MFA joignables sous verrou** (`configurer`/`confirmer`/`nouveauSecret`) :
  `confirmer` exige le TOTP du téléphone, `desactiver` exige le mot de passe,
  `nouveauSecret` ne fait que régénérer un secret *en attente* (inutilisable
  sans confirmation TOTP). Aucune élévation possible sans secrets. Conforme.

## 4. Corrections et justification (2 cycles, §9)

### Cycle 1 — P0-1 (Livewire)
- `app/Providers/AppServiceProvider.php` : `->middleware(['web', 'secondaire.verrou'])`
  (au lieu de `'secondaire.verrou'` seul). Livewire ne duplique pas `web`
  (garde `in_array`, `HandleRequests.php`) ; ordre final vérifié :
  `web` → `SecondaireVerrou` → `RequireLivewireHeaders`.
- `app/Http/Middleware/SecondaireVerrou.php` : traversée immédiate si
  `! $request->hasSession()` (fail-safe anti-500) + docblock d'ordre.
- Justification : le verrou lit session + utilisateur ; il doit s'exécuter
  après leur initialisation. Aucun changement de permissions, scopes ou
  comportement métier ; invités/non-éligibles traversent toujours.

### Cycle 2 — P1-2 (throttles)
- `routes/web.php` : `throttle:5,1,verrou-verifier`, `throttle:10,1,verrou-definir`,
  `throttle:30,1,verrou-prolonger` (3e paramètre = compartiment).
- Justification : rend les limites documentées déterministes (5/10/30 par
  fonction). Seul effet : les budgets ne se consomment plus entre fonctions ;
  aucun assouplissement (chaque budget reste appliqué). Les autres routes
  gardent le motif historique (périmètre verrou uniquement, §7).

### Correctif JS — P2-3 (419)
- `resources/views/components/verrouillage-surveillance.blade.php` :
  `rep.status === 423 || rep.status === 419` → rechargement vers l'écran
  serveur / connexion. Aucun secret manipulé ; aucun stockage ajouté.

## 5. Fichiers modifiés (dépôt ; hors artefacts `/tmp`)

| Fichier | Nature |
|---|---|
| `app/Providers/AppServiceProvider.php` | Ordre middlewares route Livewire |
| `app/Http/Middleware/SecondaireVerrou.php` | Fail-safe sans session + docblock |
| `routes/web.php` | 3 préfixes de throttle (verrouillage) |
| `resources/views/components/verrouillage-surveillance.blade.php` | 419 → rechargement |
| `tests/Feature/VerrouillageSecondaireTest.php` | 3 tests + 2 attentes throttle mises à la vraie sémantique |
| `tests/Feature/ApiAuthenticationTest.php` | Test TTL 90 jours |
| `docs/engineering/loop-p-rapport.md` | Ce rapport (créé) |
| `docs/registre/registre-canonique.md` | Entrée SEC-15 (créée) |

Artefacts E2E hors dépôt (reproductibles, §8) : `/tmp/loop-p-e2e/e2e.mjs`,
`/tmp/loop-p-e2e/helper.php` (+ `package.json`, `playwright-core`).

## 6. Tests ajoutés (non-régression)

1. `test_livewire_update_bloque_sous_verrou` — session saine → pas 423 ;
   session verrouillée + `POST /livewire/update` → **423**. (RED 500 avant fix.)
2. `test_recuperation_sous_verrou_exige_mot_de_passe` — PIN faux → verrou
   maintenu ; redéfinition faux mot de passe refusée ; redéfinition légitime
   (mot de passe) → nouveau PIN seul valide ; sans session → connexion.
3. `test_throttles_verrouillage_isoles_par_fonction` — 3 définitions puis
   5 vérifications sans 429 prématurée ; 6e → 429.
4. `test_token_expire_apres_duree_configurée` — TTL 90 j appliqué (200 → 401).
5. Mises à jour : `test_seuil_et_throttle_anti_brute_force` (5 échecs puis 429,
   vraie sémantique du budget isolé), `test_brute_force_temporise`
   (assertion du middleware préfixé).

## 7. Commandes exécutées et résultats exacts

```
# Baseline
php artisan test --filter="VerrouillageSecondaireTest" --compact
→ 18/18, 160 assertions — PASS (avant modification)

# RED P0-1 (avant correction)
php artisan test --filter="test_livewire_update_bloque_sous_verrou"
→ FAIL : attendu 423, obtenu 500 (Session store not set on request)

# Après corrections (cycles 1-2 + JS)
php artisan test --filter="VerrouillageSecondaireTest" --compact
→ 21/21, 197 assertions — PASS
php artisan test --filter="ApiAuthenticationTest" --compact
→ 8/8, 24 assertions — PASS
php artisan test --filter="VerrouillageSecondaireTest|ApiAuthenticationTest" --compact
→ 29/29, 221 assertions — PASS (sanité post-stash)
php artisan test --filter="MfaLoopJTest|MfaTotpServiceTest|RevocationSessionsTest|SentinelTest|SentinelMetierTest|SentinelConvergenceTest|SecuritePhaseFTest|IndustrialisationTest|XssCsrfSecurityTest|DurcissementTransversalTest|AuthorizationSecurityTest|ArchiveAuthorizationTest|AttendanceTemplateBuilderTest" --compact
→ 144/144, 476 assertions — PASS
php artisan test --filter="AffectationEnseignantExamenTest" --compact
→ 28/28 — PASS (budget requêtes 32 tenu)
php artisan test --filter="EspaceEnseignantTest|EnseignantPortailTest|EnseignantNotesTest" --compact
→ 34/34, 159 assertions — PASS (portail inchangé)
vendor/bin/pint --test → PASS (après `pint --dirty` : 1 import Carbon)
php artisan audit:routes-securite → exit 0 (« 58 à revue humaine », identique LOOP O)
php artisan route:list → 799 routes hors vendor (R3 : 775 ; delta = LOOP N/O)

# Suite complète
php artisan test --compact
→ 3 168 tests · 3 142 réussis · 4 échoués · 22 ignorés · 13 712 assertions (204 s)
→ les 4 échecs sont PRÉEXISTANTS (budgets requêtes, prouvés sans mes
  changements par stash, §12) ; 0 régression imputable à LOOP P.
```

## 8. Résultats navigateur par scénario (Chrome réel, 2 passages 12/12)

Méthode : serveur isolé `sqlite:/tmp/loop-p-e2e/e2e.sqlite` (`migrate` à neuf,
1 compte `super_admin` éphémère), `playwright-core` + `channel: 'chrome'`
(headless). Inactivité serveur = backdate `derniere_activite` (état identique
à une inactivité réelle, documenté) ; minuterie client = forçage d'état
Alpine + rendu/assertions réels ; 2 pauses 65 s pour respecter le throttle
5/min (preuve que la protection s'applique au navigateur aussi).
Reproduction : `cd /tmp/loop-p-e2e && node e2e.mjs` (après `migrate` + compte).

| # | Scénario | Résultat | Preuve |
|---|---|---|---|
| 1 | Connexion compte test | PASS ×2 | Dashboard 200 « Tableau de bord » |
| 2 | Activation verrou (PIN via UI) | PASS ×2 | Bandeau « Un code est enregistré », overlay en DOM masqué |
| 3 | Préavis + compte à rebours | PASS ×2 | Bandeau visible avec décompte, fermé par `keydown` réel |
| 4 | Voile client | PASS ×2 | Opaque, champ `password`, focus sur le champ |
| 5 | PIN faux puis bon (voile) | PASS ×2 | Erreur générique, voile levé **sans rechargement** (URL identique) |
| 6 | Soumission après expiration | PASS ×2 | Interceptée (URL inchangée, saisie conservée), renvoi OK après PIN |
| 7 | Verrou serveur + écran | PASS ×2 | Écran 200 sans données (CSRF présent, « Code oublié »), PIN faux refusé, reprise **sans reconnexion** |
| 8 | Deux onglets | PASS ×2 | Verrou A → B via `storage` réel ; déverrouillage B (fetch réel) → A relevé |
| 9 | Retour onglet invisible | PASS ×2 | `auReveil()` pose le voile immédiatement |
| 10 | Session expirée | PASS ×2 | Ligne supprimée → redirection connexion |
| 11 | MFA à la connexion | PASS ×2 | Défi exigé, TOTP faux refusé, TOTP valide (MfaTotpService) ouvre la session |
| 12 | Déconnexion (écran verrouillé) | PASS ×2 | Bouton → page de connexion |

Non exécuté (environnement) : **vraie mise en veille OS** (`NOT_EXECUTED`,
aucune veille réelle possible dans ce conteneur ; la reprise sur `tic()` /
`visibilitychange` est couverte par 8/9). Aucun test HTTP ne tient lieu de
ces preuves : chaque ligne ci-dessus est un rendu/assertion Chrome réel.

## 9. API Sanctum — analyse de menace (§5, sans changement de contrat)

Inventaire (inchangé, LOOP O §2 vérifié à nouveau) : `auth:sanctum` + scopes
fins + `throttle:api*` + `CheckApiScope` (403 générique) ; émission/révocation
CLI uniquement ; TTL 90 j (`config/sanctum.php:57`) ; `SecondaireVerrou`
absent de la pile API (volontaire, stateless).

| Menace | Évaluation | Couverture |
|---|---|---|
| Jeton volé/copié | Utilisable jusqu'expiration/révocation (bearer) | TTL 90 j **prouvé** (test §6) ; révocation immédiate prouvée (`test_token_revoque…`) |
| Jeton trop privilégié | Atténué à l'émission | CLI valide scopes (`ApiScopes`) + refuse `super_admin` ; moindre privilège par compte technique |
| TTL 90 j trop long (écritures) | Risque assumé à ce jour | `BLOCKED_DECISION` D1 (TTL par scope) |
| Pas de step-up écritures sensibles | Pas de facteur humain sur token machine | `BLOCKED_DECISION` D2 (pas de MFA fictif) |
| Révocation tardive | Immédiate et totale (suppression ligne) | Test existant vert |
| Réutilisation après changement de privilèges | Abilities figées au token (ligne), pas au rôle | Par construction Sanctum |
| Rejeu / idempotence | `idempotency` sur reenroll/imports/exports ; TOCTOU connu | Propriétaire SEC-10 |
| Réinscription interfaculté (SEC-10 V-1) | Non traité ici | Propriétaire SEC-10 (NOT_STARTED) |

**Décisions** : D1 (TTL par scope), D2 (step-up API), D3 (verrou enseignant)
= `BLOCKED_DECISION`, §11. Mécanisme TTL précisé : refus via `created_at`
+ config (Guard), `expires_at` NULL (vérifié dans le code Sanctum).

## 10. Portail enseignant — proposition sans modification (§6)

Comportement actuel démontré (inchangé, 34/34 verts) : guard
`enseignant_auth_id` (`EnsureTeacher`, revalidation base) ; session 120 min
**globale** (`.env SESSION_LIFETIME=120`, aucune durée par profil) ;
saisie/import notes (`Evaluation/Routes/web.php:350-374`, throttles 10/1,
anonymat CMP) ; incidents, demandes, réclamations, changement mot de passe,
exports CSV — **sans** verrou d'inactivité ni réauthentification.

- Menace : poste partagé → jusqu'à 120 min d'accès (intégrité des notes, EXM-01).
- Options : (a) statu quo + réserve [DÉCIDÉ en LOOP O, maintenu] ;
  (b) verrou dédié adapté à `enseignant_auth_id` ; (c) durée de session
  dédiée (ex. 30 min) ; (d) réauthentification mot de passe pour
  `notes.store/importer`, révocations, exports nominatifs.
- **Aucune modification appliquée** (autorisation explicite requise, §11 D3).

## 11. Décisions métier nécessaires (`BLOCKED_DECISION`)

- **D1** — TTL différencié par scope API (ex. écritures 30 j) : changement de
  contrat (rotation intégrations) → arbitrage métier/ops requis.
- **D2** — Step-up sur écritures API sensibles : pas de MFA fictif sans
  identité humaine et flux défini → spécification métier requise.
- **D3** — Verrou/réauthentification portail enseignant (options §10) :
  changement de comportement métier → autorisation explicite requise
  (statu quo maintenu, réserve conservée).
- **D4** (suivi) — Intégration suite navigateur au dépôt/CI + budgets
  requêtes des 4 suites §12 (propriétaires) : hors boucle, à planifier.

## 12. Réserves restantes et limites

1. **4 échecs PHPUnit préexistants** (budgets requêtes, pages hors périmètre
   verrou) : `InscriptionFileTraitementTest:411` (25<25),
   `InscriptionPerformanceFiabiliteTest:139` (25<25),
   `PopulationPhase2CompleteTest:130` (23 vs 21),
   `ArchivesFicheLoop3Test:74` (25<25). **Prouvés antérieurs à LOOP P**
   (rejoués avec mes 6 fichiers `stash`és : mêmes 4 échecs). Cause probable :
   coût +2 du middleware LOOP N (paramètre + PIN) sur budgets écrits avant
   l'extension. Non relevés (règle anti-seuil-arbitraire) ; aux propriétaires.
2. Vraie veille OS : `NOT_EXECUTED` (conteneur). Focus-trap complet du voile :
   non implémenté (focus initial seul) — accessibilité partielle assumée.
3. Production : `NOT_VERIFIED` (interdite d'accès).
4. Arbre Git initialement sale (LOOP N/O non commités) : mes hunks listés §5 ;
   aucun commit effectué dans la boucle.

## 13. Risques et plan de retour arrière (indépendants)

1. Ordre Livewire : remettre `->middleware('secondaire.verrou')` (restaure le
   500/contournement — ne pas faire sans alternative).
2. Fail-safe : retirer le garde `hasSession` (restaure le 500 si ordre régresse).
3. Throttles : retirer `,verrou-*` (restaure les budgets partagés ; le test
   d'isolation redevient rouge).
4. JS 419 : retirer `|| rep.status === 419` (restaure le message trompeur).
5. TTL test : suppression locale (aucun effet prod — test seul).
6. Registre : SEC-15 reste lue comme réserve (aucun effet exécutable).

## 14. Verdict : `PASS_WITH_RESERVATIONS`

Toutes les corrections autorisées sont vérifiées (P0-1 RED→GREEN, 21/21 ;
P1-2 démontré + isolé ; P2-3 + 12/12 navigateur ×2 ; TTL API ; enseignant
inchangé + proposition). Réserves non bloquantes : 4 échecs préexistants
hors périmètre (§12.1), veille réelle impossible (§12.2), décisions D1-D3 en
attente (§11), production non vérifiée. Aucune boucle suivante lancée.

## 15. État de nettoyage des données de test

- PHPUnit : bases `sqlite :memory:` éphémères (aucune trace).
- Navigateur : base `sqlite` isolée **détruite** (`/tmp/loop-p-e2e/e2e.sqlite`),
  serveur `:8123` arrêté, cookies/contextes fermés (`navigateur.close()`),
  `mfa_off` rappelé en `finally`, TOTP courant seul affiché (30 s).
- Base dev MySQL : **jamais touchée** par la suite navigateur.
- Dépôt : aucun fichier temporaire ajouté (artefacts E2E sous `/tmp` uniquement).

## 16. Limites de la validation

- Backdate `derniere_activite` pour l'inactivité serveur (état identique,
  pas d'attente réelle de 15 min ; l'attente réelle 6 min a été prouvée en
  LOOP O côté HTTP).
- Forçage d'état Alpine pour préavis/voile (rendu et assertions réels,
  minuterie physique non attendue).
- `visibilitychange` déclenché par événement synthétique (gestionnaire réel).
- TOTP calculé via `MfaTotpService` (même code que le contrôleur).
- 2 passages complets 12/12 (24/24 exécutions réussies), pas de test
  tactile/mobile, pas de multi-navigateur (Chrome uniquement).