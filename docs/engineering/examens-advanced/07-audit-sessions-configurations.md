# EA-EXAMENS-02 — Phase 0 : Audit des Sessions d'Examen et Configurations (Menus 2 & 3)

**Projet :** UniRegistre — Laravel  
**Module :** Examens Advanced — Menus 2 & 3  
**Date :** 28 Octobre 2026  
**Branche :** `feature/admin-modifier-etudiant`  
**HEAD Git :** `38ef14933de9980a4c62cb805bd19bc86c2b3081`  

---

## 1. Périmètre et Objectifs de la Boucle EA-EXAMENS-02

La boucle **`EA-EXAMENS-02`** fait évoluer les Menus 2 et 3 vers un niveau Enterprise en formalisant la gouvernance des cycles d'examens et la configuration académique des épreuves :

1. **Gouvernance des Sessions (Menu 2 — `/admin/evaluation/examens/sessions`) :**
   - Typologie des sessions : `principale`, `rattrapage`, `exceptionnelle`.
   - Traçabilité et rattachement des rattrapages à la session principale d'origine (`session_origine_id`).
   - Cohérence des dates de session (début <= fin, incluses dans l'année universitaire).
   - Machine à états serveur : `draft` → `open` → `ongoing` → `closed` → `locked` → `archived`.
   - Contrôles de readiness obligatoires avant passage en `open`.
   - Verrouillage officiel (`verrouillee_at`) garantissant l'immutabilité des sessions clôturées.
   - Mode d'organisation : **Mode A** (groupes pédagogiques) vs **Mode B** (groupes temporaires construits depuis l'éligibilité).

2. **Gouvernance des Configurations d'Examen (Menu 3 — `/admin/evaluation/examens/configurations`) :**
   - **Règle R-05 & Décision D-02 :** Limite de **1 à 3 modules / ECUE** par configuration d'examen.
   - **Modalités d'épreuve :** Épreuves distinctes (standard) vs Épreuve combinée (validation explicite).
   - **Cohérence académique :** Respect strict de la hiérarchie Programme → Niveau → Semestre LMD → Groupe → Faculté.
   - **Sécurité et cloisonnement :** Filtrage systématique par périmètre facultaire (`faculteRestreinteId`).

---

## 2. Inventaire Technique Existant

### 2.1 Modèle `SessionExamen`
- Table : `evaluation_sessions`
- Champs clés : `annee_universitaire_id`, `session_origine_id`, `code`, `nom`, `type`, `date_debut`, `date_fin`, `statut`, `regles`, `verrouillee_at`.
- Constantes : `TYPES = ['principale', 'rattrapage', 'exceptionnelle']`, `STATUTS = ['draft', 'open', 'ongoing', 'closed', 'locked', 'archived']`.
- Matrice des transitions : transitions autorisées codifiées dans `TRANSITIONS`.
- Manques constatés :
  - Pas de scope `pourFaculteId` sur le modèle.
  - Absence d'accesseur formel pour le Mode d'organisation (`mode_a` vs `mode_b`).
  - Validation des contraintes de dates et de session d'origine non unifiée.

### 2.2 Modèle `Examen`
- Table : `evaluation_examens`
- Champs clés : `session_id`, `annee_universitaire_id`, `programme_id`, `niveau_id`, `semestre_lmd_id`, `groupe_id`, `libelle`, `statut`.
- Relations : `epreuves()`, `session()`, `programme()`.
- Scope existant : `scopePourFaculteId(Builder $query, int $faculteId)`.

### 2.3 Service `SessionExamenService`
- Méthode `readiness(SessionExamen $session)` : contrôle les épreuves sans salle, sans surveillant, sans candidat et en sur-capacité.
- Méthode `transiter(SessionExamen $session, string $cible)` : applique les transitions autorisées et bloque l'ouverture si la readiness échoue.

---

## 3. Plan d'Implémentation de EA-EXAMENS-02

1. **Service Métier :** Créer `ExamSessionConfigurationService` pour auditer et valider les sessions et configurations d'examen.
2. **Modèle `SessionExamen` :** Ajouter `scopePourFaculteId`, accesseurs `modeOrganisation()`, `modeOrganisationLabel()`, et vérification de conformité.
3. **Contrôleur `ExamenAdvancedMenuController` :** Injecter le service et enrichir les méthodes `sessions()` et `configurations()`.
4. **Vues Blade :** Moderniser `sessions.blade.php` et `configurations.blade.php` avec les indicateurs de gouvernance, règles 1-3 modules et readiness en temps réel.
5. **Suite de Tests Dédiée :** Créer `tests/Feature/ExamSessionConfigurationEnterpriseTest.php`.
6. **Validation Globale & Non-Régression :** Exécuter la suite complète des tests d'examens (103+ tests).
