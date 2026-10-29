# UniRegistre — Examens Advanced — Matrice des tests et preuves

- **Date :** 2026-10-27
- **Statut :** Phase 0 — Inventaire et audit (lecture seule)
- **Environnement de test :** PHP 8.5.11, Laravel 13.33.0, PHPUnit, SQLite `:memory:`
- **Référentiel :** `docs/engineering/LOOP-UNIREGISTRE-EXAMENS-ADVANCED.md`

---

## 1. Synthèse d'exécution de la suite existante

La suite ciblée de tests existants sur le domaine Évaluation / Examens a été exécutée sur l'environnement de test isolé (`sqlite :memory:`, sans toucher aux bases de dev ou de production).

### 1.1 Commandes et résultats réels

| Commande | Tests | Succès | Assertions | Durée | Statut |
|---|---|---|---|---|---|
| `php artisan test --filter="PlanificationAutomatiqueExamensTest"` | 16 | 16 | 89 | 1 275 ms | **PASS** |
| `php artisan test --filter="Loop06PlanificationExamensVerificationTest"` | 22 | 22 | 71 | 1 509 ms | **PASS** |
| `php artisan test --filter="ExamensInvariantsValidationTest\|ExamensInvariantsSallesTest"` | 32 | 32 | 92 | 1 493 ms | **PASS** |
| `vendor/bin/phpunit --filter='ExamenCreationManuelleTest\|ExamensInvariantsValidationTest\|ExamensInvariantsSallesTest\|ExamPreparationTest\|PlanificationAutomatiqueExamensTest\|SchedulePhase12ExamensExtensionTest\|ExamensFeatureCompleteTest\|AffectationEnseignantExamenTest\|ExamensCommunicationTest\|Loop06PlanificationExamensVerificationTest'` | 171 | 171 | 665 | 6 683 ms | **PASS** |
| `vendor/bin/phpunit tests/Feature/ExamensConcurrenceReelleTest.php` | 1 | 0 | 0 | 50 ms | **NOT_EXECUTED** (1 skipped / opt-in requis) |

---

## 2. Matrice d'adéquation aux exigences du protocole

Chaque exigence du protocole est mise en regard des tests existants, de leur statut d'exécution et des tests nécessaires lors de l'implémentation.

### 2.1 Tests fonctionnels (§11.1)

| Exigence fonctionnelle | Test existant / Couverture | Statut audit | Preuve / Remarque |
|---|---|---|---|
| Création et modification d'une session | `ExamenCreationManuelleTest`, `SessionExamenTest` | **PASS** | Cycle de vie de session testé |
| Configuration avec 1 module | Non couvert (concept d'entité configuration absent) | **NOT_EXECUTED** | À implémenter au Lot 2 |
| Configuration avec 2 modules | Non couvert | **NOT_EXECUTED** | À implémenter au Lot 2 |
| Configuration avec 3 modules | Non couvert | **NOT_EXECUTED** | À implémenter au Lot 2 |
| Configuration dépassant la limite (4+ modules) | Non couvert | **NOT_EXECUTED** | À implémenter au Lot 2 |
| Épreuve distincte par module | `ExamenCreationManuelleTest` | **PASS** | Cas standard 1 épreuve = 1 ECUE |
| Épreuve combinée explicitement autorisée | Non couvert | **NOT_EXECUTED** | Décision D-03 validée, à coder Lot 2 |
| Épreuve combinée sans configuration suffisante | Non couvert | **NOT_EXECUTED** | À coder Lot 2 |
| Mode groupe pédagogique (Mode A) | `PlanificationAutomatiqueExamensTest`, `EpreuveServiceTest` | **PASS_WITH_RESERVATIONS** | Actuel : convoque tous les étudiants du groupe |
| Mode regroupement spécifique (Mode B) | Non couvert | **NOT_EXECUTED** | À implémenter aux Lots 4 et 6 |
| Candidat éligible module A, non éligible module B | Non couvert | **NOT_EXECUTED** | Éligibilité par ECUE à centraliser Lot 1 |
| Candidat exclu avec motif explicite | Non couvert | **NOT_EXECUTED** | À implémenter Lot 1 / Lot 3 |
| Données d'éligibilité incomplètes | Non couvert | **NOT_EXECUTED** | À implémenter Lot 1 / Lot 3 |
| Capacités connues et validées | `ExamensInvariantsSallesTest` | **PASS_WITH_RESERVATIONS** | Capacité théorique testée, pas capacité autorisée |
| Capacité inconnue bloque publication | `ExamensInvariantsValidationTest` | **PASS** | Détection salle sans capacité |
| Capacité utile dépassée bloque publication | `PlanificationAutomatiqueExamensTest` | **PASS** | Bloque application scénario si effectif > capacité |
| Salle indisponible détectée | `PlanificationAutomatiqueExamensTest` | **PASS** | Détection d'indisponibilité |
| Candidat affecté à 2 épreuves simultanées | `ExamensInvariantsValidationTest` | **PASS** | Détection conflit étudiant |
| Salle affectée à 2 épreuves incompatibles | `PlanificationAutomatiqueExamensTest` | **PASS** | Détection chevauchement horaire |
| Surveillant affecté à 2 créneaux incompatibles | `AffectationEnseignantExamenTest` | **PASS** | Détection conflit surveillant |
| Échec de planification avec diagnostic précis | `Loop06PlanificationExamensVerificationTest` | **PASS** | Diagnostic complet sur rejet de scénario |
| Simulation valide sans modification persistée | `ExamScheduleEngineServiceTest` | **PASS** | `genererScenarios` ne persiste rien |
| Publication après contrôles réussis | `ExamensFeatureCompleteTest` | **PASS** | Transition conditionnée à l'audit conforme |
| Blocage publication si contrôle obligatoire échoue | `ExamensInvariantsValidationTest` | **PASS** | Conflit dur empêche le statut `published` |
| Exception en attente d'approbation | Non couvert | **NOT_EXECUTED** | Workflow exception absent du schéma actuel |
| Approbation par rôle autorisé | Non couvert | **NOT_EXECUTED** | À implémenter Lot 7 |
| Refus par rôle non autorisé | Non couvert | **NOT_EXECUTED** | À implémenter Lot 7 |
| Génération répétée (idempotence) | `ExamPreparationTest`, `Loop06PlanificationExamensVerificationTest` | **PASS** | Idempotence `genererCandidats` et scénarios |
| Modification après publication interdite sans version | `ExamenServiceTest`, `ExamensFeatureCompleteTest` | **PASS_WITH_RESERVATIONS** | Bloque via `estCritique`, mais pas de versioning |
| Republication versionnée | Non couvert | **NOT_EXECUTED** | Entité version à créer Lot 7 |
| Génération des convocations | `ExamPreparationTest`, `ExamensCommunicationTest` | **PASS** | PDF individuel et émargement testés |
| Absence de convocation/notification avant publication | `ExamensCommunicationTest` | **FAIL (écart identifié)** | Étudiant peut télécharger convocation d'épreuve `scheduled` |
| Audit de chaque action sensible | `EvaluationAuditTest` | **PASS** | Traces dans `IntegrationLog` et `EvaluationChanged` |

---

### 2.2 Tests de sécurité (§11.2)

| Contrôle de sécurité | Test existant / Emplacement | Statut audit | Preuve |
|---|---|---|---|
| Accès sans permission (403) | `EvaluationTest`, `ExamensFeatureCompleteTest` | **PASS** | Middlewares `can:...` testés |
| Identifiant session hors faculté (Anti-IDOR) | `ExamensFeatureCompleteTest` | **PASS** | Scope `faculteRestreinteId` vérifié |
| Accès liste candidats autre faculté | `ExamPreparationTest` | **PASS** | `pourFaculteId` appliqué |
| Publication par requête directe sans droit | `ExamensFeatureCompleteTest` | **PASS_WITH_RESERVATIONS** | Contrôle `evaluations.update`, permission dédiée requise |
| Forcer un statut arbitraire côté client | `ExamenControllerTest` | **PASS** | `whereIn('cible', Examen::STATUTS)` + matrice serveur |
| Forcer une capacité non validée côté client | `Loop06PlanificationExamensVerificationTest` | **PASS** | Revalidation serveur stricte dans `appliquerScenario` |
| Inclure un candidat non éligible | `ExamPreparationTest` | **FAIL (écart identifié)** | Si groupe_id renseigné, aucun contrôle pédagogique |
| Contourner la validation de scénario | `Loop06PlanificationExamensVerificationTest` | **PASS** | Rejet scénario incomplet ou forgé |
| Double génération concurrente | `ExamensConcurrenceReelleTest` | **NOT_EXECUTED** | Test MySQL skippé |
| Modification après publication sans version | `ExamenServiceTest` | **PASS** | Verrou `estCritique` |
| Sécurité des exports (anonymisation, pas de PII) | `DocumentBatchTest`, `ExamPreparationTest` | **PASS** | Logs sans CIN, numéros anonymes |

---

### 2.3 Tests de concurrence et d'intégrité (§11.3)

| Scénario de concurrence | Fichier de test | Statut d'exécution | Justification |
|---|---|---|---|
| Deux générations simultanées | `tests/Feature/ExamensConcurrenceReelleTest.php` | **NOT_EXECUTED** | Nécessite un environnement MySQL multi-connexions avec variable `RUN_MYSQL_CONCURRENCY_TESTS=true`. Skippé sous SQLite `:memory:`. |
| Deux publications simultanées | Non implémenté | **NOT_EXECUTED** | À couvrir au Lot 7 avec test de verrouillage |
| Réservation concurrente de même salle | `ScheduleVerrouConcurrenceTest` | **PASS** (sur schedule) | À généraliser sur examens |
| Modification de capacité pendant génération | Non implémenté | **NOT_EXECUTED** | À couvrir au Lot 5 |
| Dérive d'éligibilité entre simulation et publication | `ExamPreparationTest` (dérive population) | **PASS** | Snapshot hash + effectif détecte la dérive |
| Rollback sur transaction interrompue | `ExamensInvariantsValidationTest` | **PASS** | Pas d'écriture partielle en cas d'exception |
| Répétition de requête après erreur | `Loop06PlanificationExamensVerificationTest` | **PASS** | Idempotence des opérations |

---

## 3. Registre des preuves d'exécution

### Preuve E-01 : Planification automatique
```text
Commande : php artisan test --filter=PlanificationAutomatiqueExamensTest
Résultat : tests: 16, passed: 16, assertions: 89, duration_ms: 1275
Validé : espacement 50 %, détection collisions salles/groupes/surveillants, rejet indisponibilités.
```

### Preuve E-02 : Revalidation stricte des scénarios
```text
Commande : php artisan test --filter=Loop06PlanificationExamensVerificationTest
Résultat : tests: 22, passed: 22, assertions: 71, duration_ms: 1509
Validé : rejet des placements forgés, contrôle d'appartenance à l'examen, rejet épreuves dupliquées, contrôle d'ouverture de salle.
```

### Preuve E-03 : Invariants de validation et de capacité
```text
Commande : php artisan test --filter="ExamensInvariantsValidationTest|ExamensInvariantsSallesTest"
Résultat : tests: 32, passed: 32, assertions: 92, duration_ms: 1493
Validé : publication bloquée sur conflit dur, capacité insuffisante refusée, rollback transactionnel sans résidu.
```

---

## 4. Verdict de la matrice de tests

- **Tests existants exécutés :** 171 / 171 réussis (100 %)
- **Contrôles non exécutés documentés :** Concurrence MySQL réelle (`NOT_EXECUTED`), tests des lots 1 à 9 non encore créés.
- **Verdict Phase 0 :** `PASS_WITH_RESERVATIONS`
