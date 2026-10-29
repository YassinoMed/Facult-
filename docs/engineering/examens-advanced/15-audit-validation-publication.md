# AUDIT TECHNIQUE & ARCHITECTURE — EA-EXAMENS-06
## VALIDATION, PUBLICATION OFFICIELLE & SCELLEMENT (MENU 10)

**Projet :** UniRegistre — Laravel  
**Périmètre :** Menu 10 — Validation et publication (`/admin/evaluation/examens/publication`)  
**Date :** 28 Octobre 2026  
**Protocole :** AUDIT → BASELINE → CARTOGRAPHIE → ARCHITECTURE → PLAN → IMPLÉMENTATION → TESTS → AUTO-PASS  

---

## 1. Contexte & Enjeux Métier

Dans le cycle de vie des examens universitaires, la publication officielle constitue le jalon juridique et opérationnel le plus critique :
1. **Règle D-05 — Publication Officielle Immuable (R-27 à R-30) :**
   - La publication fait basculer l'examen du statut ordonnancé (`scheduled`) vers le statut officiel scellé (`published`).
   - Un horodatage certifié `published_at` est apposé.
   - Les convocations et listes d'émargement sont déverrouillées pour les étudiants et surveillants (Règle D-06).
   - Une fois publié, le planning ne peut être altéré silencieusement : toute modification ultérieure requiert une procédure d'amendement formelle ou de révision avec traçabilité intégrale dans le journal d'audit.
2. **Audit de Conformité Préalable Obligatoire (Gate de Publication) :**
   - Aucun examen ne peut être publié sans avoir validé à 100% l'ensemble des contraintes dures :
     - Toutes les épreuves doivent avoir une salle assignée et active.
     - Les capacités utiles avec espacement physique (Décision D-04) doivent couvrir l'effectif convoqué.
     - Les ratios réglementaires de surveillants doivent être atteints (au minimum 2 surveillants, ratio étudiants respecté).
     - Aucun conflit horaire d'enseignant, de groupe ou de salle ne doit subsister.
     - La population doit être stabilisée et scellée.
3. **Scellement Numérique & Intégrité Cryptographique :**
   - Calcul d'une empreinte cryptographique SHA-256 du planning complet lors du scellement.
   - Permet de détecter immédiatement toute dérive ou altération post-publication.
4. **Ergonomie Enterprise & Diagnostic en Direct :**
   - Fournir aux gestionnaires académiques une visibilité instantanée sur l'état de préparation de chaque examen.
   - Indiquer clairement les causes de blocage éventuelles (ex: "Salle manquante sur Épreuve #EPR-01", "Surveillance incomplète : 1/2 requis") avec accès direct pour résolution.
   - Bouton de publication avec confirmation solennelle pour les examens conformes.

---

## 2. Cartographie des Composants Existants

| Composant | Fichier | Rôle actuel |
| :--- | :--- | :--- |
| `Examen` (Modèle) | `app/Modules/Evaluation/Models/Examen.php` | Modèle d'examen avec statuts (`scheduled`, `published`), relation avec `Epreuve`, `SessionExamen`, `Programme`. |
| `ExamenService` | `app/Modules/Evaluation/Services/ExamenService.php` | Méthode `transiter($examen, $cible)` avec validation bloquante via `auditerSessionExamens()`. |
| `ExamScheduleEngineService` | `app/Modules/Schedule/Services/ExamScheduleEngineService.php` | Moteur CSP et méthode `auditerSessionExamens(Examen $examen)` vérifiant les contraintes bloquantes. |
| `ExamenAdvancedMenuController` | `app/Modules/Evaluation/Controllers/ExamenAdvancedMenuController.php` | Méthode `publication()` fournissant les données de la vue. |
| `publication.blade.php` | `resources/views/modules/evaluation/examens-advanced/publication.blade.php` | Vue du menu 10 à moderniser au standard Enterprise. |

---

## 3. Cibles & Innovations Enterprise (EA-EXAMENS-06)

1. **Création d'un Service d'Orchestration Spécialisé :**
   - `ExamPublicationOrchestratorService`
   - `auditerPublication(?int $anneeId, ?int $faculteId, ?string $statutFiltre, ?string $recherche)` :
     - KPI en direct : Total examens, en attente, scellés/publiés, épreuves publiées, épreuves ordonnancées, taux de publication (%), examens bloqués par non-conformité.
     - Liste enrichie des examens avec diagnostic individuel de pré-publication.
   - `diagnostiquerExamen(Examen $examen)` :
     - Exécute `ExamScheduleEngineService::auditerSessionExamens($examen)` et synthétise le résultat (`pret_a_publier`, `bloque_anomalies`, `deja_publie`).
     - Liste les anomalies bloquantes précises si non conforme.
   - `calculerEmpreintePublication(Examen $examen)` :
     - Empreinte SHA-256 déterministe basée sur le code de l'examen, la session, et la séquence ordonnée des épreuves (dates, créneaux, salles, matières, effectifs).
   - `publierExamen(Examen $examen, int $userId)` :
     - Exécute la transition vers `published`, horodate, met à jour les épreuves associées, et journalise l'audit.

2. **Modernisation UI Menu 10 (`publication.blade.php`) :**
   - 4 Cartes KPI animées : En attente de publication, Officiellement publiés, Épreuves publiées, Taux de conformité & couverture.
   - Bannière réglementaire Règle D-05 (publication officielle immuable, R-27 à R-30).
   - Filtres dynamiques : Année universitaire, Statut (`scheduled`, `published`, tous), Recherche (code / libellé).
   - Tableau enrichi avec colonne de diagnostic de conformité :
     - Badge vert "Conforme (Prêt à publier)" + bouton d'action solennel "Publier & Sceller".
     - Badge rouge "Non conforme (X conflits)" avec tiroir de détail listant les causes exactes de blocage.
     - Badge vert foncé "Officiellement publié & scellé" avec horodatage certifié, empreinte SHA-256 et lien direct vers les convocations.
   - Panneau Enterprise détaillant le protocole de scellement et d'intégrité académique.

3. **Sécurité, Isolation & Non-Régression :**
   - Isolation stricte des facultés (`User::faculteRestreinteId()`).
   - Garantie zéro écriture sur les requêtes de consultation GET.
   - Préservation stricte de la compatibilité avec `ExamensAdvancedPagesRegressionTest` et tous les tests existants.
   - Suite Feature dédiée `ExamPublicationEnterpriseTest`.

---

## 4. Plan d'Exécution par Lots

- **Lot 1 :** Création du service `ExamPublicationOrchestratorService.php`.
- **Lot 2 :** Mise à jour du contrôleur `ExamenAdvancedMenuController.php` (`publication()`).
- **Lot 3 :** Modernisation de la vue `publication.blade.php`.
- **Lot 4 :** Création de la suite de tests `ExamPublicationEnterpriseTest.php`.
- **Lot 5 :** Exécution des tests, `vendor/bin/pint --dirty`, `npm run build`, et régression complète.
- **Lot 6 :** Rapport final `16-rapport-final-ea-examens-06.md` et validation.
