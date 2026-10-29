# LOOP-SMART-TIMETABLES-PHASE-C — Audit Qualité, Priorités & Sécurité Consolidée (module Schedule)

**Date :** 24/10/2026 · **Protocole :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Plan validé :** Phases A (contraintes) → B (moteur de génération) → C (qualité, priorités & sécurité) → D (extensions)
**Périmètre de ce LOOP :** Phase C — Résolution des réserves R1 et R2 de la Phase B, harmonisation de l'audit indépendant de qualité, ordonnancement par priorité métier dans le générateur CSP, durcissement et scoping des contrôleurs de génération et de disponibilités.

---

## 0. Périmètre et principe directeur

La Phase B avait branché le moteur de génération CSP (`ScheduleGeneratorService`) sur les pondérations déclarées et les préférences horaires `souhaitee`. Cependant, deux réserves d'intégrité et d'homogénéité restaient documentées :

1. **R1 (Résolue en C1)** — L'audit indépendant `ScheduleConstraintChecker` n'évaluait les préférences `souhaitee` que pour les **enseignants** ; les préférences déclarées pour les **salles** et les **groupes** étaient ignorées après coup, risquant de sous-estimer la conformité du planning.
2. **R2 (Résolue en C2)** — Les demandes `SeanceDemande` possédaient un attribut `priorite` (1 à 10, ex: CM = 8, TD = 7, TP = 6), mais le moteur ne triait les demandes que sur la taille du domaine initial (MRV), sans prendre en compte la priorité métier.
3. **C3** — Sécurité, contrôle d'accès et scoping multi-facultés sur les surfaces d'accès HTTP créées pour la génération (`ScheduleGenerationController`) et les disponibilités (`ScheduleAvailabilityController`).

---

## 1. LAN — Baseline et constats initiaux

- **Baseline avant Phase C** : 267 tests exécutés dans le module Schedule (265 OK, 2 skipped MySQL opt-in, 0 échec).
- **Constat R1** : Dans `ScheduleConstraintChecker.php`, seul `$parEnseignant` était parcouru pour rechercher les souhaits `type = 'souhaitee'`. Les souhaits liés à une salle (`room_id`) ou à un groupe (`group_id`) n'étaient jamais pénalisés en cas de placement hors souhait. De surcroît, le code effectuait une requête par enseignant.
- **Constat R2** : Dans `ScheduleGeneratorService.php:338`, `$demandes->sortBy(fn ($d) => count($domainesInitiaux[$d->id]))` ne considérait pas `priorite`.
- **Constat C3** : Les contrôleurs vérifient `can:schedules.view` / `can:schedules.manage` et appliquent `peutAccederFaculte()` avec réponse 404 en cas d'IDOR ou tentative de manipulation cross-faculté.

---

## 2. DEV — Implémentations réalisées

1. **C1 : Harmonisation et optimisation des préférences dans `ScheduleConstraintChecker` :**
   - Préchargement groupé de l'ensemble des souhaits (`ScheduleAvailability::where('type', 'souhaitee')`) pour les enseignants, salles et groupes concernés en **une seule requête SQL unifiée** (zéro N+1).
   - Évaluation et pénalisation souple (`TimetableConstraint::SOFT_TEACHER_PREFERENCE`) pour les séances programmées hors des créneaux souhaités des salles et des groupes.
2. **C2 : Ordonnancement combiné MRV + Priorité métier dans `ScheduleGeneratorService` :**
   - Tri initial des demandes combinant la taille croissante du domaine (MRV heuristique CSP) et la priorité décroissante :
     ```php
     $demandesOrdonnees = $demandes->sort(function (SeanceDemande $a, SeanceDemande $b) use ($domainesInitiaux) {
         $tailleA = count($domainesInitiaux[$a->id]);
         $tailleB = count($domainesInitiaux[$b->id]);

         if ($tailleA !== $tailleB) {
             return $tailleA <=> $tailleB;
         }

         return $b->priorite <=> $a->priorite;
     })->values();
     ```
3. **C3 : Validation de la sécurité multi-facultés et des garde-fous :**
   - Protection 404 stricte contre les accès IDOR sur la génération (`/admin/emplois-du-temps/{planning}/generation`) et les indisponibilités (`/admin/emplois-du-temps/indisponibilites/{plage}`).
   - Préservation stricte de la règle : aucune génération automatique sur un planning publié, verrouillé ou archivé.

---

## 3. Tests et validation

- **Nouveaux tests créés** : `tests/Feature/ScheduleSmartTimetablesPhaseCTest.php` (7 tests, 19 assertions, 100% verts).
  - `test_audit_detecte_penalite_souple_sur_preference_salle` : PASS
  - `test_audit_ne_penalise_pas_creneau_conforme_au_souhait_salle` : PASS
  - `test_audit_detecte_penalite_souple_sur_preference_groupe` : PASS
  - `test_generateur_ordonnance_par_priorite_a_taille_de_domaine_egale` : PASS
  - `test_admin_faculte_hors_perimetre_recoit_404_sur_generation` : PASS
  - `test_admin_faculte_dans_perimetre_peut_consulter_generation` : PASS
  - `test_admin_faculte_ne_peut_pas_modifier_indisponibilite_hors_perimetre` : PASS
- **Tests de performance et absence de N+1** : `ScheduleGenerationPerformanceBTest` (2/2 verts, nombre de requêtes constant quel que soit le volume de séances évaluées).
- **Batterie complète Schedule** : **274 tests exécutés, 272 OK, 2 skipped, 0 échec** (1203 assertions, durée : 32.9s).
- **Formatage du code** : `vendor/bin/pint --dirty` validé sans avertissement.

---

## 4. Verdict

**VALIDÉ (PASS_WITH_RESERVATIONS)**
- R1 (audit préférences salles/groupes) et R2 (priorité métier) résolues.
- Réserves conservées : absence de tests multi-processus MySQL réels (concurrence SQLite isolée) et absence de pilote navigateur E2E.
