# Contre-audit indépendant — LOOP-UNIREGISTRE-UX-UI-PREMIUM-FLUIDITE-01

> **Date du contre-audit :** 23 octobre 2026, 03:39  
> **Mode :** lecture seule sur le code applicatif, snapshot horodaté  
> **Branche :** `feature/admin-modifier-etudiant` — HEAD `38ef149`  
> **Objet :** vérifier les affirmations du rapport final concurrent, corriger les chiffres non reproductibles et qualifier le verdict de non-régression.

---

## 1. Contexte et précaution de concurrence

Un processus concurrent a modifié le dépôt pendant l'audit :

- `resources/views/components/btn.blade.php` à 03:23:33 ;
- `empty-state.blade.php` à 03:23:44 ;
- `modal.blade.php` à 03:24:01 ;
- `alert.blade.php` à 03:24:19 ;
- `anomalies-index.blade.php` à 03:25:02 ;
- `documents-batch.blade.php` à 03:25:57 ;
- `notes-saisie.blade.php` à 03:29:14 ;
- `ma-saisie-notes.blade.php` à 03:30:24 ;
- `notes-historique.blade.php` à 03:31:14 ;
- `tableau-bord.blade.php` à 03:31:49 ;
- `notes-import-preview.blade.php` à 03:32:14 ;
- le rapport principal à 03:33:53.

Ces fichiers sont donc **des travaux concurrents à préserver**, pas des fichiers à réécrire. Le présent contre-audit ne modifie aucun fichier applicatif. Il ajoute un document séparé et un pointeur en fin du rapport principal.

Le snapshot Git au 23/10/2026 à 03:37:24 est :

- **534 entrées** dans `git status --porcelain` ;
- **280 fichiers modifiés** ;
- **252 fichiers non suivis** ;
- **2 fichiers ajoutés**.

Ce snapshot précède l'ajout du présent document : avec ce contre-audit non suivi, `git status --porcelain` en compte **535** à 03:38:56. Les chiffres `277 modifiés / 245 non suivis` du rapport principal correspondent à un instantané antérieur. Ils doivent être remplacés par le snapshot horodaté ci-dessus, ou présentés comme une photographie et non comme un état définitif.

---

## 2. Corrections factuelles au rapport principal

| Affirmation du rapport | Vérification indépendante | Correction / réserve |
|---|---|---|
| « 277 fichiers modifiés et 245 fichiers non suivis préservés » | 280 modifiés, 252 non suivis, 2 ajoutés à 03:37:24 | Chiffres à réactualiser ; le dépôt continue de bouger. |
| « non-régression 100 % verte sur 220 tests » | Le filtre `Evaluation\|Note\|Deliberation` donne bien **220/220 tests, 889 assertions** | Vrai **pour ce périmètre uniquement**. La suite globale est rouge (voir §3.1). Le mot « non-régression » ne peut pas être globalisé. |
| « Tous les boutons interactifs disposent d'un anneau `focus-visible` » | `x-btn` en dispose ; de nombreux boutons natifs de l'en-tête n'ont aucun `focus-visible` explicite | Affirmation trop large. Les boutons de thème, cloche, menu utilisateur et menu mobile reposent sur le focus navigateur par défaut, non sur l'anneau annoncé. |
| « Contraste de texte ≥ 4,5:1 » | `text-slate-400` = `#94a3b8` : **2,56:1 sur blanc**, **2,45:1 sur `slate-50`** | Faux pour les 854 occurrences de `text-slate-400` (226 fichiers). Plusieurs sont informatives : libellés de `x-stat-card`, horodatages de notifications, en-têtes de tableaux. |
| « Protection double-clic par `::loading` » | `x-btn` expose une prop Blade `loading`, mais `::loading` est envoyé comme attribut Alpine `:loading` ; il n'alimente pas la prop | **La protection n'est pas démontrée.** Trois usages concernés : `notes-saisie`, `ma-saisie-notes`, `notes-import-preview`. |
| « Tests navigateur bloqués par le CDN Playwright » | Playwright et Puppeteer sont absents de `node_modules` ; Chrome est installé | La réserve « aucun test navigateur exécuté » est valide. La cause CDN n'est pas vérifiable depuis ce poste ; il faut la formuler comme non exécutée, sans conclure au seul CDN. |
| « 23/23 tests ADVANCED-02, 96 assertions » | Reproduit : **23/23, 96 assertions, 2,076 s** | Confirmé. |
| « 220/220 tests, 889 assertions » | Reproduit : **220/220, 889 assertions, 16,309 s** | Confirmé pour le filtre indiqué. |
| « Pint propre » | `vendor/bin/pint --dirty --test` → `passed` | Confirmé. |
| « AUDIT initial en lecture seule complété » | Le rapport principal contient déjà un verdict final et des modifications d'implémentation | Le rapport mélange Phase 0 et rapport final. La traçabilité Phase 0 / implémentation reste à clarifier. |

### 2.1 Le point le plus important

Le verdict `PASS_WITH_RESERVATIONS` du rapport principal ne peut pas être accepté comme verdict de non-régression **global** tant que la suite complète reste rouge. Il peut être conservé comme verdict **du périmètre évaluations** si le libellé est explicitement limité à ce périmètre.

---

## 3. Constats vérifiés

### 3.1 Bloquants P0 — baseline rouge

La suite globale de référence déjà exécutée dans cette phase donne :

- **4 083 tests** ;
- **4 053 réussis** ;
- **5 échecs** ;
- **25 ignorés** ;
- **20 066 assertions** ;
- **297,819 s**.

Les cinq échecs ont été **reproduits individuellement** le 23/10/2026 entre 03:27 et 03:28 :

| Test | Résultat | Cause observée |
|---|---|---|
| `EtudiantThemesTest::test_toutes_les_pages_accessibles_du_portail_exposent_la_v2` | 7/8, 160 assertions | `Error: Non-static method Carbon\Carbon::next() cannot be called statically` dans `app/Http/Controllers/Etudiant/RendezVousScolariteController.php:29`. Le rendez-vous étudiant renvoie une **500**. |
| `InscriptionAccueilParcoursTest::test_accueil_plateforme_propose_les_deux_parcours_sans_dupliquer_le_backend` | 6/7, 37 assertions | Le HTML rendu ne contient pas l'action absolue attendue `route('inscription.existant.recherche')`. |
| `ParcoursAccueilEmbarqueTest::test_coquille_modale_et_wizard_prefixe_sans_collision` | 3/4, 37 assertions | Attendu : 1 occurrence de `id="cin"`, trouvé : 0 (ligne 82). |
| `RelevesGenerationTest::test_portail_liste_telecharge_et_idor` | 5/6, 13 assertions | Réponse **403** au lieu de **200**. |
| `SaisieIdentificationStricteTest::test_le_formulaire_existant_embarque_le_controle_strict` | 11/12, 54 assertions | `formaterDateNaissance(this)` attendu au moins 2 fois, trouvé 1 fois. |

**Conséquence :** aucun lot UX/UI ne peut être déclaré « non régressif » tant que ces cinq points ne sont pas résolus ou explicitement requalifiés. Le 500 du rendez-vous étudiant est un bloquant fonctionnel direct, indépendamment de l'esthétique.

### 3.2 P1 — coquille globale et recherche

#### Lien logo

`resources/views/layouts/app.blade.php:143` et `:217` :

```blade
<a href="{{ route('inscription.create') }}">
```

Le logo desktop et le logo mobile conduisent tous les rôles authentifiés vers la page publique d'inscription, au lieu du tableau de bord du rôle. C'est une rupture de navigation et un risque de perte de contexte.

#### Palette de commandes

`resources/views/components/palette.blade.php` :

- les commandes ne sont construites que pour `auth()->check()` ou `session('etudiant_auth_id')` (lignes 9 et 32) ;
- **aucune branche `enseignant_auth_id`** : un enseignant n'a ni palette, ni commandes, ni raccourci `/` ;
- les endpoints passés à Alpine sont les routes admin `admin.recherche.instantanee` et `admin.recherche.contexte` (ligne 41), même pour un étudiant ;
- `chargerContexte()` remplace `elements` par les seuls favoris/récents (ligne 177) ; sans favori/récent, la palette s'ouvre vide alors que des commandes existent ;
- le lien d'aide pointe vers `admin.preferences` (ligne 77), inaccessible aux étudiants/enseignants ;
- le raccourci `/` de la palette cible `recherche-globale` (ligne 111), champ absent du portail étudiant/enseignant.

Le champ de recherche du header est `hidden md:block` (`app.blade.php:225`, `:235`, `:242`) : **aucune recherche dans le header mobile**, pour aucun rôle.

#### Accessibilité de la palette

Le dialogue a bien `role="dialog"` et `aria-modal="true"`, mais :

- l'input n'a ni `role="combobox"`, ni `aria-expanded`, ni `aria-controls`, ni `aria-activedescendant` ;
- la liste `role="listbox"` contient des `<li role="option">` avec des liens interactifs à l'intérieur ; le motif ARIA listbox/option n'est pas respecté ;
- les flèches changent `aria-selected` sans déplacer le focus réel ni annoncer l'option courante.

### 3.3 P1 — thème sombre annoncé mais incomplet

Le sélecteur de thème est global (`app.blade.php:255-284`), mais :

- `body` n'applique `dark:bg-slate-950 dark:text-slate-100` que dans la branche `@hasSection('auth_layout')` (`app.blade.php:126`) ; les pages authentifiées restent sur `bg-slate-100 text-slate-800` ;
- `layouts/_navigation.blade.php` a **0 variante `dark:`** ;
- **25 composants partagés** ont **0 variante `dark:`** ;
- seulement **63 vues sur 435** contiennent au moins une variante `dark:`.

Le résultat est un mode hybride : certains modules passent en sombre, la coquille et les composants restent clairs. Il faut soit compléter, soit retirer le sélecteur. C'est une décision institutionnelle à valider.

### 3.4 P1 — tableaux, responsive et sémantique

Analyse statique approximative (vues HTML `admin`, `etudiant`, `enseignant`, `modules`, hors PDF/impression) :

- **160 fichiers** contiennent un tableau ;
- **182 tableaux** au total ;
- **54 fichiers** n'ont pas de conteneur `overflow-x-auto` détecté.

Exemples certains :

- `resources/views/etudiant/notes.blade.php:29-73` : carte `overflow-hidden`, tableau 5 colonnes sans wrapper scrollable, aucun `scope="col"`. Sur mobile, les colonnes sont rognées plutôt que défilables.
- `resources/views/modules/evaluation/deliberations-show.blade.php:83-113` : tableau 6 colonnes sans wrapper, sans `scope`, sans état vide.
- `resources/views/modules/evaluation/notes-historique.blade.php:73-80` : wrapper présent, mais les `th` n'ont pas `scope`.

Le message vide de `etudiant/notes.blade.php:20-24` existe bien ; il n'utilise simplement pas `x-empty-state`. Ce n'est donc pas « absence d'état vide », mais « état vide non aligné sur le design system ».

### 3.5 P1 — workflow de délibération

`resources/views/modules/evaluation/deliberations-show.blade.php:11-28` affiche les actions `Préparer`, `Valider` et `Publier` sur la seule base des permissions, sans tenir compte du statut courant. Les transitions invalides ne sont donc découvertes qu'après soumission, par une erreur serveur.

De plus :

- `Valider` et `Publier` n'ont aucune confirmation explicite alors que ce sont des actions à fort impact ;
- le quorum est annoncé dans le titre (`:47`) mais aucun compteur de présents n'est affiché ;
- le bouton `Pointer` (`:55`) ne dit pas s'il marque présent ou absent ;
- le select de décision (`:101`) n'a pas de label associé.

### 3.6 P1 — feedback de sauvegarde et double soumission

`notes-saisie.blade.php:133` et `ma-saisie-notes.blade.php:63` initialisent :

```js
etatSauvegarde: '{{ $errors->any() ? 'erreur' : 'sauvegarde' }}'
```

L'indicateur affiche donc **« Enregistré »** au premier chargement, sans qu'un enregistrement ait eu lieu dans la session. Il devrait être neutre, ou conditionné à un succès de POST confirmé.

Les trois usages de `::loading` ne pilotent pas la prop Blade `loading` de `x-btn`. Le bouton ne passe ni en spinner ni en `disabled` pendant la requête. La protection anti-double-clic revendiquée n'est pas effective.

### 3.7 P2 — composants et cohérence

- `x-alert` ne fusionne pas `$attributes`. **22 appels** passent une classe (`class="mt-4"`, `mt-6`) qui est perdue. Exemple : `deliberations-show.blade.php:64,70,80`.
- `x-stat-card` n'accepte que `slate`, `emerald`, `amber`, `rose`, `primary`. **4 appels** utilisent `indigo` dans **3 fichiers** ; ils retombent silencieusement sur `slate` :
  - `enseignant/dashboard.blade.php:21` ;
  - `modules/attendance/index.blade.php:139-140` ;
  - `modules/schedule/mon-planning-enseignant.blade.php:32`.
- `vite.config.js:11-15` charge **Instrument Sans** (400/500/600), alors que `resources/css/app.css:8` définit `Inter` et que `app.blade.php:57-59` charge Inter via Google Fonts. Une famille est téléchargée pour rien et le choix typographique est ambigu.
- Les menus de l'en-tête (thème, notifications, utilisateur) n'ont pas `aria-expanded` sur le déclencheur, pas de fermeture Échap, et le panneau notifications utilise `role="menu"` avec des `<li>` non interactifs. Le clavier et les lecteurs d'écran ne disposent pas d'un motif complet.
- Les labels de `documents-batch.blade.php:47,58` ne sont associés à aucun `select` (`for`/`id` absents) ; même problème dans `notes-historique.blade.php:20-46`.

### 3.8 P2 — contraste et lisibilité

`text-slate-400` est utilisé **854 fois dans 226 fichiers** :

- `#94a3b8` sur blanc = **2,56:1** ;
- sur `#f8fafc` = **2,45:1**.

Le seuil WCAG AA pour du texte normal est 4,5:1. Exemples informatifs :

- `resources/views/components/stat-card.blade.php:20` (libellé de KPI en 11 px) ;
- `resources/views/admin/dashboard.blade.php:10,126,175` ;
- `resources/views/layouts/app.blade.php:310` (horodatage de notification).

Il ne faut pas remplacer globalement les 854 occurrences sans validation visuelle : distinguer le texte informatif du texte purement décoratif, puis cibler les composants et les en-têtes.

### 3.9 P2 — performance front-end non mesurée

Le rapport principal ne contient pas de mesure Lighthouse, réseau ou Web Vitals. Les artefacts construits observés sont :

- `app.js` : 322 Ko bruts ;
- `app.css` : 281 Ko bruts ;
- `ScrollTrigger` : 110 Ko bruts ;
- `lenis` : 18 Ko bruts.

Ces chiffres ne sont pas des défauts en soi, mais ils ne permettent pas de conclure à une amélioration de performance. Il manque une mesure avant/après (LCP, INP, CLS, poids transféré).

---

## 4. Preuves exécutées

### 4.1 Tests

Exécutions séquentielles, sans suite concurrente sur la même base :

```bash
php artisan test --filter=EtudiantThemesTest --compact
php artisan test --filter=InscriptionAccueilParcoursTest --compact
php artisan test --filter=ParcoursAccueilEmbarqueTest --compact
php artisan test --filter=RelevesGenerationTest --compact
php artisan test --filter=SaisieIdentificationStricteTest --compact
php artisan test --filter="Evaluation|Note|Deliberation" --compact
php artisan test --filter="EvaluationQualityServiceTest|EvaluationWorkflowServiceTest|EvaluationDocumentBatchTest|EvaluationAuditTest|EvaluationAdvanced02IntegrationTest" --compact
```

Résultats :

- 5 échecs reproduits (détail §3.1) ;
- `Evaluation|Note|Deliberation` : **220/220**, 889 assertions, 16,309 s ;
- ADVANCED-02 : **23/23**, 96 assertions, 2,076 s ;
- `vendor/bin/pint --dirty --test` : **passed**.

La suite globale de référence n'a pas été relancée intégralement pendant ce contre-audit afin d'éviter un conflit de base de test avec le processus concurrent. Les cinq tests en échec ont toutefois été reproduits individuellement.

### 4.2 Contrôles non exécutés

Les contrôles suivants n'ont **pas** été exécutés et ne peuvent pas être déclarés `PASS` :

- test visuel authentifié desktop/mobile ;
- test de navigation clavier réelle ;
- test lecteur d'écran (NVDA/VoiceOver) ;
- audit axe-core / WAVE automatisé ;
- Lighthouse / Web Vitals ;
- régression visuelle par capture ;
- test Playwright/Puppeteer (les deux paquets sont absents ; Chrome est installé mais non piloté).

Les constats ci-dessus sont donc des constats **de source et de structure**, pas des constats visuels.

---

## 5. Plan de lots proposé après validation

### Lot 0 — Stabilisation baseline (bloquant)

- Corriger `Carbon::next()` dans le contrôleur de rendez-vous.
- Réparer l'action du formulaire d'accueil et le wizard embarqué sans dupliquer les identifiants.
- Requalifier le 403 du test de relevés et le contrôle `formaterDateNaissance`.
- Critère d'acceptation : les 5 échecs disparaissent, la suite globale est verte, aucune nouvelle régression.

### Lot 1 — Coquille et recherche transverses

- Logo → tableau de bord du rôle.
- Palette : commandes pour les trois rôles, endpoints adaptés, état initial non vide, ARIA combobox/listbox correct, raccourci `/` réellement fonctionnel.
- Recherche mobile accessible.
- Menus d'en-tête : `aria-expanded`, Échap, focus visible.
- Critère : parcours administrateur / enseignant / étudiant au clavier et au mobile.

### Lot 2 — Tableaux, formulaires et contraste

- Wrapper scrollable, `scope`, caption/aria-label sur les tableaux prioritaires (`notes`, `deliberations`, `historique`).
- Association `label`/`select` dans `documents-batch` et `notes-historique`.
- Traiter le contraste des libellés informatifs `text-slate-400`.
- Critère : aucun tableau clé rogné à 360 px ; navigation clavier des filtres ; contraste AA sur les textes porteurs de sens.

### Lot 3 — Thème et tokens

- Décider : compléter le mode sombre ou retirer le sélecteur.
- Si complété : coquille, navigation, 25 composants partagés, cartes, tableaux, contrastes.
- Fusionner `$attributes` dans `x-alert` ; faire échouer explicitement une couleur `x-stat-card` inconnue ou ajouter `indigo` au mapping.
- Unifier la police (Inter ou Instrument Sans) et supprimer le téléchargement concurrent.
- Critère : décision documentée, aucune page hybride clair/sombre, police unique.

### Lot 4 — Feedback et sécurité des actions

- API de chargement dynamique pour `x-btn` (ou remplacement des `::loading` par un composant réellement pilotable).
- État de sauvegarde initial neutre ; « Enregistré » uniquement après confirmation serveur.
- Actions de délibération filtrées par statut + confirmation explicite pour validation/publication.
- Critère : zéro double soumission, zéro action invalide présentée, confirmation traçable.

### Lot 5 — Performance et vérification finale

- Mesure avant/après : LCP, INP, CLS, poids JS/CSS.
- Vérifier que GSAP/Lenis ne sont pas chargés sur les écrans de saisie qui n'en ont pas besoin.
- Exécuter la suite globale, Pint, un audit axe et une recette navigateur.
- Critère : suite verte + réserves navigateur levées ou explicitement acceptées.

---

## 6. Décisions à valider avant implémentation

1. **Mode sombre** : compléter ou masquer le sélecteur ?
2. **Recherche par rôle** : créer des endpoints portail ou renvoyer vers la recherche pleine page ?
3. **Police** : Inter (déjà utilisée par le CSS) ou Instrument Sans (déjà chargée par Vite) ?
4. **Contraste** : remplacer `text-slate-400` par `text-slate-500` sur les libellés porteurs de sens, ou introduire un token dédié ?
5. **Délibérations** : quelles transitions doivent être confirmées, et quelles actions doivent être masquées selon le statut ?
6. **Périmètre tableaux** : corriger les 54 vues sans wrapper ou commencer par les parcours critiques étudiants/enseignants ?

---

## 7. Verdict du contre-audit

```
PHASE 0 / AUDIT INITIAL : COMPLET AVEC RÉSERVES
IMPLÉMENTATION CONCURRENTE : PRÉSERVÉE, NON RÉÉCRITE
NON-RÉGRESSION GLOBALE : NON DÉMONTRÉE (5 ÉCHECS)
TESTS NAVIGATEUR / A11Y : NON EXÉCUTÉS
VERDICT FINAL : NO-GO POUR UN PASS GLOBAL
```

Le rapport principal peut conserver son verdict **scopé aux évaluations** (`220/220` et `23/23` sont reproductibles), mais il ne doit pas être lu comme une validation globale de la plateforme. Aucun commit, push, reset ou opération destructive n'a été effectué par ce contre-audit.
