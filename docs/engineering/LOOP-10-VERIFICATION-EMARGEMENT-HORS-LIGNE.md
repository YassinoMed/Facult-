# LOOP-10 — VÉRIFICATION INDÉPENDANTE DE L'ÉMARGEMENT NUMÉRIQUE HORS LIGNE

**Date d'exécution :** 26/10/2026
**Périmètre :** PWA et service worker (`public/sw.js`), page de repli `/hors-ligne`, politique de cache, minimisation des données locales, et état réel de la synchronisation d'émargement hors ligne (audit §F10).
**Mode :** audit statique du service worker + test de garde PHPUnit ; aucun navigateur réel, aucun scénario réseau hors ligne, aucune migration, aucun commit, aucun push.
**Verdict :** `PARTIAL`

> **Le correctif de minimisation du pré-cache est `PASS` et prouvé. La
> synchronisation d'émargement hors ligne complète reste `BLOCKED` par une
> décision de politique de données locales ; elle n'est ni implémentée ni
> revendiquée.** Le lot global n'est donc **pas** déclaré `PASS`.
> Aucun rapport LOOP-10 préexistant n'a été trouvé ; l'audit LOOP-00 §F10
> sert d'entrée. Ce rapport est distinct et ne remplace aucun document.

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture de l'audit §F10 ; inspection de `public/sw.js`,
  `public/manifest.webmanifest`, `resources/views/hors-ligne.blade.php`,
  `resources/views/layouts/app.blade.php`, de la route `/hors-ligne` et du
  flux d'émargement (`EpreuveService::enregistrerEmargement`,
  `EpreuveController`) ; écriture d'un test de garde statique ; exécution
  avant/après correctif ; non-régression PWA.
- **Environnement :** PHP 8.5.11 (NTS), Laravel 13.33.0, PHPUnit 12.5.35,
  SQLite `:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`,
  `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.
- **Limites majeures :**
  - aucun navigateur ni runtime de service worker exécuté ;
  - aucun scénario réseau réel (avion, coupure, latence, reprise) ;
  - aucun test IndexedDB/file de synchronisation (inexistante) ;
  - aucun test multi-appareils ni conflit de versions ;
  - le test PHPUnit lit le **fichier** `public/sw.js` : il prouve le code
    statique, pas le comportement runtime du navigateur.

  Ces limites interdisent de qualifier le lot de `PASS` global. Les points
  non exécutés sont `NOT_EXECUTED`, jamais `PASS`.

---

## 1. État initial et frontière de flux

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` (inchangé) |
| Commit / push pendant le lot | aucun |
| Migration | aucune |
| Rapport LOOP-10 préexistant | aucun |
| Suite de garde | `tests/Feature/Loop10EmargementHorsLigneTest.php` (nouvelle) |

Le lot ne revendique que :

- `public/sw.js` (pré-cache de `/hors-ligne` sans session) ;
- `tests/Feature/Loop10EmargementHorsLigneTest.php` (garde statique) ;
- le présent rapport.

Le reste du dispositif — émargement web, PDF, batch ZIP, QR HMAC, page de
repli — préexiste et n'est pas modifié.

---

## 2. Défaut prouvé et corrigé — D10/1

### 2.1 Constat

Le service worker pré-cachait la page de repli ainsi :

```js
caches.open(VERSION).then((cache) => cache.add(HORS_LIGNE))
```

`cache.add()` effectue une requête `fetch` puis stocke la réponse. Pour une
URL same-origin, le mode d'identification par défaut est `same-origin` :
les **cookies de session sont envoyés**. Or `/hors-ligne` étend
`layouts.app`, qui contient :

- `<meta name="csrf-token" content="{{ csrf_token() }}">` ;
- le bloc `@auth` avec le nom de l'utilisateur et son rôle
  (`auth()->user()->name`, `role_libelle`) ;
- la navigation authentifiée.

Le cache pouvait donc conserver, sur le poste, une **version authentifiée**
de la page de repli : nom, rôle et jeton CSRF. C'est une conservation locale
de données de session et de métadonnées personnelles, contraire à la
promesse affichée par la page elle-même (« aucune donnée sensible n'est
conservée hors ligne »). Sur un poste partagé, la page servie hors ligne
pouvait exposer ces éléments.

### 2.2 Preuve avant correctif

```bash
php artisan test --compact tests/Feature/Loop10EmargementHorsLigneTest.php
```

**Résultat avant correctif : 3 tests, 2 PASS, 1 échec, 6 assertions.**

Le test `test_le_service_worker_pre_cache_la_page_hors_ligne_sans_session`
échouait : le fichier ne contenait ni `credentials: 'omit'`, ni
`cache: 'no-store'`, et contenait encore `cache.add(HORS_LIGNE)`.

La preuve est **statique** : elle établit la présence du motif dangereux,
pas l'observation d'un cookie dans un navigateur. Cette distinction est
assumée dans le verdict.

### 2.3 Correctif minimal

L'installation du service worker utilise désormais une requête explicite :

```js
fetch(HORS_LIGNE, { credentials: 'omit', cache: 'no-store' })
  .then((response) => {
    if (!response.ok) {
      throw new Error('Page hors ligne indisponible');
    }

    return cache.put(HORS_LIGNE, response);
  })
```

- `credentials: 'omit'` : aucun cookie de session n'est envoyé ;
- `cache: 'no-store'` : aucun cache HTTP intermédiaire n'est utilisé ;
- `cache.put` : seule la réponse **anonyme** est stockée ;
- `response.ok` : une réponse d'erreur n'est pas mise en cache ;
- en cas d'échec, l'installation échoue et l'ancien service worker reste en
  place : pas de cache partiel silencieux.

Le reste de la politique est inchangé : jamais de POST/PUT/PATCH/DELETE,
jamais `/api/*`, seuls les GET same-origin `/build/` et `/icons/` sont
cachés.

---

## 3. Contrôles confirmés par exécution

| Contrôle | Preuve | Résultat |
|---|---|---|
| Le pré-cache de `/hors-ligne` n'envoie pas de cookies | `test_le_service_worker_pre_cache_la_page_hors_ligne_sans_session` | PASS — `credentials: 'omit'` présent |
| Le pré-cache n'utilise pas le cache HTTP | même test | PASS — `cache: 'no-store'` présent |
| L'ancien `cache.add(HORS_LIGNE)` a disparu | même test | PASS — motif absent |
| Les mutations et l'API ne sont jamais cachées | `test_le_service_worker_ne_met_jamais_en_cache_les_mutations_ni_l_api` | PASS |
| La page de repli reste accessible sans authentification | `test_la_page_hors_ligne_reste_accessible_sans_authentification` | PASS — 200, textes attendus |
| Le manifeste, les icônes et le service worker restent cohérents | `IndustrialisationTest::test_pwa_manifest_sw_offline` | PASS — 9/9, 54 assertions |
| Les POST d'émargement ne sont pas pré-cachés | lecture de `cachable()` | PASS — `request.method !== 'GET'` |

---

## 4. Critères d'acceptation

| Critère | Statut | Preuve |
|---|---|---|
| C1 — Le pré-cache de la page de repli est anonyme | PASS | test statique + code |
| C2 — Aucun cookie de session n'est envoyé lors du pré-cache | PASS | `credentials: 'omit'` |
| C3 — Aucun cache HTTP intermédiaire n'est utilisé | PASS | `cache: 'no-store'` |
| C4 — Les mutations et `/api/*` restent hors cache | PASS | test statique |
| C5 — La page de repli reste accessible sans session | PASS | test HTTP |
| C6 — La PWA existante ne régresse pas | PASS | `IndustrialisationTest` 9/9 |
| C7 — Synchronisation hors ligne complète (file idempotente, conflits, reprise, signature, liaison Attendance) | **BLOCKED** | non implémentée ; décision de politique requise |
| C8 — Scénario navigateur hors ligne réel | **NOT_EXECUTED** | aucun navigateur/runtime SW |

---

## 5. Modifications appliquées

| Fichier | Nature |
|---|---|
| `public/sw.js` | pré-cache `/hors-ligne` via `fetch` sans credentials ni cache HTTP, puis `cache.put` |
| `tests/Feature/Loop10EmargementHorsLigneTest.php` | 3 tests de garde statique |
| `docs/engineering/LOOP-10-VERIFICATION-EMARGEMENT-HORS-LIGNE.md` | présent rapport |

Aucune migration, aucune route, aucune permission, aucun changement du
modèle d'émargement ou d'`Attendance`.

---

## 6. Preuves d'exécution

### 6.1 Suite de garde

```bash
php artisan test --compact tests/Feature/Loop10EmargementHorsLigneTest.php
```

**Avant : 3 tests, 2 PASS, 1 échec, 6 assertions.**
**Après : 3/3 PASS, 8 assertions, 164 ms.**

### 6.2 Non-régression PWA

```bash
php artisan test --compact tests/Feature/Loop10EmargementHorsLigneTest.php \
  tests/Feature/IndustrialisationTest.php
```

**Résultat : 12/12 PASS, 62 assertions, 1 622 ms** (3 tests / 8 assertions
+ 9 tests / 54 assertions).

### 6.3 Limite de preuve

Aucun `php -l` ni Pint ne s'applique à `public/sw.js` (fichier JavaScript
statique). Aucun test navigateur n'a été exécuté. Le test PHPUnit lit le
fichier et vérifie les motifs ; il ne prouve pas que le navigateur applique
effectivement `credentials: 'omit'` au moment de l'installation. Ce point
reste `NOT_EXECUTED`.

---

## 7. Non-régression et hygiène

- Aucun commit, push, tag ni déploiement.
- Aucune migration, aucun `migrate:fresh`, `db:wipe` ou suppression de
  données.
- Aucune donnée réelle, aucun cookie, aucun jeton, aucune PII dans les tests
  ou ce rapport.
- Le test ne se connecte pas et ne crée aucune session ; il lit un fichier
  statique et vérifie la page publique.
- Le reste du service worker (manifeste, icônes, cache `/build/`) est
  inchangé et couvert par `IndustrialisationTest`.

---

## 8. Risques résiduels et blocages

- **R-10/1 — Synchronisation hors ligne complète : `BLOCKED`.** Il n'existe
  ni IndexedDB, ni file de mutations idempotente, ni résolution de conflits
  multi-appareils, ni reprise après expiration de session, ni signature
  numérique de prise de poste, ni liaison explicite entre l'émargement
  d'épreuve et les présences `Attendance`. Ces éléments ne peuvent pas être
  inventés : ils dépendent d'une **décision de politique de données
  locales** (minimisation, chiffrement au repos, durée de rétention,
  identité d'appareil, règles de conflit, niveau de preuve). Le lot ne les
  revendique pas.
- **R-10/2 — Preuve runtime absente.** Le test est statique ; aucun
  navigateur n'a exécuté le service worker, aucun scénario de coupure
  réseau réelle n'a été joué. `NOT_EXECUTED`.
- **R-10/3 — Autres données locales.** L'audit §F10 signale des brouillons
  de notes conservés dans `localStorage`
  (`resources/views/modules/evaluation/notes-saisie.blade.php`, clé
  `notes-eval:<user>:<epreuve>`). Ce stockage contient des notes et des
  numéros d'étudiants ; il n'est pas traité par le présent correctif. La
  phrase « aucune donnée sensible n'est conservée hors ligne » de la page
  `/hors-ligne` doit donc être lue comme une promesse **du pré-cache**,
  pas comme une propriété globale de l'application. Une politique de
  minimisation/chiffrement de ces brouillons reste à décider.
- **R-10/4 — Version de cache inchangée.** Le nom de cache reste
  `uniregistre-v1`. Le nouveau service worker réécrit l'entrée
  `/hors-ligne` à l'installation ; un changement de version explicite
  (`v2`) serait plus lisible pour forcer une purge, mais n'est pas
  nécessaire au correctif. **Non bloquant.**
- **R-10/5 — Installation hors ligne.** Si `/hors-ligne` est injoignable au
  moment de l'installation, l'installation échoue et sera retentée ; aucun
  cache partiel n'est créé. Comportement acceptable, à vérifier en
  navigateur. **Non exécuté.**
- **R-10/6 — Pas de test multi-navigateurs.** Chrome, Firefox, Safari et
  WebView peuvent différer sur le cycle de vie du service worker et le
  stockage. **Limite de vérification.**

---

## 9. Verdict

**`PARTIAL`.**

- **PASS prouvé :** le pré-cache de `/hors-ligne` n'envoie plus de cookies,
  n'utilise plus le cache HTTP et ne stocke que la réponse anonyme ; les
  mutations et l'API restent hors cache ; la page de repli reste accessible
  sans session ; la PWA existante ne régresse pas (12/12).
- **BLOCKED :** la synchronisation d'émargement hors ligne complète
  (file idempotente, conflits, reprise, signature de poste, liaison
  `Attendance`) reste dépendante d'une décision de politique de données
  locales et n'est pas implémentée.
- **NOT_EXECUTED :** le comportement runtime du service worker dans un
  navigateur réel et les scénarios de coupure réseau.

Le lot ne peut donc pas être déclaré `PASS` global. Le correctif de
minimisation est un progrès vérifié, pas une clôture du chantier
d'émargement hors ligne.

---

# Mise à jour — exécution du 27/10/2026

**Mode :** implémentation après arbitrage explicite de la politique de données
locales ; tests PHPUnit isolés (SQLite) ; aucun commit, aucun push, aucune
migration jouée sur la base de développement.
**Verdict :** `PASS_WITH_RESERVATIONS`.

## 10. Politique de données locales appliquée

Arbitrage retenu : **file minimale sans PII**.

- Stockage local par agent : identifiant technique du candidat, statut pointé,
  horodatage, clé d'idempotence. **Aucun** nom, e-mail, CIN, matricule ni
  justification libre.
- TTL **24 h**, purge automatique des entrées expirées, effacement de la file
  après synchronisation réussie.
- Activation **opt-in** explicite par l'agent ; aucun envoi automatique si
  l'agent n'a pas activé la saisie hors ligne, sauf perte de réseau détectée.
- Le serveur reste seul juge : il revalide le statut, l'appartenance du
  candidat à l'épreuve et le verrouillage de l'épreuve.

## 11. Livré

- Migration additive `2026_10_27_100000_creer_emargements_hors_ligne.php`
  (journal idempotent, contrainte unique `(epreuve_id, cle_idempotence)`).
- Modèle `EmargementHorsLigne`.
- Endpoint `POST admin/evaluations/epreuves/{epreuve}/emargement/hors-ligne`
  (`EpreuveController::enregistrerEmargementHorsLigne`) : validation serveur,
  idempotence, refus d'une épreuve verrouillée (409), candidat hors épreuve
  ignoré sans écriture.
- `public/js/emargement-hors-ligne.js` : file locale TTL 24 h, purge,
  synchronisation manuelle et automatique au retour du réseau, clé UUID.
- Panneau « Saisie hors ligne » dans
  `resources/views/modules/evaluation/emargement.blade.php`, avec `aria-live`
  et avertissement sur la non-conservation du texte libre.

## 12. Preuves exécutées

| Commande | Résultat |
|---|---|
| `vendor/bin/phpunit --configuration=phpunit.xml --filter="Loop10EmargementHorsLigneTest"` | **11/11 tests, 36 assertions** |

Les tests couvrent : pré-cache sans session, absence de cache des mutations et
de l'API, page de repli accessible sans authentification, absence de champ
nominatif dans la file locale, TTL 24 h et purge, synchronisation idempotente
(rejeu = aucun second pointage), candidat hors épreuve ignoré, épreuve
verrouillée refusée, statut illégal refusé (422), permission `epreuves.update`
requise, isolation facultaire (404), présence du panneau et de l'URL de
synchronisation.

## 13. Réserves

- **R-10/7** — Aucun test de navigateur réel avec coupure réseau : la file JS
  est vérifiée statiquement et l'endpoint serveur est testé, mais le cycle
  complet (mode avion, reprise, quota `localStorage`) reste **non exécuté**.
- **R-10/8** — Aucun test multi-navigateurs (Chrome, Firefox, Safari, WebView).
- **R-10/9** — Les conflits multi-agents sur une même épreuve restent résolus
  par la clé d'idempotence et le verrouillage serveur ; aucun mécanisme de
  fusion de pointages divergents n'est revendiqué.
- **R-10/10** — La justification libre n'est volontairement pas conservée hors
  ligne ; elle doit être saisie après synchronisation.

## 14. Statut final

**`PASS_WITH_RESERVATIONS`** — la synchronisation hors ligne est implémentée,
minimisée et idempotente, avec 11 tests au vert. La vérification navigateur
réelle reste à exécuter avant une mise en production.
