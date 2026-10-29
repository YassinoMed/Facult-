# RAPPORT DE CLÔTURE — LOOP GLOBAL UNIREGISTRE
## Examens, gouvernance, sécurité et continuité — Traitement des actions résiduelles

**Projet :** UniRegistre — Laravel 13.33.0 / PHP 8.5.11  
**Date d'exécution :** 28 octobre 2026 (CET)  
**Branche Git :** `feature/admin-modifier-etudiant`  
**Commit HEAD :** `38ef14933de9980a4c62cb805bd19bc86c2b3081`  
**Mode :** implémentation contrôlée, sécurité vérifiée, tests réels, aucun effet sur la production  
**Verdict global :** `PASS_WITH_RESERVATIONS`

---

## 1. Résumé exécutif

La boucle d'audit précédente avait laissé cinq actions résiduelles. Quatre ont été traitées
dans cette session ; la cinquième (concurrence réelle MySQL) reste bloquée par une
autorisation d'infrastructure absente, conformément au §0.1 du LOOP.

| # | Action résiduelle | État | Preuve |
|---|---|---|---|
| 1 | API v1 `student-dossiers` (lecture seule) | **IMPLÉMENTÉE** | 3 routes, contrôleur dédié, 8 tests PASS / 47 assertions |
| 2 | Balayage `expires_at → expired` | **DÉJÀ IMPLÉMENTÉ + RENFORCÉ** | commande existante + nouveau test d'idempotence (1 test / 8 assertions) |
| 3 | Suite complète des tests | **EXÉCUTÉE — 1 passage vert, 2 passages impactés par l'arbre mouvant** | 5 462 tests / 5 428 PASS / 34 ignorés / 0 échec (passage 1) ; échecs des passages 2-3 hors périmètre, tous PASS isolément (§5.1) |
| 4 | Concurrence réelle MySQL | **BLOCKED_ENVIRONMENT** | base dédiée non autorisée ; test existant volontairement non exécuté |
| 5 | Rapport de clôture | **CRÉÉ** | ce document |

**Aucune migration créée ou appliquée. Aucune donnée locale ou de production modifiée.
Aucun travail préexistant supprimé, réinitialisé ou écrasé.**

---

## 2. Périmètre et règles respectées

### 2.1 Périmètre examens déjà validé (rappel de l'audit)

Le module `Evaluation` reste le socle du LOOP :

- **106 fichiers PHP**, **146 routes**, **12 permissions** `evaluations.*` ;
- planification, salles, surveillance, convocations, présence, incidents, urgences,
  suppléance, clôture, corrections post-clôture, continuité, cockpit, audit ;
- gouvernance persistée et append-only : `soumis → certifié → approuvé (2 personnes
  distinctes) → publié`, avec rejet motivé et escalade bloquante.

Les deux baselines exécutées lors de l'audit restent la référence de non-régression :

| Suite | Fichiers | Tests | PASS | Skipped | Assertions | Durée |
|---|---:|---:|---:|---:|---:|---:|
| Examens / évaluation / surveillance / gouvernance | 61 | 656 | 654 | 2 | 2 841 | 48,18 s |
| Archivage / dossier / documents / rétention / backup | 55 | 460 | 459 | 1 | 2 606 | 41,93 s |

### 2.2 Interdictions respectées

| Interdiction | Respect |
|---|---|
| `git reset --hard`, `git clean`, `checkout` destructif, `stash`, `rebase`, `push`, `pull`, `merge` | **Aucune exécution** |
| `migrate:fresh`, `db:wipe`, suppression globale | **Aucune exécution** |
| Migration appliquée sans autorisation | **Aucune migration appliquée** |
| Modification de la production | **Aucune** |
| Désactivation d'une protection pour faire passer un test | **Aucune** |
| Affaiblissement d'un test | **Aucun** (le test de concurrence reste honnêtement non exécuté) |
| Secrets/PII dans les journaux | **Aucun secret journalisé** ; seuls des noms de bases existantes ont été listés en lecture |

---

## 3. Implémentation — API v1 `student-dossiers`

### 3.1 Décision d'architecture

Le contrôleur est placé dans le module, pas dans le CORE :

`app/Modules/StudentDossiers/Controllers/Api/StudentDossierApiController.php`

Ce choix respecte l'architecture **CORE + MODULES** et le précédent existant
(`App\Modules\ParcoursAcademique\Controllers\ParcoursApiController` référencé depuis
`routes/api.php`). Le CORE ne dépend donc pas de l'implémentation interne du module :
il ne connaît qu'un point d'entrée HTTP.

Les Resources existantes sont réutilisées sans duplication :

- `App\Modules\StudentDossiers\Resources\StudentDossierResource` ;
- `App\Modules\StudentDossiers\Resources\StudentDocumentResource`.

Aucun nouveau service, aucune nouvelle abstraction, aucun accès direct au disque.

### 3.2 Endpoints exposés

| Méthode | URI | Scope | Middleware | Réponse |
|---|---|---|---|---|
| `GET` | `/api/v1/student-dossiers` | `archives:read` | `module:student-dossiers`, `throttle:api` | collection paginée `StudentDossierResource` |
| `GET` | `/api/v1/student-dossiers/{dossier}` | `archives:read` | idem | `StudentDossierResource` + `documents_count` |
| `GET` | `/api/v1/student-dossiers/{dossier}/documents` | `documents:read` | idem | collection paginée `StudentDocumentResource` |

Ordre des middlewares : `module` **avant** `scope`, afin qu'un module désactivé réponde
`404` sans divulguer l'existence de la route ni l'exigence de scope.

### 3.3 Contrôles serveur implémentés

- **Isolation faculté** : `User::faculteRestreinteId()` relu en base à chaque requête ;
  un `admin_faculte` ne voit que les dossiers des étudiants de sa faculté.
- **Anti-BOLA** : hors périmètre → `404`, jamais `403` (aucune confirmation d'existence).
- **Paramètre `faculty`** : ignoré lorsqu'un token est déjà restreint ; un token restreint
  ne peut donc pas élargir son périmètre via l'URL.
- **Filtres en liste blanche** : `search`, `status` (enum `StudentDossier::STATUTS`),
  `niveau`, `faculty` ; pour les documents : `status` (enum `StudentDocument::STATUTS`),
  `category` (clés de `config/student-dossiers.php`), `document_type`, `search`.
- **Pagination bornée** : `per_page` ∈ [1, 100] (`config/integrations.php`), jamais une
  collection complète.
- **Recherche littérale** : `App\Support\Recherche::contient()` neutralise les jokers SQL.
- **Aucune donnée sensible** : `storage_path` est `hidden` sur le modèle et absent des
  Resources ; aucune version binaire ni contenu de fichier n'est exposé.
- **Lecture seule** : aucune route `POST/PATCH/DELETE`, aucun audit d'écriture implicite.

### 3.4 Documentation

- `docs/openapi.yaml` : trois nouveaux chemins documentés (scopes, filtres, 401/403/404/429).
- `docs/API.md` : section « dossiers administratifs permanents » ajoutée.
- `docs/engineering/INVENTAIRE_TACHES_ET_URLS.md` : les trois routes figurent dans l'inventaire.
- Validation YAML effectuée avec le parseur Symfony (`YAML_OK`) ; couverture OpenAPI et
  contrat `scope:` vérifiés par script contre `QualiteApiSupervisionTest`.

---

## 4. Balayage d'expiration documentaire

### 4.1 État réel vérifié

La commande **existait déjà** :

- `app/Console/Commands/MarquerDocumentsExpires.php` (`documents:marquer-expires`) ;
- planifiée quotidiennement à **02:30** dans `routes/console.php` ;
- couvre `StudentDocument` (bascule `expired`) et compte `GeneratedDocument` ;
- **aucune suppression** : la destruction reste un workflow manuel contrôlé.

Elle était déjà couverte par `tests/Feature/GedFinancesBiTest.php` (bascule + présence en
base). Le besoin résiduel était l'**idempotence** d'une tâche planifiée réexécutée.

### 4.2 Test ajouté

`tests/Feature/DocumentsExpirationSweepTest.php` :

- un document périmé passe à `expired` et déclenche `DocumentExpired` **une seule fois** ;
- un document valide et un document sans échéance restent inchangés ;
- une seconde exécution ne redéclenche aucun événement et ne modifie rien ;
- le document reste présent en base (aucune suppression).

**Résultat : 1 test, 8 assertions — PASS.**

---

## 5. Rapport de tests

### 5.1 Suite complète

Trois passages complets ont été exécutés. L'arbre de travail était modifié **en
parallèle** par un autre travail sur les examens (tests et migrations ajoutés pendant
les runs), ce qui explique la variation du nombre total de tests et des résultats.
Les chiffres ci-dessous sont ceux réellement observés, sans recomposition.

**Passage 1 — référence intégralement verte** (lancé avant le renforcement final des
assertions API et avant l'ajout du test d'expiration) :

```bash
php artisan test --compact
# {"tool":"phpunit","result":"passed","tests":5462,"passed":5428,"assertions":26765,"duration_ms":507984,"skipped":34}
# EXIT_CODE:0
```

**Résultat : 5 462 tests — 5 428 PASS, 34 ignorés, 0 échec, 26 765 assertions,
507,98 s (8 min 28 s), code de sortie 0.**

**Passage 2 — code final de la boucle (les 9 nouveaux tests présents) :**

```bash
php artisan test --compact
# {"tool":"phpunit","result":"failed","tests":5463,"passed":5428,"assertions":26776,"duration_ms":569203,"failed":1,"skipped":34}
# EXIT_CODE:1
```

Unique échec : `VersioningTest::test_app_1_0_0_db_release_1_0_0_et_93_migrations_est_compatible`
(`compatible` attendu, `compatible_avec_avertissement` obtenu). **Cause identifiée, hors
périmètre :** `VersioningService::etatMigrations()` compare le nombre de fichiers
`database/migrations/*.php` au nombre de migrations appliquées ; deux migrations
untracked ont été créées **pendant le run** par le travail parallèle
(`2026_10_28_100000_loop_global_examens_gouvernance.php` à 15:52:25 et
`2026_10_28_130000_align_incident_gravite_default.php` à 15:54:07), créant un
« pending » transitoire. Preuve de non-régression : le test passe seul (29/29,
139 assertions) et avec `BiKpiDefinitionsTest` (34/34, 225 assertions). Aucun des
9 tests de cette boucle n'est en échec dans ce passage.

**Passage 3 — arbre encore élargi (20 tests supplémentaires, 2 migrations désormais
présentes avant le run) :**

```bash
php artisan test --compact
# {"tool":"phpunit","result":"failed","tests":5483,"passed":5446,"failed":3,"skipped":34,"assertions":26899,"duration_ms":457642}
# EXIT_CODE:1
```

Trois échecs, tous **hors périmètre** de cette boucle, tous rejoués isolément et **PASS** :

| Test | Symptôme dans la suite | Cause | Vérification isolée |
|---|---|---|---|
| `ExamensAdvancedPagesRegressionTest::test_la_page_publication_affiche_le_style_enrichi_et_les_filtres` | HTML de la page publication sans le style attendu | page en cours de modification par le travail parallèle | 1/1 PASS (8 assertions) |
| `AffectationBenchmarkTest::test_balai_pipeline_masse` (N=500) | « Analyse trop lente à N=500 » (`analyse_ms` = 1 049 448 ms) | contention machine + saut d'horloge (`analyse_ms` incohérent avec la durée réelle du run) | 3/3 PASS, N=500 analysé en 232 ms |
| `ScheduleExplorerBenchmarkTest::test_benchmark_1000_creneaux` | `conflitsDetectes` à 973 468 ms | même saut d'horloge : `microtime(true)` aberrant alors que la durée totale PHPUnit n'est que de 457 s | 1/1 PASS |

**Synthèse honnête :** la seule exécution intégralement verte (0 échec, `exit 0`) est le
passage 1. Les passages 2 et 3 ne sont pas verts, mais aucun de leurs échecs n'est
imputable aux fichiers de cette boucle : ils proviennent de l'instabilité de l'arbre
(migrations et tests créés en cours de route), de la contention et d'un saut d'horloge
machine. Les 9 tests ajoutés sont PASS : 8/8 API et 1/1 expiration dans le passage 2,
puis à nouveau vérifiés isolément dans leurs fichiers respectifs (§5.2).

Les 34 tests ignorés sont les tests conditionnés par l'environnement (concurrence
MySQL, export QA, restauration de sauvegarde, etc.) — aucun n'est un échec.

### 5.2 Tests ciblés exécutés dans cette session

```bash
php artisan test --compact tests/Feature/ApiStudentDossiersTest.php
# {"result":"passed","tests":8,"passed":8,"assertions":47,"duration_ms":1941}
```

```bash
php artisan test --compact tests/Feature/DocumentsExpirationSweepTest.php
# {"result":"passed","tests":1,"passed":1,"assertions":8,"duration_ms":1339}
```

```bash
php artisan test --compact tests/Feature/ApiStudentsTest.php tests/Feature/ApiAuthenticationTest.php
# {"result":"passed","tests":14,"passed":14,"assertions":47,"duration_ms":6316}
```

```bash
php artisan test --compact tests/Feature/ExamGlobalGouvernanceSecuriteContinuiteTest.php
# {"result":"passed","tests":20,"passed":20,"assertions":92,"duration_ms":8109}
```

### 5.3 Vérification des routes

```bash
php artisan route:list --path=api/v1/student-dossiers
# GET|HEAD api/v1/student-dossiers ......... api.v1.student-dossiers.index
# GET|HEAD api/v1/student-dossiers/{dossier} api.v1.student-dossiers.show
# GET|HEAD api/v1/student-dossiers/{dossier}/documents api.v1.student-dossiers.documents
# Showing [3] routes
```

### 5.4 Qualité de code

```bash
vendor/bin/pint --test \
  app/Modules/StudentDossiers/Controllers/Api/StudentDossierApiController.php \
  tests/Feature/ApiStudentDossiersTest.php \
  tests/Feature/DocumentsExpirationSweepTest.php \
  routes/api.php
# {"tool":"pint","result":"passed"}
```

> `pint --dirty` n'a **pas** été lancé sur l'arbre entier : 401 fichiers suivis sont
> modifiés et 554 fichiers sont non suivis ou ajoutés (955 entrées `git status` au moment
> de la rédaction, dont 4 créées et 3 modifiées par cette boucle). Un formatage global
> aurait réécrit du travail local hors périmètre. Le formatage a donc été limité aux
> fichiers touchés par cette boucle.

### 5.5 Principaux tests non exécutés (inchangés)

Sur les 34 tests ignorés de la suite, les cas structurants pour ce LOOP restent :

| Test | Raison | Verdict |
|---|---|---|
| `ExamensConcurrenceReelleTest` | `EXAM_CONCURRENCY_DB_DRIVER` absent + base dédiée non autorisée ; scénario volontairement en attente | `BLOCKED_ENVIRONMENT` |
| `QaExamensRenderTest` | `QA_OUT` absent (export d'écrans non demandé) | `NOT_EXECUTED` |
| `BackupRoundtripTest` | `BACKUP_ROUNDTRIP=1` absent ; `mysqldump` présent mais base non sollicitée | `NOT_EXECUTED` |

---

## 6. Matrice de sécurité — API `student-dossiers`

| ID | Contrôle | Test | Résultat |
|---|---|---|---|
| SEC-API-01 | Authentification obligatoire | `test_authentification_et_identifiants_invalides` (401 sans token) | **PASS** |
| SEC-API-02 | Scope `archives:read` requis | `test_scopes_restreints` (403 avec `students:read`) | **PASS** |
| SEC-API-03 | Scope `documents:read` requis | `test_scopes_restreints` (403 avec `archives:read`) | **PASS** |
| SEC-API-04 | Isolation faculté — liste | `test_liste_des_dossiers_selon_perimetre` | **PASS** |
| SEC-API-05 | Isolation faculté — détail (404) | `test_detail_dossier_et_404_hors_perimetre` | **PASS** |
| SEC-API-06 | `faculty` non contournable par token restreint | `test_liste_des_dossiers_selon_perimetre` (`faculty=FDEG` ignoré) | **PASS** |
| SEC-API-07 | `storage_path` jamais exposé | `test_documents_metadata_sans_chemin_interne` | **PASS** |
| SEC-API-08 | Filtres en liste blanche | `test_filtres_en_liste_blanche_et_recherche`, `sort=storage_path` ignoré | **PASS** |
| SEC-API-09 | Pagination bornée | `test_pagination_bornee` (`per_page=1000` → 100 ; `0` → 1) | **PASS** |
| SEC-API-10 | Module désactivé = 404 | `test_module_desactive_repond_404` | **PASS** |
| SEC-API-11 | IDs invalides = 404 | `test_authentification_et_identifiants_invalides` (`abc`, injection) | **PASS** |
| SEC-API-12 | Lecture seule | Revue de routes : uniquement `GET` | **PASS** |

---

## 7. Inventaire des changements

### 7.1 Fichiers créés

| Fichier | Rôle |
|---|---|
| `app/Modules/StudentDossiers/Controllers/Api/StudentDossierApiController.php` | Contrôleur API v1 lecture seule (index / show / documents) |
| `tests/Feature/ApiStudentDossiersTest.php` | 8 tests : scopes, BOLA, filtres, pagination, module, 404 |
| `tests/Feature/DocumentsExpirationSweepTest.php` | 1 test : expiration ciblée + idempotence + non-suppression |
| `docs/engineering/examens-advanced/19-rapport-cloture-loop-global.md` | Ce rapport de clôture |

### 7.2 Fichiers modifiés

| Fichier | Modification |
|---|---|
| `routes/api.php` | Import du contrôleur + 3 routes GET sous `auth:sanctum`, `module`, `scope`, `throttle` |
| `docs/openapi.yaml` | 3 chemins documentés (scopes, filtres, réponses) |
| `docs/API.md` | Section dossiers administratifs permanents |
| `docs/engineering/INVENTAIRE_TACHES_ET_URLS.md` | 3 routes API ajoutées à l'inventaire |

### 7.3 Fichiers volontairement non modifiés

- `app/Modules/Evaluation/**` : aucune régression, aucun besoin identifié.
- `phpunit.xml`, `.env`, `config/*` : aucune nécessité.
- Tout fichier préexistant modifié dans l'arbre de travail : **préservé tel quel**.

---

## 8. Migrations et données

| Élément | État |
|---|---|
| Migrations créées | **0** |
| Migrations modifiées | **0** |
| Migrations appliquées | **0** |
| `migrate:fresh` / `db:wipe` | **Non exécutés** |
| Écritures en base MySQL locale | **Aucune** (seul `SHOW DATABASES` en lecture) |
| Écritures en production | **Aucune** |
| Suppressions de fichiers/données | **Aucune** |
| Écritures des tests | SQLite `:memory:` éphémère uniquement |

**Vérification d'environnement (lecture seule) :** client `mysql` et `mysqldump` présents,
port `3306` ouvert, base applicative `inscription_etudiants` non modifiée. Des bases de test
préexistent (`inscription_etudiants_loop13_test`, `inscription_loop4_test`,
`inscription_uniq_test`, `ERP_TEST`), mais **aucune autorisation explicite n'a été donnée
pour y appliquer des migrations ou y exécuter un scénario concurrent**. Le test reste donc
bloqué, sans substitution par SQLite.

---

## 9. Réserves

| # | Réserve | Gravité | Impact | Condition de résolution |
|---|---|---|---|---|
| R1 | Concurrence réelle MySQL non exécutée | Moyenne | Le double booking sous charge réelle n'est pas prouvé | Autorisation explicite + base dédiée + `EXAM_CONCURRENCY_DB_DRIVER=mysql` + scénario implémenté |
| R2 | Arbre Git très sale (955 entrées, dont 401 fichiers suivis modifiés et 554 non suivis/ajoutés) | Moyenne | Risque de confusion baseline/commit | Revue et commits atomiques par lot, hors LOOP |
| R3 | Branche en retard de 3 commits sur `origin` | Faible | Divergence possible | Synchronisation contrôlée par l'équipe (interdite ici) |
| R4 | `QaExamensRenderTest` / `BackupRoundtripTest` non exécutés | Faible | Preuves d'export/restauration absentes | Fournir `QA_OUT` / `BACKUP_ROUNDTRIP=1` + base dédiée |
| R5 | API en lecture seule uniquement | Faible | Pas d'écriture externe des dossiers | Décision produit ; l'écriture reste volontairement dans l'interface admin auditée |
| R6 | Suite complète non reproductible à l'identique pendant la session | Moyenne | Un seul passage intégralement vert ; passages 2-3 pollués par des ajouts concurrents | Rejouer `php artisan test --compact` sur un arbre figé (aucun fichier créé/modifié pendant le run) |
| R7 | Saut d'horloge machine pendant un run | Faible | Deux benchmarks ont produit des durées aberrantes (973 468 ms / 1 049 448 ms pour un run de 457 s) | Rejouer les benchmarks isolément (fait : 3/3 et 1/1 PASS) et surveiller la synchronisation NTP |

---

## 10. Verdict global

# `PASS_WITH_RESERVATIONS`

**Justification :**

- ✅ Actions 1, 2 et 5 entièrement implémentées et prouvées par des tests verts.
- ✅ Action 3 exécutée : passage de référence **5 462 tests, 0 échec**, 34 ignorés,
  26 765 assertions ; les deux passages ultérieurs (5 463 puis 5 483 tests) ont échoué
  uniquement sur des causes hors périmètre (migrations créées pendant le run, page examens
  en cours d'édition, saut d'horloge), toutes rejouées et PASS isolément (§5.1, R6, R7).
- ✅ 9 nouveaux tests PASS (47 + 8 assertions), plus 14 tests API de non-régression PASS.
- ✅ Aucune opération interdite, aucune migration, aucune donnée détruite.
- ✅ Aucune vulnérabilité bloquante démontrée ; `storage_path` et le contenu binaire restent inaccessibles.
- ⚠️ La concurrence réelle MySQL reste `BLOCKED_ENVIRONMENT` : elle ne peut pas être déclarée validée.

---

## 11. Réponses explicites aux 10 questions du LOOP

1. **Fonctionnalités réellement implémentées :** API v1 lecture seule des dossiers
   administratifs (`index`, `show`, `documents`), avec isolation faculté, scopes, filtres
   whitelistés et pagination bornée ; test d'idempotence du balayage d'expiration.
2. **Règles métier couvertes / en attente :** couvertes = accès minimal, confidentialité
   documentaire, périmètre faculté, non-suppression, expiration planifiée. En attente =
   concurrence réelle MySQL (R1) et écriture API (hors périmètre volontaire).
3. **Tests réellement exécutés et réussis :** passage complet de référence 5 462 tests /
   5 428 PASS / 34 ignorés / 0 échec (26 765 assertions, `exit 0`) ; passages 2 et 3 non
   verts à cause de l'arbre modifié en parallèle (1 puis 3 échecs hors périmètre, tous PASS
   isolément) ; 8/8 API (47 assertions), 1/1 expiration (8 assertions), 14/14 API
   existantes (47 assertions), 20/20 gouvernance (92 assertions).
4. **Contrôles de sécurité exécutés :** 401, 403 par scope, 404 hors faculté, 404 module
   désactivé, 404 IDs invalides, non-exposition de `storage_path`, filtres whitelistés,
   pagination bornée, lecture seule.
5. **Régressions / vulnérabilités bloquantes :** aucune démontrée dans le périmètre modifié.
6. **Migrations créées / appliquées :** 0 / 0.
7. **Données et travail local préservés :** oui ; aucun reset, clean, stash, rebase, merge
   ou pull ; les entrées préexistantes de l'arbre (955 au moment de la rédaction) sont
   intactes, seuls 4 fichiers créés et 3 modifiés par cette boucle s'y ajoutent.
8. **Phases bloquées / non exécutées :** concurrence MySQL (`BLOCKED_ENVIRONMENT`), QA
   export et backup roundtrip (`NOT_EXECUTED`).
9. **Verdict global :** `PASS_WITH_RESERVATIONS`, justifié par les preuves ci-dessus.
10. **Prochaines actions strictement nécessaires :**
    1. Rejouer `php artisan test --compact` sur un arbre **figé** (aucun fichier créé ou
       modifié pendant l'exécution) pour obtenir une référence reproductible — lève R6.
    2. Autoriser une base MySQL de test dédiée, implémenter puis exécuter le scénario de
       concurrence (`lockForUpdate`) — lève R1.
    3. Traiter l'arbre Git préexistant par commits atomiques — lève R2.
    4. Fournir `QA_OUT` et `BACKUP_ROUNDTRIP=1` pour compléter les preuves
       d'export/restauration.
