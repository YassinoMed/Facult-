# EA-EXAMENS-03 — Phase 0 : Audit Planification des Examens & Allocation des Salles (Menus 4 & 5)

**Projet :** UniRegistre — Laravel  
**Module :** Examens Advanced — Menus 4 & 5 (`/admin/evaluation/examens/planification` et `/admin/evaluation/examens/salles`)  
**Date :** 28 Octobre 2026  
**Branche :** `feature/admin-modifier-etudiant`  
**HEAD Git :** `38ef14933de9980a4c62cb805bd19bc86c2b3081`  

---

## 1. Périmètre & Objectifs de la Boucle EA-EXAMENS-03

La boucle **`EA-EXAMENS-03`** s'attaque au cœur opérationnel des examens : l'ordonnancement algorithmique des épreuves et l'affectation optimisée des capacités d'accueil.

### 1.1 Objectifs Clés
1. **Génération & Comparaison des 3 Scénarios :**
   - Scénario 1 : **Équilibré** (répartition harmonieuse dans la session, espacement suffisant pour les révisions).
   - Scénario 2 : **Compact** (optimisation du calendrier, concentration des créneaux pour libérer les locaux rapidement).
   - Scénario 3 : **Amphis Prioritaires** (privilégie les très grands espaces pour maximiser l'espacement et simplifier la surveillance).
   - Calcul des scores de qualité objectifs (0 à 100 pts) et recommandation déterministe argumentée.
2. **Contraintes Dures Incompressibles :**
   - **Anti-collision groupe/étudiant :** Zéro chevauchement pour un même groupe pédagogique.
   - **Anti-collision salle :** Zéro double affectation d'une salle sur un même créneau horaire (y compris pour les salles partagées inter-facultés).
   - **Ratio anti-fraude 50% (Décision D-04) :** Capacité utile examen = `floor(capacite * 0.5)`. Seule cette capacité est opposable.
   - **Capacité inconnue bloquante :** Une salle sans capacité ou avec capacité <= 0 est rejetée pour toute planification.
   - **Disponibilités & Horaires :** Respect des plages d'ouverture du bâtiment et des disponibilités déclarées (`ScheduleAvailability`).
3. **Ergonomie des Vues Menus 4 & 5 :**
   - Menu Planification : Comparateur visuel des 3 scénarios, matrice de conformité, liste détaillée des placements d'épreuves avec indicateurs de capacité.
   - Menu Salles : KPI de gouvernance des capacités (total amphis/salles, capacité théorique vs capacité utile 50%, salles à capacité manquante bloquantes, indicateur d'état d'opposabilité).
4. **Cloisonnement & Sécurité :**
   - Respect strict du périmètre facultaire (`faculteRestreinteId`) tout en vérifiant les collisions globales pour les salles partagées inter-facultés.
   - Zéro mutation de la base de données en mode consultation (GET).

---

## 2. Inventaire Technique Existant

### 2.1 Moteur `ExamScheduleEngineService`
- Localisation : `app/Modules/Schedule/Services/ExamScheduleEngineService.php`
- Constante : `RATIO_CAPACITE_EXAMEN_DEFAUT = 0.50`
- Méthode `capaciteUtileExamen(Room $salle, float $ratio = 0.50): int`
- Méthode `genererScenariosPlanning(Examen $examen, array $options = []): array`
- Méthode `appliquerScenario(Examen $examen, string $scenarioKey, int $userId): array`
- Détection des conflits durs et production d'un tableau comparatif avec scoring.

### 2.2 Service `ExamRoomAssignmentService`
- Localisation : `app/Modules/Evaluation/Services/ExamRoomAssignmentService.php`
- Gestion unitaire des affectations et simulations multi-salles.
- Respect du ratio et des verrous de concurrence transactionnels (`lockForUpdate`).

### 2.3 Contrôleur `ExamenAdvancedMenuController`
- Méthode `planification(Request $request)` : génère les scénarios pour l'examen sélectionné.
- Méthode `salles(Request $request)` : liste les salles avec pagination et recherche.

---

## 3. Axes d'Amélioration & Plan d'Action

1. **Création d'un Service de Synthèse Enterprise : `ExamPlanificationSallesOrchestratorService` :**
   - Fournit un audit global des salles et capacités (KPIs, ventilation par bâtiment, détection des salles bloquantes).
   - Enrichit la présentation des scénarios de planification avec analyse comparative et métriques d'espacement.
2. **Modernisation de la Vue `planification.blade.php` :**
   - Tableau de bord de comparaison des 3 scénarios avec jauge de score, métriques et mise en valeur du scénario recommandé.
   - Liste des placements détaillés (matière, date, heure, salle, effectif requis vs capacité utile 50%).
   - Messages d'explication clairs sur les contraintes dures rencontrées le cas échéant.
3. **Modernisation de la Vue `salles.blade.php` :**
   - 4 KPI cards : Salles répertoriées, Capacité utile d'examen (50%), Salles validées pour examens, Salles bloquantes (sans capacité).
   - Filtrage par bâtiment et recherche textuelle.
   - Jauge graphique de ratio 50% par salle et badge d'opposabilité.
4. **Création de la Suite de Tests Dédiée : `ExamPlanificationSallesEnterpriseTest` :**
   - Test des permissions et sécurité anti-IDOR.
   - Test de génération des 3 scénarios (équilibré, compact, amphis).
   - Test du calcul du ratio anti-fraude 50% sur les salles.
   - Test du blocage des salles à capacité nulle ou indéterminée.
   - Test de détection des collisions de salles et de groupes.
   - Test d'isolation facultaire des listes.
5. **Non-régression complète :**
   - Exécution de la suite complète de 115+ tests pour garantir zéro régression.
