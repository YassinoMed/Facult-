# LOOP-UNIREGISTRE — Centre de travail administratif intelligent

## Lot 0 — Inventaire, audit, matrice de couverture et plan

- **Date :** 23 octobre 2026
- **Branche :** `feature/admin-modifier-etudiant`
- **HEAD :** `38ef149`
- **Mode :** `AUDIT ONLY` — aucune modification applicative, aucune migration, aucun commit
- **Périmètre du lot :** contrôle et validation des inscriptions, file de traitement, vérification, corrections, statuts, tâches, cockpit, permissions, audit et notifications
- **Verdict du Lot 0 :** `PASS_WITH_RESERVATIONS`
- **Verdict du LOOP global :** `BLOCKED` — l'implémentation ne peut pas démarrer avant validation du plan et des décisions métier du §6.

> Ce rapport est le seul fichier créé par ce lot. Le dépôt contenait déjà des modifications locales et des fichiers non suivis avant l'audit ; ils n'ont été ni modifiés, ni supprimés, ni écrasés.

---

## 0. Résumé exécutif

L'existant est riche et largement réutilisable : file de traitement, moteur de vérification, workflow 4-yeux des corrections, transitions canoniques des statuts, module de tâches, cockpit, audit et notifications. Il ne faut donc **pas créer un second moteur** de validation, d'affectation, de statuts ou de file de tâches.

L'audit démontre cependant **14 défauts** qui empêchent de considérer le centre de travail comme fiable dans son état actuel. Les plus critiques sont :

1. **Les KPI de la file ignorent tous les filtres sauf la faculté** ; ils ne correspondent donc pas aux listes filtrées et ne sont pas cliquables (`D-01`).
2. **Les KPI temporels sont faux** : « validés aujourd'hui » utilise `updated_at`, et l'ancienneté utilise `created_at`, ce qui compte une simple édition ou une réouverture comme une validation/réouverture récente (`D-02`).
3. **Le filtre SQL `READY_FOR_REVIEW` exclut les runs `A_VERIFIER` sans famille**, alors que la catégorie applicative les y place : des dossiers disparaissent du filtre (`D-03`).
4. **Les catégories SQL se chevauchent** et ne respectent pas la priorité PHP : un dossier peut apparaître dans plusieurs listes, avec un badge différent (`D-04`).
5. **Un rôle en lecture seule (`consultation`) peut déclencher des écritures de vérification** (`verifier`, `bulk`) faute de permission d'écriture sur les routes (`D-05`).
6. **L'action groupée des tâches contourne `tasks.assign`** et permet une assignation à une cible non contrôlée (`D-06`, `D-13`).
7. **Les corrections approuvées mais non appliquées sont invisibles dans la file** ; un dossier peut être affiché « prêt pour décision humaine » alors qu'une correction validée attend son application (`D-07`).

Les tests ciblés existants passent, mais ils ne couvrent pas ces défauts :

```text
php artisan test --compact --filter='FileTraitementCategoriesTest|InscriptionFileTraitementTest|TasksCockpitTest|InscriptionEligibiliteFacadeTest|PermissionsCoherenceTest'

41 tests réussis, 138 assertions, 2,185 s
```

Le plan propose cinq sous-lots. Les sous-lots A (cohérence des compteurs/catégories) et B (permissions/intégrité) peuvent démarrer après autorisation. Les sous-lots C (centre unifié) et D (panneau de décision) restent bloqués par les décisions métier M-01 à M-03.

---

## 1. Périmètre et non-objectifs

### 1.1 Dans le périmètre

- File `admin/inscriptions/file-traitement` : listes, filtres, KPI, catégories, actions.
- Vérification intelligente : moteur, runs persistés, verdicts, erreurs techniques.
- Corrections : proposition, approbation 4-yeux, application, compléments.
- Statuts : `Etudiant.statut` et `InscriptionAcademique.statut`.
- Tâches : génération, transitions, assignation, actions groupées, cockpit.
- Autorisations serveur, périmètre faculté, audit et notifications.
- Tests existants, non-régression, performance et accessibilité.

### 1.2 Hors périmètre de ce lot

- Modification du moteur d'affectation, des finances, des évaluations ou des emplois du temps.
- Refonte visuelle complète.
- Migration de données ou changement de schéma.
- Exécution de la suite complète dans l'état actuel du dépôt (294 fichiers modifiés avant l'audit).
- Recette manuelle avec lecteur d'écran, navigateur ou benchmark de charge.

### 1.3 Non-objectifs de conception

- Ne pas créer de table « centre de travail ».
- Ne pas créer de statut persistant parallèle.
- Ne pas créer une seconde file de tâches.
- Ne pas dupliquer les règles de vérification, de validation ou d'affectation.
- Ne pas faire de l'interface le contrôle de sécurité.

---

## 2. Méthode et preuves

### 2.1 Méthode

1. Lecture du code au HEAD `38ef149`, avec références fichier/ligne.
2. Confrontation des rapports antérieurs au code actuel ; aucun rapport n'est utilisé comme preuve suffisante.
3. Exécution des tests ciblés existants, sans modification de test.
4. Recherche des routes, policies, permissions, services et vues réellement câblés.
5. Aucun test de navigateur, de charge ou d'accessibilité automatisée dans ce lot.

### 2.2 Environnement vérifié

| Élément | Valeur |
|---|---|
| PHP | 8.5.11 |
| Laravel | 13.33.0 |
| Composer | 2.8.6 |
| Node | 26.0.0 |
| npm | 11.12.1 |
| MySQL | 9.2.0 |
| Environnement applicatif | `production` (debug OFF, locale `fr`) |
| Base des tests | SQLite `:memory:` (`phpunit.xml:32-33`) |

### 2.3 État Git au début de l'audit

- 557 entrées : 294 fichiers modifiés, 261 non suivis, 2 ajoutés.
- `git diff --check` signale un espace trailing préexistant dans `resources/views/inscription/existant/accueil.blade.php:1718`.
- Pendant l'audit, un fichier concurrent `docs/engineering/LOOP-UNIREGISTRE-UX-UI-CERTIFICATION-TRANSVERSALE-01.md` est apparu ; il n'a pas été modifié.
- Après création de ce rapport : 559 entrées (les 558 précédentes + ce rapport). Ce rapport est le seul fichier ajouté par ce lot.

### 2.4 Rapport UX/UI existant

Le rapport `LOOP-UNIREGISTRE-UX-UI-CERTIFICATION-TRANSVERSALE-01.md` présent dans l'arbre n'est **pas** utilisé comme preuve de couverture métier :

- il porte sur la présentation, la navigation et les contrastes, pas sur la cohérence des KPI, des catégories ou des permissions ;
- il annonce une stack Laravel 11 / PHP 8.2, alors que l'environnement vérifié est Laravel 13.33 / PHP 8.5.11 ;
- il ne prouve pas l'exécution des tests dans l'état actuel à 294 fichiers modifiés.

---

## 3. Inventaire des briques canoniques

| Surface | Service / composant canonique | Routes / points d'entrée | Données | Tests existants | État |
|---|---|---|---|---|---|
| File d'inscription | `FileTraitementService`, `FileTraitementController` | `admin.inscriptions.file-traitement` | `etudiants`, `dossier_verifications`, `dossier_corrections` | `InscriptionFileTraitementTest`, `FileTraitementCategoriesTest` | Mature mais incohérent sur KPI/catégories |
| Vérification | `VerificationEngine`, `VerificationStore`, `VerificationController` | `admin.verification.show`, `verifier`, `bulk` | `dossier_verifications`, `historique_academique` | `VerificationEngineTest`, `FileTraitementCategoriesTest` | Moteur fiable, permissions d'écriture incomplètes |
| Corrections | `CorrectionService`, `DossierComplementService` | `admin.verification.proposer`, `decider`, `demanderComplement` | `dossier_corrections` | Tests de correction/4-eyes | Workflow 4-yeux solide, visibilité file incomplète |
| Inscription académique | `InscriptionService` | `admin.inscriptions.valider/rejeter/reaffecter/progresser` | `inscriptions_academiques`, `situations_etudiant` | Tests inscription/affectation | Blocages contrôlés, mais TOCTOU |
| Statut étudiant | `StudentStatusService` | `admin.etudiants.updateStatut`, `bulkStatut` | `etudiants`, `situations_etudiant` | Tests statuts | Transitions canoniques, sans contrôle des blocages |
| Éligibilité / blocages | `InscriptionEligibilityService`, `BlocageInscriptionService`, `DocumentsRequisService` | Façade dry-run, validation académique | Pièces, règles, MENS | `InscriptionEligibiliteFacadeTest` | Lecture seule, explicable |
| Tâches | `TaskService`, `TaskGenerationService`, `TaskController` | `admin.taches.*` | `tasks`, `task_evenements` | `TasksCockpitTest` | Idempotence séquentielle, permissions groupées fragiles |
| Cockpit | `CockpitController` | `admin.cockpit.index` | Tâches, agenda, audit, demandes | `TasksCockpitTest` | Composition existante, sans file d'inscription |
| Audit / notifications | `AuditService`, `NotificationService`, listeners | Décisions, corrections, commentaires | `historique_academique`, notifications | Tests audit/notification | Traçabilité bonne, double notification possible |
| Permissions | `Permissions`, policies, middleware | Routes `can:*` | Rôles RBAC | `PermissionsCoherenceTest` | Matrice riche, quelques routes non couvertes |

### 3.1 Ce qui existe déjà et doit être réutilisé

- **File** : `FileTraitementService` + `FileTraitementController` + vue `admin/inscriptions/file-traitement.blade.php`.
- **Vérification** : `VerificationEngine` (11 règles, fail-soft) + `VerificationStore` (runs append-only).
- **Corrections** : `CorrectionService` (verrous, 4-eyes, anti-TOCTOU, re-vérification) + `DossierComplementService`.
- **Statuts** : `StudentStatusService` pour `Etudiant.statut` ; `InscriptionService` pour `InscriptionAcademique.statut`.
- **Blocages** : `BlocageInscriptionService` agrège le moteur et les pièces ; `InscriptionEligibilityService` fournit une façade dry-run.
- **Tâches** : `Tasks` module, `cle_unique`, transitions serveur, génération idempotente.
- **Cockpit** : `CockpitController` compose déjà tâches, agenda, activité et demandes.
- **Audit** : `AuditService` + `HistoriqueAcademique` + `SituationEtudiant`.

### 3.2 Ce qui ne doit pas être créé

- Un second `VerificationEngine` ou un second `BlocageInscriptionService`.
- Un second workflow de correction ou de validation.
- Un second moteur d'affectation.
- Une seconde file de tâches ou une table `centre_travail`.
- Un statut persistant `TECHNICAL_RETRY` ou `CORRECTION_TO_APPLY` : ces catégories doivent rester **dérivées** dans `FileTraitementService`.

---

## 4. Matrice de couverture

Légende :

- `PASS` : existant, vérifié, sans écart matériel pour l'exigence.
- `PARTIAL` : existant mais incomplet ou incohérent.
- `FAIL` : défaut démontré.
- `BLOCKED` : conclusion impossible sans décision métier.
- `NOT_TESTED` : aucune preuve d'exécution dans ce lot.

| ID | Exigence | Statut | Preuve / écart |
|---|---|---|---|
| R-01 | Point d'entrée unique du centre de travail | **FAIL** | La file, la vérification, les tâches et le cockpit sont quatre surfaces distinctes. `CockpitController` ne charge que les tâches, l'agenda, l'activité et les demandes ; aucune section « dossiers d'inscription » (`CockpitController.php:24-53`, vue `admin/cockpit/index.blade.php`). |
| R-02 | Réutiliser les workflows existants, sans second moteur | **PASS** | Les services canoniques sont identifiés et câblés ; aucun second moteur n'est nécessaire. La contrainte est respectée dans l'audit et doit le rester dans le plan. |
| R-03 | File d'inscription : filtres, tri, pagination | **PASS** | `FileTraitementController.php:46-136` ; `InscriptionFileTraitementTest` couvre scoping faculté, actions, FIFO/LIFO, `depose_depuis` et budget de requêtes. |
| R-04 | Compteurs KPI cohérents avec la liste filtrée | **FAIL** | `FileTraitementController.php:150` ne transmet que `faculte_id` ; `FileTraitementService.php:148-154` n'accepte que ce filtre. `search`, `statut`, `programme_id`, `niveau`, `action_attendue`, `categorie`, `depose_depuis` ne modifient pas les KPI. |
| R-05 | Chaque indicateur mène à une liste exacte | **FAIL** | Les cartes KPI sont des `<div>` sans lien (`file-traitement.blade.php:36-99`) ; « Aujourd'hui » et « Délai moyen » n'ont pas de filtre de liste correspondant. |
| R-06 | Catégorie applicative = filtre SQL | **FAIL** | `determinerCategorie()` et `contrainteCategorie()` divergent sur `A_VERIFIER` sans famille et sur les catégories multiples (`FileTraitementService.php:451-469`, `:484-578`). |
| R-07 | Plusieurs corrections visibles, y compris approuvées non appliquées | **FAIL** | La file ne charge que `DossierCorrection.statut = proposee` (`FileTraitementService.php:363-368`, `:519-520`) ; une correction `approuvee` non appliquée n'est ni comptée ni catégorisée. |
| R-08 | Erreur technique distincte d'un contrôle réussi | **PARTIAL** | `VerificationEngine.php:70-88` transforme l'exception en `avertissement` et verdict `A_VERIFIER`, jamais `CONFORME` ; mais aucun statut/catégorie `TECHNICAL_RETRY` n'est exposé (`FileTraitementService.php:73-74`). |
| R-09 | Autorisations serveur sur toutes les actions | **FAIL** | `verification.verifier` et `verification.bulk` n'ont que `throttle` (`routes/web.php:890-912`) ; `taches.bulk` n'a que `can:tasks.update` et le contrôleur n'autorise que `viewAny` (`TaskController.php:79-87`). |
| R-10 | Périmètre faculté / anti-IDOR | **PARTIAL** | File, commentaires et vérification sont scopés ; l'assignation de tâche ne valide pas la faculté/le rôle/le statut de la cible (`TaskController.php:72`). |
| R-11 | Transitions canoniques et idempotence | **PASS** | `StudentStatusService` et `InscriptionService` verrouillent la ligne et revalident la transition ; `TaskService` est idempotent en séquentiel. La concurrence des tâches reste fragile (`D-12`). |
| R-12 | Source de vérité du statut de la file | **BLOCKED** | La file utilise `Etudiant.statut` + `groupe_id` (`FileTraitementService.php:436-443`) ; l'inscription académique a un statut distinct (`InscriptionService.php:30-34`). Aucune décision métier ne dit lequel fait foi. |
| R-13 | Traçabilité et audit des décisions | **PASS** | `SituationEtudiant`, `HistoriqueAcademique`, `AuditService`, `TaskEvenement` ; commentaires de file audités (`FileTraitementService.php:596-640`). |
| R-14 | Notifications idempotentes, sans validation implicite | **PARTIAL** | Clés de déduplication présentes (`StudentStatusService.php:101-111`, `NotifierDecisionDossier.php:42-53`) ; deux notifications in-app distinctes (`dossier.statut` et `dossier.decision`) restent possibles. |
| R-15 | Performance / absence de N+1 | **PARTIAL** | Budget existant ≤ 25 requêtes sur la file (`InscriptionFileTraitementTest.php:400-415`) ; aucun benchmark ni `EXPLAIN` dans ce lot ; les filtres catégories utilisent `LIKE` sur JSON. |
| R-16 | Tests de non-régression suffisants | **PARTIAL** | 41 tests ciblés verts, mais aucun test ne couvre les défauts D-01 à D-07, D-09, D-12, D-13. La suite complète n'a pas été relancée. |
| R-17 | Accessibilité clavier / lecteur d'écran / responsive | **NOT_TESTED** | Aucun Playwright/Puppeteer/axe/Lighthouse disponible ; Chrome seul ne suffit pas. |
| R-18 | Décisions métier documentées | **PARTIAL** | Ce rapport documente les décisions manquantes ; elles restent à arbitrer (§6). |

---

## 5. Défauts démontrés

### D-01 — Les KPI ignorent les filtres de la liste

- **Sévérité :** P1
- **Preuve :**
  - `app/Http/Controllers/Admin/FileTraitementController.php:150` : `kpis($utilisateur, $valide['faculte_id'] ?? null)`.
  - `app/Services/FileTraitementService.php:148-154` : la méthode n'accepte que `?int $faculteId`.
  - `app/Http/Controllers/Admin/FileTraitementController.php:142` : le commentaire affirme que les KPI restent « calculés sur tout le périmètre filtré », ce qui est faux pour tous les autres filtres.
  - `resources/views/admin/inscriptions/file-traitement.blade.php:35` : la vue dit explicitement « non affectés par les autres filtres ».
  - `resources/views/admin/inscriptions/file-traitement.blade.php:36-99` : les cartes sont des `<div>` sans `route()`.
- **Impact :** avec `statut=rejete`, la liste montre les rejetés mais la carte « En attente » continue d'afficher le stock global. Avec `action_attendue=COMPLEMENT_ATTENDU`, le compteur de l'action ne correspond pas au total de la liste. L'agent ne peut pas faire confiance aux chiffres et ne peut pas cliquer pour vérifier.
- **Correctif proposé :**
  1. Extraire une méthode unique `appliquerFiltres(Builder $query, array $filtres, User $user)` ou un `FileTraitementQuery` qui applique **tous** les filtres.
  2. Utiliser ce même builder pour `paginate()`, `count()` et chaque compteur d'action.
  3. Faire recevoir les filtres à `kpis(array $filtres, User $user)`, pas seulement `faculte_id`.
  4. Envelopper chaque carte dans un lien avec les paramètres exacts (`statut`, `action_attendue`, `categorie`, `depose_depuis`, etc.).
  5. Ajouter un lien « Réinitialiser les filtres » pour la vue globale ; si une vue globale reste affichée, la libeller explicitement « périmètre global » et non « périmètre filtré ».
- **Test de non-régression :** `FileTraitementKpiCoherenceTest` avec au moins 6 dossiers répartis sur les statuts, actions, catégories, programmes et facultés. Pour chaque filtre, assert `kpis['total']` et `kpis['actions'][$action]` égalent `paginate()->total()` de la même requête ; assert le lien de chaque carte ; assert le scoping faculté.

### D-02 — Les KPI temporels utilisent `updated_at` et `created_at` au lieu de l'historique de transition

- **Sévérité :** P1
- **Preuve :**
  - `app/Services/FileTraitementService.php:189-199` : `valides_aujourdhui` et `rejetes_aujourdhui` filtrent sur `statut` + `updated_at >= aujourd'hui`.
  - `app/Services/FileTraitementService.php:301-322` : le délai moyen et la souffrance utilisent `created_at`.
  - `app/Services/StudentStatusService.php:18-24` : les transitions autorisent `valide → en_attente` et `rejete → en_attente` (réouverture).
  - `app/Services/StudentStatusService.php:33-95` : chaque transition crée une `SituationEtudiant` avec `date_effet`, source canonique de la date de décision.
- **Impact :**
  - Un étudiant validé hier, dont on modifie aujourd'hui le téléphone ou l'adresse, est compté dans « validés aujourd'hui » parce que `updated_at` a changé. Le KPI ne mesure pas les décisions, il mesure les éditions.
  - Un dossier réouvert aujourd'hui depuis `valide` vers `en_attente` est immédiatement compté « en souffrance > 48h » parce que `created_at` est ancien, alors qu'il vient de revenir dans la file.
  - Ces indicateurs n'ont pas de liste filtrée correspondante : impossible de vérifier le chiffre.
- **Correctif proposé :**
  - Calculer `valides_aujourdhui`/`rejetes_aujourdhui` depuis la dernière `SituationEtudiant` de type `statut` avec `nouvelle_valeur` cible et `date_effet`/`created_at` dans la journée.
  - Calculer l'ancienneté depuis la dernière transition vers `en_attente` (ou `created_at` si aucune situation antérieure).
  - Ajouter un filtre `decision_depuis` (jours) et `decision=valide|rejete` pour rendre les cartes cliquables.
- **Test de non-régression :** `FileTraitementTimeKpiTest` avec `Carbon::setTestNow` :
  - validé hier + `update()` aujourd'hui → ne doit pas être compté aujourd'hui ;
  - réouverture aujourd'hui → ancienneté ≈ 0, pas > 48h ;
  - décision aujourd'hui → comptée et retrouvée par le filtre `decision_depuis=1`.

### D-03 — Le filtre `READY_FOR_REVIEW` exclut les runs `A_VERIFIER` sans famille

- **Sévérité :** P1
- **Preuve :**
  - `app/Services/FileTraitementService.php:451-469` : un run `A_VERIFIER` sans code de famille retourne `READY_FOR_REVIEW`.
  - `app/Services/FileTraitementService.php:519-530` : `$anomalieRun` sélectionne tout dernier run `BLOQUANT` **ou** `A_VERIFIER`.
  - `app/Services/FileTraitementService.php:563-567` : `READY_FOR_REVIEW` ajoute `whereNotExists($anomalieRun)`, donc exclut précisément le cas `A_VERIFIER` sans famille.
  - `app/Services/FileTraitementService.php:559-561` : `BLOCKED` ne retient que `BLOQUANT` + `sansFamilles`.
  - `tests/Feature/FileTraitementCategoriesTest.php:146-156` : le test du repli `A_VERIFIER` vérifie seulement l'accesseur `assertCategorie()`, pas `contrainteCategorie()`.
- **Impact :** un dossier avec un run `A_VERIFIER` sans code spécialisé est affiché avec le badge « Prêt pour décision humaine », mais le filtre `categorie=READY_FOR_REVIEW` ne le retourne pas. Il n'apparaît dans aucune catégorie filtrée : il devient invisible pour l'agent qui travaille par catégorie.
- **Correctif proposé :** aligner le SQL sur le PHP. Soit ajouter `A_VERIFIER + sansFamilles` à `READY_FOR_REVIEW`, soit créer une catégorie dédiée `TECHNICAL_RETRY`/`HUMAN_REVIEW` et l'utiliser dans les deux logiques. Ne pas dupliquer les prédicats : une seule fonction de catégorie doit produire le badge et le filtre.
- **Test de non-régression :** `FileTraitementCategoriesSqlTest::test_a_verifier_sans_famille_est_visible_dans_ready_for_review` : créer le run, appeler `contrainteCategorie()`, assert que l'étudiant est retourné ; assert que `determinerCategorie()` retourne la même catégorie.

### D-04 — Les catégories SQL se chevauchent et ne respectent pas la priorité PHP

- **Sévérité :** P1
- **Preuve :**
  - `app/Services/FileTraitementService.php:119-125` : ordre de priorité `mens` > `identite` > `doublons` > `documents` > `pieces`.
  - `app/Services/FileTraitementService.php:446-461` : `determinerCategorie()` s'arrête à la première famille trouvée ; un complément (`$aComplement`) est prioritaire sur toutes les familles.
  - `app/Services/FileTraitementService.php:534-551` : `MENS_REVIEW`, `IDENTITY_REVIEW` et `DUPLICATE_REVIEW` n'excluent ni les autres familles ni `$complementExiste`.
  - `app/Services/FileTraitementService.php:552-557` : `MISSING_DOCUMENTS` accepte tout run contenant `documents.`/`pieces.`, sans exclure `mens`, `identite` ou `doublons`.
- **Impact :**
  - Un run `BLOQUANT` avec `mens.divergences` + `documents.manquant` apparaît dans `MENS_REVIEW` et dans `MISSING_DOCUMENTS` ; PHP le classe `MENS_REVIEW`.
  - Un dossier avec un complément ouvert et un run `mens.*` apparaît dans `MENS_REVIEW` et dans `MISSING_DOCUMENTS` ; PHP le classe `MISSING_DOCUMENTS` (complément prioritaire).
  - Les compteurs par catégorie peuvent donc être supérieurs au nombre réel de dossiers, et un dossier peut être traité deux fois.
- **Correctif proposé :** construire un prédicat unique par catégorie qui applique les exclusions de priorité :
  - `MENS_REVIEW` : `en_attente` + `mens.*` + pas de complément ;
  - `IDENTITY_REVIEW` : `en_attente` + `identite.*` + pas de complément + pas de `mens.*` ;
  - `DUPLICATE_REVIEW` : `en_attente` + `doublons.*` + pas de complément + pas de `mens.*`/`identite.*` ;
  - `MISSING_DOCUMENTS` : `en_attente` + (`complementExiste` ou `documents.*`/`pieces.*`) et, si l'on veut une partition stricte, exclure les familles supérieures lorsqu'il n'y a pas de complément.
  - `READY_FOR_REVIEW` : `en_attente` + pas de complément + pas d'anomalie (ou fallback `A_VERIFIER` si D-03 est traité).
- **Test de non-régression :** `FileTraitementCategoriesSqlTest` :
  - run multi-familles → une seule catégorie, celle de la priorité PHP ;
  - complément + run `mens.*` → `MISSING_DOCUMENTS` uniquement ;
  - union des catégories = base `en_attente` sans doublon (ou partition documentée) ;
  - chaque `contrainteCategorie()` retourne exactement les étudiants dont `determinerCategorie()` retourne la catégorie.

### D-05 — Un rôle en lecture seule peut déclencher des écritures de vérification

- **Sévérité :** P1
- **Preuve :**
  - `routes/web.php:890-895` : `verification.index` et `verification.show` n'ont aucune permission `can:archives.*`.
  - `routes/web.php:896-899` : `verification.verifier` n'a que `throttle:30,1`.
  - `routes/web.php:910-912` : `verification.bulk` n'a que `throttle:10,1`.
  - `app/Support/Permissions.php:424-465` : le rôle `consultation` possède `archives.view` mais **pas** `archives.update`.
  - `app/Http/Controllers/Admin/VerificationController.php:91-108` : `verifier()` écrit un `DossierVerification` et un audit.
  - `app/Http/Controllers/Admin/VerificationController.php:346-377` : `bulk()` dispatche `VerifyStudentDossierJob`, qui écrit un run (`app/Jobs/VerifyStudentDossierJob.php:58-59`).
  - `app/Http/Controllers/Admin/VerificationController.php:490-497` : `autoriserAccesEtudiant()` ne fait que `Gate::authorize('view', $etudiant)`.
  - `app/Policies/EtudiantPolicy.php:21-24` : `viewAny()` retourne toujours `true`.
  - `app/Policies/EtudiantPolicy.php:35-38` : `view()` ne vérifie que le périmètre faculté.
- **Impact :** un compte `consultation` peut ouvrir la fiche, cliquer « Relancer la vérification » ou lancer une vérification en masse, et provoquer des écritures, des audits et des jobs. Ce n'est pas un simple écran de lecture. La route `verification.show` est également accessible sans permission explicite, même si tous les rôles actuels ont `archives.view`.
- **Correctif proposé :**
  - `verification.index`/`show` : `can:archives.view`.
  - `verification.verifier` : `can:archives.update` + `Gate::authorize('update', $etudiant)` dans le contrôleur.
  - `verification.bulk` : `can:archives.update` (ou une permission dédiée `verification.bulk` si le métier veut distinguer).
  - Ajouter une décision explicite : un rôle lecture seule peut-il lancer une vérification ? Si oui, créer une permission `verification.run` et l'accorder volontairement, pas par absence de middleware.
- **Test de non-régression :** `VerificationPermissionsTest` :
  - `consultation` : GET `show` 200, POST `verifier` 403, POST `bulk` 403 ;
  - `agent_scolarite` : POST `verifier` 200, `bulk` 200 ;
  - `admin_faculte` hors faculté : 403/404 ;
  - vérifier qu'aucun `DossierVerification` n'est créé sur les 403.

### D-06 — L'action groupée des tâches contourne `tasks.assign` et `tasks.complete`

- **Sévérité :** P1
- **Preuve :**
  - `app/Modules/Tasks/Routes/web.php:26-28` : `taches.bulk` n'a que `can:tasks.update`.
  - `app/Modules/Tasks/Controllers/TaskController.php:79-87` : `actionGroupee()` ne fait que `Gate::authorize('viewAny', Task::class)` et valide `assigne_a` par `exists:users,id`.
  - `app/Modules/Tasks/Services/TaskService.php:108-130` : `actionGroupee()` exécute `transiter(completed)` ou `assigner()` sans contrôle de permission.
  - `app/Support/Permissions.php:414` : `agent_scolarite` possède `tasks.update` et `tasks.complete`, mais **pas** `tasks.assign`.
  - `app/Modules/Tasks/Controllers/TaskController.php:67-76` : l'action unitaire `assigner()` est correctement protégée par `can:tasks.assign` + `Gate::authorize('assign', $tache)`.
- **Impact :** `agent_scolarite` peut appeler `POST admin/taches/actions-groupees` avec `action=assigner` et assigner des tâches sans la permission dédiée. Le contrôle de périmètre ne porte que sur les tâches, pas sur la cible. Le même mécanisme permettrait à un rôle ayant `tasks.update` mais pas `tasks.complete` de terminer des tâches en masse.
- **Correctif proposé :**
  - Dans `actionGroupee()`, mapper l'action vers l'ability : `terminer` → `complete`, `demarrer` → `update`, `assigner` → `assign`.
  - Autoriser chaque tâche sélectionnée (`Gate::authorize($ability, $tache)`) avant l'exécution, ou faire porter le contrôle par le service avec l'utilisateur courant.
  - Valider la cible d'assignation (voir D-13).
  - Ne pas se contenter de `can:tasks.update` sur la route.
- **Test de non-régression :** `TaskBulkPermissionsTest` :
  - `agent_scolarite` + `action=assigner` → 403 ;
  - `admin_faculte` avec `tasks.assign` + tâche de sa faculté → 200 ;
  - rôle sans `tasks.complete` + `action=terminer` → 403 ;
  - cible hors faculté/inactive → 422, aucune écriture.

### D-07 — Les corrections approuvées non appliquées sont invisibles ; plusieurs corrections ne sont pas listées

- **Sévérité :** P1
- **Preuve :**
  - `app/Services/FileTraitementService.php:363-368` : la file ne charge que les `DossierCorrection` au statut `proposee`.
  - `app/Services/FileTraitementService.php:519-520` : `$complementExiste` ne filtre que `proposee`.
  - `app/Models/DossierCorrection.php:17` : les statuts incluent `approuvee`, `appliquee`, `rejetee`.
  - `app/Services/Verification/CorrectionService.php:230-245` : `approuver()` passe à `approuvee` et s'arrête là.
  - `app/Services/Verification/CorrectionService.php:283-330` : `appliquer()` est une action séparée, réservée à une correction `approuvee`.
  - `app/Services/DocumentsRequisService.php:238-255` : une pièce rejetée est retournée avec l'état `rejete` ; la file ne lit pas cet état.
  - `app/Services/FileTraitementService.php:33-34` : la documentation du service parle de « pièce rejetée en cours », mais l'implémentation ne consulte que `DossierCorrection`.
- **Impact :**
  - Une correction approuvée mais non appliquée n'est ni comptée dans `COMPLEMENT_ATTENDU` ni catégorisée. Si le dernier run est `CONFORME`, `determinerCategorie()` retourne `READY_FOR_REVIEW` : la file dit « Prêt pour décision humaine » alors qu'une correction validée attend son application.
  - Un dossier avec trois corrections (nom, CIN, pièce) est affiché comme un simple booléen « complément ouvert », avec le libellé générique « Documents à compléter ». L'agent ne voit pas lesquelles sont demandées.
  - Une correction de champ (nom, email, téléphone) est étiquetée « Documents à compléter » et la prochaine action parle de « pièces obligatoires », ce qui est faux.
- **Correctif proposé :**
  - Définir « correction ouverte » = `proposee` **ou** `approuvee` (une correction approuvée non appliquée est encore une action attendue).
  - Ajouter une catégorie dérivée `CORRECTION_TO_APPLY` (ou un libellé spécifique dans `MISSING_DOCUMENTS`) et la comptabiliser dans les KPI.
  - Charger en lot les corrections ouvertes (`champ`, `motif`, `statut`, `propose_par`, `decide_par`) et afficher la liste ou un compteur + les libellés des champs dans la file ; la fiche détaillée affiche le détail.
  - Ne pas afficher les anciennes/nouvelles valeurs dans la file (PII) ; afficher le champ et le statut.
  - Décider si une correction `approuvee` non appliquée bloque la validation (M-03).
- **Test de non-régression :** `FileTraitementCorrectionsTest` :
  - 2 corrections `proposee` → les deux champs sont visibles (ou comptés) ;
  - 1 correction `approuvee` + dernier run `CONFORME` → catégorie `CORRECTION_TO_APPLY`/action non `READY_FOR_REVIEW` ;
  - 1 correction `appliquee` → plus dans les corrections ouvertes ;
  - 1 correction de champ → pas de libellé « Documents à compléter » ;
  - aucune PII (CIN, email) dans le motif de file.

### D-08 — Le centre de travail n'existe pas comme surface unifiée

- **Sévérité :** P1 (objectif produit)
- **Preuve :**
  - `app/Http/Controllers/Admin/CockpitController.php:24-53` : le cockpit ne charge que les tâches, l'agenda, l'activité et les demandes en ligne.
  - `resources/views/admin/cockpit/index.blade.php` (64 lignes) : aucune section « dossiers d'inscription », aucun lien vers `admin.inscriptions.file-traitement`.
  - La file `admin/inscriptions/file-traitement` est une surface distincte.
  - `admin.verification.index` est une troisième surface (« inscriptions bloquées »).
  - `admin.taches.index` est une quatrième surface.
- **Impact :** l'agent doit naviguer entre quatre pages pour voir les dossiers, les anomalies, les corrections et ses tâches. Les chiffres ne sont pas réconciliés. Le « centre de travail » demandé n'est pas identifiable comme point d'entrée unique.
- **Correctif proposé :**
  - Ajouter une section « Centre de travail — Inscriptions » au cockpit existant, ou une page dédiée qui **compose** les services existants.
  - Contenu minimal : KPI de la file (avec liens filtrés), 5 dossiers FIFO, corrections à appliquer, anomalies techniques, tâches urgentes, décisions récentes.
  - Réutiliser `FileTraitementService`, `TaskService`, `CockpitController` ; aucune nouvelle table, aucun nouveau moteur.
  - Si une page dédiée est créée : route `admin.travail.index` avec `cockpit.view` ou `archives.view`, entrée de navigation, tests de permission.
- **Test de non-régression :** `WorkCenterTest` :
  - les compteurs affichés égalent les listes liées ;
  - chaque carte a un lien avec les bons paramètres ;
  - `consultation` peut voir, mais pas écrire ;
  - `admin_faculte` ne voit que sa faculté ;
  - budget de requêtes constant (pas de N+1).

### D-09 — La file « inscriptions bloquées » inclut des dossiers validés ou rejetés

- **Sévérité :** P2
- **Preuve :**
  - `app/Http/Controllers/Admin/VerificationController.php:396-408` : `index()` filtre sur le dernier run `BLOQUANT`/`A_VERIFIER`, sans filtrer `etudiant.statut`.
  - `app/Http/Controllers/Admin/VerificationController.php:470-484` : `compteurs()` compte tous les derniers runs de la faculté, sans statut étudiant.
  - `app/Services/FileTraitementService.php:436-443` et `:534-578` : la file, elle, filtre `statut = en_attente` pour les catégories de traitement.
- **Impact :** un étudiant validé ou rejeté dont le dernier run est encore `BLOQUANT`/`A_VERIFIER` apparaît dans « inscriptions bloquées » alors que la file le considère « traité » ou « à affecter ». Les deux surfaces affichent des chiffres différents pour la même population.
- **Correctif proposé :**
  - Pour la file active : ajouter `where('statut', 'en_attente')` à `index()` et aux compteurs.
  - Pour l'historique : créer/étiqueter une vue « historique des vérifications » distincte, ou autoriser `verdict=CONFORME` et afficher le statut étudiant.
  - Réutiliser la même définition de « dossier actif » que `FileTraitementService`.
- **Test de non-régression :** `VerificationQueueScopeTest` : étudiant `valide` + dernier run `BLOQUANT` → absent de la file active, présent dans l'historique ; compteurs actifs cohérents avec la file.

### D-10 — La validation du statut étudiant ne contrôle pas les blocages, contrairement à la validation académique

- **Sévérité :** P1 (dépend de la décision M-02)
- **Preuve :**
  - `app/Http/Controllers/Admin/EtudiantController.php:405-421` : `updateStatut()` valide la requête puis appelle uniquement `StudentStatusService::changer()`.
  - `app/Services/StudentStatusService.php:33-95` : transitions, verrou, historique, notification ; aucun appel à `BlocageInscriptionService` ou `DocumentsRequisService`.
  - `app/Services/InscriptionService.php:122-140` : `transiter('valide')` vérifie les pièces obligatoires et les blocages `BLOQUANT`, et refuse.
  - `app/Services/FileTraitementService.php:436-443` : la file catégorise sur `Etudiant.statut`, pas sur `InscriptionAcademique.statut`.
- **Impact :** un dossier avec un verdict `BLOQUANT` peut être validé via la fiche 360 (statut étudiant), alors qu'une validation de l'inscription académique serait refusée. La file le fait passer en `A_AFFECTER`/`TRAITE`. La fiche 360 affiche le verdict, mais aucun garde serveur n'impose sa résolution : c'est une décision humaine non tracée comme override.
- **Correctif proposé (après décision M-02) :**
  - **Option A — blocage strict :** `StudentStatusService::changer('valide')` consulte le dernier run / `BlocageInscriptionService` et refuse si `BLOQUANT`.
  - **Option B — override encadré :** autoriser avec une permission dédiée (`archives.validate_override`), un motif obligatoire et un audit explicite.
  - **Option C — souveraineté documentée :** conserver la validation humaine, mais afficher un avertissement bloquant dans l'UI et enregistrer l'override dans l'audit.
  - Dans tous les cas, ne pas dupliquer les règles : réutiliser `BlocageInscriptionService` / `InscriptionEligibilityService`.
- **Test de non-régression :** `StudentStatusValidationGuardTest` : `BLOQUANT` + option A → refus ; option B sans permission → 403 ; option B avec permission + motif → succès audité ; option C → avertissement et audit.

### D-11 — TOCTOU dans `InscriptionService::transiter()` sur les pièces et blocages

- **Sévérité :** P2
- **Preuve :**
  - `app/Services/InscriptionService.php:117-140` : les contrôles `DocumentsRequisService` et `BlocageInscriptionService` sont exécutés **avant** la transaction.
  - `app/Services/InscriptionService.php:146-184` : la transaction verrouille l'inscription et revalide uniquement la transition ; elle ne revalide pas les pièces ni les blocages.
- **Impact :** entre le contrôle et le verrou, une pièce peut être rejetée ou un run `BLOQUANT` peut être créé ; la validation réussit avec une preuve devenue stale. La transition elle-même est protégée, mais la décision métier ne l'est pas.
- **Correctif proposé :** déplacer les contrôles dans la transaction, après `lockForUpdate()` sur l'inscription, et verrouiller les lignes de pièces/vérification nécessaires. Si le coût est trop élevé, revalider un `updated_at`/version des pièces et refuser si changement.
- **Test de non-régression :** test MySQL gaté avec deux connexions, ou test avec un faux `DocumentsRequisService` qui change l'état entre deux appels ; assert que la validation échoue si une pièce devient bloquante entre le premier contrôle et le commit.

### D-12 — `TaskService` n'a pas de verrou de concurrence

- **Sévérité :** P2
- **Preuve :**
  - `app/Modules/Tasks/Services/TaskService.php:22-32` : `synchroniser()` fait `first()` puis `update()` sans `lockForUpdate`.
  - `app/Modules/Tasks/Services/TaskService.php:34` : `create()` sans intercepter `UniqueConstraintViolationException`.
  - `app/Modules/Tasks/Services/TaskService.php:73-91` : `transiter()` fait `refresh()` puis `update()` sans verrou.
  - `app/Modules/Tasks/Services/TaskService.php:93-101` : `assigner()` met à jour `assigne_a` sans verrou.
  - `app/Modules/Tasks/Models/Task.php:12-31` : `TRANSITIONS` est la matrice serveur, mais rien ne protège deux transitions concurrentes.
- **Impact :** deux générations simultanées peuvent créer la même `cle_unique` ; l'une lève une erreur 500 et le lot de génération peut s'arrêter. Deux transitions concurrentes peuvent produire un état final incohérent et des `TaskEvenement` contradictoires. Le test `TasksCockpitTest:60-82` ne couvre que le séquentiel.
- **Correctif proposé :** `lockForUpdate()` dans `transiter()` ; dans `synchroniser()`, `firstOrCreate` avec `lockForUpdate` ou `catch (UniqueConstraintViolationException)` puis relire la tâche ; éventuellement `upsert` avec contrainte unique. Ajouter un test MySQL de concurrence.
- **Test de non-régression :** `TaskConcurrencyMysqlTest` (gaté MySQL) : deux transactions concurrentes sur la même `cle_unique` → une seule tâche, aucune exception non gérée ; deux transitions concurrentes → une seule transition valide, l'autre rejetée proprement.

### D-13 — L'assignation d'une tâche ne valide pas la cible

- **Sévérité :** P2
- **Preuve :**
  - `app/Modules/Tasks/Controllers/TaskController.php:72` : `assigner()` valide seulement `assigne_a => exists:users,id`.
  - `app/Modules/Tasks/Services/TaskService.php:93-101` : `assigner()` écrit directement `assigne_a`.
  - `app/Modules/Tasks/Policies/TaskPolicy.php:30-33` : `assign()` vérifie la permission et le périmètre de l'acteur, pas la cible.
- **Impact :** une tâche peut être assignée à un utilisateur inactif, sans `tasks.view`, ou d'une autre faculté. La tâche disparaît alors de la liste de la cible (404 sur `show`) ou n'est jamais traitée. L'action groupée permet la même cible non contrôlée.
- **Correctif proposé :** valider la cible avec une requête : `users.id` actif, `tasks.view`, et `peutAccederFaculte($tache->faculte_id)` si la tâche est facultaire. Pour une tâche centrale (`faculte_id = null`), autoriser les utilisateurs centraux actifs avec `tasks.view`. Documenter la règle (M-04).
- **Test de non-régression :** `TaskAssignmentTargetTest` : cible inactive → 422 ; cible sans `tasks.view` → 422 ; cible d'une autre faculté → 422 ; cible valide → 200.

### D-14 — Une erreur technique n'a pas de statut/catégorie dédié

- **Sévérité :** P2
- **Preuve :**
  - `app/Services/Verification/VerificationEngine.php:70-88` : une exception de règle devient une `Anomalie` de gravité `avertissement`, avec le message « erreur d'exécution ».
  - `app/Services/Verification/VerificationResult.php:44-60` : la règle est comptée comme non réussie (`rulesPassed`), la pénalité est de 5, le verdict devient `A_VERIFIER` (jamais `CONFORME`).
  - `app/Services/FileTraitementService.php:73-74` : le commentaire dit que `TECHNICAL_RETRY` n'existe pas par étudiant et que les erreurs techniques vivent dans les jobs.
  - `app/Services/FileTraitementService.php:468-469` : un `A_VERIFIER` sans famille devient `READY_FOR_REVIEW` (« Prêt pour décision humaine »).
- **Impact :** le contrôle impossible est bien distingué du succès (pas de `CONFORME`), mais il n'est pas distingué comme **contrôle indisponible** dans la file. L'agent voit « Prêt pour décision humaine » et peut valider alors qu'un contrôle n'a pas pu s'exécuter. C'est une ambiguïté de statut, pas une fausse validation silencieuse.
- **Correctif proposé :** ajouter un booléen dérivé `controle_indisponible` (ou une catégorie `TECHNICAL_RETRY`) dans `FileTraitementService`, sans toucher au moteur ; afficher un badge « Contrôle indisponible — relancer » et exiger soit une relance, soit un override explicite (M-02). Ne pas compter la règle comme « validée ».
- **Test de non-régression :** `VerificationTechnicalErrorTest` : une règle qui lève une exception → verdict `A_VERIFIER`, `controle_indisponible = true`, catégorie dédiée, jamais `CONFORME` ; validation selon la décision M-02.

---

## 6. Décisions métier manquantes

Ces décisions ne peuvent pas être tranchées par le code seul. Elles bloquent tout ou partie de l'implémentation.

| ID | Question | Comportement actuel | Options | Recommandation | Bloque |
|---|---|---|---|---|---|
| **M-01** | Quel statut est la source de vérité de la file : `Etudiant.statut` ou `InscriptionAcademique.statut` ? | La file utilise `Etudiant.statut` + `groupe_id` ; l'inscription académique a un statut séparé (`en_cours`, `valide`, `ajourne`, `abandon`). | A. Statut étudiant = décision d'admission ; académique = inscription administrative. B. Académique = source unique. C. Les deux, avec « traité » = les deux validés. | **C** : garder le statut étudiant pour la candidature, afficher le statut académique, et définir « traité » comme `Etudiant.valide` + groupe + inscription académique `valide` (ou exception documentée). | Oui — catégories `A_AFFECTER`/`TRAITE`, KPI, validation |
| **M-02** | Peut-on valider un dossier avec un verdict `BLOQUANT` ou un contrôle technique indisponible ? | `InscriptionService` refuse `BLOQUANT` ; `StudentStatusService` ne vérifie rien. | A. Blocage strict. B. Override avec permission + motif + audit. C. Souveraineté humaine avec avertissement. | **A pour `BLOQUANT`** ; **B pour `A_VERIFIER` technique** (relance ou override motivé), jamais de validation silencieuse. | Oui — D-10, D-14, panneau de décision |
| **M-03** | Une correction `approuvee` non appliquée bloque-t-elle la validation ? | La file ne la voit pas ; le run peut être `CONFORME` et le dossier `READY_FOR_REVIEW`. | A. Blocage jusqu'à application. B. Application automatique dans la même transaction. C. Avertissement seulement. | **A** : une correction validée est une décision ; tant qu'elle n'est pas appliquée, le dossier n'est pas « prêt ». | Oui — D-07, panneau de décision |
| **M-04** | Qui peut être assigné à une tâche ? | `exists:users,id` seulement. | A. Actif + `tasks.view` + périmètre faculté. B. Actif + `tasks.view` seulement. C. Toute cible. | **A** pour les tâches facultaires ; **B** pour les tâches centrales (`faculte_id = null`). | Oui — D-06, D-13 |
| **M-05** | Une tâche `completed`/`cancelled` doit-elle être réactivée si la source réapparaît ? | `TaskService::synchroniser()` la repasse à `pending` ; `TasksCockpitTest:60-82` l'affirme. | A. Réactivation automatique (comportement actuel). B. Réactivation seulement pour `expired`/`waiting`. C. Réouverture explicite avec motif. | **B** si l'on veut préserver la clôture humaine ; **A** si la source est vraiment récurrente. Décider par source. | Non — mais à documenter avant Lot A |
| **M-06** | Quelles règles de priorité/SLA pour les dossiers d'inscription ? | Pas de priorité persistée ; `souffrance` = 48h/7j fixes ; tri FIFO par défaut. | A. FIFO pur. B. Priorité par catégorie (MENS/identité/blocage > documents > instruction). C. SLA par type de dossier. | **B** : priorité dérivée par catégorie, sans nouvelle table ; SLA à préciser par le métier. | Non — Lot C/E |
| **M-07** | L'ancienneté part-elle de `created_at` ou de la dernière transition vers `en_attente` ? | `created_at`. | A. Création. B. Dernière transition vers `en_attente`. | **B** : un dossier réouvert ne doit pas être immédiatement « en souffrance ». | Non — Lot A |
| **M-08** | Un rôle lecture seule peut-il lancer une vérification ? | `consultation` peut le faire aujourd'hui par absence de middleware. | A. Non, `archives.update` requis. B. Oui, via une permission dédiée `verification.run`. | **A** par défaut ; **B** si le métier veut un déclenchement en lecture seule, avec permission explicite. | Oui — D-05 |

---

## 7. Plan de sous-lots

Le plan respecte les contraintes : aucun second moteur, aucune table « centre de travail », aucune migration destructive, aucun affaiblissement de test. Les sous-lots A et B peuvent démarrer en parallèle après autorisation ; C et D dépendent des décisions M-01 à M-03.

### Lot A — Vérité des compteurs, des catégories et des dates

**Objectif :** garantir que chaque indicateur, chaque badge et chaque liste décrivent exactement la même population.

**Périmètre :**

- `app/Services/FileTraitementService.php`
- `app/Http/Controllers/Admin/FileTraitementController.php`
- `resources/views/admin/inscriptions/file-traitement.blade.php`
- Tests : `tests/Feature/FileTraitementKpiCoherenceTest.php`, `FileTraitementCategoriesSqlTest.php`, `FileTraitementTimeKpiTest.php`, mise à jour ciblée des tests existants.

**Travaux :**

1. Extraire un builder unique de filtres (D-01) et l'utiliser pour la liste et les KPI.
2. Rendre les cartes KPI cliquables avec les paramètres exacts ; ajouter « Réinitialiser ».
3. Aligner les catégories PHP/SQL (D-03, D-04) ; une seule source de vérité pour le badge et le filtre.
4. Inclure `approuvee` dans les corrections ouvertes et afficher les champs demandés (D-07), après M-03.
5. Remplacer les KPI temporels par l'historique `SituationEtudiant` (D-02) ; ajouter `decision_depuis`/`decision`.
6. Ajouter `controle_indisponible`/`TECHNICAL_RETRY` si M-02 le retient (D-14).
7. Ajouter les tests de caractérisation et de non-régression ; ne pas supprimer d'assertion existante.

**Critères de sortie :**

- Pour chaque filtre, `kpis['total']`, `kpis['actions'][...]` et `paginate()->total()` sont égaux.
- La somme des catégories (si partition) est égale à la base `en_attente` ; aucun doublon.
- Un run `A_VERIFIER` sans famille est visible dans la catégorie annoncée par le badge.
- Une correction `approuvee` non appliquée n'est jamais `READY_FOR_REVIEW`.
- Les 41 tests ciblés restent verts ; les nouveaux tests couvrent D-01 à D-04, D-07, D-14.
- Budget de requêtes ≤ 25 sur la file (ou budget justifié si les KPI filtrés ajoutent une requête).

**Dépendances :** M-03 (corrections), M-07 (ancienneté), M-02 (technique) ; M-01 pour la sémantique de `TRAITE`.

### Lot B — Autorisations et intégrité des actions

**Objectif :** garantir qu'aucune route ne permet une action non autorisée et qu'aucune transition concurrente ne perd d'état.

**Périmètre :**

- `routes/web.php` (vérification)
- `app/Http/Controllers/Admin/VerificationController.php`
- `app/Modules/Tasks/Controllers/TaskController.php`
- `app/Modules/Tasks/Services/TaskService.php`
- `app/Services/InscriptionService.php`
- Policies et tests.

**Travaux :**

1. Ajouter `can:archives.view` à `verification.index/show`, `can:archives.update` à `verifier/bulk` (D-05) ; `Gate::authorize('update', $etudiant)` dans `verifier()`.
2. Corriger `actionGroupee()` : ability par action, autorisation par tâche, cible validée (D-06, D-13).
3. Ajouter `lockForUpdate()` dans `TaskService::transiter()` et sécuriser `synchroniser()` contre la course `first/create` (D-12).
4. Revalider les pièces/blocages dans la transaction de `InscriptionService::transiter()` (D-11).
5. Tests de permission et de concurrence (MySQL gaté) ; tests de non-écriture sur 403.

**Critères de sortie :**

- `consultation` ne peut pas écrire ; `agent_scolarite` ne peut pas assigner en masse sans `tasks.assign`.
- Aucune cible d'assignation invalide n'est acceptée.
- Deux générations/transitions concurrentes ne créent ni doublon ni 500.
- La validation académique ne peut pas utiliser des pièces devenues stale.
- Tests ciblés verts ; aucune régression sur `PermissionsCoherenceTest`, `TasksCockpitTest`, `InscriptionEligibiliteFacadeTest`.

**Dépendances :** M-04 (cible), M-08 (permission de vérification). M-02 pour le comportement de validation, mais les contrôles de permission peuvent être faits indépendamment.

### Lot C — Centre de travail unifié

**Objectif :** offrir un point d'entrée unique qui compose la file, les tâches et les décisions, sans nouvelle table ni second moteur.

**Périmètre :**

- `app/Http/Controllers/Admin/CockpitController.php`
- `resources/views/admin/cockpit/index.blade.php`
- éventuellement une route/vue `admin/travail/index` et l'entrée de navigation
- `app/Services/FileTraitementService.php` (lecture)
- Tests : `tests/Feature/WorkCenterTest.php`.

**Travaux :**

1. Ajouter une section « Inscriptions » au cockpit ou une page dédiée :
   - KPI de la file (avec liens filtrés) ;
   - 5 dossiers FIFO (nom, catégorie, action, ancienneté) ;
   - corrections à appliquer / anomalies techniques ;
   - tâches urgentes et « mes tâches » ;
   - dernières décisions.
2. Utiliser les services existants ; aucune duplication de requête métier.
3. Vérifier les permissions : `cockpit.view`/`archives.view` pour la lecture ; pas d'action d'écriture depuis le centre sans permission dédiée.
4. Tests de compteurs, liens, scoping faculté et budget de requêtes.

**Critères de sortie :**

- Un agent voit la file et ses tâches au même endroit, avec des liens exacts.
- Les compteurs du centre égalent ceux de la file.
- Aucune table ajoutée ; aucune logique de validation dupliquée.
- `admin_faculte` ne voit que sa faculté.

**Dépendances :** Lot A (compteurs fiables), M-01 (définition de « traité »), M-06 (priorité).

### Lot D — Panneau de décision du dossier

**Objectif :** permettre à l'agent de voir, dans un seul écran, le verdict, les corrections demandées, les pièces et les actions, avec des gardes serveur.

**Périmètre :**

- `resources/views/admin/etudiants/show.blade.php`
- `resources/views/admin/verification/show.blade.php`
- `app/Http/Controllers/Admin/VerificationController.php`
- `app/Services/FileTraitementService.php` (lecture des corrections)
- `app/Services/BlocageInscriptionService.php` / `InscriptionEligibilityService` (réutilisation)
- Tests : `tests/Feature/DossierDecisionPanelTest.php`.

**Travaux :**

1. Afficher le statut étudiant **et** le statut de l'inscription académique (M-01).
2. Afficher le verdict, le score, les règles non exécutées et un badge « contrôle indisponible » (D-14).
3. Lister toutes les corrections ouvertes : champ, motif, statut (`proposee`, `approuvee`), acteur ; pas d'ancienne/nouvelle valeur sensible dans la file.
4. Afficher les pièces manquantes/rejetées via `DocumentsRequisService`.
5. Actions : vérifier, proposer, approuver, appliquer, valider, rejeter — avec les gardes M-02/M-03.
6. Tracer les overrides (permission, motif, acteur) dans l'audit.
7. Tests : plusieurs corrections, correction approuvée, erreur technique, 4-eyes, permissions, absence de PII.

**Critères de sortie :**

- L'agent voit « ce qui est demandé » sans ouvrir une troisième page.
- Une correction approuvée non appliquée est visible et bloque (si M-03 = A).
- Un contrôle technique indisponible n'est jamais présenté comme un contrôle réussi.
- Aucune validation implicite ; chaque décision est explicite et auditée.

**Dépendances :** M-01, M-02, M-03 ; Lots A et B.

### Lot E — Performance, accessibilité et non-régression

**Objectif :** prouver que le centre reste rapide, accessible et sans régression.

**Périmètre :**

- Tests de budget de requêtes (`WorkCenterTest`, `InscriptionFileTraitementTest`).
- `EXPLAIN` sur les requêtes de catégories ; vérifier les index `dossier_verifications(etudiant_id, id)` et `dossier_corrections(etudiant_id, statut)`.
- Remplacer si possible les `LIKE '%"famille.%'` par des fonctions JSON portables, ou documenter la limite.
- Tests navigateur/a11y si Playwright/axe disponible ; sinon checklist manuelle et mention `NOT_TESTED`.
- Suite complète SQLite ; tests MySQL gatés pour la concurrence ; `vendor/bin/pint --dirty --test`.

**Critères de sortie :**

- Aucune régression sur les tests ciblés et la suite complète disponible.
- Budget de requêtes constant avec 10/100 dossiers.
- Aucun `LIKE` non indexé sur une table volumineuse sans justification.
- Rapport de performance et d'accessibilité avec preuves ou réserves explicites.

**Dépendances :** Lots A–D.

### 7.1 Séquencement recommandé

```text
Décisions M-01/M-02/M-03/M-04/M-08
        │
        ├── Lot A (compteurs/catégories/dates) ──┐
        │                                        ├── Lot C (centre unifié)
        └── Lot B (permissions/concurrence) ─────┤
                                                 └── Lot D (panneau de décision)
                                                          │
                                                          └── Lot E (perf/a11y/non-régression)
```

- **A et B** peuvent être menés en parallèle : A touche surtout `FileTraitementService`/vue ; B touche routes, tâches et `InscriptionService`.
- **C** après A : les compteurs doivent être fiables avant d'être affichés au centre.
- **D** après A/B : le panneau doit utiliser les catégories fiables et les permissions corrigées.
- **E** après C/D : la performance et l'accessibilité se mesurent sur la surface finale.

### 7.2 Conditions d'arrêt

- Si M-01 n'est pas tranchée : ne pas implémenter C/D ; A peut corriger les filtres et les catégories, mais `TRAITE` reste provisoire.
- Si M-02 n'est pas tranchée : ne pas modifier `StudentStatusService` ; D reste bloqué ; B peut corriger les permissions.
- Si M-03 n'est pas tranchée : ne pas ajouter de blocage sur les corrections ; A peut les afficher, pas les rendre bloquantes.
- Si un test existant doit changer de comportement : le justifier par une décision métier explicite, jamais par un affaiblissement d'assertion.
- Si une migration additive est nécessaire (index) : la limiter à `CREATE INDEX`, jamais de `DROP`/`TRUNCATE`/`migrate:fresh`.

---

## 8. Contrôles exécutés et non exécutés

### 8.1 Exécutés

| Contrôle | Résultat | Preuve |
|---|---|---|
| Tests ciblés file/catégories/tâches/éligibilité/permissions | **41/41 réussis, 138 assertions, 2,185 s** | commande §0 |
| Lecture des routes et middlewares | Effectuée | `routes/web.php`, `app/Modules/Tasks/Routes/web.php` |
| Lecture des permissions et policies | Effectuée | `app/Support/Permissions.php`, `EtudiantPolicy`, `TaskPolicy` |
| Lecture des services de file/vérification/corrections/statuts/tâches | Effectuée | références §5 |
| Vérification de l'état Git | 557 entrées au début ; 558 pendant l'audit (fichier UX concurrent) | `git status --short` |
| `git diff --check` | 1 espace trailing préexistant | `resources/views/inscription/existant/accueil.blade.php:1718` |
| Base de tests | SQLite `:memory:` | `phpunit.xml:32-33` |

### 8.2 Non exécutés

| Contrôle | Raison | Statut |
|---|---|---|
| Suite complète | 294 fichiers modifiés ; 5 échecs préexistants connus ; pas de relance dans cet état | `NOT_TESTED` |
| Tests MySQL de concurrence | Nécessitent une base MySQL de test dédiée | `NOT_TESTED` |
| Tests navigateur (Playwright/Puppeteer) | Outils absents | `NOT_TESTED` |
| Accessibilité automatisée (axe, Lighthouse) | Outils absents | `NOT_TESTED` |
| Lecteur d'écran / clavier manuel | Pas de session de recette | `NOT_TESTED` |
| Benchmark de charge / `EXPLAIN` | Pas exécuté dans ce lot | `NOT_TESTED` |
| Envoi réel d'emails/notifications | Pas de worker/mailer de test dans ce lot | `NOT_TESTED` |
| Recette sur données réelles | Interdite dans ce lot (lecture seule) | `NOT_TESTED` |
| Migration/index | Aucune modification | `N/A` |

### 8.3 Limites

- Les références de ligne sont valables au HEAD `38ef149` et à l'état des fichiers au 23/10/2026. Un travail concurrent peut les décaler ; toute implémentation doit revérifier avant édition.
- Le rapport ne prouve pas l'absence totale d'autres défauts ; il prouve les défauts listés et l'absence de couverture de test correspondante.
- Le budget de 25 requêtes est un test existant, pas un benchmark de production.
- L'accessibilité n'est pas certifiée ; elle est `NOT_TESTED`.

---

## 9. Verdict

### 9.1 Verdict du Lot 0

**`PASS_WITH_RESERVATIONS`** : l'inventaire, l'audit, la matrice et le plan sont complets. Les réserves sont les 14 défauts démontrés, les 8 décisions métier ouvertes et les contrôles non exécutés.

### 9.2 Verdict du LOOP global

**`BLOCKED`** : l'implémentation ne peut pas démarrer dans ces conditions, car :

1. Les KPI et les catégories ne décrivent pas la même population que les listes (D-01 à D-04) ; construire un centre dessus afficherait des chiffres faux.
2. Deux failles d'autorisation permettent des écritures/assignations non prévues (D-05, D-06) ; le centre ne peut pas être livré avec ces contournements.
3. Les corrections approuvées non appliquées peuvent être validées (D-07) ; c'est un risque d'intégrité métier.
4. La source de vérité du statut (M-01) et la politique de validation malgré blocage (M-02) ne sont pas tranchées.
5. Aucune preuve navigateur/accessibilité/performance n'existe dans ce lot.

### 9.3 Conditions pour débloquer

1. Valider le plan et le séquencement du §7.
2. Trancher au minimum **M-01, M-02, M-03, M-04, M-08**.
3. Autoriser le démarrage des sous-lots A et B, sans migration destructive.
4. Confirmer que les tests existants peuvent être adaptés uniquement pour refléter les décisions métier, sans affaiblir les assertions.
5. Planifier une session de recette navigateur/accessibilité et une base MySQL de test pour la concurrence.

### 9.4 État des modifications

- **Aucune modification applicative** n'a été effectuée dans ce lot.
- **Aucun commit, push, reset, clean, stash** n'a été exécuté.
- **Aucune migration** n'a été créée ou exécutée.
- **Aucun fichier existant** n'a été écrasé.
- Ce rapport est le seul fichier ajouté.

---

## Annexe A — Commandes exécutées

```bash
php artisan test --compact --filter='FileTraitementCategoriesTest|InscriptionFileTraitementTest|TasksCockpitTest|InscriptionEligibiliteFacadeTest|PermissionsCoherenceTest'

git status --short | wc -l
git branch --show-current
git rev-parse --short HEAD
git diff --check
```

## Annexe B — Fichiers inspectés (principaux)

- `app/Services/FileTraitementService.php`
- `app/Http/Controllers/Admin/FileTraitementController.php`
- `resources/views/admin/inscriptions/file-traitement.blade.php`
- `app/Services/InscriptionService.php`
- `app/Services/StudentStatusService.php`
- `app/Http/Controllers/Admin/EtudiantController.php`
- `app/Http/Controllers/Admin/VerificationController.php`
- `app/Services/Verification/VerificationEngine.php`
- `app/Services/Verification/VerificationResult.php`
- `app/Services/Verification/CorrectionService.php`
- `app/Services/BlocageInscriptionService.php`
- `app/Services/DocumentsRequisService.php`
- `app/Services/DossierComplementService.php`
- `app/Modules/Tasks/Services/TaskService.php`
- `app/Modules/Tasks/Services/TaskGenerationService.php`
- `app/Modules/Tasks/Controllers/TaskController.php`
- `app/Modules/Tasks/Policies/TaskPolicy.php`
- `app/Modules/Tasks/Models/Task.php`
- `app/Http/Controllers/Admin/CockpitController.php`
- `resources/views/admin/cockpit/index.blade.php`
- `app/Support/Permissions.php`
- `app/Policies/EtudiantPolicy.php`
- `routes/web.php`
- `app/Modules/Tasks/Routes/web.php`
- `tests/Feature/InscriptionFileTraitementTest.php`
- `tests/Feature/FileTraitementCategoriesTest.php`
- `tests/Feature/TasksCockpitTest.php`
- `tests/Feature/InscriptionEligibiliteFacadeTest.php`
- `tests/Feature/PermissionsCoherenceTest.php`

## Annexe C — Preuves clés (index)

| Réf. | Fichier:ligne | Fait |
|---|---|---|
| D-01 | `FileTraitementController.php:150` | KPI appelés avec `faculte_id` seulement |
| D-01 | `FileTraitementService.php:148-154` | Signature `kpis(User, ?int $faculteId)` |
| D-01 | `file-traitement.blade.php:35` | « non affectés par les autres filtres » |
| D-02 | `FileTraitementService.php:189-199` | `updated_at` pour validés/rejetés du jour |
| D-02 | `FileTraitementService.php:301-322` | `created_at` pour l'ancienneté |
| D-03 | `FileTraitementService.php:451-469` | Fallback `A_VERIFIER` → `READY_FOR_REVIEW` |
| D-03 | `FileTraitementService.php:563-567` | `READY_FOR_REVIEW` exclut `A_VERIFIER` |
| D-04 | `FileTraitementService.php:534-557` | Catégories SQL sans exclusions de priorité |
| D-05 | `routes/web.php:890-912` | `verifier`/`bulk` sans permission d'écriture |
| D-05 | `Permissions.php:424-465` | `consultation` sans `archives.update` |
| D-06 | `Tasks/Routes/web.php:26-28` | `taches.bulk` seulement `tasks.update` |
| D-06 | `TaskController.php:79-87` | `actionGroupee` seulement `viewAny` |
| D-07 | `FileTraitementService.php:363-368` | Corrections `proposee` seulement |
| D-07 | `CorrectionService.php:230-330` | `approuvee` et `appliquer` séparés |
| D-08 | `CockpitController.php:24-53` | Cockpit sans file d'inscription |
| D-09 | `VerificationController.php:396-484` | File bloquée sans filtre de statut étudiant |
| D-10 | `EtudiantController.php:405-421` | Validation étudiant sans blocage |
| D-10 | `InscriptionService.php:122-140` | Validation académique avec blocage |
| D-11 | `InscriptionService.php:117-184` | Contrôles avant transaction |
| D-12 | `TaskService.php:22-101` | Absence de verrou sur synchronisation, transition et assignation |
| D-13 | `TaskController.php:72` | Cible `exists:users,id` seulement |
| D-14 | `VerificationEngine.php:70-88` | Erreur technique → avertissement |
| D-14 | `FileTraitementService.php:468-469` | `A_VERIFIER` → `READY_FOR_REVIEW` |

---

## Annexe D — Addendum d’implémentation des lots A et B

**Date :** 23/10/2026 — **HEAD :** `38ef149` — **Branche :** `feature/admin-modifier-etudiant`

Cet addendum documente les corrections réellement appliquées après le Lot 0. Il ne remplace pas le verdict initial : les décisions métier non tranchées et les contrôles non exécutés restent bloquants pour le centre unifié.

### D.1 Défauts traités

| Défaut | État | Correction appliquée | Preuve de non-régression |
|---|---|---|---|
| **D-01** | **Corrigé** | `FileTraitementController::index()` construit `$kpiBase` à partir du builder filtré ; `FileTraitementService::kpis()` neutralise présentation, colonnes et eager-loading avant agrégation. Les KPI décrivent la liste filtrée, plus seulement le périmètre faculté. | `FileTraitementCoherenceTest::test_kpis_actions_egaux_aux_listes_sous_tous_les_filtres` ; `HarmonisationFileRegistreTest::test_indicateurs_file_suivent_tous_les_filtres` |
| **D-02** | **Corrigé** | Ancienneté, souffrance et délai moyen calculés depuis la dernière transition `SituationEtudiant` vers `en_attente`, avec repli documenté sur `etudiants.created_at` pour les lignes historiques. Les décisions du jour utilisent la dernière transition de statut, avec repli `updated_at` uniquement sans situation. | Tests de cohérence KPI/liste et tests de file ciblés |
| **D-03** | **Corrigé** | Le filtre SQL `READY_FOR_REVIEW` inclut désormais `A_VERIFIER` sans famille spécialisée, comme le classificateur applicatif. `BLOCKED` reste réservé à `BLOQUANT` sans famille. | `FileTraitementCategoriesTest::test_filtre_ready_for_review_inclut_a_verifier_sans_famille` |
| **D-04** | **Corrigé** | Priorité SQL alignée sur PHP : complément d’abord, puis `mens > identite > doublons > documents/pièces`. Les catégories ne se chevauchent plus sur les familles prioritaires. | `FileTraitementCategoriesTest::test_filtres_sql_categories_ne_se_chevauchent_pas` |
| **D-05** | **Corrigé** | `verification.index`/`show` exigent `archives.view` ; `verifier`/`bulk` exigent `archives.update`. Les actions appellent aussi la policy/Gate côté serveur. Un rôle lecture seule ne peut plus déclencher de vérification. | `VerificationAuthorizationTest` (4 tests) |
| **D-06** | **Corrigé** | `TaskController::actionGroupee()` applique une permission par action (`tasks.assign`, `tasks.complete`, `tasks.update`) puis l’ability de policy tâche par tâche. Aucun ID client n’est traité hors périmètre. | `TaskBulkAuthorizationTest` ; `TasksCockpitTest` |
| **D-07** | **Partiel** | Les corrections `proposee` **et** `approuvee` sont chargées et affichées : badges « Demandé : champ » et « À appliquer : champ ». Une correction approuvée n’est toutefois **pas encore bloquante** : M-03 A n’a pas été appliqué. | `FileTraitementCoherenceTest::test_correction_approuvee_visible_dans_la_file_sans_bloquer_prematurement` |
| **D-09** | **Corrigé** | La file active et les compteurs `bloquants`/`a_verifier`/`conformes` ne considèrent plus que les étudiants `en_attente`. Un étudiant validé/rejeté avec un ancien verdict non conforme disparaît de la file active. Aucune vue d’historique distincte n’a été créée. | `CentreResolutionTest::test_centre_bloquees_exclut_les_dossiers_deja_traites` |
| **D-12** | **Partiel** | `TaskService::transiter()` et `assigner()` verrouillent la tâche avec `lockForUpdate()` dans la transaction, en plus du verrou déjà présent dans `synchroniser()`. La concurrence n’a été prouvée qu’en SQLite ; aucun test MySQL réel n’a été exécuté. | `TaskBulkAuthorizationTest` ; `TasksCockpitTest` |
| **D-13** | **Corrigé** | La cible d’assignation est validée : compte actif, permission `tasks.view`, et accès à la faculté de la tâche pour une tâche facultaire. Règle M-04 A/B appliquée. | `TaskBulkAuthorizationTest` |

### D.2 Décisions métier : appliquées ou non

| Décision | Recommandation du rapport | État réel | Conséquence |
|---|---|---|---|
| **M-01** | C : statut étudiant pour la candidature, statut académique affiché, « traité » = les deux validés | **Non complété** | `TRAITE` conserve la sémantique existante `Etudiant.valide` + groupe, ou rejeté. Le statut académique n’est pas encore exigé ni affiché dans la file. |
| **M-02** | A pour `BLOQUANT` ; B pour `A_VERIFIER` technique | **Non implémenté** | `StudentStatusService` n’a pas été modifié ; D-10 reste ouvert. Une validation via la fiche étudiant n’est toujours pas gardée par le verdict. |
| **M-03** | A : une correction approuvée non appliquée bloque la validation | **Non implémenté** | Seule la visibilité a été ajoutée (D-07 partiel). Le dossier peut encore être validé avant application de la correction approuvée. |
| **M-04** | A pour tâches facultaires ; B pour tâches centrales | **Appliqué** | Cible active + `tasks.view` ; périmètre faculté contrôlé pour les tâches facultaires. |
| **M-07** | B : ancienneté depuis la dernière transition vers `en_attente` | **Appliqué** | Les métriques d’attente utilisent la dernière situation de statut, avec repli historique. |
| **M-08** | A : `archives.update` requis pour vérifier | **Appliqué** | Les routes de vérification sont protégées ; la lecture reste `archives.view`. |

### D.3 Sémantique des indicateurs après correction

- `total`, `en_attente`, `valides`, `rejetes` et les compteurs d’actions sont calculés sur **le même builder filtré** que la liste (faculté, recherche, statut, action, catégorie, ancienneté de dépôt, programme, niveau).
- `souffrance_48h`, `souffrance_7j` et `delai_moyen_heures` partent de la dernière transition `SituationEtudiant` vers `en_attente` ; à défaut, de `etudiants.created_at`.
- `valides_aujourdhui` et `rejetes_aujourdhui` partent de la dernière transition de statut et de sa `date_effet` ; le repli `updated_at` ne s’applique qu’aux dossiers sans aucune situation de statut.
- Le filtre `depose_depuis` reste basé sur `created_at` (ancienneté de dépôt). Il peut donc diverger d’un dossier réouvert, dont la souffrance repart de la dernière transition : c’est la conséquence assumée de M-07 B, pas une incohérence de KPI.
- `TRAITE` reste provisoire tant que M-01 C n’est pas implémenté.

### D.4 Liens et cohérence visuelle

- Sont cliquables : **En attente**, **À instruire**, et les sous-compteurs **complément attendu** / **anomalie détectée**.
- Un lien est **désactivé** lorsqu’un filtre contradictoire est actif (statut différent de `en_attente`, ou autre action sélectionnée). Cela évite qu’un compteur affiché à 0 ouvre une liste non vide après remplacement du filtre.
- Restent **non cliquables** car agrégats ou flux sans filtre de liste exact : **En souffrance > 48h** (dont `> 7j`), **Délai moyen**, **Traités aujourd’hui**, **Affectation auto** et **changements de groupe**. La condition « chaque indicateur ouvre une liste exacte » n’est donc pas encore satisfaite pour ces indicateurs ; elle est documentée comme réserve.
- Les badges de correction affichent le champ concerné sans exposer les valeurs avant/après dans la file.

### D.5 Contrôles exécutés

```bash
php artisan test --compact --filter='FileTraitement|HarmonisationFileRegistre|InscriptionFileTraitement|VerificationAuthorization|TaskBulkAuthorization|CentreResolution|TasksCockpit'
# 92/92 tests réussis, 551 assertions, 5 725 ms
```

Détails des campagnes ciblées :

| Campagne | Résultat |
|---|---|
| `FileTraitementCoherenceTest` | **5/5**, 161 assertions |
| `FileTraitementCategoriesTest` | **17/17**, 42 assertions |
| `CentreResolutionTest` + `VerificationAuthorizationTest` | **11/11**, 38 assertions |
| `HarmonisationFileRegistreTest` + `FileTraitementCoherenceTest` | **19/19**, 328 assertions |
| Analyse syntaxique PHP des contrôleurs/services touchés | Aucune erreur |

Les tests ont été exécutés sur SQLite `:memory:` (configuration de test du projet), pas sur MySQL.

### D.6 État du dépôt et limites

- `git status --short` comptait **577 entrées** au dernier contrôle (le nombre évolue sous travail concurrent) ; le dépôt reste volontairement sale.
- **Aucun commit, push, reset, clean ou stash** n’a été exécuté.
- **Aucune migration** n’a été créée ou exécutée.
- Les modifications concurrentes présentes dans les fichiers ciblés ont été conservées ; les tests ajoutés `HarmonisationFileRegistreTest.php`, `TaskBulkAuthorizationTest.php` et `VerificationAuthorizationTest.php` sont encore non suivis (`??`).
- La suite complète, les tests MySQL de concurrence, les tests de charge, les tests navigateur, axe/Lighthouse, le clavier et le lecteur d’écran restent **NON EXÉCUTÉS**.
- Aucune preuve réelle d’envoi de notification n’a été produite.

### D.7 Verdict de l’addendum

- **Lots A et B : `PASS_WITH_RESERVATIONS`.** Les défauts de cohérence KPI/liste, de catégories, de permissions de vérification, d’action groupée de tâches et de file bloquée sont couverts par des tests ciblés.
- **LOOP global : toujours `BLOCKED`.** M-01 C, M-02 A/B et M-03 A ne sont pas implémentés ; D-08, D-10, D-11 et D-14 restent ouverts ; les lots C/D, la suite complète, MySQL, la charge et l’accessibilité navigateur ne sont pas validés.
- Le centre de travail administratif unifié ne doit pas être annoncé comme livré tant que ces réserves subsistent.
