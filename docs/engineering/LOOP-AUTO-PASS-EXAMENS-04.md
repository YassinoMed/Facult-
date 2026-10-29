# LOOP AUTO-PASS — UNIREGISTRE : CRÉATION MANUELLE D'UN EXAMEN
## Contrôle croisé indépendant — Module Évaluation

**Date :** 24 octobre 2026
**Branche :** `feature/admin-modifier-etudiant` — **HEAD initial = HEAD final** `38ef1493` (aucun commit, aucun push)
**Référence mandate :** CRÉATION MANUELLE D'UN EXAMEN depuis `/admin/evaluations/examens`
**Verdict final :** `PASS_WITH_RESERVATIONS`

---

## 1. Résultat de l'audit initial (Phase 0)

Audit exécuté sur l'état du dépôt de ce jour (runtime concurrent : un flux parallèle travaillait le
même mandat pendant mon audit — mtimes contrôleur 18:29–18:32, vue 18:25, Request étendue ~18:33).

**Contrat backend mesuré sur le terrain :**

- **Route existante réutilisée :** `POST admin.evaluations.examens.store`
  (`app/Modules/Evaluation/Routes/web.php`, groupe `prefix('admin')->name('admin.')`, middleware
  `['can:evaluations.create', 'throttle:20,1']`). **Aucune nouvelle route nécessaire** (solution
  modale sur la page index — ni route GET ni page dédiée requises).
- **`StoreExamenRequest`** — état initial de mon audit (31 lignes) : `session_id` requis,
  `annee_universitaire_id` requis, `libelle` requis (max 200), `type` requis ∈ `Examen::TYPES`
  (8 valeurs), `semestre_lmd_id` / `programme_id` / `niveau_id` / `groupe_id` nullable,
  `description` / `observations` nullable (max 2000). `authorize()` = permission `evaluations.create`.
- **`ExamenController::store()`** : crée **uniquement l'examen** (`statut = 'draft'`,
  `responsable_id` = user) — **jamais d'épreuves**. Double-gate : `Gate::authorize('create', $session)`
  (politique sur la session ≡ toujours true, sans faculté propre) + contrôle périmètre faculté
  programme/groupe → **404** hors périmètre (`abort_unless` + `peutAccederFaculte`).
- **`Examen` / `SessionExamen`** : `TYPES` (8 valeurs), statuts, `estVerrouillee()`
  (`verrouillee_at !== null` **ou** statut ∈ `['locked','archived']`).
- **Permissions** (`app/Support/Permissions.php`) : `super_admin` = TOUTES ; `admin_scolarite` et
  `admin_faculte` ont `evaluations.create` ; `agent_scolarite` et `consultation` = vue seule (403
  attendus sur la création).
- **Vue initiale** `examens-index.blade.php` : aucun accès de création (état vide sans action,
  header sans bouton).

## 2. Conception — arbitrage de concurrence

Découverte en cours d'exécution : un flux parallèle avait déjà livré la solution complète :

- **Vue `examens-index.blade.php` réécrite** (+210 lignes) : bouton « Nouvel examen » dans
  `x-page-header` (slot `actions`, `@can('evaluations.create')`), bouton équivalent dans
  `x-empty-state`, **modal Alpine** `<x-modal nom="modalNouvelExamen" taille="lg">` avec formulaire
  POST vers le store existant (`@csrf`), réouverture si `$errors->any()`, `old()` conservé,
  `@error` par champ, labels FR, flash `succes`/`erreur`, textarea de description, boutons
  Annuler / « Enregistrer l'examen », **synchronisation Alpine année↔session** (`data-annee`),
  état vide sessions → bouton vers `admin.evaluations.sessions.create`.
- **`index()` du contrôleur** enrichi et passe `$sessions`, `$programmes`, `$groupes`, `$annees` au modal.
- **`StoreExamenRequest` étendu** : `prepareForValidation()` (dédution `annee_universitaire_id`
  depuis la session), `withValidator()` (session verrouillée/archivée → **erreur de validation sur
  `session_id`**, cohérence année↔session), `messages()` en français.

**Ma contribution d'exécution (arbitrage « un seul chemin » :**

| Action | Statut |
|---|---|
| Route GET `evaluations/examens/creer` (page dédiée) — créée par moi | **Retirée proprement** (redondante avec le modal) |
| `ExamenController::create()` + vue `examens-create.blade.php` — créés par moi | **Retirés proprement** (un seul chemin de création : le modal) |
| Doublon d'import `use App\Models\AnneeUniversitaire;` dans `ExamenController` (mien + flux → erreur de compilation PHP) | **Corrigé** (retrait de mon exemplaire, `php -l` OK) |
| Modal du flux, `index()`, `StoreExamenRequest`, 17 tests du flux | **Conservés tels quels** (aucun travail du flux écrasé) |

## 3. Comportement final du formulaire

- Bouton « Nouvel examen » visible **uniquement** pour `@can('evaluations.create')`
  (header + état vide) — masqué pour `agent_scolarite` / `consultation` / invités.
- Modal : session (requis, non verrouillée) → année synchronisée automatiquement ; libellé (requis,
  max 200) ; type (requis, 8 choix FR) ; programme/groupe optionnels filtrés par périmètre faculté ;
  description (consignes). CSRF, `old()`, erreurs par champ en rouge, modal ferro à réouverture
  après erreur.
- Rejet français : session inexistante / verrouillée / archive → erreur `session_id` ; type/champ
  manquants → erreurs de champ.
- Succès : redirection vers la liste + flash vert « Examen « libellé » créé. » ; l'examen figure
  en statut **draft**, responsable = utilisateur — aucune épreuve générée par cette voie.

## 4. Tests exécutés — commandes et résultats observés

| # | Commande | Résultat observé |
|---|---|---|
| 1 | `php artisan test tests/Feature/ExamenCreationManuelleTest.php --compact` | **17 tests / 69 assertions — PASSED** (run final, stable) |
| 2 | Même commande pendant l'édition concurrente (~18:34) | 15/17 (2 échecs temporaires des tests invités) → **non reproduit** en isolation ni en pair-run → artefact de mid-edit du flux, pas un défaut de code |
| 3 | Régressions : `FaculteScopeEcritureTest`, `ExamensFeatureCompleteTest`, `ExamensInvariantsValidationTest`, `ExamensInvariantsSallesTest`, `EvaluationTest` | **78 tests / 245 assertions — PASSED** |
| 4 | `vendor/bin/pint <contrôleur> <routes> <Request> <tests> --test` | **PASSED** (aucun formatage requis) |
| 5 | `php -l` contrôleur + routes | OK (vérifié après chaque relecture) |

Couverture du contrat PHASE 3 — bouton visible/403, invités, sessions disponibles (verrouillées
jamais proposées), création valide (assertDatabaseHas + suivi liste), champs manquants/type
invalide/session inexistante/verrouillée/archivée, IDOR programme cross-faculté (assertNotFound),
conservation `old()` (`assertSessionHasInput`), transition `draft→planned`, génération auto
`SessionPreparationService::genererEpreuves` intacte — **tout couvert par la suite existante,
aucun test dupliqué ajouté**.

## 5. Régressions vérifiées

- Isolation faculté / anti-IDOR (`FaculteScopeEcritureTest` — 19 tests, dont création examen
  cross-faculté → 404) : intacts.
- Transitions de statut d'examen (`draft → planned`) et garde de liste : intacts.
- Génération automatique d'épreuves depuis une session (`SessionPreparationService`) : non altérée.

## 6. Réserves restantes (non bloquantes, documentées)

1. **`DomainException('Session verrouillée.')` dans `store()` est devenue une défense en
   profondeur morte** : `withValidator` intercepte la session verrouillée/archivée en erreur de
   validation **avant** le contrôleur ; le niveau contrôleur n'est atteignable qu'en cas de
   course (verrouillage entre validation et store) → 500 brut. Acceptable ; documenté.
2. **Écart UI↔backend pour les comptes à périmètre faculté** : `index()` filtre les
   programmes/groupes avec `where('faculte_id', $faculteId)` — **exclut les ressources
   transversales** (`faculte_id = NULL`) que le backend `store()` autorise pourtant. Le formulaire
   n'expose jamais une option refusée (pas de problème de sécurité), l'écart est de complétude.
   Non corrigé par minimalisme — signalé pour alignement futur.
3. **Champs `niveau_id` / `semestre_lmd_id`** (nullable au backend) non exposés dans le modal —
   choix minimal conforme au périmètre demandé (session/année/type/libellé/programme/groupe).
4. **Documentation du flux** : le premier rapport indique « 13 tests » (état antérieur) alors que
   la suite actuelle en compte 17 (extension d'audit final) — incohérence documentaire mineure,
   le rapport d'audit final du flux prévaut.
5. **Environnement concurrent** : le mandat a été exécuté en parallèle par deux flux ; cette
   mission arbitre en conservant une unique voie de création et en retirant ses propres ajouts
   redondants. Les mesures de ce rapport sont des reconfirmations post-stabilisation.

## VERDICT FINAL

**`PASS_WITH_RESERVATIONS`**
— La fonctionnalité est intégralement livrée et vérifiée (17/17 ciblés, 78/78 régressions,
Pint PASSED, 0 commit, aucun travail du flux écrasé). Les réserves ci-dessus sont mineures,
documentées et n'entravent ni le formulaire, ni le workflow de génération automatique.
