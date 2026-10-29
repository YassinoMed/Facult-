# EA-EXAMENS-01 — Phase 0 : Audit Initial & Cartographie

**Projet :** UniRegistre — Laravel  
**Module :** Examens Advanced — Menu 1 (Tableau de bord Enterprise) & Écosystème des 12 menus  
**Date :** 28 Octobre 2026  
**Branche :** `feature/admin-modifier-etudiant`  
**HEAD Git :** `38ef14933de9980a4c62cb805bd19bc86c2b3081`  
**Statut audit :** PASS — Baseline vérifiée, aucune régression préexistante sur le périmètre Examens.

---

## 1. Inventaire Technique & Cartographie des 12 Menus Examens Advanced

Le module Examens d'UniRegistre est articulé autour de 12 menus fonctionnels avancés définis dans `routes/web.php` et orchestrés par `ExamenAdvancedMenuController` et les contrôleurs associés.

### 1.1 Matrice des 12 Menus Réels

| N° | Menu | Route (URI) | Nom de Route | Contrôleur & Méthode | Permissions / Gates | Services Métier Sous-jacents | Périmètre & Données |
|:---|:---|:---|:---|:---|:---|:---|:---|
| **1** | **Tableau de bord** | `GET /admin/evaluation/examens/tableau-de-bord` | `admin.evaluation.examens.tableau-de-bord` | `ExamenAdvancedMenuController@tableauDeBord` | `evaluations.view` | `PreparationExamenService`, `ExamScheduleEngineService`, `ConflitEpreuveService` | Sessions, alertes, préparation, KPI agrégés par faculté/année |
| **2** | **Sessions d'examens** | `GET /admin/evaluation/examens/sessions` | `admin.evaluation.examens.sessions` | `ExamenAdvancedMenuController@sessions` | `sessions_examen.view` | `PreparationExamenService`, `SessionExamen` Eloquent | Cycles, périodes de sessions, statuts, validation de session |
| **3** | **Configurations** | `GET /admin/evaluation/examens/configurations` | `admin.evaluation.examens.configurations` | `ExamenAdvancedMenuController@configurations` | `evaluations.view` | `EvaluationConfigurationService` | Barèmes, coefficients, règles d'anonymat, seuils |
| **4** | **Éligibilité** | `GET /admin/evaluation/examens/eligibilite` | `admin.evaluation.examens.eligibilite` | `ExamenAdvancedMenuController@eligibilite` | `evaluations.view` | `EligibiliteExamenService` | Inscriptions pédagogiques, assiduité, prérequis académiques |
| **5** | **Groupes d'étudiants** | `GET /admin/evaluation/examens/groupes` | `admin.evaluation.examens.groupes` | `ExamenAdvancedMenuController@groupes` | `epreuves.view` | `GroupeEtudiantsService` | Répartition des cohortes, tiers-temps, aménagements |
| **6** | **Salles & Capacités** | `GET /admin/evaluation/examens/salles` | `admin.evaluation.examens.salles` | `ExamenAdvancedMenuController@salles` | `evaluations.view` | `ExamRoomAssignmentService`, `Batiment`, `Salle` | Capacités brutes vs examen, équipements, accessibilité PMR |
| **7** | **Planification** | `GET /admin/evaluation/examens/planification` | `admin.evaluation.examens.planification` | `ExamenAdvancedMenuController@planification` | `epreuves.view` | `ExamScheduleEngineService`, `ConflitEpreuveService` | Créneaux, conflits, génération automatique, grille horaire |
| **8** | **Surveillance** | `GET /admin/evaluation/examens/surveillance` | `admin.evaluation.examens.surveillance` | `ExamenAdvancedMenuController@surveillance` | `epreuves.view` | `SurveillanceExamenService`, `Enseignant` Eloquent | Disponibilité surveillants, ratios, affectations, émargement |
| **9** | **Simulation** | `GET /admin/evaluation/examens/simulation` | `admin.evaluation.examens.simulation` | `ExamenAdvancedMenuController@simulation` | `evaluations.view` | `ExamScheduleEngineService` (scénarios CSP / heuristiques) | Scénarios virtuels, comparaison taux saturation, zéro écriture |
| **10** | **Publication** | `GET /admin/evaluation/examens/publication` | `admin.evaluation.examens.publication` | `ExamenAdvancedMenuController@publication` | `evaluations.view` (et `evaluations.publish` pour actions) | `EvaluationWorkflowService`, `WorkflowPublication` | Workflow 4 yeux, gel des plannings, publication portails |
| **11** | **Convocations** | `GET /admin/evaluation/examens/convocations` | `admin.evaluation.examens.convocations` | `ExamenAdvancedMenuController@convocations` | `evaluations.view` | `ConvocationPdfService`, `NotificationOutbox` | Génération PDF, QR code de validation, notifications email |
| **12** | **Historique & Audit** | `GET /admin/evaluation/examens/historique` | `admin.evaluation.examens.historique` | `ExamenAdvancedMenuController@historique` | `evaluations.view` | `EvaluationAuditService`, `AuditLog` Eloquent | Journal des transitions, traçabilité des modifications |

---

## 2. Audit des Règles Métier et Services Existants

L'audit approfondi du code source révèle l'existence de services spécialisés très riches mais sous-exploités par le Menu 1 actuel :

### 2.1 Service `PreparationExamenService`
- **Méthode auditée :** `tableauDeBord(?int $anneeId = null, ?int $faculteRestreinteId = null)`
- **Fournit :**
  - KPI de base : nombre d'épreuves (total, planifiées, non planifiées, publiées).
  - Nombre de salles (total, utilisées, surchargées).
  - Sessions actives et récapitulatif.
  - Alertes basiques (salles en surcharge, épreuves sans salle).
- **Limites constatées :**
  - Ne calcule pas de score explicable multi-dimensionnel [0..100%].
  - N'agrège pas les conflits par famille standardisée (Salles, Candidats, Surveillants, Planning).
  - Ne fournit pas d'état de gouvernance (propositions soumises en attente d'approbation à 4 yeux).
  - Pas d'indicateur de fraîcheur ou de fiabilité des données.

### 2.2 Service `ExamScheduleEngineService`
- **Capacité utile examen :** Calculée via `capaciteUtileExamen(Salle $salle, ?string $mode = null)` qui applique le ratio anti-fraude (par défaut 50% ou capacité d'examen déclarée).
- **Moteur d'audit de session :** `auditerSessionExamens(int $sessionExamenId)` retourne un diagnostic complet avec code de règle, sévérité (`CRITICAL`, `WARNING`, `INFO`), composant, et suggestions de réparation.
- **Moteur de scénarios :** `genererScenariosPlanning(...)` et `comparerScenarios(array $scenarios)` génèrent des propositions alternatives sans persistance destructive.

### 2.3 Service `ConflitEpreuveService`
- **Méthode :** `detecter(Epreuve $epreuve, ?Carbon $dateDebut, ?Carbon $dateFin, ?int $salleId, array $surveillantIds)`
- **Règles auditées :**
  - Conflits horaires de salle (deux épreuves dans la même salle sur un créneau se chevauchant).
  - Dépassement de capacité (inscrits > capacité examen de la salle).
  - Conflit d'indisponibilité de la salle.
  - Conflits de surveillants (enseignant déjà affecté à une autre épreuve sur la même plage horaire).

### 2.4 Cloisonnement Facultaire et Sécurité
- Le composant `FacultePerimetre::resoudre($user, $request->input('faculte_id'))` garantit qu'un utilisateur rattaché à une faculté ne peut pas voir ou modifier les données d'une autre faculté.
- Le super-administrateur peut filtrer librement ou voir la synthèse globale.

---

## 3. Baseline des Tests Existants

Une baseline complète a été exécutée avant toute modification de code pour enregistrer l'état exact du dépôt :

```bash
vendor/bin/phpunit --filter="ExamensAdvancedSidebarTest|ExamensAdvancedPagesRegressionTest|ExamPreparationTest|ExamenCreationManuelleTest|ExamensInvariantsValidationTest|ExamensInvariantsSallesTest|PlanificationAutomatiqueExamensTest" --testdox
```

**Résultat :**
- **Tests exécutés :** 92
- **Assertions :** 397
- **Échecs :** 0
- **Erreurs :** 0
- **Temps d'exécution :** 4.22 secondes
- **Mémoire utilisée :** 68.50 MB
- **Verdict Baseline :** PASS (100% au vert)

---

## 4. Écarts Constatés et Architecture Cible

### Écarts par rapport au mandat Enterprise :
1. **Fiabilité et exhaustivité des KPI :** Le tableau de bord actuel n'affiche pas la fraîcheur des données, la distinction entre zéro et données manquantes, ni l'indicateur de fiabilité.
2. **Score de préparation des sessions :** Absence d'un score composite pondéré et explicable décomposé en 7 dimensions académiques avec verdict d'éligibilité opérationnelle (`ELIGIBLE` vs `BLOQUE`).
3. **Moteur consolidé de conflits :** Pas de matrice consolidée exposant les conflits par famille (Salles, Candidats, Surveillants, Planning) avec références stables et boutons d'action vers les menus de résolution.
4. **Allocation intelligente & Capacité Salles :** Manque d'une vue synthétique sur la saturation des salles par bâtiment/centre, alertes de sous-capacité ou capacité non vérifiée.
5. **Gouvernance & Workflow à 4 Yeux :** Aucune visibilité directe sur le tableau de bord des propositions d'allocation en attente de double validation humaine.
6. **Navigation contextuelle unifiée :** Absence d'un hub de navigation vers les 12 menus conservant le filtre actif (`annee_id`, `faculte_id`).

### Architecture de la Solution :
- Création de `ExamDashboardOrchestratorService` pour orchestrer les données sans dupliquer la logique métier des services spécialisés.
- Enrichissement de `ExamenAdvancedMenuController::tableauDeBord` pour consommer cet orchestrateur en lecture seule.
- Refonte de la vue `resources/views/modules/evaluation/examens-advanced/tableau-de-bord.blade.php` avec une ergonomie Enterprise (Blade + Tailwind / Alpine.js).
- Création d'une suite de tests dédiée `tests/Feature/ExamDashboardEnterpriseTest.php`.
