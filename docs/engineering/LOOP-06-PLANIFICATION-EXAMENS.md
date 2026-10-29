# LOOP-06 — PLANIFICATION AUTOMATIQUE DES EXAMENS

> **Verdict : PASS** (0 réserve bloquante, validation complète 153/153 tests passés)  
> **Date :** 25/10/2026 · **Mode :** extension minimale d'un noyau existant (Phase 12 / Evaluation / Schedule)  
> **Contrat :** Audit-first, réutilisation maximale, 0 commit/push, 0 migration destructive.

---

## 1. Objectif et périmètre du lot

Amener la planification et l'ordonnancement automatique des examens aux critères d'acceptation du programme **LOOP MASTER (LOT 06)** :
- **Réutilisation maximale** : sessions, épreuves, maquettes, groupes, salles, enseignants et surveillances existantes.
- **Moteur de contraintes dures et préférences souples** : placement automatique multi-matières, multi-groupes et multi-salles.
- **Respect strict des contraintes dures** :
  1. Zéro collision de groupe / étudiants (aucun étudiant ou groupe ne peut avoir deux épreuves simultanées).
  2. Zéro double réservation de salle.
  3. Capacité utile de salle respectée avec ratio d'espacement réglementaire anti-fraude (50%).
  4. Respect absolu des indisponibilités confirmées (`ScheduleAvailability::indisponibles()`).
  5. Absence de conflit avec les cours réguliers planifiés (`ScheduleSlot`).
  6. Salles fermées ou inactives exclues.
- **Signalement des contraintes impossibles** : détection et explicitation immédiate si aucune salle ou aucun créneau ne permet de satisfaire les contraintes dures.
- **Production de scénarios comparables** :
  - **Scénario A — Confort & Révision (Étalé)** : espacement maximal entre épreuves d'un même groupe (1 examen par jour max, jours de révision intercalaires), créneaux matinaux privilégiés (08:30-10:30).
  - **Scénario B — Compact & Optimisation Salles** : regroupement temporel sur un nombre minimal de journées pour réduire les coûts logistiques d'ouverture et mutualiser les salles.
  - **Scénario C — Grands Espaces & Amphis Prioritaires** : priorité aux amphithéâtres et grandes capacités pour maximiser la distance sanitaire/anti-fraude et centraliser les équipes de surveillance.
- **Matrice comparative et recommandation** : score de qualité (0-100), taux d'occupation des salles, journées mobilisées, et motif de recommandation.
- **Protection du planning validé** : la génération ou l'application refuse d'écraser un planning validé ou verrouillé (`published`, `locked`, `archived`) sans autorisation explicite (`autoriser_ecrasement => true` ou `forcer => true`).
- **Verrou de publication strict** : `ExamenService::transiter` bloque impérativement la transition vers `published` si une seule contrainte dure est violée (**Règle d'or : FAIL si un planning est déclaré valide malgré une contrainte dure violée**).
- **Exécution asynchrone** : `PlanifierExamensJob` (ShouldQueue avec `WithoutOverlapping`).

---

## 2. État Git et intégrité du référentiel

- **HEAD initial et final :** `38ef149` (inchangé — 0 commit, 0 push).
- **Migrations :** 0 migration lancée, 0 schéma altéré, base `:memory:` SQLite en tests, conformité stricte production.
- **Modifications ciblées :**
  - [app/Modules/Schedule/Services/ExamScheduleEngineService.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/Schedule/Services/ExamScheduleEngineService.php) (Moteur de contraintes dures, génération de 3 scénarios comparables, comparaison, application transactionnelle et audit renforcé).
  - [app/Modules/Evaluation/Services/ExamenService.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/Evaluation/Services/ExamenService.php) (Verrou bloquant de publication via `auditerSessionExamens`).
  - [app/Modules/Evaluation/Controllers/ExamenController.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/Evaluation/Controllers/ExamenController.php) (Points de terminaison `genererScenarios`, `appliquerScenario`, `auditConformite` avec contrôle IDOR / faculté).
  - [app/Modules/Evaluation/Routes/web.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/Evaluation/Routes/web.php) (Enregistrement des routes d'ordonnancement sous middleware `auth`, `admin`, `module:evaluation`).
  - [app/Modules/Evaluation/Jobs/PlanifierExamensJob.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/Evaluation/Jobs/PlanifierExamensJob.php) (Job asynchrone avec middleware `WithoutOverlapping`).
  - [tests/Feature/PlanificationAutomatiqueExamensTest.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/PlanificationAutomatiqueExamensTest.php) (16 tests de validation exhaustive).

---

## 3. Architecture et réutilisation maximale (Zéro duplication)

Le module s'appuie intégralement sur les services et modèles préexistants :
1. **Ressources calendrier et indisponibilités** :
   - `ScheduleAvailability::query()->indisponibles()` : contrainte dure pour exclure salles et groupes déclarés indisponibles.
   - `ScheduleSlot` : exclusion automatique des créneaux où un cours régulier occupe la salle.
2. **Capacités et anti-fraude** :
   - Constante `ExamScheduleEngineService::RATIO_CAPACITE_EXAMEN_DEFAUT = 0.50` : 1 place sur 2 pour l'espacement d'examen.
   - `capaciteUtileExamen(Room $salle, float $ratio)` : calcul de la capacité utile réelle.
3. **Surveillance** :
   - Respect de `Room::$min_surveillants` et affectation équitable via `Surveillance::updateOrCreate`.
4. **Traçabilité** :
   - `IntegrationLog::tracer('evaluation', 'internal', 'exam.schedule.scenario_applied', ...)`
   - `EvaluationChanged::dispatch('examen.planning_applique', $examen->id)`

---

## 4. Matrice des contraintes dures et préférences souples

| Type | Règle | Implémentation | Sanction en cas de violation |
|---|---|---|---|
| **Dure** | Collision de groupe | `verifierCreneauValide` + `auditerSessionExamens` | Créneau rejeté / `conforme = false` |
| **Dure** | Collision de salle | `verifierCreneauValide` + `auditerSessionExamens` | Créneau rejeté / `conforme = false` |
| **Dure** | Capacité utile avec ratio 50% | `capaciteUtileExamen` >= effectif | Créneau rejeté / `conforme = false` |
| **Dure** | Indisponibilité confirmée | `ScheduleAvailability::indisponibles()` | Créneau rejeté / `conforme = false` |
| **Dure** | Conflit cours régulier | `ScheduleSlot::chevauche` | Créneau rejeté / `conforme = false` |
| **Dure** | Salles inactives ou fermées | `salle->actif && salle->estOuvertePlage` | Créneau rejeté / `conforme = false` |
| **Dure** | Protection planning validé | `$examen->estCritique() && !$forcer` | `DomainException` levée |
| **Dure** | Blocage publication | `ExamenService::transiter('published')` | `DomainException` (« contraintes dures violées ») |
| **Souple** | Préférence matinée | Confort horaire (08:30 = 95, 11:00 = 85, 14:30 = 80) | Pondération du `score_qualite` |
| **Souple** | Étalement des révisions | 1 épreuve par jour max par groupe si possible | Sélection prioritaire dans `SCENARIO_ETALE` |
| **Souple** | Densité des salles | Regroupement des créneaux sur jours rapprochés | Sélection prioritaire dans `SCENARIO_COMPACT` |
| **Souple** | Salles à grand gabarit | Tri décroissant des capacités de salles | Sélection prioritaire dans `SCENARIO_AMPHI` |

---

## 5. Commandes exécutées et preuves de validation

### 5.1 Suite de tests dédiée (PlanificationAutomatiqueExamensTest)

```bash
php artisan test --compact --filter="PlanificationAutomatiqueExamensTest"
```
**Résultat : 16/16 PASSED · 89 assertions · 1,8 s**
- `test_generation_produit_3_scenarios_comparables_avec_scores_et_metriques` : ✅ PASS
- `test_comparaison_scenarios_recommande_la_meilleure_variante` : ✅ PASS
- `test_contraintes_dures_anti_collision_groupe_dans_le_planning` : ✅ PASS
- `test_contraintes_dures_anti_collision_salle_dans_le_planning` : ✅ PASS
- `test_contraintes_dures_rejet_salles_saturees_et_respect_ratio_espacement` : ✅ PASS
- `test_contraintes_dures_respect_indisponibilites_confirmees_salle_et_groupe` : ✅ PASS
- `test_contraintes_impossibles_sont_signalees_explicitement` : ✅ PASS
- `test_application_scenario_invalide_est_strictement_rejetee` : ✅ PASS
- `test_application_scenario_valide_met_a_jour_epreuves_et_statut_examen` : ✅ PASS
- `test_generation_necrase_pas_un_planning_valide_ou_verrouille_sans_autorisation` : ✅ PASS
- `test_autorisation_explicite_autorise_ecrasement_planning_valide` : ✅ PASS
- `test_publication_bloquee_si_contrainte_dure_violee_dans_examen` : ✅ PASS
- `test_publication_autorisee_si_planning_strictement_conforme` : ✅ PASS
- `test_job_asynchrone_planification_examens` : ✅ PASS
- `test_routes_http_scenarios_et_application_avec_isolation_faculte` : ✅ PASS
- `test_concurrence_et_generation_repetee_idempotente` : ✅ PASS

### 5.2 Régression élargie (Examens, Salles, Surveillance, Invariants)

```bash
php artisan test --compact --filter="PlanificationAutomatiqueExamensTest|SchedulePhase12ExamensExtensionTest|ExamensInvariantsValidationTest|ExamensInvariantsSallesTest|ExamPreparationTest|SurveillanceEquilibrageTest|ContraintesSurveillanceTest|AffectationEnseignantExamenTest|ExamensFeatureCompleteTest"
```
**Résultat : 153/153 PASSED · 622 assertions · 6,5 s · 0 échec**

### 5.3 Formatage et conformité de code

```bash
vendor/bin/pint --dirty --test
```
**Résultat : PASSED**

---

## 6. Sécurité et contrôle d'accès

- **Isolation faculté (anti-IDOR)** : `ExamenController` vérifie systématiquement `$examen->faculteId()` et l'appartenance de l'utilisateur via `$request->user()->peutAccederFaculte()`. Un administrateur restreint accédant à un examen d'une autre faculté reçoit un refus immédiat (403/404).
- **Contrôle RBAC** : Gates `evaluations.update` requises pour la génération et l'application des scénarios, `evaluations.view` pour l'audit de conformité.
- **Throttling anti-déni de service** : routes de génération bridées à 15 req/min, audit à 30 req/min.

---

## 7. Verdict et conditions de passage au lot suivant

### Verdict : PASS

Toutes les exigences du **LOT 06** sont couvertes, testées et validées sans aucune réserve bloquante :
1. ✅ Génération de 3 scénarios comparables avec contraintes dures et préférences souples.
2. ✅ Respect strict des capacités utiles (ratio 50%), indisponibilités et anti-collisions.
3. ✅ Signalement explicite des contraintes impossibles.
4. ✅ Protection contre l'écrasement intempestif des plannings validés/verrouillés.
5. ✅ Blocage absolu de la publication si une contrainte dure est violée (**Règle d'or respectée**).
6. ✅ Validation asynchrone (Job) et HTTP (API + Web).

### Conditions de passage au LOT 07 (Coffre-fort documentaire et attestations QR) :
- Le LOT 06 est clos avec succès.
- Le LOT 07 abordera la sécurisation des documents officiels, la vérification publique et la génération de QR codes scannables.
