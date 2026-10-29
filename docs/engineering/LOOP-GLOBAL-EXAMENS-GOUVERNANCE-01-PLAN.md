# LOOP GLOBAL UNIREGISTRE — EXAMENS, GOUVERNANCE, SÉCURITÉ ET CONTINUITÉ

**Version :** 1.0
**Date d'audit :** 28/10/2026
**Branche :** `feature/admin-modifier-etudiant` (HEAD inchangé)
**Mode :** audit statique + implémentation contrôlée + tests reproductibles sur SQLite `:memory:`
**Périmètre :** examens, devoirs, salles, surveillances, convocations, présence, incidents, urgences, clôture, corrections post-clôture, continuité et gouvernance.

---

## 1. Environnement et état initial (constaté, non supposé)

| Élément | Valeur constatée |
|---|---|
| Dépôt | `/Users/mohamedyassine/Desktop/PFE/inscription-etudiants` |
| Branche | `feature/admin-modifier-etudiant` |
| PHP | 8.5.11 (NTS) |
| Laravel | 13.33.0 |
| Base locale | MySQL `inscription_etudiants` — **3 migrations du lot appliquées** (batch 21) ; les 13 migrations hors lot restent `Pending` |
| Base de test | SQLite `:memory:` (phpunit.xml) + base dédiée MySQL `inscription_etudiants_concurrency_test` pour la concurrence réelle |
| Travail local | ~945 entrées `git status` — **préservé intégralement** (aucun reset, clean, stash, rebase, merge, push) |
| Commit / push pendant le lot | aucun |

Le dépôt contient déjà un module `app/Modules/Evaluation/` (89 fichiers, 34 services, 20 modèles) et
585 fichiers de tests. Une part importante du cahier des charges est donc **déjà implémentée** ; le lot
consiste à combler les lacunes réelles, pas à reconstruire l'existant.

### 1.1 Baseline de tests (avant toute modification)

Commande :

```bash
php artisan test --compact --filter="ExamensFeatureCompleteTest|ExamensInvariantsValidationTest|ExamensInvariantsSallesTest|ExamPublicationEnterpriseTest|ExamPlanificationSallesEnterpriseTest|ExamCandidatePopulationTest|EligibiliteExamenTest|EvaluationIncidentsTest|ExamPreparationTest|Loop06PlanificationExamensVerificationTest"
```

Résultat : **130 tests — 123 PASS, 7 erreurs**, 444 assertions, 6 247 ms.

Les 7 erreurs sont **préexistantes** et strictement localisées dans `tests/Feature/EligibiliteExamenTest.php` :
le helper crée une `InscriptionPedagogique` sans `semestre_id` ni `ue_id`, colonnes `NOT NULL` du schéma
réel (`2026_09_29_210249_create_population_tables.php`). L'application, elle, renseigne bien ces colonnes
(`InscriptionService::inscrireEcue`, l. 382-385). Il s'agit donc d'un **défaut de fixture**, pas d'une
régression applicative. Il est corrigé dans ce lot (l. 337 du test) sans affaiblir aucune assertion.

---

## 2. Cartographie : règle métier → composant existant → lacune → action

| # | Règle (§) | Composant existant | Lacune constatée | Action du lot |
|---|---|---|---|---|
| A1 | Éligibilité par inscriptions réelles (§2.1) | `ServiceEligibiliteExamen` (214 l.) : académique active, pédagogique module, suspension, cohérence programme, rattrapage en anomalie | **Aucune** — couverture complète et explicable | Vérifié par exécution, non modifié |
| A2 | Devoirs multi-modules séparés (§2.2) | `Epreuve` liée à `matiere_id` + `Examen` 1-N `Epreuve` ; notes et clôture par module | **Aucune** — la séparation existe déjà (1 épreuve = 1 module, note et verrou par module) | Vérifié, non modifié |
| B1 | **Groupes virtuels d'examen** (§2.1) | Aucun (`grep "groupe virtuel"` = 0 occurrence) | **Absent** : le regroupement de plusieurs groupes sources pour un même module n'existe pas | **LOT B implémenté** |
| B2 | Conflits, capacité, délais, salles (§2.3, §2.4) | `ExamScheduleEngineService` (revalidation serveur complète), `ConflitEpreuveService`, `ExamRoomAssignmentService` (395 l.) | Aucune sur le moteur ; durci par LOOP-06 | Vérifié par exécution |
| C1 | Surveillance, ratio, remplacements (§2.5) | `SurveillanceService` (651 l.), `EmploiSurveillanceService` (499 l.), `SurveillanceEquilibrageService` (2 078 l.), contraintes versionnées | Aucune | Vérifié par exécution |
| D1 | **Double validation, certification, rejet, escalade** (§2.7) | `ExamPublicationOrchestratorService::publierExamen` : audit de conformité + empreinte SHA-256 + scellement. Permission `evaluations.publish` **déclarée mais jamais utilisée** | **Critique** : un utilisateur `evaluations.update` peut publier seul, via `POST .../transiter/published`. Aucune certification, aucun rejet motivé, aucune escalade, aucune séparation des responsabilités | **LOT D implémenté et prouvé** : circuit HTTP complet, `transiter/published` neutralisé, permission vérifiée dans le service, verrou `lockForUpdate` |
| D2 | **Gel institutionnel** (§2.8) | Aucun | **Absent** | **LOT D implémenté** |
| E1 | **Absence provisoire, retard horodaté, régularisation** (§2.9) | `Candidat::STATUTS` (7 statuts), `EpreuveService::enregistrerEmargement` | Absence immédiatement typée `absent_injustifie` possible ; pas d'heure d'arrivée ; pas de demande de régularisation instruite | **LOT E implémenté** |
| F1 | **Incidents : gravité, responsable, délai, preuve** (§2.11) | `EvaluationIncident` : type, statut, description, auteur | Pas de gravité, de responsable de traitement, de délai cible, de preuve, ni de validation | **LOT F implémenté et prouvé** : colonnes `gravite`/`delai_cible_at`/`preuves`/`actions_prises`/`valide_par`, validation indépendante (déclarant, responsable de colonne et résolveur réel exclus), UI et route HTTP |
| F2 | **Suppléance d'urgence bornée** (§2.12) | `request_delegations` (module OnlineRequests, périmètre demandes) | **Absent** pour les examens : aucune délégation bornée, révocable, sans auto-validation | **LOT F implémenté** |
| G1 | Clôture avec blocage sur écarts (§2.13) | `SessionExamenService::readiness` avant **ouverture** ; `transiter('closed')` sans contrôle | Pas de readiness de **clôture** (absences provisoires, régularisations en attente, incidents critiques) | **LOT G partiel** : readiness de clôture ajoutée |
| H1 | Reprise après panne idempotente (§2.15) | `EmargementHorsLigne` + route `emargement/hors-ligne` + `Loop10EmargementHorsLigneTest` | Aucune sur l'idempotence ; réconciliation papier/numérique non instrumentée | Vérifié ; **réconciliation complète = réserve** |
| I1 | Notifications, audit, indicateurs (§2.18) | `EvaluationChanged`, `IntegrationLog`, `AuditController`, cockpit | Aucune sur l'existant | Vérifié par exécution |
| J1 | Sécurité, IDOR, concurrence | `EvaluationPolicy` (périmètre faculté), verrous `lockForUpdate`, 22 tests LOOP-06 | Aucune faille connue ouverte sur le périmètre vérifié ; la concurrence salle n'était **pas** démontrée sur un moteur réel | **LOT J exécuté et prouvé** : verrouillage des salles/enseignants avant toute lecture, test multi-processus MySQL (5 itérations) |

---

## 3. Ordre de réalisation retenu

1. **LOT B** — Groupes virtuels d'examen (additif, isolé, sans impact sur les inscriptions).
2. **LOT D** — Validation/certification/publication à deux personnes distinctes + gel.
3. **LOT E** — Absence provisoire, retard horodaté, régularisation instruite.
4. **LOT F** — Incidents qualifiés + délégation d'urgence bornée.
5. **LOT G partiel** — Blocage de clôture sur écarts non résolus.
6. **LOT J** — Tests de sécurité et de non-régression, puis verdict.

Chaque lot suit : implémentation → test dédié → non-régression → verdict. Un lot bloqué n'arrête pas les
lots indépendants.

## 4. Décisions de conception (et points laissés au métier)

| Sujet | Décision du lot | Justification |
|---|---|---|
| Correspondance académique d'un groupe virtuel | Tuple explicite `(faculté, programme, niveau, filière)` ; refus si une source diverge | §2.1 « éviter toute sélection approximative » |
| Effectif d'un groupe virtuel | Calculé par `ServiceEligibiliteExamen`, un étudiant = une décision tracée | §2.1 « éligibilité recalculée » |
| Non-destructivité | Aucune écriture dans `etudiants`, `groups`, `inscriptions_*` ; les propositions antérieures sont conservées (`statut = remplace`) | §2.1 + §0.1.13 |
| Nombre d'approbations | **Deux approbateurs distincts** + certificateur distinct + publicateur distinct du préparateur | §2.7 |
| Gel | Date `modules.evaluation.date_gel` (configurable) ; après gel, soumission bloquée sauf exception motivée autorisée par `evaluations.publish` | §2.8 |
| Absence par défaut | `absent` = **provisoire** (`presence_provisoire = true`) jusqu'à décision | §2.9 |
| Régularisation | Demandeur ≠ décideur, motif obligatoire, aucune écriture destructive de l'historique | §2.9 + §3.5 |
| Rôle du suppléant | Périmètre d'actions explicite (`json`), fenêtre bornée, révocable, auto-validation interdite | §2.12 |
| `ExamenService::transiter('published')` | Conservé comme primitive de service (tests existants) ; **retiré de la surface HTTP** | Ferme le contournement sans casser l'existant |

## 5. Critères d'acceptation du lot

| ID | Critère | Preuve attendue |
|---|---|---|
| AC-B1 | Un étudiant non inscrit au module est exclu du groupe virtuel | test dédié |
| AC-B2 | Deux groupes de niveaux/programmes incompatibles ne sont jamais fusionnés | test dédié |
| AC-B3 | La création d'un groupe virtuel ne modifie ni inscriptions ni groupes | test dédié (comptages avant/après) |
| AC-B4 | Un même module peut agréger plusieurs groupes sources | test dédié |
| AC-B5 | Le regroupement diffère selon le module | test dédié |
| AC-D1 | Le préparateur ne peut pas certifier/approuver/publier sa propre proposition | tests négatifs |
| AC-D2 | Deux approbateurs distincts sont exigés | tests négatifs |
| AC-D3 | Un rejet exige un motif et renvoie au préparateur, historique conservé | tests dédiés |
| AC-D4 | Un désaccord escaladé bloque la publication | test dédié |
| AC-D5 | Après gel, la soumission est refusée sans exception motivée autorisée | test dédié |
| AC-D6 | La route HTTP `transiter/published` ne permet plus de publier | test dédié |
| AC-E1 | Un retard enregistre l'heure d'arrivée sans exclusion | test dédié |
| AC-E2 | Une absence reste provisoire jusqu'à décision | test dédié |
| AC-E3 | Un justificatif tardif crée une demande instruite et motivée | test dédié |
| AC-E4 | Le demandeur ne peut pas trancher sa propre demande | test négatif |
| AC-F1 | Un incident porte gravité, responsable, délai cible et est validé à la résolution | test dédié |
| AC-F2 | Un suppléant ne peut agir hors de son périmètre, de sa fenêtre, ni s'auto-valider | tests négatifs |
| AC-G1 | Une clôture est bloquée par une absence provisoire ou une régularisation en attente | test dédié |
| AC-J1 | Aucune régression sur les suites examens | exécution comparée à la baseline |

## 6. Risques identifiés avant développement

| Risque | Gravité | Mitigation |
|---|---|---|
| Contournement de la publication par la route `transiter` | **Critique** | Retrait de `published` de la surface HTTP + test négatif |
| Régression sur 130 tests examens | Élevé | Surface additive, primitive de service conservée, exécution avant/après |
| Migration non appliquée en local | Moyen | Migration strictement additive, appliquée en batch 21 sur MySQL local ; contrainte de nommage FK MySQL (≤ 64 caractères) corrigée avant exécution |
| Interprétation métier du « groupe virtuel » | Moyen | Tuple explicite, refus en cas d'ambiguïté, réserve documentée |
| Concurrence non testable sur SQLite | Moyen | **Levée** : test multi-processus (`pcntl_fork`) sur base dédiée MySQL, 5 itérations ; échec reproduit sans verrou, succès avec verrou |

## 7. Périmètre résiduel et limites déclarées

- Réconciliation papier ↔ numérique ↔ journaux (§2.15) : rapprochement corrigé, dossier d'écart
  **persistant** et écran de rapprochement assisté livrés (voir §8.7). Le cycle est
  `ouvert → instruit → valide`, avec instruction et validation par deux personnes distinctes,
  contrôle de fraîcheur de la source, anti-doublon par empreinte et périmètre facultaire serveur.
  Restent hors périmètre : pièces jointes binaires et purge/archivage des dossiers clos.
- Archives légales et rétention (§2.13 `archived`) : politique de rétention existante
  (`PolitiqueRetention`), pas de coffre d'archive examens dédié.
- Tests de charge : **exécutés** sur base MySQL dédiée (`php artisan examens:charge`,
  `ExamensChargeTest`) — voir §8.8. La concurrence transactionnelle reste prouvée sur MySQL réel
  par `ExamensConcurrenceReelleTest`.
- Séparation des pouvoirs **par rôle** (et non seulement par personne) : **livrée et active par
  défaut**. Trois rôles dédiés (`certificateur_qualite`, `direction_etudes`,
  `autorite_publication`) portent chacun une seule décision, la colonne MySQL `users.role` est
  alignée sur cette matrice, et le diagnostic `php artisan examens:separation-roles` rend la
  faisabilité mesurable (§8.5). `super_admin` reste la voie de secours tracée.

---

## 8. Journal d'exécution et preuves (28/10/2026)

### 8.1 Migrations

| Migration | État | Vérification |
|---|---|---|
| `2026_10_28_100000_loop_global_examens_gouvernance` | Appliquée (batch 21) | Tables de gouvernance créées ; noms de FK raccourcis (`fk_gv_membre_inscr_acad`, `fk_gv_membre_inscr_peda`) après erreur MySQL 1059 |
| `2026_10_28_120000_loop_global_examens_audit_planning` | Appliquée (batch 21) | Table d'audit du planning vérifiée |
| `2026_10_28_130000_align_incident_gravite_default` | Appliquée | Colonne `evaluation_incidents.gravite` : défaut `mineure`, mapping `faible/normale → mineure`, `haute → majeure` |
| `2026_10_28_140000_create_evaluation_reconciliation_ecarts_table` | Appliquée (via `--path`) | Dossier d'écart persistant : statuts `ouvert/instruit/valide`, empreinte unique `(epreuve_id, candidat_id, empreinte_source)`, index de traitement |
| `2026_10_28_150000_etendre_role_utilisateurs_pour_separation_examens` | Appliquée (via `--path`) | ENUM MySQL `users.role` étendu à `certificateur_qualite`, `direction_etudes`, `autorite_publication` ; retour arrière refusé si un compte porte encore un rôle dédié |

Les 13 migrations hors périmètre restent `Pending` sur la base de développement : elles n'ont pas
été exécutées par ce lot.

### 8.2 Concurrence réelle (§7.3 / DM-05)

Commande (base dédiée, jamais la base de développement) :

```bash
EXAM_CONCURRENCY_DB_DRIVER=mysql \
EXAM_CONCURRENCY_DB_DATABASE=inscription_etudiants_concurrency_test \
php artisan test --compact tests/Feature/ExamensConcurrenceReelleTest.php
```

Résultat : **1 test, 45 assertions — PASS**, 5 itérations. Chaque itération crée deux examens
distincts et lance deux processus (`pcntl_fork`) synchronisés par barrière fichier ; au plus une
affectation dans la même salle/créneau réussit, la perdante échoue sur `Conflit en base`, ne laisse
aucune écriture partielle et la salle ne porte qu'une occupation.

**Preuve de sensibilité du test** : verrous désactivés temporairement → le test échoue
(`{"A":"OK","B":"OK"}`, deux occupations) ; verrous restaurés → il repasse. Le correctif verrouille
les salles et les enseignants en ordre croissant **avant** la première lecture cohérente, et la
requête de conflit est une lecture courante (`lockForUpdate`) lors de l'application d'un scénario.

### 8.3 Suites exécutées

| Commande | Résultat |
|---|---|
| `php artisan test --compact --filter="Exam"` | **384 tests — 382 PASS, 2 ignorés (concurrence sans base dédiée, QA_OUT absent), 1 665 assertions** |
| `php artisan test --compact --filter="EvaluationIncidentsTest\|ExamUrgenceSuppleanceTest\|ExamGlobalGouvernanceSecuriteContinuiteTest"` | **44 tests — 44 PASS, 160 assertions** |
| `php artisan test --compact tests/Feature/ExamIncidentQualificationTest.php` | **12 tests — 12 PASS, 45 assertions** |
| `php artisan test --compact tests/Feature/ExamGouvernanceHttpAuthorizationTest.php` | **13 tests — 13 PASS, 92 assertions** |
| `php artisan test --compact tests/Feature/ExamPublicationEnterpriseTest.php` | **9 tests — 9 PASS, 45 assertions** |
| `php artisan test --compact tests/Feature/ExamPlanificationSallesEnterpriseTest.php tests/Feature/Loop06PlanificationExamensVerificationTest.php tests/Feature/PlanificationAutomatiqueExamensTest.php tests/Feature/SchedulePhase12ExamensExtensionTest.php` | **56 tests — 56 PASS, 240 assertions** |
| `vendor/bin/pint` sur les fichiers du lot | **PASS** |

Exécutions de clôture des trois décisions (28/10/2026) :

| Commande | Résultat |
|---|---|
| `php artisan test --compact --filter="Exam"` | **420 tests — 416 PASS, 4 ignorés, 1 885 assertions, 0 erreur** |
| `php artisan test --compact tests/Feature/ExamReconciliationEcartsTest.php` | **10 tests — 10 PASS, 71 assertions** |
| `php artisan test --compact tests/Feature/ExamReconciliationTriSourcesTest.php tests/Feature/ExamReconciliationEcartsTest.php tests/Feature/ExamGouvernanceHttpAuthorizationTest.php` | **28 tests — 28 PASS, 187 assertions** |
| `php artisan test --compact tests/Feature/ExamSeparationRolesParRoleTest.php tests/Feature/ExamGouvernanceDoubleValidationTest.php` | **24 tests — 24 PASS, 79 assertions** |
| `EXAM_LOAD_DB_DRIVER=mysql EXAM_LOAD_DB_DATABASE=inscription_etudiants_load_test php artisan test --compact tests/Feature/ExamensChargeTest.php` | **2 tests — 2 PASS, 83 assertions** |
| `EXAM_LOAD_DB_DRIVER=mysql EXAM_LOAD_DB_DATABASE=inscription_etudiants_load_test php artisan examens:charge --iterations=25 --candidats=60 --json` | **260 exécutions, 0 erreur, p95 14,79 ms, 54,5 Mo — `respecte = true`** |
| `php artisan test --compact tests/Feature/ExamDocumentsProfessionnelsTest.php` | **8 tests — 8 PASS, 93 assertions** (fichier local non suivi, non modifié) |
| `php artisan migrate:status` | **13 migrations hors lot toujours `Pending`** |

Les 2 tests ignorés sont assumés et explicites : `ExamensConcurrenceReelleTest` (base dédiée non
configurée dans l'exécution par défaut) et `QaExamensRenderTest` (variable `QA_OUT` absente).

Les deux ont ensuite été exécutés séparément avec leur environnement propre :

| Commande | Résultat |
|---|---|
| `EXAM_CONCURRENCY_DB_DRIVER=mysql EXAM_CONCURRENCY_DB_DATABASE=inscription_etudiants_concurrency_test php artisan test --compact tests/Feature/ExamensConcurrenceReelleTest.php` | **1 test — 1 PASS, 45 assertions** |
| `QA_OUT=<répertoire temporaire> php artisan test --compact tests/Feature/QaExamensRenderTest.php` | **1 test — 1 PASS, 4 assertions** ; 3 écrans exportés (tableau de bord, simulation, surveillance), 0 anomalie PHP détectée dans le HTML (aucun `Whoops`/`SQLSTATE`/variable non définie) |

### 8.4 Points corrigés pendant la clôture

- **FK MySQL > 64 caractères** dans la migration `100000` : correction des noms, suppression des
  artefacts vides, relance réussie.
- **UI de publication** : la transition directe `scheduled → published` a disparu ; la vue expose
  le circuit soumettre → certifier → approuver (1/2, 2/2) → publier → nouvelle version, avec rejet
  et escalade. Les tests d'écran ont été renforcés (`Soumettre au circuit` présent,
  `Publier officiellement` absent avant approbation).
- **Validation d'incident** : exclusion du déclarant, du `responsable_id` de colonne **et** du
  résolveur réel enregistré dans `resolution_finale` ; validation non rejouable.
- **Incidents HTTP** : `preuves` accepte une référence par ligne (texte), converti en tableau borné
  à 10 entrées avant persistance ; la colonne JSON reste alimentée pour compatibilité.

### 8.5 Séparation des pouvoirs par rôle (§2.7)

La séparation par **personne** était déjà garantie. La séparation par **rôle** est une exigence
distincte : la matrice a été complétée et la règle est désormais **active par défaut**.

| Élément | Contenu |
|---|---|
| Configuration | `modules.evaluation.separation_pouvoirs_par_role` (`EXAM_SEPARATION_POUVOIRS_PAR_ROLE`, **true** par défaut) |
| Règle activée | le rôle de l'acteur doit porter la permission de l'étape **et** différer du rôle de l'étape de référence ; la séparation par personne reste appliquée |
| Étapes couvertes | préparateur (`evaluations.update`), certificateur (`evaluations.certify`), approbateur (`evaluations.approve`), publicateur (`evaluations.publish`) ; l'arbitrage d'escalade exige la permission de publication et un rôle distinct du déclarant et du préparateur |
| Rôles dédiés | `certificateur_qualite` → `certify` ; `direction_etudes` → `approve` ; `autorite_publication` → `publish`. Ces rôles ne portent que la lecture nécessaire et leur propre décision |
| Compatibilité | `admin_scolarite` conserve `certify/approve/publish` : les circuits existants restent valides, la règle par rôle s'applique à qui n'est pas `super_admin` |
| Compte de secours | `super_admin` exempté de la comparaison de rôles (il porte toutes les permissions, donc ne peut rien séparer) ; son usage reste journalisé par l'audit de gouvernance |
| Diagnostic | `php artisan examens:separation-roles [--json]` — éligibilité par étape, faisabilité, proposition d'attribution, actions requises ; code de sortie 1 si la règle est active et non satisfaite |
| Schéma | migration `2026_10_28_150000` : l'ENUM MySQL `users.role` accepte les trois rôles dédiés (sans quoi MySQL tronquait l'attribution, erreur 1265) |

**Attribution livrée** (sortie réelle du diagnostic) :

```
preparateur  → admin_faculte
certificateur → admin_scolarite
approbateur  → direction_etudes
publicateur  → autorite_publication
faisable = true, actions_requises = []
```

**Preuves** — `tests/Feature/ExamSeparationRolesParRoleTest.php` +
`tests/Feature/ExamGouvernanceDoubleValidationTest.php`, **24 tests, 79 assertions** :

- mode désactivé : le circuit existant (séparation par personne) reste inchangé ;
- mode actif : même rôle + personnes distinctes → refus explicite `Séparation des pouvoirs par rôle` ;
- mode actif : rôle différent mais sans la permission de l'étape → refus nommant la permission ;
- mode actif : le compte de secours peut certifier (exemption assumée) ;
- mode actif : publication par un rôle déjà intervenu → refus ;
- activation par défaut : un même rôle ne peut plus porter deux étapes du circuit ;
- diagnostic matrice livrée → `faisable = true`, aucune action requise ;
- diagnostic matrice synthétique infaisable → détection et actions requises ;
- commande : code de sortie 1 si règle active et infaisable, 0 sinon.

Suites de non-régression : `--filter="Exam"` → **420 tests, 416 passés, 4 ignorés, 1 885
assertions, 0 erreur** ; les 4 ignorés sont les deux harnais à environnement dédié
(`ExamensConcurrenceReelleTest`, `ExamensChargeTest` ×2) et `QaExamensRenderTest` (`QA_OUT` absent).
Gouvernance (double validation + HTTP + rôles) → **33 tests, 187 assertions**.

### 8.6 Réconciliation tri-sources (§2.15)

Le rapprochement existant comparait le papier au numérique et **attachait** la date du journal sans
jamais la comparer. Deux défauts corrigés, tous deux démontrés par un test qui échoue sur l'ancien
comportement :

| Défaut constaté | Correctif | Preuve de sensibilité |
|---|---|---|
| Journal cherché par `orWhere('operation','like','%attendance%')` puis `str_contains('CAND-11','CAND-1')` → la trace d'un candidat **d'une autre épreuve** était attribuée (collision de préfixe) | Requête bornée aux références exactes `CAND-{id}` des candidats de l'épreuve, indexée par référence | Test avec candidats #1 et #11 : lookup historique → **échec** (`reserves_detectees = 0`, trace volée) ; lookup corrigé → succès |
| Trois sources annoncées, deux comparées : une source muette (pas de signature, pas de journal) était indistinguable d'une concordance | Sortie enrichie : `reserves` (types `signature_absente`, `trace_journal_absente`), `reserves_detectees`, `reconciliation_complete`, `sources_comparees` ; une réserve **ne bloque pas**, une divergence **bloque** | Tests dédiés : concordance sans journal → réserve non bloquante ; présence sans signature → réserve ; divergence papier/numérique → blocage conservé |

`tests/Feature/ExamReconciliationTriSourcesTest.php` : **5 tests, 24 assertions**. Le contrat de
sortie reste rétro-compatible (`ecarts`, `divergences_detectees`, `validation_bloquee` inchangés) ;
les nouvelles clés sont additives.

### 8.7 Dossier d'écart persistant et écran de rapprochement (§2.15)

Le rapprochement ne s'arrête plus à une détection en mémoire : chaque divergence devient un
**dossier** consultable, instruit puis validé.

| Élément | Contenu |
|---|---|
| Table | `evaluation_reconciliation_ecarts` — instantané `statut_papier`, `statut_numerique`, `journal_horodate`, `nature_divergence`, acteurs et horodatages, empreinte SHA-256 unique par `(epreuve_id, candidat_id, empreinte_source)` |
| Cycle | `ouvert → instruit → valide` ; aucune suppression, aucun retour arrière |
| Quatre yeux | `instruireEcart()` puis `validerEcart()` refusent le même acteur (`DomainException` explicite) ; l'habilitation `evaluations.update` est revérifiée côté service, pas seulement côté HTTP |
| Fraîcheur | la source numérique doit être identique à l'instantané de détection, à l'instruction **et** à la validation ; le candidat est verrouillé (`lockForUpdate`) avant l'application du statut |
| Anti-doublon | rejouer la même analyse retourne les dossiers existants (`crees = 0`), un écart validé n'est jamais rouvert |
| Application | la validation réutilise `regulariserEcartTriSources()` : statut candidat, `decide_par`, justification JSON auditée, journal d'intégration |
| Surface HTTP | 5 routes `evaluations.reconciliation.*` : liste + filtres, analyse papier → serveur, dossier, instruction, validation ; permissions `evaluations.view` / `evaluations.update`, périmètre facultaire serveur (404 hors faculté) |
| Écrans | `reconciliation-index` (KPI par statut, filtre épreuve/statut, saisie papier par candidat) et `reconciliation-show` (instantané tri-sources, instruction, validation, dossier scellé) ; lien direct depuis la page de publication |

**Preuves** — `tests/Feature/ExamReconciliationEcartsTest.php`, **10 tests, 71 assertions** :
persistance sans doublon, quatre yeux (auto-validation refusée puis tiers accepté), fraîcheur de la
source à l'instruction, permission d'instruction, index/`show` protégés et bornés à la faculté,
analyse HTTP idempotente, refus 403 du rôle lecture seule, circuit HTTP complet.

Non-régression : `ExamReconciliationTriSourcesTest` + `ExamReconciliationEcartsTest` +
`ExamGouvernanceHttpAuthorizationTest` → **28 tests, 187 assertions**. Les 5 routes sont
enregistrées (`php artisan route:list --name=evaluations.reconciliation`).

**Reste hors périmètre** : pièces jointes binaires (la référence de preuve reste textuelle) et
purge/archivage des dossiers clos.

### 8.8 Charge des examens (§7.3 / §2.7 / §2.15)

Harnais : `php artisan examens:charge [--iterations=20] [--candidats=40] [--json]`, service
`ExamChargeService`, test `tests/Feature/ExamensChargeTest.php`.

| Garde | Comportement |
|---|---|
| Base dédiée | refus si le nom ne contient pas `_test` ou s'il désigne la base de développement (`DomainException` avant toute migration) |
| Skip explicite | sans `EXAM_LOAD_DB_DRIVER=mysql`, le test est ignoré (aucune preuve inventée) |
| Schéma | `migrate:fresh` **uniquement** sur la base dédiée (`--database=mysql --force`) |
| Seuils | p95 ≤ `EXAM_LOAD_P95_MS` (1500 ms), erreurs ≤ `EXAM_LOAD_MAX_ERREURS_PCT` (0 %), pic mémoire ≤ `EXAM_LOAD_MAX_MEMOIRE_MB` (512 Mo) ; code de retour 1 si un seuil est dépassé |

Charges mesurées : rapprochement tri-sources, persistance anti-doublon, instruction et validation
quatre yeux, soumission → certification → double approbation → publication officielle, lecture
paginée de la liste des dossiers.

Commande (base dédiée, jamais la base de développement) :

```bash
EXAM_LOAD_DB_DRIVER=mysql \
EXAM_LOAD_DB_DATABASE=inscription_etudiants_load_test \
php artisan examens:charge --iterations=25 --candidats=60 --json
```

Résultat observé (MySQL 9.2.0 local, 28/10/2026) : **260 exécutions, 0 erreur**, p50 **3,42 ms**,
p95 **14,79 ms**, p99 **33,92 ms**, pic mémoire **54,5 Mo** → `respecte = true`. Montée en charge
100 itérations / 120 candidats : **920 exécutions, 0 erreur**, p95 **15,19 ms**, p99 **58,36 ms**,
pic mémoire **54,5 Mo**, durée totale 42,9 s → `respecte = true`. L'opération la plus lente est
`reconciliation.persister_ecarts` (p95 60,32 ms à 100 itérations), la publication officielle reste
sous 40 ms p95.

Deux défauts réels ont été révélés par la charge et corrigés :

1. l'ENUM MySQL `users.role` ne contenait pas les rôles dédiés → migration `150000` (erreur 1265) ;
2. le valideur du harnais (`autorite_publication`) ne portait pas `evaluations.update` → un valideur
   habilité distinct a été ajouté, et le message d'erreur « Impossible d’valider » a été corrigé.

Preuves PHPUnit : `EXAM_LOAD_DB_DRIVER=mysql EXAM_LOAD_DB_DATABASE=inscription_etudiants_load_test
php artisan test --compact tests/Feature/ExamensChargeTest.php` → **2 tests, 83 assertions — PASS**
(dont vérification que l'ENUM `users.role` contient les trois rôles dédiés et qu'un sous-processus
visant la base de développement échoue avant migration).

### 8.9 Durcissement « max saisie » des notes et erreurs 500 des écrans

**Erreurs 500 reproduites puis corrigées** (base de développement uniquement, jamais la
production) :

| Écran | Cause racine | Correction |
|---|---|---|
| `GET /admin/referentiel/competences` | tables `competences*` absentes de la base de dev | migrations additives `2026_10_25_010000_creer_tables_competences` et `2026_10_27_130000_creer_snapshots_couverture_competences` appliquées **ciblées** via `migrate --path=…` (les autres migrations en attente restent hors lot) |
| `GET /admin/evaluations/epreuves/{id}/notes` | colonne `evaluation_notes.valide_at` absente (auditabilité LOT 05) | migration additive `2026_10_24_100000_ajouter_valide_at_aux_notes_evaluation` appliquée ciblée ; le modèle garde un fail-soft `existeColonneValideAt()` par worker |

Vérification HTTP authentifiée réelle (`php repro-500.php <url> <userId>`) : compétences **200**,
contrôle de cohérence **200**, saisie des notes **200** pour toutes les épreuves et tous les
profils testés. Aucune autre migration n'a été appliquée sur la base de développement.

**Règles de saisie durcies** (défense en profondeur) :

1. `NoteDansBareme` (règle serveur) : barème absent ou `note_max ≤ 0` → refus ; valeur non
   finie (INF/NAN par notation exponentielle) → refus ; hors `[0, note_max]` → refus ;
   précision > 2 décimales → refus (la colonne SQL `decimal(5,2)` arrondirait sinon
   silencieusement).
2. `SaisieNotesRequest` et `SaisieNotesAnonymesRequest` : `authorize()` évalue la **policy
   `saisirNote`** (permission + périmètre faculté de l'épreuve) **avant** toute validation,
   puis appliquent `decimal:0,2` + `NoteDansBareme`. Une épreuve hors périmètre renvoie 403
   sans révéler d'erreur de charge utile.
3. `SaisieNoteService` rejoue les mêmes contrôles (nombre fini, 2 décimales, bornes, barème
   strictement positif) : un appel direct au service, un import ou un script ne peuvent pas
   contourner la validation HTTP. Le flux anonyme ne résout l'étudiant que par le
   compostage serveur — un `etudiant_id` client est ignoré.
4. `MesNotesController` (portail enseignant) applique la même règle de barème.
5. Pages et exports de notes/correspondance : `Cache-Control: no-store`, `Pragma: no-cache`,
   `X-Robots-Tag: noindex, nofollow`. Les rejets sont journalisés **sans valeur de note ni
   identité étudiante** (épreuve, opération, auteur, volume).
6. Interface : clamp client des valeurs collées/saisies, `baremeValide` désactive la saisie
   quand le barème est invalide, blocage de la double-soumission.

**Preuves** — nouveaux tests de non-régression dans `tests/Feature/NotesEvaluationsSecuriteTest.php`
(7 tests) et `tests/Feature/EnseignantNotesTest.php` (1 test) : note > barème refusée sans
écriture, note négative / non finie / 3 décimales refusées, barème nul bloquant (HTTP + service),
service seul refusant les valeurs invalides, `etudiant_id` client ignoré, 403 hors périmètre
avant validation, en-têtes no-store/noindex, portail enseignant 3 décimales + barème nul.
Exécution : **8 tests, 40 assertions — PASS**.

Non-régression élargie : `--filter="Evaluation"` → **125 tests, 443 assertions — PASS** ;
périmètre notes/imports/référentiel (`NotesEvaluationsSecuriteTest`,
`NotesEvaluationsAmeliorationsTest`, `NotesImportMultiTest`, `NotesImportAssociationTest`,
`EnseignantNotesTest`, `ReferentielCompetencesTest`) → **116 tests, 526 assertions — PASS**.
`vendor/bin/pint --dirty` → PASS.

Doc utilisateur mise à jour : `docs/utilisateur/02-guides-pas-a-pas.md` (§C3) et
`docs/utilisateur/06-vocabulaire.md` (bornes serveur, 2 décimales, barème > 0).

### 8.10 Fichier de test local, hors lot

`tests/Feature/ExamDocumentsProfessionnelsTest.php` est un fichier local **non suivi**, non modifié
par ce lot. Lors de cette exécution il est **vert** : 8 tests, 93 assertions. Il n'entre donc dans
aucune réserve.

## 9. Verdict actualisé

**`PASS_WITH_RESERVATIONS`** maintenu, mais les trois réserves précédentes sont **traitées** :

1. séparation des pouvoirs **par rôle** : livrée, active par défaut, stockable en base, prouvée et
   diagnostiquable (§8.5). Réserve restante, d'ordre institutionnel : **nommer les titulaires**
   des trois rôles dédiés et retirer progressivement `certify/approve/publish` de
   `admin_scolarite` si l'université veut une séparation stricte — le mécanisme le permettra sans
   changement de code ;
2. réconciliation tri-sources : dossier persistant et écran de rapprochement livrés, cycle à quatre
   yeux, fraîcheur et anti-doublon prouvés (§8.7). Réserve restante : pièces jointes binaires et
   purge des dossiers clos ;
3. tests de charge : **exécutés** sur base MySQL dédiée avec seuils et verdict machine (§8.8) —
   p95 14,79 ms à 25 itérations, 15,19 ms à 100 itérations, 0 erreur, 54,5 Mo ;
4. erreurs 500 des écrans compétences/notes et durcissement « max saisie » : tables/colonne
   manquantes corrigées par migrations **additives ciblées**, bornes serveur (`0..note_max`),
   2 décimales maximum, barème strictement positif, autorisation avant validation, flux
   anonyme non déplaçable par `etudiant_id`, pages/exports non cachés ni indexés (§8.9).

Réserves résiduelles, toutes explicitement hors code : politique d'archivage légal des examens
(§2.13). Aucune réserve ne porte sur une garantie logicielle vérifiable : chaque énoncé ci-dessus
est accompagné d'une commande reproductible et d'un résultat observé.
