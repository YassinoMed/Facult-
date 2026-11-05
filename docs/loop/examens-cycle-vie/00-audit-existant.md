# 00 — Audit de l'existant : cycle de vie des examens

**LOOP GLOBAL — Évaluation / Examens — Lot EX-LIFECYCLE**

Statut : Phase A — AUDIT (état constaté **avant** corrections). Les réalisations du LOOP et leur
périmètre sont dans `03-implementation.md` ; les corrections y sont référencées par `§16` et `§5.4`.

---

## 1. Périmètre audité

- Module : `app/Modules/Evaluation/` (modèles, services, contrôleurs, policies, routes).
- Entités : `Examen`, `SessionExamen`, `Epreuve` et leurs relations.
- Gouvernance : `PlanningValidation`, `PlanningEvenement`, `ExamGouvernanceValidationService`.
- Publication : `ExamPublicationOrchestratorService`.
- Planification : `ExamScheduleEngineService` (partie transitions).
- Chemins d'écriture alternatifs : DataExchange (`ExamenDefinition`, `EvaluationDefinition`), Console (`ExamChargeService`).
- Tests existants : `tests/Feature/Exam*`, `tests/Feature/Evaluation*`.
- Sécurité : `EvaluationPolicy`, middlewares `auth`/`admin`/`module:evaluation`/`secondaire.verrou`, `faculteRestreinteId()`.

Hors périmètre : notes, délibérations, publication des résultats (dépendances vérifiées mais non réimplémentées).

## 2. État Git initial

- Branche : `main`.
- Dernier commit : `a8b3f0b` (merge `feature/admin-modifier-etudiant`).
- WIP significatif présent et PRÉSERVÉ (aucune branche, aucun commit, aucun push, aucun nettoyage effectué) :
  - Fichiers suivis modifiés : 47 (dont `app/Modules/Evaluation/Routes/web.php`, `ExamDecisionCapaciteController.php`, `GroupeVirtuelExamenController.php`, `ConsultationExportGroupeVirtuelService.php`, `ExamDecisionCapacitePermutationService.php`).
  - Fichiers non suivis : nombreux (services Acquis, IAM, dérogations, tests `Loop*`, `ExamGroupesVirtuelsLoopGlobalTest.php`, `ExamCapaciteSalleNonConfirmeeTest.php`, etc.).
- Le WIP du module Évaluation concerne les **groupes virtuels** et la **capacité D-04** (autre LOOP) — il n'entre pas en conflit direct avec le cycle de vie `Examen` ; il est conservé tel quel.
- Fichier `tests/Feature/LoopCampagneAcquisExamenTest.php.bak` présent (sauvegarde locale d'un travail antérieur — conservé, non modifié).

## 3. Routes recensées

Module : `app/Modules/Evaluation/Routes/web.php`. Groupe : `['auth', 'admin', 'secondaire.verrou', 'module:evaluation']` + prefix `admin`.

| Route | Méthode | Contrôleur | Méthode | Permission (route) | Contrôle contrôleur |
|---|---|---|---|---|---|
| `admin.evaluations.examens.index` | GET | `ExamenController` | `index` | `can:evaluations.view` | `Gate::authorize('evaluations.view')` + `pourFaculteId` |
| `admin.evaluations.examens.store` | POST | `ExamenController` | `store` | `can:evaluations.create` + `throttle:20,1` | `StoreExamenRequest::authorize()` (permission create) ; `Gate::authorize('create', $session)` ; isolation programme/groupe par faculté |
| `admin.evaluations.examens.transiter` | POST | `ExamenController` | `transiter` | `can:evaluations.update` + `throttle:20,1` | `Gate::authorize('update', $examen)` ; **`published` explicitement bloqué** (circuit de gouvernance obligatoire) |
| `admin.evaluations.examens.planifier.scenarios` | POST | `ExamenController` | `genererScenarios` | `can:evaluations.update` + `throttle:15,1` | `Gate::authorize('update')` + périmètre faculté |
| `admin.evaluations.examens.planifier.appliquer` | POST | `ExamenController` | `appliquerScenario` | `can:evaluations.update` + `throttle:15,1` | `Gate::authorize('update')` + périmètre faculté |
| `admin.evaluations.examens.audit-conformite` | GET | `ExamenController` | `auditConformite` | `can:evaluations.view` + `throttle:30,1` | `Gate::authorize('view')` + périmètre faculté |
| `admin.evaluations.gouvernance.soumettre` | POST | `ExamGouvernanceController` | `soumettre` | `can:evaluations.update` | `Gate::authorize('update', $examen)` |
| `admin.evaluations.gouvernance.certifier` | POST | `ExamGouvernanceController` | `certifier` | `can:evaluations.certify` | `Gate::authorize('evaluations.certify')` + périmètre |
| `admin.evaluations.gouvernance.approuver-1/2` | POST | `ExamGouvernanceController` | `approuver…` | `can:evaluations.approve` | `Gate::authorize` + périmètre |
| `admin.evaluations.gouvernance.refuser` | POST | `ExamGouvernanceController` | `refuser` | `can:evaluations.approve` | idem + motif obligatoire (`min:5`) |
| `admin.evaluations.gouvernance.escalader` | POST | `ExamGouvernanceController` | `escalader` | `can:evaluations.approve` | idem + motif obligatoire |
| `admin.evaluations.gouvernance.resoudre` | POST | `ExamGouvernanceController` | `resoudre` | `can:evaluations.publish` | idem + décision obligatoire |
| `admin.evaluations.gouvernance.publier` | POST | `ExamGouvernanceController` | `publier` | `can:evaluations.publish` | idem |
| `admin.evaluations.gouvernance.nouvelle-version` | POST | `ExamGouvernanceController` | `nouvelleVersion` | `can:evaluations.publish` | idem + motif obligatoire |

Autres surfaces importantes : menu Advanced `admin.evaluation.examens.*` (tableau-de-bord, sessions, configurations, eligibilite, groupes, salles, planification, surveillance, simulation, publication, convocations, historique, documents), sessions (`admin.evaluations.sessions.*`), épreuves (`admin.evaluations.epreuves.*`).

Contrôle par route (checklist A1) : module actif (middleware `module:evaluation` → 404), auth (middleware `auth`), permission (middlewares `can:*` — cohérents avec les Gates des contrôleurs), isolation facultaire (périmètre explicite ou Policy), validation des paramètres (`StoreExamenRequest`, `validate()`), binding ressource (`route model binding`), vérification d'état (`estVerrouillee`, `estCritique`, statut certification), appel du service métier, gestion des erreurs (`DomainException` → `back()->withErrors`), traçabilité (partielle — voir §10).

**Constat A1 (résolu)** : la permission visible dans la vue ne remplace pas la protection serveur — toutes les routes examinées portent un contrôle serveur explicite (Gate/Permission + périmètre).

## 4. États existants

Source unique : `App\Modules\Evaluation\Models\Examen`.

```php
public const STATUTS = ['draft', 'planned', 'scheduled', 'published', 'ongoing', 'completed', 'locked', 'cancelled', 'archived'];
```

| Code | Libellé | Type | Modifiable ? | Terminal ? | Observé en base de test |
|---|---|---|---|---|---|
| `draft` | Brouillon | Préparation | Oui | Non | Oui (création) |
| `planned` | Planifié | Préparation/planification | Oui | Non | Oui (transition) |
| `scheduled` | Ordonnancé | Planification | Oui (verrous épreuve) | Non | Oui (transition/scénario) |
| `published` | Publié | Publication | Non (critique) | Non effectif | Oui (gouvernance) |
| `ongoing` | En cours | Opérationnel | Non (critique) | Non effectif | Non observé via service |
| `completed` | Terminé | Clôture | Non (critique) | Non effectif | Non observé via service |
| `locked` | Verrouillé | Clôture | Non (critique) | Non effectif | Non observé via service |
| `cancelled` | Annulé | Annulation | Oui (matrix) | Non (→ archived) | Non observé dans les tests |
| `archived` | Archivé | Terminal | Non | Oui | Non observé via service |

Colonne SQL : `evaluation_examens.statut` = `string(20)`, défaut `draft` (aucune contrainte d'énumération en base). État par défaut à la création : `draft` (contrôleur + `StoreExamenRequest` n'accepte aucun champ `statut`).

## 5. Matrice de transitions observées

### 5.1 Matrice déclarée (`Examen::TRANSITIONS`)

| Initial | Cibles déclarées |
|---|---|
| `draft` | `planned`, `cancelled` |
| `planned` | `scheduled`, `cancelled` |
| `scheduled` | `published`, `cancelled` |
| `published` | `ongoing`, `cancelled` |
| `ongoing` | `completed`, `cancelled` |
| `completed` | `locked`, `archived` |
| `locked` | `archived` |
| `cancelled` | `archived` |
| `archived` | (aucune) |

### 5.2 Matrice RÉELLEMENT appliquée (`ExamenService::transiter`)

Garde supplémentaire :

```php
if ($examen->estCritique() && $cible !== 'archived') {
    throw new DomainException('Examen verrouillé.');
}
```

`estCritique()` = `statut ∈ {published, ongoing, completed, locked, archived}`.

Conséquence — transitions **effectivement exécutables** :

| Initial | Cibles réellement exécutables | Divergence avec la matrice déclarée |
|---|---|---|
| `draft` | `planned`, `cancelled` | — |
| `planned` | `scheduled`, `cancelled` | — |
| `scheduled` | `published` (via gouvernance uniquement), `cancelled` | — |
| `published` | **aucune** | `ongoing`, `cancelled` déclarés → **bloqués par la garde** |
| `ongoing` | **aucune** | `completed`, `cancelled` déclarés → **bloqués** |
| `completed` | `archived` | `locked` déclaré → **bloqué par la garde** |
| `locked` | `archived` | — |
| `cancelled` | `archived` | — |
| `archived` | (aucune) | — |

**Divergence majeure documentée (ANOM-A1)** : les états `published`/`ongoing`/`completed`/`locked` sont des culs-de-sac effectifs dans le service central. Les états `ongoing`/`completed`/`locked` ne sont atteignables par **aucun** chemin officiel actuel. La garde `estCritique` et la matrice ne sont pas cohérentes entre elles.

### 5.3 Écritures directes du statut (hors `ExamenService`)

Audit exhaustif : **9 sites d'écriture** recensés dans l'application (recherche `statut` + `Examen`/`update`). Aucun n'est un contournement silencieux non encadré.

| # | Lieu | Écriture | Qualification |
|---|---|---|---|
| 1 | `ExamenController::store` | `'statut' => 'draft'` | **Initialisation légitime** (état initial forcé serveur) |
| 2 | `ExamDevoirsMultiModulesService` | `'statut' => 'draft'` (création) | **Initialisation légitime** |
| 3 | `ExamGouvernanceValidationService::refuserPlanning` | `$examen->update(['statut' => 'draft'])` | **Règle explicite encadrée** : refus de gouvernance → retour préparateur, dans la transaction verrouillée de gouvernance, journalisé (`PlanningEvenement`), statut de version vérifié. Routage via le moteur central = point annexe (`01` annexe) — ANOM-A2 consignée |
| 4 | `ExamScheduleEngineService::appliquerScenario` | `$verrouille->update(['statut' => 'scheduled'])` depuis `draft|planned` | **Écriture directe encadrée** : transaction + `lockForUpdate` + audit ; raccourci `draft → scheduled` absent de la matrice — consigné (ANOM-A3, point annexe `01`) |
| 5 | `ExamPublicationOrchestratorService::publierExamen` | `tap($examen)->update(['published_at' => now()])` si déjà `published` | **Légitime** (re-scellement sans changement de statut) ; le vrai changement `scheduled → published` passe par `ExamenService::transiter` |
| 6 | `ExamenDefinition` (DataExchange import) | `'statut' => $ligne['statut'] ?? 'draft'` | **Import historique** — statut arbitraire depuis fichier, sans contrôle d'appartenance (ANOM-A4, point annexe `01`) |
| 7 | `EvaluationDefinition` (DataExchange import) | statut idem / `scheduled` par défaut épreuve | **Import historique** (même famille ANOM-A4) |
| 8 | `ExamChargeService` (console `examens:test-charge`) | `'statut' => 'scheduled'` | **Outil de test/charge** — données de test uniquement |
| 9 | `ExamPublicationOrchestratorService::publierExamen` | `Epreuve::…update(['statut' => 'published'])` (batch) | **Propagation explicite** documentée (règle D-06 convocations) ; sans trace unitaire par épreuve via `EpreuveService` (ANOM-A5, FAIBLE) |

### 5.4 Concurrence et verrouillage (note d'environnement — R-07)

- Depuis EX-LIFECYCLE, `ExamenService::transiter` acquiert un verrou de ligne (`lockForUpdate`) et évalue le contrôle décisif après acquisition — aligné sur `ExamGouvernanceValidationService` et `ExamPublicationOrchestratorService`.
- **En tests (SQLite `:memory:`) `lockForUpdate` est un no-op** : la garantie est effective sur MySQL (production), même convention que les services de gouvernance déjà en production. La concurrence multi-processus réelle est testée lorsque `pcntl` est disponible (`ExamensConcurrenceReelleTest`, ignorés localement sinon).

## 6. Services utilisés

| Service | Rôle | Verdict |
|---|---|---|
| `ExamenService::transiter` | **Moteur de transition central** | Conservé — point d'entrée unique côté métier |
| `ExamGouvernanceValidationService` | Circuit gouvernance append-only (soumis→certifié→approuvé×2→publié ; refus ; escalade) | Conservé — ne pas dupliquer |
| `ExamPublicationOrchestratorService` | Diagnostic pré-publication, empreinte SHA-256, scellement | Conservé |
| `ExamScheduleEngineService` | Scénarios de planification, audit de conflits, application | Conservé — écriture statut à corriger/documenter (ANOM-A3) |
| `EpreuveService` | Cycle de vie épreuve : `lockForUpdate` + `IntegrationLog::tracer` + événements | **Modèle de référence** pour corriger `ExamenService` |
| `SessionExamenService` | Cycle de vie session (transitions propres) | Conservé |
| `ExamClotureCorrectionService` | Clôture épreuve, corrections post-clôture, **réouverture de session** (motif + séparation) | Conservé |
| `ServiceEligibiliteExamen` | Éligibilité étudiants (ne pas toucher) | Conservé |
| `EvaluationChanged` + `AuditEvaluation` | Événement + log | **Payload silencieusement ignoré** (3e argument non déclaré — ANOM-A6) |

## 7. Préconditions existantes (par transition, état actuel)

`ExamenService::transiter` :

1. Transaction `DB::transaction` — **mais** `refresh()` seulement : **pas de `lockForUpdate()`** (ANOM-A7 — risque de course).
2. Garde `estCritique()` (voir §5.2).
3. `peutTransiterVers($cible)` (matrice).
4. `published` : audit de conformité `ExamScheduleEngineService::auditerSessionExamens` → refus si `conflits_bloquants` (contraintes dures).
5. Écriture `statut` + `published_at` (si publication).
6. Événement `EvaluationChanged('examen.statut')`.
7. **Aucune précondition** : session non verrouillée, épreuves existantes, dates valides, motif d'annulation, gouvernance préalable — non vérifiées à ce niveau (elles vivent en amont : validation de création, gouvernance, planification).

Gouvernance (`ExamGouvernanceValidationService`) : préconditions riches et testées — statut de version, personnes distinctes, rôles séparés (config `separation_pouvoirs_par_role` active par défaut), empreinte planning inchangée, gel institutionnel + dérogation motivée, anti-escalade non résolue. **Modèle de référence qualité**.

## 8. Relations Examen ↔ Session ↔ Épreuve

- `Examen` a `session_id` (FK `evaluation_sessions`), `programme_id`, `niveau_id`, `groupe_id`, `annee_universitaire_id`.
- `SessionExamen` : cycle propre `draft→open→ongoing→closed→locked→archived` ; verrouillage `verrouillee_at` ; `contientDate()` pour la cohérence des épreuves.
- `Epreuve` : cycle propre `draft→scheduled→published→ongoing→completed→locked→cancelled` avec `EpreuveService::transiter` (lockForUpdate + traces + préconditions : salle assignée pour publication, conformité correspondance).
- **Indépendance** : aucune transition d'examen ne propage automatiquement vers session/épreuve, SAUF la publication (propagation explicite D-06 vers les épreuves `scheduled → published`).
- Annulation d'examen : aucune conséquence automatique sur sessions/épreuves (D4 — décision métier attendue, cf. `01-decisions-metier.md`).
- Clôture de session / réouverture : `ExamClotureCorrectionService::rouvrirSessionCloturee` (motif obligatoire + approbateur distinct) — existe côté session uniquement.
- **Pas de réouverture d'examen** : aucune politique au niveau `Examen` (décision métier attendue).

## 9. Relations avec la gouvernance

- Circuit PERSISTÉ et APPEND-ONLY : `evaluation_planning_validations` (+ `evaluation_planning_evenements` immuables ; suppression et réécriture interdites par le modèle).
- La publication officielle est **exclusivement** atteignable via `publierExamenOfficiel` → `ExamPublicationOrchestratorService::publierExamen` → `ExamenService::transiter($examen, 'published')`.
- `ExamenController::transiter` refuse explicitement la cible `published` (double protection — route + test existant `test_route_transiter_ne_permet_plus_de_publier_sans_gouvernance`).
- L'empreinte planning (`calculerEmpreintePublication`) détecte toute modification postérieure à la certification → approbation/publication refusées si divergence.
- Détection de modification après approbation : OUI (empreinte) → exige une nouvelle version (`creerNouvelleVersionPostGel`).
- Publication répétée : re-scellement idempotent (pas de transition rejouée).

Conclusion A5 : la gouvernance est un workflow indépendant dont le résultat **contrôle** la publication ; aucun contournement identifié côté HTTP. Reste à vérifier les chemins internes (import, console) — couverts par ANOM-A3/A4.

## 10. Historisation

| Mécanisme | Périmètre | Contenu |
|---|---|---|
| `PlanningEvenement` (append-only) | Gouvernance | avant/après JSON, acteur, rôle, motif, empreinte |
| `IntegrationLog::tracer` | Gouvernance (`exam.governance.*`), Épreuves (`exam.epreuve.*`, `exam.locked`, émargement) | Corrélation `EXAM-{id}` / `EPR-{id}` |
| `EvaluationChanged` → `AuditEvaluation` | Tous changements évaluation | **Log Laravel uniquement** (non persistant, non requêtable) |
| `published_at` | Examen | Horodatage de publication |
| Menu « Historique » (`ExamHistoriqueAuditOrchestratorService`) | Lecture `IntegrationLog` + documents | **Aucune trace des transitions simples de l'examen** |

**ANOM-A8 (ÉLEVÉ)** : `ExamenService::transiter` n'écrit **aucune trace persistante** (pas de `IntegrationLog::tracer`, aucun enregistrement de transition). L'historique du cycle de vie de l'examen est donc incomplet : impossible de prouver « qui a fait quelle transition quand » pour les transitions ordinaires (`draft→planned→…`). Contraster avec `EpreuveService` (modèle conforme).

## 11. Sécurité (état observé)

- **Permissions** : Gates cohérentes route+contrôleur (`evaluations.view/create/update/publish/certify/approve`, `epreuves.view/update`, `surveillances.*`). Aucun wildcard implicite identifié.
- **Isolation facultaire** : `EvaluationPolicy::dansPerimetre` (programme/groupe/UE→programme) + `faculteRestreinteId()` ; contrôleurs ajoutent `abort_unless(peutAccederFaculte)` (404) ; listings `pourFaculteId`. Aucun `faculte_id` client n'est utilisé pour l'autorisation.
- **IDOR/BOLA** : route model binding + Policy + périmètre systématique sur les routes examinées. Le test `ExamGouvernanceHttpAuthorizationTest` couvre déjà « administrateur d'une autre faculté ne peut pas soumettre ».
- **MFA** : les routes du module Évaluation utilisent `['auth','admin','secondaire.verrou','module:evaluation']` — **sans** `mfa.obligatoire`, contrairement au groupe admin central. Constat systémique : tous les modules administratifs suivent le même schéma (Schedule, Finances, StudentDossiers, Sentinel…). À traiter comme point transverse (ANOM-A9, INFORMATION/MOYEN), hors correction locale sans décision d'architecture.
- **Verrou secondaire** : `secondaire.verrou` présent sur le groupe module.
- **Séparation des pouvoirs** : par personne (toujours) + par rôle (config active par défaut) dans la gouvernance.
- **Activation module** : `module:evaluation` → 404 si désactivé.

## 12. Tests existants (inventaire)

Suites pertinentes (toutes exécutées par `php artisan test`) :

| Suite | Couvre | Statut |
|---|---|---|
| `ExamGouvernanceDoubleValidationTest` | 15 tests : soumission, séparation des personnes/rôles, double approbation, refus motivé, escalade, gel, immuabilité, **transition directe vers published bloquée** | Complet |
| `ExamGouvernanceHttpAuthorizationTest` | 13 tests HTTP : permissions, hors faculté, post-gel, révision post-publication, blocage transition directe | Complet |
| `ExamGlobalGouvernanceSecuriteContinuiteTest` | Lots A–J : groupes virtuels, capacités, 4-yeux, présences, incidents, clôture, continuité, isolation | Complet |
| `ExamenCreationManuelleTest` | Création + vues + permissions index/store | Partiel (pas de transitions) |
| `EpreuveService`-liés (`EvaluationPhase5Test`, etc.) | Transitions épreuve | Existant |
| `ExamensConcurrenceReelleTest` | Concurrence réelle (processus) sur salles/D-04 — MySQL requis | Conditionnel |
| `ScheduleCycleDeVieTest` | Cycle emploi du temps (pas examen) | Hors périmètre |

**Absent** : suite dédiée au cycle de vie **`Examen`** via `ExamenService`/route `transiter` (transitions valides/invalides, répétition, annulation, terminal, concurrence du transiter, historique de transition). Le fichier `tests/Feature/ExamenCycleVieTest.php` n'existe pas (nom libre).

## 13. Incohérences constatées (synthèse)

| ID | Gravité | Description | Preuve |
|---|---|---|---|
| ANOM-A1 | ÉLEVÉ | Matrice déclarée ≠ matrice appliquée : `published`/`ongoing`/`completed`/`locked` sont des culs-de-sac effectifs ; `ongoing`/`completed`/`locked` inatteignables par chemin officiel | `Examen::TRANSITIONS` vs garde `estCritique()` |
| ANOM-A2 | MOYEN | `refuserPlanning` écrit `statut='draft'` directement (hors matrice, hors service central) | `ExamGouvernanceValidationService:258` |
| ANOM-A3 | MOYEN | `appliquerScenario` écrit `statut='scheduled'` depuis `draft|planned` (hors matrice, hors service) | `ExamScheduleEngineService:1483` |
| ANOM-A4 | MOYEN | Imports DataExchange acceptent un `statut` arbitraire sans contrôle d'automate | `ExamenDefinition:291`, `EvaluationDefinition` |
| ANOM-A5 | FAIBLE | Propagation publication épreuves (batch SQL) sans trace unitaire par épreuve | `ExamPublicationOrchestratorService:233-235` |
| ANOM-A6 | FAIBLE | `EvaluationChanged` ignore silencieusement le 3e argument (payload d'audit) sur 4 appels | Constructeur 2 params ; appels à 3 args |
| ANOM-A7 | ÉLEVÉ | `ExamenService::transiter` : pas de `lockForUpdate()` (course possible entre deux transitions) | `ExamenService:20` (`refresh()` seul) |
| ANOM-A8 | ÉLEVÉ | Aucune trace persistante des transitions simples (`draft→planned→…`) | `ExamenService:37-42` ; menu historique = `IntegrationLog` seul |
| ANOM-A9 | INFORMATION | Routes modules sans `mfa.obligatoire` (systémique, tous modules) | `Evaluation/Routes/web.php:37` |
| ANOM-A10 | MOYEN | Annulation (`→ cancelled`) sans motif, sans effet défini sur sessions/épreuves | Route `transiter` + service |
| ANOM-A11 | MOYEN | Aucune politique de réouverture d'examen (post-publication/post-archivage) | Aucun code trouvé |
| ANOM-A12 | FAIBLE | `ExamGouvernanceValidationService` injecte `ExamenService` sans l'utiliser (dépendance morte) ; écritures directes à la place | Constructeur ligne 42 |

## 14. Points non vérifiés (limites de l'audit)

1. Données historiques réelles (hors tests) : présence effective d'examens dans des états `ongoing`/`completed`/`locked` — nécessite un accès base réelle, non autorisé dans ce contexte.
2. Comportement MySQL réel des `lockForUpdate` sur `ExamenService` (SQLite en tests) — corroboré par `ExamensConcurrenceReelleTest` (MySQL) mais pas sur l'examen.
3. Livewire/API : aucune route Livewire dédiée aux transitions d'examen trouvée ; API v1 n'expose pas de transition d'examen (lecture seule `acquis`/`students`). Vérification par recherche `transiter` : seuls contrôleurs web.
4. Jobs : `PlanifierExamensJob` (Schedule) — dispatch à vérifier en Phase F6 (hooks différés).

## 15. Risques

| Risque | Impact | Probabilité | Mitigation proposée |
|---|---|---|---|
| Double transition concurrente (ANOM-A7) | État incohérent / double événement | Faible en mono-utilisateur, réelle en multi | `lockForUpdate()` + contrôle décisif après verrou *(réalisé — `03`)* |
| Absence d'historique (ANOM-A8) | Non-répudiation impossible, audit de conformité incomplet | Systématique | Journal append-only `evaluation_examen_evenements` (avant/après, acteur, motif) *(réalisé — `03`)* |
| Imports fixant un statut (ANOM-A4) | Contournement de l'automate par fichier | Faible (usage admin) | Validation d'appartenance à `Examen::STATUTS` |
| Culs-de-sac d'état (ANOM-A1) | Impossibilité opérationnelle de clôturer un examen publié | Réelle si usage `ongoing` attendu | Décision métier (matrice cible) |
| MFA absent sur module (ANOM-A9) | Enrôlement MFA contournable via routes module | Faible (auth+admin déjà requis) | Décision d'architecture transverse |

## 16. Décision : conserver / corriger / compléter

- **CONSERVER** : `ExamenService` comme moteur central ; circuit de gouvernance ; orchestrateur de publication ; séparation examen/session/épreuve ; Policies et périmètres.
- **CORRIGER — réalisé par ce LOOP (justifié, sans décision métier, cf. `03-implementation.md`)** :
  1. `ExamenService::transiter` : `lockForUpdate()` + contrôle décisif après verrou — ANOM-A7. ✅
  2. `ExamenService::transiter` : historisation append-only (`evaluation_examen_evenements` : avant/après, acteur, motif) — ANOM-A8. ✅
  3. Explicabilité : `transitionsDisponibles()` / `previsualiserTransition()` + endpoint lecture `GET …/transitions` (D7/G3). ✅
- **COMPLÉTER — réalisé** : `tests/Feature/ExamenCycleVieTest.php` (19 scénarios EX-LC ; comportement réel documenté ; aucune règle inventée). ✅
- **CONSIGNÉ — points annexes de `01-decisions-metier.md` (aucune décision requise à ce stade)** :
  - ANOM-A1 : automate effectif (scellement post-publication) — consigné dans `04` ; évolution éventuelle = décision complémentaire.
  - ANOM-A2/A3 : écritures directes encadrées (règle gouvernance explicite / raccourci scénario) — routage éventuel via le moteur = annexe.
  - ANOM-A4 : statut importé (DataExchange) — contrôle d'appartenance = amélioration annexe.
  - ANOM-A6 : payload `EvaluationChanged` ignoré — consigné (aucun impact métier démontré : l'audit persistant passe par le journal dédié).
  - ANOM-A9 : MFA sur routes modules — transverse, hors périmètre EX-LIFECYCLE.
  - ANOM-A12 : dépendance morte `ExamenService` dans la gouvernance — nettoyage annexe.
- **DIFFÉRER (décision métier requise — `01-decisions-metier.md`)** : D-01 (réouverture ↔ ANOM-A11), D-02 (motif transition sensible), D-03 (notifications), D-04 (propagation annulation ↔ ANOM-A10), D-05 (complétude `→completed`).

---

## GATE A — Vérification

| Critère | Statut |
|---|---|
| États réels inventoriés | ✅ §4 |
| Service central identifié | ✅ `ExamenService::transiter` |
| Transitions documentées (déclarées + réelles) | ✅ §5 |
| Points d'entrée alternatifs identifiés | ✅ §5.3 (9 sites d'écriture qualifiés) |
| Règles de gouvernance comprises | ✅ §9 |
| Contrôles d'autorisation inventoriés | ✅ §3, §11 |

**Verdict GATE A : FRANCHI — poursuite en Phase B** (spécification + décisions métier), puis C/D pour les corrections justifiées listées au §16.
