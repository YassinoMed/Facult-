# LOOP GLOBAL — UX, automatisation métier, optimisation D-04 et AssistantIa

**Projet :** UniRegistre — Laravel 13 / PHP 8.5.11
**Date de la boucle :** 29 octobre 2026
**Branche :** `feature/admin-modifier-etudiant`
**Commit HEAD :** `38ef149 feat(etudiants): modification administrative d'un étudiant depuis le registre`
**Mode :** audit-first, aucun commit/push/reset/stash, aucune migration destructive.

---

## PHASE A — AUDIT ET ÉTAT INITIAL

### A.1 Environnement constaté

| Élément | Valeur constatée | Preuve |
|---|---|---|
| PHP | 8.5.11 (NTS, Homebrew) | `php -v` |
| Laravel | `^13.17` (composer.json) | `composer.json` |
| Node | v26.0.0 | `node -v` |
| Composer | 2.8.6 | `composer --version` |
| Tests | SQLite `:memory:` | `phpunit.xml` |
| Base locale | MySQL `inscription_etudiants` | `.env` (non affiché) |
| Modules | `MODULE_EVALUATION=true` en test et local | `phpunit.xml`, `.env` |
| AssistantIa | `MODULE_AI_ASSISTANT` absent (défaut false) | `.env` |
| Dépôt | Nombreux fichiers modifiés/non suivis préexistants | `git status` |

**Règle appliquée :** aucun fichier local préexistant n'a été nettoyé, réinitialisé ou supprimé.

### A.2 Composants existants (réutilisables)

| Domaine | Composant | État |
|---|---|---|
| Détection / proposition | `GroupeVirtuelExamenService` | Présent |
| Persistance groupe virtuel | `GroupeVirtuelExamen`, `GroupeVirtuelMembre` | Présent |
| Éligibilité | `ServiceEligibiliteExamen`, `CorrespondanceExamenService` | Présent |
| Consultation / exports | `ConsultationExportGroupeVirtuelService` | Présent |
| Préparation / validation population | `PreparationExamenService` | Présent |
| Affectation salles | `ExamRoomAssignmentService` | Présent |
| Gouvernance / publication | `ExamGouvernanceValidationService`, `ExamPublicationOrchestratorService` | Présent |
| Documents professionnels | `ExamDocumentsProfessionnelsService` | Présent |
| D-04 V2 | `ExamDecisionCapacitePermutationService` | Présent (3067 lignes) |
| Tableau de bord examens | `ExamDashboardOrchestratorService` | Présent |
| AssistantIa | Orchestrateur, registre d'outils, workflow d'approbation, RAG, guardrails | Présent |
| Exports | XLSX, CSV, PDF, HTML, impression, ZIP | Présent |

### A.3 Routes et permissions

- Routes principales : `app/Modules/Evaluation/Routes/web.php`.
- Préfixe : `/admin/evaluation/examens/groupes-virtuels`.
- Middleware de groupe : `auth`, `admin`, `secondaire.verrou`, `module:evaluation`.
- **Constat S1 :** les routes `groupes-virtuels` n'ont pas de middleware `can:*` explicite ; la permission est vérifiée dans le service (`verifierPermissionConsultation`) pour la lecture. Les actions d'écriture (`generer-automatique`, `resynchroniser`) ne vérifient que la permission de **lecture** — écart d'autorisation réel.
- D-04 : `epreuves.view` en lecture, `epreuves.update` en écriture (accepter/refuser/annuler).
- Export : `construireRequete()` vérifie la permission et applique l'isolation facultaire ; `construireFicheDetaillee()` vérifie l'accès au groupe et aux groupes sources.
- Numéro d'anonymat : protégé par `notes.compostage.resolve`.

### A.4 UX constatée

| Écran | Constat |
|---|---|
| `groupes-virtuels/index` | 6 KPI, filtres combinables, exports globaux, ZIP, impressions, actions par ligne |
| `groupes-virtuels/show` | Fiche détaillée, alertes, sources, éligibles/exclus, exports/impressions |
| `examens-advanced/groupes` | Panneau D-04 V2, KPI, tableau de décision, propositions récentes |

**Écarts UX identifiés :**
1. KPI calculés sur **200 lignes maximum** (`limit(200)` dans `listerGroupesVirtuels`) → chiffres faux au-delà.
2. `total_eligibles` = somme des effectifs par groupe → **double comptage** d'un étudiant présent dans plusieurs groupes.
3. KPI manquants : exclus, dérives distinctes, anomalies bloquantes, groupes sans salle, dépassements de capacité.
4. Plusieurs cartes KPI non cliquables / non reliées à un filtre.
5. Pas de mémorisation des filtres, pas de choix de pagination, tri limité à `id DESC`.
6. Pas d'action groupée de contrôle de cohérence avec récapitulatif par groupe.
7. D-04 : le service `comparerPropositions()` et la route `comparer` existent, mais aucune comparaison classée de scénarios n'est exposée dans la vue.

### A.5 D-04 — fonctionnement réel constaté

- Jauge utile : `floor(capacité × ratio)` (ratio 0,50 par défaut), via `ExamScheduleEngineService::capaciteUtileExamen()`.
- Variantes générées : salle unique, combinaison de salles, amphithéâtre, permutation avec épreuve partenaire, cohorte.
- Conflits : `ConflitEpreuveService::detecter()` (salle/groupe/candidat/ouverture/cours).
- Application : revalidation serveur, transaction, versionnement via `audit_versioning`, refus si proposition obsolète (empreinte).
- Tests existants : `ExamDecisionCapacitePermutationTest` (22 tests).
- **Écart :** la comparaison de scénarios n'expose ni classement explicite, ni taux d'utilisation, ni places restantes, ni contraintes non résolues.

### A.6 AssistantIa et automatisations

- Outils métier existants : `consulter_eligibilite_examen`, `verifier_regles_evaluation`, `preparer_action_scolarite`, etc.
- Aucun outil dédié aux **groupes virtuels en dérive**, aux **anomalies bloquantes**, ni à la **comparaison D-04**.
- Aucune notification d'examen émise par le module (`EvaluationNotificationService` n'émet qu'un événement `regroupement_virtuel.propose`).
- **Aucune tâche planifiée d'examen** dans `routes/console.php`.
- Workflow d'approbation, permissions, guardrails et journalisation présents.

### A.7 Base de référence tests (avant modification)

Commande :
```bash
php artisan test --compact --filter="ExamGroupeVirtuelTest|ExamGroupesVirtuelsConsultationExportTest|ExamDecisionCapacitePermutationTest"
```
Résultat : **50 tests PASS, 245 assertions, 7,335 s**.

### A.8 Registre des risques

| ID | Risque | Gravité | Traitement prévu |
|---|---|---|---|
| R1 | Écriture autorisée avec permission de lecture | **Majeur** | T1 |
| R2 | KPI tronqués à 200 | Majeur | T2 |
| R3 | Double comptage des éligibles | Moyen | T2 |
| R4 | Absence de contrôle groupé traçable | Moyen | T4 |
| R5 | Comparaison D-04 non exposée | Moyen | T5 |
| R6 | Absence d'audit planifié des dérives | Moyen | T6 |
| R7 | AssistantIa sans outil dérive/D-04 | Faible | T7 |
| R8 | Concurrence MySQL réelle non testable localement | Majeur (environnement) | Documenté, non revendiqué |

### Gate A — Validé

Composants existants identifiés, risques classés, base de référence établie, modifications locales préservées.

---

## PHASE B — PLAN VÉRIFIABLE

| ID | Besoin | Fichiers / services | Dépendances | Risque | Permission | Tests | Critère d'acceptation | Preuve attendue | Statut |
|---|---|---|---|---|---|---|---|---|---|
| **T1** | Interdire l'écriture aux lecteurs | `Routes/web.php`, `GroupeVirtuelExamenController` | Policies existantes | Faible | `epreuves.update` / `evaluations.update` | Feature autorisation | Un utilisateur `view` reçoit 403 sur génération/ré synchronisation ; un utilisateur `update` passe | Tests HTTP 403/302 | À faire |
| **T2** | KPI exacts, non tronqués, non doublés, cliquables | `ConsultationExportGroupeVirtuelService`, `index.blade.php` | T1 | Moyen | `evaluations.view` | Feature KPI (>200 groupes, doublon étudiant, isolation faculté) | Totaux exacts, éligibles uniques, nouveaux KPI, chaque carte mène à un filtre | Assertions sur KPI + HTML | À faire |
| **T3** | Filtres mémorisés, tri, pagination | Service + vue + contrôleur | T2 | Faible | `evaluations.view` | Feature filtres | Tri validé, pagination 15/25/50, mémorisation/restauration sans élargir le périmètre | Assertions session + HTML | À faire |
| **T4** | Contrôle groupé de cohérence | Contrôleur + service + vue | T2 | Moyen | `evaluations.view` | Feature action groupée | Récapitulatif par groupe : conforme / bloqué / en dérive, sans mutation | JSON/session + compteurs | À faire |
| **T5** | Comparaison classée des scénarios D-04 | `ExamDecisionCapacitePermutationService`, contrôleur, vue | Moteur D-04 | Moyen | `epreuves.view` | Feature D-04 | Classement explicite (capacité restante, taux d'utilisation, salles, conflits), aucune officialisation | Assertions sur rang/score | À faire |
| **T6** | Audit planifié idempotent des dérives | Nouvelle commande, `routes/console.php` | T2 | Moyen | N/A (système) | Feature commande | Double exécution = même résultat, verrou, état consultable, aucune publication | Assertions cache + sortie | À faire |
| **T7** | AssistantIa : dérives et scénarios D-04 | Nouvel outil, `ModuleServiceProvider` | T2/T5 | Faible | `assistant_ia.mode_metier` + `epreuves.view` | Feature outil | Réponse scopée faculté, refus hors permission, aucune écriture | Assertions JSON outil | À faire |

### Gate B — Validé

Chaque tâche possède un critère d'acceptation mesurable, une permission explicite, un test et une preuve attendue. Les dépendances sont ordonnées T1 → T2 → T3/T4 → T5/T6 → T7.

---

## PHASE C — SÉCURITÉ TRANSVERSALE (contrôles appliqués)

- Autorisation serveur sur chaque route modifiée.
- Isolation facultaire conservée dans `normaliserFiltres`, `construireRequete`, `verifierAccesGroupeVirtuel`.
- Aucun numéro d'anonymat exposé sans `notes.compostage.resolve`.
- Exports : protection formule CSV/XLSX existante conservée.
- Aucune donnée PII dans les logs de la nouvelle commande (identifiants techniques uniquement).
- Aucune publication automatique.

## PHASE D — TESTS

- Tests ciblés par tâche.
- Non-régression : suites `ExamGroupeVirtuelTest`, `ExamGroupesVirtuelsConsultationExportTest`, `ExamDecisionCapacitePermutationTest`, `ExamPreparationTest`, `ExamPublicationEnterpriseTest`, `ExamGouvernance*`.
- `vendor/bin/pint --dirty`.
- MySQL réel : non exécuté (environnement), documenté comme réserve.

## PHASE E/F/G

Revue du diff, verdict conditionnel et rapport final obligatoire.
