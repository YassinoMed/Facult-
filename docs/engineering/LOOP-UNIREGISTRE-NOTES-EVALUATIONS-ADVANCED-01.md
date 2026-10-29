# LOOP — Notes & Évaluations (ADVANCED-01)

> Date : 22/10/2026 · Branche `feature/admin-modifier-etudiant` · Protocole : INVENTAIRE → AUDIT →
> SPÉCIFICATION → PLAN → IMPLÉMENTATION PAR LOTS → TESTS → SÉCURITÉ → PERFORMANCE → NON-RÉGRESSION.
>
> **Contrainte préservation** : l'arbre de travail contient un WIP massif préexistant, dont
> **24 fichiers du domaine Notes/Évaluations** (13 modifiés, 11 non suivis : `SaisieNoteService`,
> `NoteEpreuveController`, `NotesEvaluationsSecuriteTest`, vues `notes-saisie`, vues préparation…).
> Ce WIP a **déjà livré** : F-01 (re-saisie d'une validée → `entered`), F-02 (pas de ligne fantôme
> pour un absent), F-07 (périmètre faculté par l'épreuve), F-09 (CSV : type MIME + 2 000 lignes),
> F-12 (verrouillées ignorées signalées), la route `valider-tout`, la navigation clavier de la
> grille de saisie et la traçabilité `$origine` enseignant. Aucun de ces travaux n'est repris ni
> écrasé ; les correctifs ci-dessous sont **additifs**.

## 1. Inventaire (Phase 0)

### 1.1 Architecture du domaine

| Couche | Éléments |
|---|---|
| Module | `app/Modules/Evaluation/` — flag `MODULE_EVALUATION` (`config/modules.php:43`, `false` par défaut, `true` en tests via `phpunit.xml:50`) |
| Contrôleurs | 15 : `NoteEpreuveController`, `DeliberationController`, `MesNotesController` (enseignant), `SessionExamenController`, `EpreuveController`, `ExamenController`, `TypeEvaluationController`, `RegleEvaluationController`, `SurveillanceController`, `AffectationEnseignantController`, `EmploiSurveillanceController`, `ContrainteSurveillanceController`, `IncidentController`, `TableauBordEvaluationController`, `PreparationExamenController` |
| Modèles | 18 : `Epreuve`, `NoteEpreuve`, `NoteEpreuveHistorique`, `Candidat`, `EvaluationCompostage`, `Examen`, `SessionExamen`, `TypeEvaluation`, `RegleEvaluation`, `Deliberation`, `DecisionDeliberation`, `JuryMembre`, `CalculationRun`, `Surveillance*` (4), `DocumentExamenGenere`, `EvaluationIncident` |
| Services calcul | `AcademicCalculationService` (cœur, `app/Services/`), `CalculEvaluationService`, `RegleEvaluationService`, `GradeCheckSuite`, `SaisieNoteService` |
| Workflow | `DeliberationService` (préparer/simuler/decider/valider/publier), `PvDeliberationService`, listener `GenererRelevesPublication` + `GenerateOfficialDocumentsJob` |
| Legacy | `app/Models/Note.php` (table `notes`, source officielle des moyennes, CHECK 0..20), `ArchiveService::saisirNote/modifierNote` (recalcul immédiat semestre+année), `routes/web.php` admin/archives/notes |
| Données liées | `Etudiant`, `InscriptionAcademique` (+ `Semestre` relatifs), `Matiere` → `UniteEnseignement` → `Programme` → `Faculte`, `Group`, affectations ECUE enseignants |

### 1.2 Deux niveaux de notes, un pont unique

| | `notes` (officiel) | `evaluation_notes` (épreuve) |
|---|---|---|
| Granularité | 1 ligne / (semestre, matière, session) | 1 ligne / (épreuve, étudiant) |
| Écriture | `ArchiveService` (+ imports DataExchange) | `SaisieNoteService` uniquement |
| Lecture | cœur académique, parcours, délibérations, portail | saisie, exports, contrôles |
| Pont | `SaisieNoteService::valider()` = **seule** synchro (note_id + statut `validated`) | |

### 1.3 Tests existants (≈30 fichiers)

Couverture dense : saisie (EnseignantNotesTest, NotesEvaluationsSecuriteTest), imports
(NotesImportAssociation/Multi, DeliberationImportAssociation), délibérations
(DeliberationPhase6/14, EvaluationDeliberationPerformance), portail (PortailEtudiantResultatsPublies),
exports (ExportResultatsAcademiques), contraintes SQL (CheckConstraintsNotesTest), surveillance
(≈10 fichiers hors périmètre).

## 2. Matrice d'audit (défauts vérifiés dans le code)

| # | Lot | Constat (vérifié) | Gravité | Décision |
|---|---|---|---|---|
| A1 | 1/3 | `semestrePourMatiere` : `firstOrFail()` → `ModelNotFoundException` = 500 quand le semestre relatif manque (`SaisieNoteService.php:489`) | Moyenne | **Corrigé** (C1) |
| A2 | 4 | Incohérence permissions : route `show`/`pv` exige `deliberations.view` mais `Gate::authorize('view')` n'accepte que `evaluations.view` (`Routes/web.php:311,338` vs `EvaluationPolicy.php:24-28`) | Moyenne | **Corrigé** (C2) |
| A3 | 4/6 | Événements `EvaluationChanged` et notifications étudiantes dispatchés **dans** la transaction (`DeliberationService.php:91,244,283,305,313-318`) : si le commit échoue après notification, les étudiants sont notifiés d'une publication qui n'a pas eu lieu | Élevée | **Corrigé** (C3) |
| A4 | 4/6 | Changement de décision jury non tracé par valeurs (audit = `deliberation_id` seul, `AuditEvaluation.php:12-15`) | Moyenne | **Corrigé** (C4) |
| A5 | 2 | `GradeCheckSuite::epreuvesAnormales` : plage 5–16 et « note = 20 » codés en dur alors que `note_max` est libre (`GradeCheckSuite.php:73,88`) → faux positifs sur tout barème ≠ /20 | Moyenne | **Corrigé** (C5) |
| A6 | 2 | Aucun contrôle `valeur > note_max` sur `evaluation_notes` (seul le CHECK 0..20 de `notes` est surveillé, mort en pratique) | Faible | **Corrigé** (C5) |
| A7 | 7 | `validerTout` : 1 transaction imbriquée (savepoint) + 2 requêtes inscription/semestre **par note** (`SaisieNoteService.php:338-372`) | Moyenne | **Corrigé** (C6) |
| A8 | 3 | Deux moteurs de décision : cœur 10/8 + 2 décimales persistées vs règles versionnées (seuils + arrondi) en délibération — incohérence possible au même seuil | Élevée | **À CONFIRMER (D-B)** |
| A9 | 3 | Note matière = **dernière épreuve validée** (écrasement), pas de composition pondérée multi-épreuves malgré `Matiere::composantes()` | Élevée | **À CONFIRMER (D-A)** |
| A10 | 4 | Rectification post-publication impossible : `marquerResultatsInvalides` cible `verrouillee_at IS NULL` alors que `valider()` pose le verrou ; aucune voie de correction d'un résultat publié | Élevée | **À CONFIRMER (D-C)** |
| A11 | 5 | Imports DataExchange peuvent poser `publie=true` sans délibération validée (le flag est la seule source de vérité portail) | Moyenne | **À CONFIRMER (D-D)** |
| A12 | 2 | Statut `submitted` présent dans transitions/`validerTout` mais jamais posé par le code | Faible | **Réserve** |
| A13 | 1 | Génération de compostages sur GET `saisie()` (mutation idempotente, gated) | Faible | **Réserve** |

## 3. Décisions métier (BLOQUÉES / À CONFIRMER — aucun code)

| Clé | Question institutionnelle |
|---|---|
| D-A | La note officielle d'une matière doit-elle composer plusieurs épreuves (CC + examen pondérés) ou rester la dernière épreuve validée ? |
| D-B | Les moyennes persistées du cœur doivent-elles suivre les seuils/arrondis des règles versionnées (`evaluation_regles`) ? Quel arrondi officiel ? |
| D-C | Quel processus officiel de rectification d'un résultat **déjà publié** (amendement de PV ? nouvelle délibération ?) ? |
| D-D | Un import externe peut-il marquer `publie` sans délibération validée, ou faut-il exiger le statut `validated/published` ? |

## 4. Plan de correction (lots sûrs, automatisés)

| Correctif | Lot | Fichiers |
|---|---|---|
| C1 — semestre manquant = erreur métier claire (plus de 500) | 1/3 | `SaisieNoteService.php` |
| C2 — policy `view` alignée sur `deliberations.view` | 4 | `EvaluationPolicy.php` |
| C3 — dispatch après commit (événement + notifications) | 4/6 | `EvaluationChanged.php`, `DeliberationService.php` |
| C4 — audit décision avec ancienne/nouvelle valeur (codes, sans PII) | 4/6 | `DeliberationService.php` |
| C5 — contrôles par barème (ratio 25–80 % de `note_max`) + hors barème épreuve | 2 | `GradeCheckSuite.php` |
| C6 — `validerTout` : préchargement inscriptions/semestres, plus de savepoint par note | 7 | `SaisieNoteService.php` |

Tests : nouveau fichier `tests/Feature/NotesEvaluationsAmeliorationsTest.php` (policy, erreur métier,
contrôles par barème, audit décision, afterCommit, perf `validerTout`) + suites ciblées existantes.

## 5. Réalisation

### 5.1 Correctifs livrés (additifs, WIP concurrent préservé)

| Clé | Fichiers | Contenu |
|---|---|---|
| C1 — erreur métier claire | `app/Modules/Evaluation/Services/SaisieNoteService.php` | `semestrePourMatiere` : `firstOrFail()` remplacé par `first()` + `DomainException("Semestre SN introuvable…")` — plus de `ModelNotFoundException` (500) lors d'une validation sur inscription sans semestre relatif |
| C2 — alignement policy/route | `app/Modules/Evaluation/Policies/EvaluationPolicy.php` | `view()` : une `Deliberation` accepte `deliberations.view || evaluations.view` (les épreuves gardent `epreuves.view`) — le jury/scolarité avec `deliberations.view` seul n'est plus en 403 sur `show`/`pv` |
| C3 — notification après transaction | `app/Modules/Evaluation/Services/DeliberationService.php` | `publier()` : les écritures (verrou pessimiste, transitions) restent en transaction ; la diffusion `CommunicationDispatcher` s'exécute **après** la fermeture — un échec de commit ne notifie jamais une publication qui n'a pas eu lieu. `EvaluationChanged` porte le marqueur de convention `$afterCommit` (documenté : la propriété seule est inerte dans Laravel ; l'interface `ShouldDispatchAfterCommit` désactiverait tous les listeners sous RefreshDatabase — rejeté pour préserver la suite) |
| C4 — audit décision par valeurs | `app/Modules/Evaluation/Services/DeliberationService.php` | `decider()` journalise `deliberation.decision.modifiee` avec `decision_id`, `deliberation_id`, `ancienne`, `nouvelle` (codes uniquement, aucune PII), `acteur_id` |
| C5 — contrôles au barème réel | `app/Modules/Evaluation/Services/GradeCheckSuite.php` | `epreuvesAnormales()` : moyenne jugée en **ratio du `note_max`** (25–80 %) au lieu de la plage 5–16 codée en dur ; « toutes identiques » détecté via `valeur >= note_max` ; nouveau contrôle non bloquant `note_epreuve_hors_bareme` (`valeur` hors `0..note_max`) pour les données historiques pré-CHECK. Le contrôle `note_hors_bornes` sur `notes` (0–20, testé par `DeliberationPhase14Test`) est conservé |
| C6 — `validerTout` sans N+1 | `app/Modules/Evaluation/Services/SaisieNoteService.php` | Inscriptions (+semestres), candidats, identités et session préchargés **une fois** par lot ; cœur de validation extrait (`validerNoteVerrouillee`) exécuté dans la transaction du lot — plus de savepoint ni de requêtes inscription/semestre/relations par note. Sémantique inchangée (verrous par note, erreurs partielles collectées) |

### 5.2 Tests

Nouveau fichier `tests/Feature/NotesEvaluationsAmeliorationsTest.php` — **7 tests / 18 assertions** :
policy `view` (jury `deliberations.view` seul accepté, sans permission refusé) · erreur métier semestre
manquant (message lisible, statut inchangé) · contrôles au barème (/100 sain, /20 suspect, toutes
identiques, hors barème non bloquant) · audit décision par valeurs (`Log::assertLogged`) · flux de
publication complet après transaction (décisions publiées + notification) · garde anti-N+1 de
`validerTout`.

| Commande | Résultat |
|---|---|
| `vendor/bin/pint` (7 fichiers du domaine) + `php -l` (3 services) | **PASS** |
| `php artisan test --filter=NotesEvaluationsAmeliorationsTest` | **7 tests / 7 réussis / 18 assertions — PASS** |
| Suites ciblées du domaine : `NotesEvaluationsSecuriteTest`, `EnseignantNotesTest`, `EvaluationTest`, `DeliberationPhase6Test`, `DeliberationPhase14Test`, `DeliberationImportAssociationTest`, `NotesImportAssociationTest`, `NotesImportMultiTest`, `EvaluationDeliberationPerformanceTest`, `CheckConstraintsNotesTest`, `PortailEtudiantResultatsPubliesTest`, `ExportResultatsAcademiquesTest` | **128 tests / 128 réussis / 544 assertions — PASS** |
| Suites adjacentes : `StudentArchiveTimelineAcademicTest`, `EvaluationQualityServiceTest`, `InscriptionResumeValidationTest` | **17 tests / 17 réussis / 59 assertions — PASS** |
| **Total ciblé** | **152 tests / 152 réussis / 621 assertions — PASS** |

### 5.3 Performance (mesurée)

| Mesure | Valeur |
|---|---|
| `validerTout` — 20 notes, requêtes SQL comptées (`DB::getQueryLog`) | **290** (≈ 14,5/note), garde anti-N+1 `< 20 × 15` |
| Avant correctif (estimation par lecture : savepoint ×2 + inscription + semestre + 2 relations par note) | ≈ +5 requêtes/note (≈ 390–400 pour 20) |
| Coût restant dominant | recalcul officiel semestre+année par note via `ArchiveService` — **conservé volontairement** (source de vérité `notes` + invalidation délibérations Phase 14 §14.6) ; le déporter demanderait un lot dédié avec `ArchiveService` en option |
| Imports multi-format (référence WIP, non modifiés) | `[PERF-IMPORT] volume=1000 duree=0.63s req_confirm=1055 pic_memoire=195Mo (FILE+JOB)` |

### 5.4 Sécurité — revue du périmètre modifié

- Aucune route, aucun middleware, aucune permission modifiée ; C2 **élargit** l'accès d'`show`/`pv`
  uniquement vers le rôle déjà exigé par la route (`deliberations.view`), toujours sous périmètre
  faculté (`dansPerimetre`).
- Aucune donnée personnelle dans les nouveaux logs (codes de décision + identifiants techniques).
- Aucune écriture de note, évaluation, inscription ou donnée historique supprimée ; aucun RESET ;
  aucune migration ajoutée.
- Les contrôles C5 restent **non bloquants** (sévérité informationnelle, décision jury inchangée).

### 5.5 Réserves

1. **Décisions métier D-A à D-D (§3) non tranchées** — aucun code ne les présuppose.
2. **Statut `submitted`** défini mais jamais posé (A12) ; **compostages générés sur GET** (A13) —
   conservés, non bloquants, à arbitrer avec la conduite UI.
3. **Recalcul officiel par note** dans `validerTout` : coût O(n) volontairement préservé ; un lot
   « recalcul différé par inscription » exigerait de modifier `ArchiveService` (impact inter-modules).
4. **Suite complète non exécutée** : aucun PASS global revendiqué (rapports antérieurs
   contradictoires, cf. `docs/audit-referentiel/06-RAPPORT-FINAL.md` §5.4). Preuve = suites ciblées
   de 152 tests ci-dessus.
5. **Cohabitation concurrente** : le WIP préexistant (F-01/F-02/F-07/F-09/F-12, `valider-tout`,
   navigation clavier, vues préparation) est intégralement conservé ; les 152 tests incluent ses
   suites (`NotesEvaluationsSecuriteTest` 15 tests, `EnseignantNotesTest`, imports) — aucun conflit
   détecté. Un co-éditeur a adapté le fichier de tests pendant la session (mock du dispatcher) ;
   sa version est conservée.

### 5.6 Verdict

**`PASS_WITH_RESERVATIONS`** — défauts démontrés A1–A7 corrigés et prouvés par 152 tests ciblés
passants ; décisions métier D-A à D-D laissées ouvertes et documentées ; suite complète et
recalcul différé non réalisés (réserves explicites). Aucun commit, aucun push.
