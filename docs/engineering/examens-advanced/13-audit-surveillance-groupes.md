# AUDIT TECHNIQUE & ARCHITECTURE — EA-EXAMENS-05
## SURVEILLANCE, RATIOS D'ENCADREMENT & GROUPES D'EXAMEN (MENUS 5 & 8)

**Projet :** UniRegistre — Laravel  
**Périmètre :** Menus 5 & 8 — Groupes d'examen (`/admin/evaluation/examens/groupes`) et Surveillance (`/admin/evaluation/examens/surveillance`)  
**Date :** 28 Octobre 2026  
**Protocole :** AUDIT → BASELINE → CARTOGRAPHIE → ARCHITECTURE → PLAN → IMPLÉMENTATION → TESTS → AUTO-PASS  

---

## 1. Contexte & Enjeux Métier

Dans le système d'examen universitaire UniRegistre, la tenue des épreuves requiert un encadrement humain rigoureux, équitable et auditable :
1. **Ratios d'encadrement réglementaires :**
   - Chaque salle d'examen requiert au minimum un surveillant principal et des surveillants adjoints selon le ratio d'étudiants (par défaut 1 surveillant pour 35 à 50 étudiants, et un renfort pour les grands amphithéâtres).
   - Une épreuve sous-surveillée compromet la régularité du concours ou de l'examen et doit être signalée de façon bloquante ou en alerte majeure avant le verrouillage.
2. **Détection et prévention des conflits de surveillance :**
   - Aucun enseignant ne peut être planifié sur deux épreuves simultanées.
   - Aucun enseignant ne peut être affecté si un cours régulier (`ScheduleSlot`) a lieu sur le même créneau.
   - Détection des enseignants inactifs ou en surcharge (plafonnement d'équité de charge).
3. **Organisation des cohortes et groupes d'examen (Menu 5) :**
   - Mode A : Convocation par groupes pédagogiques de TD/TP.
   - Mode B : Cohortes spécifiques redécoupées selon les capacités utiles des amphithéâtres à 50% (Décision D-04).
   - Détection des groupes sans capacité ou en sur-effectif.

---

## 2. Cartographie des Composants Existants

| Composant | Fichier | Rôle actuel |
| :--- | :--- | :--- |
| `Surveillance` (Modèle) | `app/Modules/Evaluation/Models/Surveillance.php` | Modèle Eloquent sur `evaluation_surveillants` (rôles `principal`, `adjoint`, `reserve` ; statuts `assigne`, `confirme`, `remplace`, `annule`). |
| `SurveillanceService` | `app/Modules/Evaluation/Services/SurveillanceService.php` | Moteur central d'affectation, remplacement, simulation, émargement et cockpit. |
| `SurveillanceConstraintResolver` | `app/Modules/Evaluation/Services/SurveillanceConstraintResolver.php` | Résolution hiérarchique des contraintes (Session > Faculté > Année > Système). |
| `ContraintesSurveillance` | `app/Modules/Evaluation/Services/ContraintesSurveillance.php` | DTO immuable avec formules `besoinActifs()` et `besoinSalle()`. |
| `ExamenAdvancedMenuController` | `app/Modules/Evaluation/Controllers/ExamenAdvancedMenuController.php` | Méthodes `groupes()` et `surveillance()`. |
| Vues Blade | `groupes.blade.php` et `surveillance.blade.php` | Vues de consultation à enrichir au niveau Enterprise. |

---

## 3. Cibles & Innovations Enterprise (EA-EXAMENS-05)

1. **Création d'un Service d'Orchestration Spécialisé :**
   - `ExamSurveillanceGroupesOrchestratorService`
   - Méthode `auditerSurveillance(?int $sessionId, ?int $faculteId, ?string $dateFiltre)` :
     - KPI en direct : Total gardes, confirmées, en attente, remplacements, épreuves sous-surveillées, taux de couverture.
     - Diagnostic d'encadrement par épreuve : calcul du besoin réglementaire vs surveillants effectifs, statut (`conforme`, `sous_surveillee`, `sans_surveillant`), et conflits horaires éventuels.
   - Méthode `auditerGroupes(?int $faculteId, ?string $recherche)` :
     - KPI des cohortes : Groupes répertoriés, étudiants inscrits, capacité nominale totale, groupes sans capacité, groupes surchargés (> 100%).
2. **Modernisation UI Menu 8 (`surveillance.blade.php`) :**
   - 4 Cartes KPI animées : Gardes actives, Taux de couverture d'encadrement, Épreuves sous-surveillées (alerte), et Enseignants mobilisés.
   - Barre de filtres : Session, Date, Rôle, Statut, Recherche.
   - Tableau enrichi : Détail par épreuve avec badge de conformité du ratio (ex. "2 / 2 requis — Conforme" vs "1 / 3 requis — Sous-surveillée").
   - Panneau de règles de garde et remplacement.
3. **Modernisation UI Menu 5 (`groupes.blade.php`) :**
   - 4 Cartes KPI : Total groupes, Effectif total, Groupes sans capacité (bloquants), et Taux moyen de remplissage.
   - Switch visuel interactif Mode A / Mode B avec explications normatives.
   - Tableau avec barre de progression de saturation et statut de conformité.
4. **Suite de Tests Dédiée `ExamSurveillanceGroupesEnterpriseTest` :**
   - Test des autorisations et cloisonnement facultaire.
   - Test du calcul exact du besoin réglementaire de surveillants.
   - Test de la détection des épreuves sous-surveillées.
   - Test de la détection de conflit horaire pour un surveillant.
   - Test de remplacement d'un surveillant avec préservation de l'historique.
   - Test d'audit des groupes (taux d'occupation, détection des surcharges).
   - Test de garantie zéro écriture sur les routes de consultation.

---

## 4. Plan d'Exécution par Lots

- **Lot 1 :** Création de `ExamSurveillanceGroupesOrchestratorService.php`.
- **Lot 2 :** Mise à jour du contrôleur `ExamenAdvancedMenuController.php` (`surveillance()` et `groupes()`).
- **Lot 3 :** Modernisation des vues `surveillance.blade.php` et `groupes.blade.php`.
- **Lot 4 :** Création de la suite de tests `ExamSurveillanceGroupesEnterpriseTest.php`.
- **Lot 5 :** Exécution des tests, `vendor/bin/pint --dirty`, `npm run build`, et régression complète.
- **Lot 6 :** Rapport final `14-rapport-final-ea-examens-05.md` et validation.
