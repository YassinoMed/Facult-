# LOOP-03 — COCKPIT KPI SCOLARITÉ

> **Verdict : PASS** (conditions d'application en fin de rapport)
> Date : 24/10/2026 — protocole audit-first, réutilisation maximale, 0 commit/push/déploiement.
> HEAD `38ef149` **inchangé** pendant tout le lot. Exécution dans le cadre du
> programme LOOP MASTER (auto-pass enchaîné L05 → L02 → **L03** → L04 → L07…).

---

## 1. Périmètre (entrée audit §F3)

Source : `docs/engineering/LOOP-00-AUDIT-DIRECTEUR-UNIREGISTRE.md` L58-67 —
statut « À_ÉTENDRE (briques réelles et testées, pas de couche KPI unifiée) ».
Les manques nommés par l'audit :

1. **table de définitions/version de KPI** ;
2. **couche snapshots** ;
3. **recalcul à chaque appel** (anomalies in-memory paginées) ;
4. **isolation faculté inégale** (widget annonces sans portée — `ModuleServiceProvider:499-513`) ;
5. **outbox DataMesh non planifiée** (`datamesh:outbox-publier` absent de console.php) ;
6. doublon : 3 écrans de pilotage sur la même table `tasks` (renvoi réserve R1 — arbitrage documenté, pas de code).

## 2. Co-édition détectée (concurrence) — réconciliation OBLIGATOIRE

Des flux parallèles ont traité une partie du périmètre **pendant l'audit** :

| Item audit | Pris en charge par | Preuve |
|---|---|---|
| (5) planification publisher | flux parallèle | `routes/console.php:22-23` → `Schedule::command('datamesh:outbox-publier --retenter')->everyFiveMinutes()` (mtime 21:22:58) |
| (4) scoping widgets annonces + evaluation | flux parallèle | `ModuleServiceProvider.php:502-514` (annonces : garde `faculteRestreinteId()` identique au pattern tasks) et `:516-533` (évaluation : `pourFaculteId()` + `whereHas('epreuve')`, sémantique cockpit) — mtime 21:22:39 |
| (1)(2) couche KPI cockpit | flux parallèle | `app/Services/Kpi/{CatalogueKpi,CockpitKpiService}.php`, DTO `app/Services/Kpi/DTO/{DefinitionKpi,IndicateurKpi,SnapshotKpi}.php`, routes `cockpit.{index,kpi,kpi.export}` (`can:cockpit.view`), test maison `CockpitKpiServiceTest.php` (mtime 21:30) |

**Décision de frontière** (doctrine « 0 duplication ») : je n'ai PAS reconstruit
ces briques ni touché la zone active du flux (`app/Services/Kpi/**`,
`CockpitController`, leurs routes). J'ai (a) réconcilié le test d'observabilité
cassé par la planification, (b) ajouté la couverture d'isolation widgets qu'ils
n'avaient pas écrite, et (c) construit le pilier resté ouvert : le **registre
persistant des définitions KPI**, branché sur `BIService` (fichier immuable,
mtime 10-12) sans couplage à leur code en mouvement.

## 3. Livraisons de ce flux

### 3.1 Registre persistant des définitions KPI (pilier manque n° 1 + 3)

- **Migration additive** `database/migrations/2026_10_24_110000_creer_kpi_definitions_table.php`
  — table `kpi_definitions` : `cle` + `provenance` (UNIQUE composite — gère la
  clé partagée `moyenne_mediane` présente dans les DEUX catalogues),
  `nom/definition/source/unite/categorie`, `version` (dérive documentaire),
  `definition_hash`, `actif`, `dernier_snapshot` (JSON valeur + contexte de
  périmètre), `dernier_snapshot_a`. Rejouable (garde `Schema::hasTable`) ;
  rollback = `dropIfExists`, le registre est un cache de documentation pure.
  **NON appliquée en local** (voir §6 conditions) ; testée via `RefreshDatabase`.
- **Modèle** `app/Models/KpiDefinition.php` — garde fail-soft
  `tableDisponible(bool $fresh)` (mémoïsation style LOT 05
  `NoteEpreuve::existeColonneValideAt`) : registre absent = repli propre,
  jamais d'erreur SQL au rendu.
- **Service** `app/Services/BIService.php` — `synchroniserDefinitions(?annee, ?faculte, ?programme, ttl)` :
  - fail-soft si table absente → `{actif: false, raison: 'migration 2026_10_24_110000 non appliquée…'}` ;
  - reflète les **12 KPI BI** (`indicateurs()`, cache versionné) : upsert par
    `cle+provenance`, **bump de version dès que la définition ou la source
    change** (comparaison directe aux catalogues de code — plus honnête
    qu'un hash seul, couvre le tamper et le refactor), snapshot de valeur
    toujours actualisé (`figurees`) ;
  - reflète les **définitions du catalogue cockpit** (`CatalogueKpi` via
    `class_exists`, découverte dynamique, zéro collision de responsabilité) :
    documentation versionnée SANS snapshot — le calcul reste la propriété de
    `CockpitKpiService`, aucune duplication de calcul ;
  - descriptif uniquement, jamais d'écriture métier.
- **Commande** `app/Console/Commands/SynchroniserDefinitionsKpi.php`
  `bi:synchroniser-kpi-definitions` — idempotente, sortie française, fail-soft
  (registre absent = `warn`, exit SUCCESS, scheduler-safe).
- **Planification** `routes/console.php` — `bi:synchroniser-kpi-definitions`
  à 04:40, juste après le préchauffage BI 04:30 existant.
- **Snapshot** : l'histoire des valeurs n'est PAS conservée (dernier snapshot
  seulement). Une time-série complète ferait doublon avec la philosophie
  Transactional Outbox / Data Mesh → **réserve** (voir R-03/1).

### 3.2 Anomalies : une passe unique sans filtre (pilier manque n° 3)

- **Service** `EvaluationQualityService` : nouvelle méthode publique
  `kpisDepuisAnomalies(array $anomalies)` + `kpis()` délégué à elle (une seule
  implémentation). Le pool d'anomalies détectables était déjà borné par
  `limit(200)` par type dans les détecteurs (constat, non modifié).
- **Contrôleur** `AnomalieController@index` : quand **aucun filtre n'est
  actif** (cas de charge principal), les KPI dérivent du même passage de
  détection que la liste — le recalcul complet disparaît. Avec filtres, la
  passe dédiée `kpis($faculteId)` est conservée : les filtres
  `session_id`/`epreuve_id` sont appliqués **en requête** et ne sont pas
  dérivables post-hoc ; la sémantique dashboard (KPI du périmètre GLOBAL,
  jamais réduite au filtre d'affichage) est prouvée par test.
- La persistance Phase 19 (`anomalies_qualite` + `qualite:auditer`) couvre déjà
  la famille « qualité transversale » : dupliquer pour la famille évaluation
  violerait la règle 0-duplication → arbitrage en réserve R-03/2.

### 3.3 Réconciliation du test d'observabilité (conséquence de la planification co-flux)

- `tests/Feature/DataMeshObservabiliteTest.php` —
  `test_outbox_automatisation_mesuree_zero_non_sain` affirmait l'état
  `NON_CONFIGURE` (0 événement datamesh), désormais faux : le publisher est
  planifié toutes les 5 min. Adaptation **en renforçant** l'intention
  historique (état MESURÉ, jamais codé en dur, zéro ≠ sain) :
  `INCONNU` (planifié mais heartbeat absent) + `evenements_planifies === 1`
  + `prochain_declenchement` non null + dernier succès toujours INCONNU
  prouvé. Modification de spec documentée — pas un affaissement de test.

### 3.4 Couverture d'isolation widgets (couverture manquante du co-flux)

- Nouveau `tests/Feature/DashboardWidgetsIsolationTest.php` (2 tests) —
  rendu direct des widgets composés sous `actingAs` :
  - **annonces** : admin global compte 3 annonces publiées, `admin_faculte`
    de la faculté A en compte 2 (A + globales) — l'annonce B est invisible.
  - **evaluation** : une épreuve sans salle (B) visible du global (total 1),
    invisible de A (total 0).
  Anti-IDOR prouvé au niveau widget (pas seulement contrôleur).

## 4. Preuves d'exécution

| Run | Résultat |
|---|---|
| `php artisan test --compact --filter="(BiKpiDefinitionsTest|EvaluationAnomaliesKpisTest|DataMeshObservabiliteTest)"` | **31/31, 411 assertions** |
| suites citées audit F3 `(TasksCockpitTest|TaskGenerationInscriptionTest|CentreTravailAdminTest|QualiteApiSupervisionTest|DataMeshTest|TableauDeBordTest|AutomationMetricsTest|EvaluationQualityServiceTest)` | **70/70, 601 assertions** |
| cross-check co-flux + LOT 02 `(CockpitKpiServiceTest|NotificationsOutboxRepriseTest|NotificationsPreferencesTest)` | **23/23, 232 assertions** |
| auto-ajustement pint (`ordered_imports` sur mon nouveau fichier) | corrigé, `pint --test` PASSED sur les 11 fichiers |
| **RUN CANONIQUE LOT 03** (15 suites, périmètre complet ci-dessus) | **126/126 PASSED, 1250 assertions, 4,8 s** |
| `git log -1` | `38ef149` — **0 commit, 0 push** |

Tests ignorés : **0** (aucun `markTestSkipped` posé).

## 5. Décisions et arbitrages

1. **Registre = miroir des catalogues de code**, PAS une nouvelle source de
   vérité : la version s'incrémente quand la définition/source du code
   change ; la table ne recalcule rien, elle documente et fige.
2. **Comparaison texte plutôt que hash seul** pour la dérive : le hash reste
   indexé pour requêtes, mais la version saute sur `definition !== code` ou
   `source !== code` (couvre tamper BD + refactor documentaire).
3. **Composite UNIQUE (cle, provenance)** : la clé `moyenne_mediane` existe
   dans les deux catalogues — deux lignes coexistent sans conflit.
4. **Second passage conservé avec filtres** (anomalies) : les filtres
   session/épreuve vivent en requête SQL ; les dériver post-hoc dupliquerait
   la logique du service. L'optimisation est bornée au chemin sans filtre
   mais c'est le cas dominant (page incidents par défaut).
5. **Sync CatalogueKpi sans snapshot de valeur** : je ne calcule pas leurs
   KPI (leur zone) ; la liaison valeur se fera côté co-flux ou en réserve
   (le compteur `cockpit` de la commande expose la population reflétée).

## 6. Conditions d'application (équipe)

1. **Appliquer la migration additive** `2026_10_24_110000` en environnement
   cible : `php artisan migrate`. En local `APP_ENV=production` l'exécution
   est REFUSÉE par le framework (interdiction programme respectée ; runs de
   preuve via RefreshDatabase). Avant migration : le registre est
   silencieusement ignoré (fail-soft), l'app se comporte exactement comme à
   la livraison LOT 02.
2. **Rien d'autre** : aucune donnée à reprendre, aucune config à ajouter
   (aucune nouvelle clé), pipeline quotidien du 04:40 hors heures de crête.

## 7. Réserves (portables vers le registre R)

- **R-03/1** — L'historisation des valeurs KPI (time-série) n'est pas
  implémentée (`dernier_snapshot` = dernier état uniquement). Si un besoin
  analytique apparaît, la couche Data Mesh outbox est le véhicule naturel
  (produit versionné), pas une nouvelle table ad hoc.
- **R-03/2** — Les anomalies d'évaluation restent recalculées in-memory
  (bornées 200/type) : la persistance Phase-19 est disponible mais sa
  généralisation à l'évaluation serait une duplication fonctionnelle ; à
  trancher par un arbitrage produit si le volume dépasse la borne.
- **R-03/3** — La couverture d'isolation widgets vit dans MON fichier
  (`DashboardWidgetsIsolationTest`) tant que le flux propriétaire des
  composers n'a pas repris ses tests maison ; risque de doublon de
  couverture de tests si les deux vivent en parallèle (à fusionner).
- **R-03/4** — `CatalogueKpi::tous()` est appelé par le registre via
  `class_exists` : si le catalogue des co-flux change de forme (renomme de
  DTO/champ), mon compteur de définitions reflète ce qui existe — le bump de
  version documentera la dérive automatiquement.
- **R-03/5** — Doublon des 3 écrans `tasks` (cockpit / centre-travail /
  taches) : non traité (audit le renvoie déjà en réserve R1 LOOP-00) ; aucune
  écriture de code, arbitrage à conduire côté produit.

## 8. Empreinte fichiers (ce flux uniquement)

**Créés (6)** : `database/migrations/2026_10_24_110000_creer_kpi_definitions_table.php`,
`app/Models/KpiDefinition.php`, `app/Console/Commands/SynchroniserDefinitionsKpi.php`,
`tests/Feature/BiKpiDefinitionsTest.php`, `tests/Feature/EvaluationAnomaliesKpisTest.php`,
`tests/Feature/DashboardWidgetsIsolationTest.php`.

**Modifiés (5)** : `app/Services/BIService.php` (+`synchroniserDefinitions`, imports),
`app/Modules/Evaluation/Services/EvaluationQualityService.php` (`kpisDepuisAnomalies` + délégation),
`app/Modules/Evaluation/Controllers/AnomalieController.php` (passe unique sans filtre),
`routes/console.php` (+1 entrée scheduler 04:40),
`tests/Feature/DataMeshObservabiliteTest.php` (adaptation spec planification, docbloc LOT 03).

**Non revendiqués (flux parallèle, préservés)** : `routes/console.php:22-23`
(outbox publisher), `app/Providers/ModuleServiceProvider.php:502-533` (scoping
widgets), `app/Services/Kpi/**`, `app/Http/Controllers/Admin/CockpitController.php`,
`tests/Feature/CockpitKpiServiceTest.php`, toutes les autres empreintes
des flux co-éditeurs.

---

**Verdict final : PASS** — avec la condition d'application §6 (migration
additive, fail-soft garantit l'état pré-migration stable) et les réserves
R-03/1…R-03/5 enregistrées. Le lot suivant reste **LOT 04** (Passeport
académique & simulation LMD, §F4).
