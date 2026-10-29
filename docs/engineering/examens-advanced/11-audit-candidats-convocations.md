# EA-EXAMENS-04 — Phase 0 : Audit Candidats, Émargement & Convocations

**Projet :** UniRegistre — Laravel  
**Module :** Examens Advanced — Menus Éligibilité (`/admin/evaluation/examens/eligibilite`), Simulation & Émargement (`/admin/evaluation/examens/simulation`), et Convocations (`/admin/evaluation/examens/convocations`)  
**Date :** 28 Octobre 2026  
**Branche :** `feature/admin-modifier-etudiant`  
**HEAD Git :** `38ef14933de9980a4c62cb805bd19bc86c2b3081`  

---

## 1. Périmètre & Objectifs de la Boucle EA-EXAMENS-04

La boucle **`EA-EXAMENS-04`** sécurise l'intégralité de la chaîne d'admissibilité des candidats, de l'attribution des anonymats, des feuilles d'émargement d'examen et des convocations officielles :

1. **Validation de Population & Anonymat Déterministe :**
   - Calcul de la liste des candidats admissibles basée sur l'inscription pédagogique et les critères LMD.
   - Figeage du snapshot de population (`population_validated_at`, `population_effectif`, `population_hash`).
   - Attribution des numéros anonymes stables (`AN-0001`, `AN-0002`, ...) — jamais la CIN ou le numéro de sécurité sociale.
   - Détection temps réel de la **dérive de population** (`populationModifiee`) si des inscriptions sont ajoutées ou retirées après validation du snapshot.
   - Revalidation serveur idempotente avec mise à jour du snapshot et préservation des numéros anonymes existants.

2. **Génération des Feuilles d'Émargement PDF Sécurisées :**
   - Mise en page officielle selon le layout partagé `pdf/liste`.
   - Génération par épreuve complète ou segmentée par salle.
   - Colonnes réglementaires : Rang, N° Anonyme, N° Étudiant/Matricule, Nom & Prénom, Groupe, Salle, Place attribuée, Émargement / Signature.
   - Traçabilité sans PII dans `integration_logs` et table `evaluation_documents_generes`.

3. **Gouvernance des Convocations Officielles :**
   - Convocations individuelles téléchargeables en PDF avec référence unique (`CNV-YYYY-XXXXXX`).
   - Intégration du dispositif anti-fraude : **QR code de contrôle d'authenticité** scannable aux entrées des centres d'examen.
   - Prévisualisation et génération par lots (batch) via `EvaluationDocumentBatchService`.
   - Zéro fuite de données et cloisonnement facultaire strict via `faculteRestreinteId`.

---

## 2. Inventaire Technique Existant

### 2.1 Services Existants
- **`PreparationExamenService` :**
  - `validerPopulation(Epreuve $epreuve, int $auteurId)` : fige le snapshot de population et attribue les numéros anonymes.
  - `populationModifiee(Epreuve $epreuve): bool` : compare le hash SHA-256 actuel au snapshot enregistré.
  - `verifierEpreuve(Epreuve $epreuve)` : retourne les conflits durs, avertissements et dérive de population.
- **`ConvocationService` :**
  - `listeEmargement(Epreuve $epreuve, ?Room $salleFiltre = null, bool $apercu = false)` : génère le PDF d'émargement.
  - `convocation(Candidat $candidat)` : génère la convocation individuelle PDF.
- **`EvaluationDocumentBatchService` :**
  - `previsualiser(array $criteres, User $utilisateur)` : prévisualisation du volume documentaire.
  - `genererArchive(array $criteres, User $utilisateur)` : génération de l'archive ZIP.

### 2.2 Modèles Clés
- **`Candidat` :** `epreuve_id`, `etudiant_id`, `salle_id`, `place`, `numero_anonyme`, `statut` (`non_pointe`, `present`, `absent`, `dispense`).
- **`Epreuve` :** `population_validated_at`, `population_effectif`, `population_hash`, `statut`.
- **`DocumentExamenGenere` :** `epreuve_id`, `type` (`emargement`, `convocation`), `nom_fichier`, `chemin_stockage`, `hash_sha256`.

---

## 3. Plan d'Implémentation

1. **Création du Service d'Orchestration : `ExamCandidatsConvocationsOrchestratorService` :**
   - Centralise l'audit d'éligibilité, l'état des snapshots, les dérives de population et le statut des convocations.
   - Gère le calcul des métriques de préparation pour les candidats et les convocations.
2. **Modernisation des Vues Blade :**
   - `eligibilite.blade.php` : ajout de l'indicateur d'état du snapshot (Validé / Dérive détectée / En attente), bouton de validation directe de la population, liste paginée avec numéros anonymes masqués/affichés selon permission.
   - `convocations.blade.php` : KPI de couverture des convocations, affichage des QR codes anti-fraude, boutons de téléchargement unitaire et d'export par lot.
   - `simulation.blade.php` : intégration de la validation de population et accès direct aux listes d'émargement PDF par salle.
3. **Contrôleur `ExamenAdvancedMenuController` :**
   - Injection du service orchestrateur.
   - Enrichissement des méthodes `eligibilite()`, `simulation()` et `convocations()`.
4. **Suite de Tests Feature Dédiée : `ExamCandidatsConvocationsEnterpriseTest` :**
   - Permissions et restrictions d'accès.
   - Validation de population et attribution de numéros anonymes stables (`AN-XXXX`).
   - Détection de la dérive de population (`populationModifiee = true`).
   - Revalidation et mise à jour idempotente du hash.
   - Génération de liste d'émargement PDF et convocation individuelle.
   - Cloisonnement facultaire et garantie zéro écriture en consultation.
5. **Non-régression complète :**
   - Validation de l'ensemble de la suite (126+ tests).
