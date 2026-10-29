# LOOP-01 — WORKFLOW DE VALIDATION CONFIGURABLE
## Plateforme Universitaire Intelligente — Rapport d'Ingénierie et de Validation

**Date :** 24 Octobre 2026  
**Lot :** LOT 01 — Workflow de validation configurable  
**Périmètre :** Moteur unifié, définitions versionnées, transitions contrôlées serveur, séparation des fonctions (4 yeux), audit et conservation des versions  
**Auteur :** Antigravity Senior Lead Architect & QA Auditor  
**Verdict :** **PASS**  

---

## 1. OBJECTIF MÉTIER ET PÉRIMÈTRE DU LOT 01

### 1.1 Objectifs
- Fournir un moteur de workflow configurable et versionné (`MoteurWorkflowService`, `RegistreWorkflow`) sans dupliquer les modules existants (`CentreTravailController`, `CorrectionService`, `TaskService`).
- Garantir le contrôle strict côté serveur des transitions d'états (rejet absolu des sauts illicites et des appels directs).
- Imposer le principe de séparation des fonctions (quatre yeux) pour les transitions sensibles : l'initiateur/proposeur d'un changement ne peut en aucun cas l'approuver ou le valider lui-même.
- Assurer la conservation stricte de la version de workflow applicable aux dossiers en cours (un dossier initié sous une version V1 conserve les règles V1 même après le déploiement d'une version V2).
- Garantir l'isolation facultaire (scoping serveur) et l'auditabilité immuable (attribution, horodatage, motif).

---

## 2. ÉTAT INITIAL ET FINAL DU DÉPÔT

### 2.1 État Git initial
- **Branche :** `feature/admin-modifier-etudiant`
- **Dernier commit :** `38ef149` (*feat(etudiants): modification administrative d'un étudiant depuis le registre*)
- **Modifications préexistantes :** Préservées intégralement sans aucune perte.

### 2.2 Fichiers consultés
- `app/Services/Verification/CorrectionService.php` (workflow 4 yeux existant).
- `app/Modules/Tasks/Services/TaskService.php` et `app/Modules/Tasks/Models/Task.php` (matrice des transitions de tâches).
- `app/Http/Controllers/Admin/CentreTravailController.php` (file de travail unifiée).
- `app/Modules/Evaluation/Services/EvaluationWorkflowService.php` (transitions de notes et délibérations).
- `app/Modules/OnlineRequests/Services/RequestWorkflowService.php` (transitions des demandes en ligne).
- `app/Support/Permissions.php` et `app/Models/User.php` (matrice RBAC et permissions).
- `app/Models/HistoriqueAcademique.php` (constantes d'audit).

### 2.3 Fichiers créés et ajoutés
1. `app/Services/Workflow/Contrats/WorkflowDefinitionInterface.php` : contrat formel pour définitions versionnées.
2. `app/Services/Workflow/Definitions/WorkflowInscriptionDefinitionV1.php` : workflow d'inscription version 1 (état 'valide' terminal).
3. `app/Services/Workflow/Definitions/WorkflowInscriptionDefinitionV2.php` : workflow d'inscription version 2 (état 'pieces_attendues' et révocation post-validation).
4. `app/Services/Workflow/Definitions/WorkflowCorrectionQuatreYeuxDefinition.php` : workflow de correction à 4 yeux avec contrôle de l'acteur.
5. `app/Services/Workflow/Definitions/WorkflowDemandeEtudianteDefinition.php` : workflow de requêtes étudiantes avec délais SLA.
6. `app/Services/Workflow/RegistreWorkflow.php` : registre central résolvant les versions par domaine.
7. `app/Services/Workflow/MoteurWorkflowService.php` : moteur transactionnel avec verrous `lockForUpdate`, contrôle de permissions, isolation facultaire et audit trail.
8. `tests/Feature/WorkflowValidationConfigurableTest.php` : suite de tests dédiée de 9 cas vérifiant l'ensemble des critères.

---

## 3. ARCHITECTURE TECHNIQUE ET RÉUTILISATION

### 3.1 Pattern et contrat
Le moteur repose sur l'interface `WorkflowDefinitionInterface`, permettant à chaque domaine (inscription, correction, tâche, demande, évaluation) de définir :
- Son identifiant de domaine (`domaine()`).
- Sa version numérique incrémentale (`version()`).
- Ses états et sa matrice de transitions autorisées (`transitions()`).
- Ses gardes métier contextuelles (`verifierGardes()`).
- L'obligation de quatre yeux (`requiertQuatreYeux()`).
- Les permissions requises (`permissionRequise()`).
- Les délais maximums de traitement SLA (`delaiMaxHeures()`).

### 3.2 Conservation des versions
L'entité peut porter une version explicite via `$entite->version_workflow` ou `$entite->data['version_workflow']`.
Si un dossier a été ouvert en V1, `MoteurWorkflowService` résout la définition V1. Si une transition 'valide' → 'revoquee' est tentée sur ce dossier, elle est rejetée (`DomainException`), tandis qu'un dossier créé en V2 l'autorise.

### 3.3 Intégrité transactionnelle et concurrence
Chaque transition est exécutée dans une transaction `DB::transaction()` avec verrou pessimiste de ligne (`$entite->lockForUpdate()`), éliminant tout risque de course concurrente ou d'incohérence TOCTOU.

---

## 4. COMMANDES EXÉCUTÉES ET RÉSULTATS DES TESTS

### 4.1 Suite ciblée du Lot 01
```bash
php artisan test --filter="WorkflowValidationConfigurableTest"
```
- **Résultat :** 9 tests réussis, 18 assertions, 0 échec.

Détail des cas validés :
1. `test_transition_autorisee_avec_permission_met_a_jour_statut_et_audite` : **PASS**
2. `test_transition_interdite_par_la_topologie_est_rejetee_avec_domain_exception` : **PASS**
3. `test_transition_refusee_si_l_acteur_n_a_pas_la_permission_requise` : **PASS**
4. `test_separation_des_fonctions_quatre_yeux_interdit_l_auto_approbation` : **PASS** (HTTP 403 levée)
5. `test_second_agent_distinct_peut_approuver_la_correction_quatre_yeux` : **PASS**
6. `test_dossier_en_cours_conserve_sa_version_de_workflow_applicable` : **PASS** (rejet en V1 car terminal)
7. `test_nouveau_dossier_v2_autorise_la_revocation_prevue_en_v2` : **PASS** (transition V2 validée)
8. `test_idempotence_si_deja_dans_l_etat_cible` : **PASS** (mutation silencieuse sans doublon)
9. `test_isolation_facultaire_bloque_un_agent_d_une_autre_faculte` : **PASS** (rejet de l'agent externe)

### 4.2 Non-régression globale sur les workflows existants
```bash
php artisan test --filter="WorkflowValidationConfigurableTest|InscriptionCorrection4YeuxTest|CentreTravailMutationTest|EvaluationWorkflowServiceTest"
```
- **Résultat :** 44 tests réussis, 141 assertions, 0 régression.

### 4.3 Formatage et conformité
```bash
vendor/bin/pint --dirty
```
- **Résultat :** 100% conforme PSR-12 / Pint.

---

## 5. RÉSULTATS SÉCURITÉ, CONCURRENCE ET AUDITABILITÉ

- **Séparation des fonctions (4 yeux) :** Validée et inviolable côté serveur (interdiction formelle de l'auto-approbation).
- **Isolation facultaire :** Respectée strictement par scoping serveur via `$acteur->faculteRestreinteId()`.
- **Prévention TOCTOU :** Verrou pessimiste `lockForUpdate` sur chaque mutation transactionnelle.
- **Auditabilité :** Chaque transition produit un enregistrement dans `HistoriqueAcademique` avec horodatage, ID de l'acteur, état source, état cible, version et métadonnées.

---

## 6. CONDITIONS DE CLÔTURE ET VERDICT

- Tous les critères d'acceptation du Lot 01 sont démontrés et vérifiés par des tests reproductibles.
- Aucune régression sur les workflows préexistants.
- **Verdict du Lot 01 :** **PASS**
- **Conditions de passage au Lot suivant :**
  - Le Lot 01 étant validé, la chaîne de dépendance autorise l'ouverture de **LOT 02 — Centre de notifications unifié**.
