# EA-EXAMENS-01 — Phase 4 : Journal des Corrections & Améliorations

**Projet :** UniRegistre — Laravel  
**Module :** Examens Advanced — Menu 1  
**Date :** 28 Octobre 2026  

---

## 1. Anomalies Détectées et Résolues

### 1.1 Incohérence de Périmètre dans les Cartes KPI Antérieures
- **Problème :** Dans la version initiale de `ExamenAdvancedMenuController::tableauDeBord`, le total des sessions filtrait par `$anneeId`, mais les sous-totaux par statut (`SessionExamen::where('statut', 'draft')->count()`) omettaient le filtre `$anneeId`. De même, les sous-statuts d'examens ne filtraient ni par `$anneeId` ni par `$faculteId`.
- **Cause racine :** Requêtes Eloquent brutes rédigées directement dans le contrôleur sans scope harmonisé.
- **Correction apportée :** Déport de l'agrégation dans `ExamDashboardOrchestratorService::calculerKpisConsolides()`. Toutes les requêtes et filtrages de collections appliquent systématiquement et de manière unifiée `$anneeId` et `$faculteId`.
- **Vérification :** Validé par `ExamDashboardEnterpriseTest::test_kpis_consolides_et_harmonises_avec_filtre_annee`.

### 1.2 Absence de Score de Préparation Explicable
- **Problème :** L'ancien tableau de bord affichait seulement un nombre d'épreuves et de sessions sans indiquer si une session était prête ou bloquée par des contraintes réglementaires.
- **Cause racine :** Manque d'une couche d'orchestration évaluant la conformité globale d'une session.
- **Correction apportée :** Implémentation de `ExamDashboardOrchestratorService::calculerScoresSessions()` décomposant l'évaluation en 7 dimensions académiques pondérées (D1 à D7) sur 100 points, et produisant un verdict d'éligibilité opérationnelle (`ELIGIBLE`, `A_COMPLETER`, `BLOQUE`).
- **Garantie d'intégrité :** La présence d'une seule contrainte dure violée (ex: capacité dépassée, épreuve hors période) classe automatiquement la session comme `BLOQUE`, interdisant la publication quel que soit le score numérique.

### 1.3 Absence de Matrice Unifiée de Conflits
- **Problème :** Les conflits étaient soit dispersés dans les menus spécialisés (Menu 6 pour les salles, Menu 7 pour les créneaux), soit non visibles depuis le point d'entrée Menu 1.
- **Correction apportée :** Création d'une matrice consolidée classée en 4 familles standardisées :
  - **Famille A : Salles & Capacités** (déficit de places, double réservation de salle, capacité non vérifiée, salle inactive).
  - **Famille B : Candidats & Groupes** (chevauchement d'épreuves pour un même groupe).
  - **Famille C : Surveillance** (surveillants manquants, double affectation d'un enseignant).
  - **Famille D : Planning & Calendrier** (heures incohérentes début >= fin, épreuve hors période de session).
- **Navigation contextuelle :** Chaque conflit inclut un identifiant stable, le code de la règle violée (ex: C-HARD-01), et un bouton de résolution pointant directement vers le menu adéquat avec conservation du filtre d'année.

### 1.4 Respect Strict des Règles de Sécurité et Style
- **Zéro écriture :** Vérifié par `test_garantie_zero_ecriture_en_consultation_dashboard`.
- **Formatage Pint :** Exécution de `vendor/bin/pint --dirty` sur les fichiers modifiés et créés (`ExamDashboardOrchestratorService.php`, `ExamDashboardEnterpriseTest.php`), conformité PSR-12 / Laravel Pint vérifiée.
