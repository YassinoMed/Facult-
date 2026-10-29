# EA-EXAMENS-01 — Phase 1 : Architecture Cible & Conception Système

**Projet :** UniRegistre — Laravel  
**Module :** Examens Advanced — Menu 1 (Enterprise Command Center)  
**Date :** 28 Octobre 2026  

---

## 1. Vue d'Ensemble Architecturale

Le Tableau de bord des examens (Menu 1) agit comme un **Command Center d'orchestration et de gouvernance** de haut niveau. Il centralise la télémétrie des 12 menus fonctionnels sans dupliquer leurs règles métier respectives.

```mermaid
graph TD
    UI[Blade View: tableau-de-bord.blade.php] --> CTRL[ExamenAdvancedMenuController]
    CTRL --> ORCH[ExamDashboardOrchestratorService]
    
    subgraph "Couche d'Orchestration (Lecture Seule & Synthèse)"
        ORCH --> PREP[PreparationExamenService]
        ORCH --> CONF[ConflitEpreuveService]
        ORCH --> SCHED[ExamScheduleEngineService]
        ORCH --> ROOM[ExamRoomAssignmentService]
        ORCH --> GOV[EvaluationWorkflowService / Workflow 4-Yeux]
    end

    subgraph "Couche Domaine & Règles Métier"
        PREP --> SESS[Sessions & Épreuves]
        CONF --> DETECT[Détection Conflits Salles/Candidats/Surveillants]
        SCHED --> CSP[Générateur Scénarios & Audit Session]
        ROOM --> CAP[Capacité Réelle vs Examen & Salles]
        GOV --> AUDIT[Journal d'Audit & Double Validation]
    end

    subgraph "Navigation Contextuelle vers les 12 Menus"
        UI -.-> M2[Menu 2: Sessions]
        UI -.-> M3[Menu 3: Configurations]
        UI -.-> M4[Menu 4: Éligibilité]
        UI -.-> M5[Menu 5: Groupes]
        UI -.-> M6[Menu 6: Salles]
        UI -.-> M7[Menu 7: Planification]
        UI -.-> M8[Menu 8: Surveillance]
        UI -.-> M9[Menu 9: Simulation]
        UI -.-> M10[Menu 10: Publication]
        UI -.-> M11[Menu 11: Convocations]
        UI -.-> M12[Menu 12: Historique]
    end
```

---

## 2. Découpage en Couches Responsables

### 2.1 Couche de Présentation (`tableau-de-bord.blade.php`)
- **Rôle :** Affichage synthétique, ergonomie Enterprise, responsive, navigation par onglets/sections.
- **Principes :**
  - Aucune requête SQL ni calcul d'allocation dans la vue.
  - Distinction visuelle claire entre contraintes dures (bloquantes / rouge vif) et avertissements (jaune/orange).
  - États explicites : zéros réels vs données non configurées vs indisponibilités.
  - Boutons de redirection contextuelle vers les menus de résolution avec conservation des filtres (`annee_id`, `faculte_id`).

### 2.2 Couche d'Orchestration (`ExamDashboardOrchestratorService`)
- **Rôle :** Agrégation en lecture seule des indicateurs de préparation, audit des sessions, détection des conflits et gouvernance.
- **Garanties :**
  - **Zéro écriture en base :** Le calcul d'un tableau de bord ou d'une simulation n'insère ni ne met à jour aucune donnée académique.
  - **Cloisonnement Faculté :** Filtrage systématique par `faculte_id` pour éviter toute fuite d'informations inter-facultés.
  - **Fiabilité & Fraîcheur :** Horodatage de calcul, indice de complétude des données.

### 2.3 Moteur de Contraintes & Détection des Conflits
- Famille A : Conflits de Salles (chevauchement, surcapacité, équipement manquant).
- Famille B : Conflits de Candidats (double convocation simultanée, transition impossible).
- Famille C : Conflits de Surveillance (surveillant doublement affecté, indisponibilité).
- Famille D : Conflits de Planning (hors période de session, dépendances non respectées).

### 2.4 Moteur de Score de Préparation
- Décomposition en 7 dimensions académiques pondérées.
- Sortie binaire impérative : **Éligibilité Opérationnelle** (`ELIGIBLE` vs `BLOQUE`).
- Règle d'or : Même avec un score de 95%, si une contrainte dure (ex: salle surchargée de 50 places) est violée, la session est déclarée `BLOQUÉE`.

### 2.5 Gouvernance à 4 Yeux (Workflow de Validation)
- Séparation stricte :
  - **Proposition calculée (`PROPOSED`)** != **Décision approuvée (`APPROVED`)**.
  - **Décision approuvée (`APPROVED`)** != **Planning publié (`PUBLISHED`)**.
- L'approbation nécessite deux acteurs distincts (interdiction de s'auto-approuver).
- Traçabilité complète dans le journal d'audit.
