# LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02 — Programme d'Amélioration Avancée
## Rapport d'Exécution, Traçabilité et Clôture

> **Révisions 2 à 7 — audit indépendant, quatre correctifs ciblés, réconciliation du lot 6 et clôture finale non validée du 22 octobre 2026.**
> La section 1 ci-dessous est un inventaire initial produit conformément au protocole. Les constats proviennent de l'inspection du dépôt réel et des commandes rejouées. Aucun commit, push, reset de base ni modification destructive n'a été effectué. `ADVANCED-01` est préservé et n'a pas été réécrit. Les verdicts finaux par lot et le verdict global consolidé sont consignés au §18.

> **Périmètre :** Modules Notes (`notes`, `evaluation_notes`), Évaluations (`evaluation_*`), Délibérations, Imports multi-format, Centre de contrôle des anomalies, Workflow de validation, Saisie rapide type tableur, Génération documentaire groupée et Historique différentiel.  
> **Date de démarrage :** 22 octobre 2026.  
> **Environnement :** PHP 8.5.11 / Laravel Framework 13.33.0 / MySQL 8.x / PHPUnit 12.5.  
> **Règles absolues respectées :** Aucun commit, aucun push, aucune migration destructive (`migrate:fresh`, `db:wipe`), préservation des fichiers concurrents / non suivis (279 fichiers suivis modifiés ou ajoutés et 249 non suivis au moment de la rédaction finale ; compteur instable), validation systématique par tests exécutés.

---

## 1. LOT 0 — Inventaire et Audit Réel

### 1.1 Environnement et Outils Techniques Constatés
- **PHP :** 8.5.11 (NTS) avec OPcache.
- **Laravel Framework :** 13.33.0.
- **Bibliothèques clés :**
  - `openspout/openspout` (^5.11) : moteur de lecture/écriture streaming haute performance pour XLSX, ODS et CSV (sans dépassement mémoire).
  - `dompdf/dompdf` (^3.1) : moteur de rendu PDF serveur.
  - `livewire/livewire` (^4.4) : composants réactifs légers.
  - `laravel/pint` (^1.27) : linter et formateur de style PSR-12 / Laravel.
  - `phpunit/phpunit` (^12.5.12) : suite de tests automatisée.
- **Base de données :** MySQL avec contraintes d'intégrité référentielle, clés étrangères et index uniques sur `(epreuve_id, etudiant_id)` et `(semestre_id, matiere_id, session)`.

### 1.2 Cartographie des Composants Existants
- **Modèles centraux :**
  - `App\Models\Note` : table `notes` (notes officielles rattachées aux inscriptions académiques et semestres).
  - `App\Modules\Evaluation\Models\NoteEpreuve` : table `evaluation_notes` (notes de copies d'examens avec cycle de vie `draft`, `entered`, `submitted`, `validated`, `locked`, `correction_demandee`).
  - `App\Modules\Evaluation\Models\NoteEpreuveHistorique` : table `evaluation_notes_historique` (traçabilité des valeurs, motifs, auteurs).
  - `App\Modules\Evaluation\Models\Epreuve`, `SessionExamen`, `Examen`, `Candidat`, `EvaluationCompostage`.
  - `App\Modules\Evaluation\Models\Deliberation`, `DecisionDeliberation` : table `evaluation_decisions` (instantané officiel de délibération).
  - `App\Modules\Evaluation\Models\DocumentExamenGenere` : table `evaluation_documents_generes`.
- **Services métier existants :**
  - `SaisieNoteService` : saisie unitaire, groupée, anonyme, validation, verrouillage et correction.
  - `GradeCheckSuite` : détection d'anomalies lors des délibérations.
  - `DeliberationService` : compilation, préparation, validation et publication des délibérations.
  - `AcademicCalculationService` : calcul centralisé des moyennes pondérées LMD et des crédits ECTS.
  - `CompostageService` : anonymisation des copies d'examens.
  - `PvDeliberationService` : génération de PV de délibération.
- **Contrôleurs et Vues :**
  - `NoteEpreuveController` : gestion back-office de la saisie, validation et imports anonymes.
  - `MesNotesController` : saisie et consultation pour les enseignants.
  - `TableauBordEvaluationController` : tableau de bord d'évaluation avec KPI d'alerte.
  - Vues Blade : `notes-saisie.blade.php`, `ma-saisie-notes.blade.php`, `notes-import-preview.blade.php`, `tableau-bord.blade.php`.

### 1.3 Les Trois Niveaux de Données Académiques
1. **Niveau 1 — Notes en cours de saisie (`NoteEpreuve` / `evaluation_notes`) :**
   - Source de vérité : la copie d'examen ou le relevé d'évaluation continu.
   - Cycle de vie : `draft` $\rightarrow$ `entered` $\rightarrow$ `submitted` $\rightarrow$ `validated` $\rightarrow$ `locked`.
   - Modifiabilité : modifiable par l'enseignant ou le gestionnaire tant que la note n'est pas verrouillée.
2. **Niveau 2 — Notes officielles validées (`Note` / `notes`) :**
   - Source de vérité : l'inscription académique au semestre / matière.
   - Synchronisation : alimentée automatiquement lors de la validation d'une `NoteEpreuve` par un utilisateur autorisé.
   - Verrouillage : verrou posé lors de la préparation de la délibération.
3. **Niveau 3 — Décisions et instantanés de délibération publiés (`DecisionDeliberation` / `evaluation_decisions`) :**
   - Source de vérité : le procès-verbal de jury de délibération.
   - Immuabilité : une fois publiée (`publie = true`, `statut = published`), aucune modification de note ne peut altérer silencieusement la décision.

### 1.4 Plan d'Implémentation Minimal et Dépendances
- **LOT 1 :** Centre de contrôle des anomalies (`EvaluationQualityService`, `AnomalieController`, vue `anomalies-index`).
- **LOT 2 :** Import Excel/CSV haute fiabilité (`EvaluationImportService`, support `OpenSpout`, prévalidation, rapport granulaire).
- **LOT 3 :** Workflow de validation et publication (`EvaluationWorkflowService`, matrice de transitions, contrôles de verrouillage et publication).
- **LOT 4 :** Saisie rapide type tableur (navigation clavier complète, collage rapide multi-lignes Excel, filtres dynamiques, protection réseau).
- **LOT 5 :** Génération documentaire groupée (`EvaluationDocumentBatchService`, `DocumentBatchController`, génération sécurisée par lot).
- **LOT 6 :** Historique différentiel et audit (`EvaluationAuditService`, timeline chronologique, comparaison avant/après).
- **LOT 7 :** Intégration, sécurité, benchmarks réels et tests de non-régression.

**Gate G0 (version initiale) : NON VALIDÉE — voir §8.**

---

# Révision 2 — Audit indépendant, preuves et gate G0

## 2. Verdict de gate G0

**Verdict : `PASS_WITH_RESERVATIONS` pour la tranche d'audit et les seuls lots indépendants.**
Ce n'est ni un `PASS` global, ni une autorisation de traiter D-01 à D-06 à la place de l'établissement.

Le gate G0 constate que :

- l'inventaire du dépôt réel est suffisant pour distinguer les composants existants, les travaux concurrents et les correctifs réellement vérifiés ;
- la suite ciblée Notes/Évaluations passe, mais la suite globale n'est pas verte : le dernier run complet laisse **4 échecs / 0 erreur**, tous stables ; le test de performance `EvaluationDeliberationPerformanceTest` est passé dans ce run final après avoir échoué de façon dépendante de l'ordre dans un run intermédiaire ; les quatre non-pass `EvaluationDocumentBatchTest` du run initial sont corrigés depuis ;
- les revendications de performance et de clôture de `ADVANCED-01` ne sont pas reproductibles sur le pipeline complet ;
- les décisions institutionnelles D-01 à D-06 restent ouvertes ;
- les fichiers applicatifs et les rapports sont modifiés concurremment : le dépôt n'est pas dans un état figé.

**Conséquence :** seuls les lots sans décision institutionnelle peuvent avancer. Les lots F-03, F-05, F-06 et D-01 à D-06 restent `BLOCKED`. La révision 2 n'a fait aucune modification fonctionnelle ; le code concurrent présent a été audité, pas certifié. Quatre correctifs indépendants ont ensuite été appliqués et sont tracés aux §14 à §16, avec la réconciliation du lot 6 au §17.

## 3. Preuves exécutées

| Commande | Résultat observé | Portée |
|---|---|---|
| `php artisan test --compact tests/Feature/EvaluationQualityServiceTest.php` | **4/4 PASS**, 15 assertions, 1,193 s | Centre d'anomalies concurrent |
| `php artisan test --compact tests/Feature/NotesEvaluationsSecuriteTest.php tests/Feature/NotesImportMultiTest.php` | **59/59 PASS**, 214 assertions, 17,499 s | Sécurité Notes + import multi-format |
| Suite ciblée élargie après correctifs | **129/129 PASS**, 501 assertions, 10,321 s | Notes, import, qualité, inscription, compostage |
| `php artisan test --compact` (run global initial, avant corrections) | **4 065 tests / 4 032 PASS / 5 échecs / 3 erreurs / 25 skip / 19 984 assertions / 283,572 s** | Suite globale historique |
| `php artisan test --compact` (run global intermédiaire, avant le test d'intégration) | **4 076 tests / 4 046 PASS / 5 échecs / 0 erreur / 25 skip / 20 056 assertions / 244,561 s** | Suite globale historique |
| `php artisan test --compact` (run global final, après ajout du test d'intégration) | **4 083 tests / 4 054 PASS / 4 échecs / 0 erreur / 25 skip / 20 075 assertions / 244,271 s** | Suite globale courante |
| `php artisan test --compact tests/Feature/EvaluationDocumentBatchTest.php` (rejoué après correction de la fixture et du rôle de test) | **5/5 PASS**, 20 assertions, 1,075 s | Lot 5 concurrent |
| `php artisan test --compact tests/Feature/EvaluationAuditTest.php` | **5/5 PASS**, 22 assertions, 831 ms | Lot 6 concurrent |
| `php artisan test --compact tests/Feature/EvaluationAdvanced02IntegrationTest.php` | **5/5 PASS**, 28 assertions, 1,109 s | Parcours transversaux lot 7 |
| `php artisan test --compact tests/Feature/CompostageAnonymeTest.php --filter=test_saisie_expose_autosave_local_et_collage_sans_pii` | **1/1 PASS**, 8 assertions, 672–767 ms | Autosave / collage lot 4 |
| `php artisan test --compact` sur 9 fichiers Notes/Qualité/Inscription | **137/137 PASS**, 553 assertions, 10,035 s | Suite ciblée après autosave |
| `vendor/bin/pint --test` sur les fichiers Notes/Évaluations ciblés | **PASS** sur les 9 fichiers vérifiés (tests, définition, services, contrôleur, policy) et sur les deux services concurrents | Style |
| `php -l` sur les 4 nouveaux fichiers | **PASS** | Syntaxe |

Le run global final observe **4 échecs, 0 erreur, 25 skip** :

1. `Tests\Feature\InscriptionAccueilParcoursTest::test_accueil_plateforme_propose_les_deux_parcours_sans_dupliquer_le_backend` — HTML contient encore l'URL absolue `http://localhost:8000/...`.
2. `Tests\Feature\ParcoursAccueilEmbarqueTest::test_coquille_modale_et_wizard_prefixe_sans_collision` — compteur de coquille à 0 au lieu de 1.
3. `Tests\Feature\RelevesGenerationTest::test_portail_liste_telecharge_et_idor` — attendu 200, reçu 403 (gate de publication, D-05).
4. `Tests\Feature\SaisieIdentificationStricteTest::test_le_formulaire_existant_embarque_le_controle_strict` — 1 occurrence au lieu de 2.

Le run global intermédiaire (avant l'ajout du test d'intégration) avait observé **5 échecs + 0 erreur** : aux quatre échecs stables s'ajoutait `EvaluationDeliberationPerformanceTest::test_deliberation_preparer_sans_n_plus_un`, à 25 requêtes pour un seuil strict `< 25`. Ce test passe seul deux fois de suite (1/1, 2 assertions, 729 ms puis 638 ms) et passe dans le run final ; il n'a pas été modifié et le seuil n'a pas été relevé.

Le premier run global avait observé **5 échecs + 3 erreurs** : les quatre non-pass `EvaluationDocumentBatchTest` (fixture `niveau` puis rôle `admin_faculte`) ont été corrigés et le fichier rejoue **5/5 PASS**, 20 assertions. Le lot 6 concurrent rejoue **5/5 PASS**, 22 assertions, après requalification de son test sur `saisirLot`. Les correctifs §14–§16 ne touchent pas les quatre échecs stables. Le run global n'est pas un `PASS` et ne doit pas être présenté comme tel.

## 4. Inventaire réel et architecture

### 4.1 Trois niveaux de données

| Niveau | Table / modèle | Rôle | Point de vigilance |
|---|---|---|---|
| Saisie | `evaluation_notes` / `NoteEpreuve` | note de copie, statuts `draft` → `entered` → `submitted` → `validated` → `locked` | une modification d'une note validée la repasse à `entered` et exige une revalidation ; la note officielle reste l'ancienne valeur jusqu'à cette revalidation |
| Officiel | `notes` / `Note` | note de relevé, liée à l'inscription, la matière et la session | ne doit pas être réécrite si elle est verrouillée |
| Décision | `evaluation_decisions` / `DecisionDeliberation` | instantané de jury, `publie` | la publication ne rend pas les corrections post-publication réversibles |

`NoteEpreuveHistorique` (`evaluation_notes_historique`) existe et est alimenté à la saisie, la validation, le verrouillage et les demandes de correction. Un contrôleur, un service d'audit, des routes et des vues de consultation ont été ajoutés concurremment ; leur suite `EvaluationAuditTest` rejoue désormais **5/5 PASS**, 22 assertions, 831 ms, avec `saisirLot` et des attentes alignées sur trois révisions. L'écran est donc testé au niveau HTTP, mais pas dans un navigateur réel.

### 4.2 Composants à étendre avant d'en créer

| Besoin | Composant existant | Décision d'architecture |
|---|---|---|
| Centre qualité | `admin/qualite`, `AnomalieQualite`, `QualitePolicy`, `AgregateurQualite` | doit être étendu avec une source `evaluation`, pas doublé par un centre parallèle |
| Import | `DataImportService`, `ImportReport`, `ImportOrchestrator`, `NoteEpreuveAnonymeDefinition` | le pipeline preview/confirmation et le rapport existent déjà |
| Documents groupés | `DocumentBatch`, `GenerateDocumentBatchJob`, `GenerateOfficialDocumentsJob`, `DeliberationController::genererReleves` | la génération par lot existe ; l'intégration Évaluations manque |
| Historique | `NoteEpreuveHistorique`, `NoteEpreuve::historique()`, `EvaluationAuditService`, `HistoriqueNotesController` | lecture et différentiel implémentés concurremment et testés 5/5 ; pas de nouvelle table, validation navigateur à faire |
| Délibération | `DeliberationService`, `Deliberation` | le workflow de validation/publication existe ; le gate qualité n'y est pas branché |

### 4.3 Travaux concurrents constatés

Les fichiers suivants ont été ajoutés ou modifiés pendant la boucle, après l'audit initial :

- `app/Modules/Evaluation/Services/EvaluationQualityService.php` ;
- `app/Modules/Evaluation/Controllers/AnomalieController.php` ;
- `resources/views/modules/evaluation/anomalies-index.blade.php` ;
- `app/Modules/Evaluation/Services/EvaluationImportService.php` ;
- `app/Modules/Evaluation/Services/EvaluationWorkflowService.php` ;
- `app/Modules/Evaluation/Services/EvaluationAuditService.php`, `HistoriqueNotesController.php`, vues `notes-historique*` et `tests/Feature/EvaluationAuditTest.php` ;
- routes `admin/evaluations/anomalies*`, `admin/epreuves/{epreuve}/notes/soumettre` et `admin/evaluations/historique-notes*`.

Ils sont conservés. Leur existence ne vaut pas validation : les sections suivantes distinguent ce qui est testé, ce qui est câblé et ce qui reste une revendication.

## 5. État des constats F-01 à F-12

| ID | Constat | Preuve / état réel | Verdict |
|---|---|---|---|
| F-01 | modification d'une note validée | `SaisieNoteService::saisirLot` repasse à `entered` et exige une revalidation ; test dédié PASS | `PASS_WITH_RESERVATIONS` |
| F-02 | note fantôme pour absent vide | aucune création si valeur `null` et note inexistante ; test PASS | `PASS_WITH_RESERVATIONS` |
| F-03 | correction après publication | `marquerResultatsInvalides` exclut `locked`/`archived` et ne dépublie pas ; `Deliberation::TRANSITIONS` n'a pas de retour `published → prepared` | **`BLOCKED` (D-03)** |
| F-04 | verrous concurrents | `lockForUpdate` sur épreuve, notes et note officielle ; aucun test multi-connexions réel | `PASS_WITH_RESERVATIONS` |
| F-05 | fuite live/snapshot | téléchargement d'un relevé non publié bloqué à 403 ; la liste peut toutefois exposer les métadonnées de relevés non publiés si une autre inscription est publiée | **`BLOCKED` (D-04)** |
| F-06 | relevés sans décision publiée | `GenerateOfficialDocumentsJob` ne prend que `publie = true` ; `OfficialDocumentService::generer` direct reste accessible au staff | **`BLOCKED` (D-05)** |
| F-07 | périmètre faculté | policy `NoteEpreuve` vérifie épreuve et étudiant ; centre d'anomalies filtré par faculté | `PASS_WITH_RESERVATIONS` |
| F-08 | N+1 import | après correctif §15, persistance par paquets de 500 : 1 000 lignes = **1 055 requêtes de confirmation / 0,69–0,79 s** (contre 9 037 / 9,98 s avant) ; reste O(N) par les insertions, pas de budget `< 50` | `PASS_WITH_RESERVATIONS` |
| F-09 | MIME / volume / rapport | MIME réel vérifié dans `DataImportService` ; legacy limité à 2 000 lignes ; `ImportReport` absent du chemin legacy | `PASS_WITH_RESERVATIONS` |
| F-10 | auteur enseignant | `auteurId` nul si l'enseignant n'a pas de `user_id` ; le matricule est seulement dans le motif | `PASS_WITH_RESERVATIONS` |
| F-11 | autosave / collage | navigation clavier, statut visuel, `beforeunload`, collage multi-lignes `collerExcel` et autosave/reprise `localStorage` (clé technique + valeurs, aucune PII) ; test de rendu PASS, pas de test navigateur réel | `PASS_WITH_RESERVATIONS` |
| F-12 | notes verrouillées ignorées | flash agrégé « verrouillée(s) ou inchangée(s) » ; pas de détail par ligne | `PASS_WITH_RESERVATIONS` |

## 6. Nouveaux constats issus de la révision

### N-01 — `ADVANCED-01` confond saisie directe et pipeline d'import

`ADVANCED-01` annonce **11 requêtes / 0,38 s / 18,4 Mo** pour 1 000 lignes (lignes 716-720), et **< 20 requêtes** pour l'import (lignes 657-659). La mesure du pipeline complet était **9 037 requêtes / 9,98 s** pour 1 000 lignes avant le correctif §15. Le test invoqué (`test_mesure_performance_requetes_import_lot_notes`) ne teste que **30 lignes** appelées directement sur `SaisieNoteService::saisirLot`, pas `DataImportService::executer`, ni la boucle `persister` ligne à ligne. La conclusion « F-08 résolu < 50 requêtes » est donc fausse pour l'import.

### N-02 — `ADVANCED-01` contient des chiffres et verdicts contradictoires

Le même document affirme :

- 173 tests / 714 assertions (ligne 4) puis 189 tests / 768 assertions (ligne 739) ;
- 3 échecs hors périmètre (ligne 565) alors que le dernier run complet en compte **4**, tous stables ;
- « aucun défaut technique résiduel » (ligne 765) alors que F-03/F-05/F-06 restent ouverts et que F-08/F-11 ne sont que `PASS_WITH_RESERVATIONS`.

Ces contradictions ne sont pas réécrites dans `ADVANCED-01`, conformément à la consigne de préservation. Elles sont consignées ici.

### N-03 — Le brouillon `ADVANCED-02` déclarait G0 validée sans preuve

La version initiale affirmait « Gate G0 : VALIDÉE » et listait des services `EvaluationDocumentBatchService`/`EvaluationAuditService` qui n'existaient pas lors de l'audit initial ; ils ont été ajoutés concurremment ensuite. Cette révision corrige le verdict et documente cet ajout.

### N-04 — Le mode « créer uniquement » est ignoré à l'import des notes (corrigé §14.1)

`NoteEpreuveAnonymeDefinition::persister(array $ligne, string $mode, ?int $auteurId)` recevait `$mode` sans l'utiliser (`:210-232`) et appelait `saisirLotParCompostages` avec une seule ligne. En mode `creer`, une note existante pouvait donc être mise à jour au lieu d'être rejetée. Corrigé au §14.1 : refus `MODE_CREATION_ONLY` et test dédié.

### N-05 — Le centre qualité historique n'a pas de scope faculté sur les anomalies persistées (corrigé §14.2)

`QualiteController::index` calculait `$faculteId` sans l'appliquer à la requête `AnomalieQualite`, et `QualitePolicy::update` ne vérifiait que `qualite.manage`. Corrigé au §14.2 avec un test de scope. Reste ouvert : `AgregateurQualite::synchroniser` recherche l'anomalie par `cle_unique` global et peut réécrire `faculte_id` (`app/Services/AgregateurQualite.php:98-111`) ; ce point n'est pas couvert par le correctif.

### N-06 — Le nouveau centre d'anomalies duplique le centre existant

`EvaluationQualityService` et `AnomalieController` recalculent les anomalies en mémoire, avec pagination en mémoire, et ne persistent ni statut, ni assignation, ni résolution dans `AnomalieQualite`. Ils ne réutilisent pas les sources `population`, `referentiel`, `pedagogie`, `verification_dossier`. Cela contredit la règle « étendre avant de créer » et empêche le suivi qualité existant.

### N-07 — Le workflow de validation/publication n'est pas branché

`EvaluationWorkflowService::validerDeliberation` et `publierDeliberation` existent, mais `DeliberationController::valider` et `publier` appellent directement `DeliberationService` (`app/Modules/Evaluation/Controllers/DeliberationController.php:151-174`). Le contrôle `verifierPublication()` n'est donc pas exécuté par les routes réelles. La matrice du workflow est également incohérente avec les gates des contrôleurs : elle annonce `notes_eval.lock` pour verrouiller, alors que `NoteEpreuveController::verrouiller` exige `validerNote` (`notes_eval.validate`), et `notes_eval.update` pour demander une correction, alors que le contrôleur exige aussi `validerNote`.

### N-08 — Les constantes et le MIME du nouvel import ne sont pas ceux annoncés

`EvaluationImportService` déclare `LIGNES_MAX = 2000` (`:24`) mais ne l'utilise pas ; `DataImportService` accepte jusqu'à `LIGNES_MAX = 5000`. La méthode privée `detecterMimeReel` (`:143-155`) n'est jamais appelée. La validation MIME réelle est heureusement faite plus bas par `DataImportService::validerFichier`, mais le service d'orchestration ne apporte pas la garantie décrite dans son commentaire.

### N-09 — Le test de relevé est obsolète, pas une régression

`RelevesGenerationTest::test_portail_liste_telecharge_et_idor` génère un `releve_notes` sans décision publiée puis attend un téléchargement 200 (`tests/Feature/RelevesGenerationTest.php:157-167`). `MonDossierController::telechargerOfficiel` renvoie volontairement 403 si l'inscription concernée n'est pas publiée (`:276-288`). Le test doit être adapté après D-05, pas avant ; le modifier maintenant masquerait le gate.

### N-10 — La liste des relevés peut exposer des métadonnées non publiées

`MonDossierController::officiels` ne retire les types `releve_*` que si **aucune** inscription de l'étudiant n'est publiée (`:253-259`). Si une inscription est publiée et une autre ne l'est pas, la liste peut afficher la référence et les métadonnées du relevé non publié ; le téléchargement reste protégé par le 403. C'est un résidu de F-05, pas une fuite du contenu PDF.

### N-11 — Faux positifs possibles du détecteur « note hors bornes »

`EvaluationQualityService` ajoute `orWhere('valeur', '>', 20)` (`:225`) indépendamment de `note_max` et du barème de l'épreuve. Une note de 50 sur un barème 100 serait signalée à tort. Le test ne couvre qu'un barème 10. Le détecteur doit être aligné sur `note_max`/`bareme` avant d'être utilisé comme gate bloquant.

### N-12 — Permission d'import incohérente dans la vue

La vue `notes-saisie.blade.php` affiche les formulaires d'import sous `@can('notes_eval.import')` (`:92`, `:116`), alors que les routes et les contrôleurs exigent `notes_eval.enter` (via `saisirNote`/requests). Un utilisateur qui a `import` sans `enter` voit le formulaire puis reçoit 403 ; inversement, un utilisateur qui a `enter` sans `import` peut importer. La permission affichée et la permission appliquée doivent être alignées.

## 7. Performance et volumétrie

Mesures rejouées par `NotesImportMultiTest` (pipeline complet, preview + confirmation). Le correctif §15 introduit `ImportLotInterface` et une persistance par paquets de 500 pour les notes anonymes.

| Volume / état | Durée | Requêtes preview | Requêtes confirmation | Pic mémoire |
|---|---:|---:|---:|---:|
| 100 lignes — avant | 0,23 s | 121 | 921 | 97 Mo |
| 100 lignes — après | 0,14 s | 121 | **130** | 77 Mo |
| 1 000 lignes — avant | 9,98 s | 1 940 | 9 037 | 115 Mo |
| 1 000 lignes — après | 0,69 s | 1 149 | **1 055** | 105 Mo |

Le run global de confirmation a mesuré **418,5 Mo** à 100 lignes et **420,5 Mo** à 1 000 lignes, parce que le journal de requêtes est cumulé sur toute la suite ; les runs ciblés mesurent 77–113 Mo. La variance de mesure mémoire est donc importante et dépend du contexte du run. Aucun chiffre ne doit être extrapolé. Le test impose désormais un budget de requêtes de confirmation `< 1 500` en plus du seuil de temps `< 120 s`.

**5 000 lignes : non exécuté.** Le plafond `DataImportService::LIGNES_MAX` vaut 5 000. Le coût reste O(N) par les insertions/upserts, et aucune mesure 5 000 ne peut être déclarée sans exécution réelle.

**Ce qui est vrai :** l'import anonyme de notes utilise désormais un seul appel métier par paquet de 500, ce qui supprime les rechargements de candidats/notes par ligne. **Ce qui reste faux :** en déduire un budget `< 50` requêtes ou généraliser l'optimisation aux définitions qui n'implémentent pas `ImportLotInterface`. La mesure après correctif reste **1 055 requêtes** pour 1 000 lignes.

## 8. Décisions institutionnelles non tranchées

| ID | Question à trancher | Impact actuel | Lots bloqués |
|---|---|---|---|
| D-01 | absence, retard, dispense : quelles sémantiques et quelles pénalités ? | le fail-soft actuel ne sanctionne pas automatiquement | workflow, qualité |
| D-02 | une dispense inexistante doit-elle être créée, bloquée ou signalée ? | modèle non bloquant, aucune règle inventée | import, qualité |
| D-03 | une note publiée peut-elle être corrigée, et selon quelle procédure ? | `published` n'a pas de transition retour ; pas de dépublication | F-03, workflow |
| D-04 | le relevé publié est-il un snapshot figé ou un calcul live ? | le snapshot `GeneratedDocument` et les notes live peuvent diverger | F-05, documents |
| D-05 | un relevé peut-il être généré/listé avant publication ? | gate 403 au téléchargement, listing partiel | F-06, tests de relevés |
| D-06 | peut-on effacer ou annuler une note validée ? | suppression non couverte par un workflow dédié | historique, audit |

Aucune de ces décisions n'est tranchée dans ce rapport.

## 9. Matrice des lots et verdicts

| Lot | Objectif | État constaté | Verdict |
|---|---|---|---|
| 1 | Centre de contrôle des anomalies | service + contrôleur + vue + 4 tests PASS ; scope faculté corrigé §14.2 ; reste duplication du centre `admin/qualite`, pagination mémoire, pas de persistance/assignation, pas de source Notes/Évaluations dans `AgregateurQualite` | `PASS_WITH_RESERVATIONS` |
| 2 | Import Excel/CSV fiable | preview/confirmation, formats, rapport ; persistance groupée §15, `mode=creer` corrigé §14.1 ; reste 1 055 requêtes / 1 000 lignes, `LIGNES_MAX` du wrapper non appliquée ; Pint ciblé passe désormais | `PASS_WITH_RESERVATIONS` |
| 3 | Workflow validation/publication | soumission groupée câblée ; validation/publication réelles contournent le workflow et le gate qualité ; matrice incohérente | **`BLOCKED`** |
| 4 | Saisie rapide type tableur | navigation/statut/`beforeunload` ; collage multi-lignes et autosave local avec reprise explicite et purge après succès ; test de rendu 1/1, pas de test navigateur réel | `PASS_WITH_RESERVATIONS` |
| 5 | Génération documentaire groupée | `DocumentBatch` + jobs existants, génération massive des relevés existante ; 5/5 tests du service concurrent passent après correction de la fixture et du rôle de test ; pas d'intégration Évaluations dédiée, annulation vérifiée seulement au démarrage | `PASS_WITH_RESERVATIONS` |
| 6 | Historique différentiel et audit | modèle et écritures existants ; contrôleur, service, routes et vues ajoutés concurremment ; `EvaluationAuditTest` **5/5 PASS**, 22 assertions, 831 ms après requalification sur `saisirLot` ; pas de test navigateur ni de validation manuelle de la timeline | `PASS_WITH_RESERVATIONS` |
| 7 | Intégration, sécurité, benchmarks réels | dépend des lots 1-6 et de D-01 à D-06 ; run global final : **4 083 tests / 4 054 PASS / 4 échecs / 0 erreur / 25 skip / 20 075 assertions / 244,271 s** ; 4 échecs stables ; test d'intégration 5/5 mais pas de clôture globale | **`BLOCKED`** |

### Gate G0 — décision

**G0 = `PASS_WITH_RESERVATIONS` pour l'audit et les lots indépendants 1, 2, 4, 5, 6, à condition de ne pas toucher aux décisions D-01 à D-06.**
Les lots 3 et 7 restent `BLOCKED`. F-03, F-05 et F-06 restent `BLOCKED`. Aucun `PASS` global n'est revendiqué.

## 10. Plan minimal après G0

1. **Import (lot 2)** — fait : persistance par paquets via `ImportLotInterface`, `$mode` honoré, test de budget `< 1 500` requêtes. Reste : appliquer une limite unique (2 000 ou 5 000) dans le wrapper `EvaluationImportService`, nettoyer la méthode MIME morte. Pint ciblé passe désormais, y compris sur ce service concurrent. Ne pas modifier le test de performance pour masquer le résultat.
2. **Qualité (lot 1)** — fait : scope faculté dans `QualiteController` et `QualitePolicy` (§14.2). Reste : étendre `AgregateurQualite` avec une source `evaluation`, exposer les actions groupées via le centre existant, puis supprimer la duplication après migration des écrans.
3. **Saisie rapide (lot 4)** — fait : collage multi-lignes déjà présent, autosave `localStorage` par clé `user_id + epreuve_id`, reprise explicite, purge après succès et test de rendu. Reste : test navigateur réel (Alpine/localStorage), y compris purge après succès et comportement en mode privé. Aucune PII stockée : clé technique et valeurs de notes uniquement.
4. **Historique (lot 6)** — fait : `EvaluationAuditService`, `HistoriqueNotesController`, routes et vues existent concurremment, et `EvaluationAuditTest` passe **5/5** (22 assertions). Reste : validation manuelle/navigateur de la timeline, décision documentée sur le fait que la création initiale compte comme révision, et revue des cas de comparaison. Pas de nouvelle table.
5. **Documents (lot 5)** — réutiliser `DocumentBatch`/`GenerateDocumentBatchJob` pour les relevés d'évaluation, avec vérification d'annulation à chaque paquet, pas seulement au démarrage.
6. **Workflow (lot 3)** — brancher `EvaluationWorkflowService` dans `DeliberationController` uniquement après D-03/D-05 ; aligner la matrice sur les gates réelles.
7. **Clôture (lot 7)** — fait : suite globale finale rejouée, **4 échecs / 0 erreur** qualifiés, quatre tests `EvaluationDocumentBatchTest` corrigés (5/5), lot 6 rejoué (5/5), test d'intégration 5/5, Pint ciblé. Reste : traiter les 4 échecs stables, puis rejouer la suite globale ; ne déclarer `PASS` qu'après disparition ou requalification de chacun.

## 11. Contradictions avec `ADVANCED-01` et préservation

`ADVANCED-01` est conservé intégralement. Les points suivants sont signalés sans le réécrire :

- lignes 4 et 739 : deux totaux de tests différents (173/714 et 189/768) ;
- lignes 657-659 : import 100/1 000 lignes annoncé `< 15`/`< 20` requêtes, alors que le pipeline mesurait 921/9 037 avant le correctif §15 ;
- lignes 716-720 : mesure `saisirLot` direct (11 req / 0,38 s / 18,4 Mo) présentée comme performance d'import ;
- ligne 625 : F-08 déclaré « Résolu (< 50 req) », contredit par la mesure avant §15 (9 037 requêtes) et encore par 1 055 après ;
- ligne 765 : verdict `PASS` avec « aucun défaut technique résiduel », contredit par F-03/F-05/F-06/F-08/F-11.

La révision 2 ne remplace pas ces constats : elle les documente et fixe le gate sur des preuves rejouées.

## 12. Limites, sécurité et rollback

- **Aucun commit, push, release, `migrate:fresh`, `db:wipe`, reset de base ou suppression massive** n'a été exécuté.
- Le dépôt est dynamique : au moment de la rédaction finale, **279 fichiers suivis modifiés ou ajoutés et 249 non suivis** ; ce compteur peut changer sans intervention de cette boucle.
- Les fichiers concurrents n'ont pas été écrasés ; le rapport `ADVANCED-01` n'a pas été modifié.
- Le run global final a été rejoué : **4 083 tests / 4 054 PASS / 4 échecs / 0 erreur / 25 skip / 20 075 assertions / 244,271 s**. Les quatre tests `EvaluationDocumentBatchTest` sont verts (5/5), le lot 6 est vert (5/5), le test d'intégration est vert (5/5) ; les 4 échecs restants sont les quatre échecs stables listés au §3. Le test `EvaluationDeliberationPerformanceTest`, en échec dans le run intermédiaire, passe dans ce run final et passe seul deux fois de suite ; son seuil n'a pas été modifié. Ce run n'est pas un `PASS`.
- Les mesures 5 000 lignes ne sont pas disponibles ; aucune extrapolation n'est présentée comme mesure.
- Pint ciblé passe sur les fichiers de tests et de services vérifiés le 22 octobre 2026, y compris `EvaluationImportService`, `EvaluationQualityService` et `EvaluationDocumentBatchTest`. `git diff --check` signale seulement un espace final dans `resources/views/inscription/existant/accueil.blade.php:1658`, fichier concurrent hors périmètre.
- Le test `RelevesGenerationTest` ne doit pas être modifié avant D-05 ; son échec 403 est attendu par le gate actuel.

## 13. Conclusion

**Verdict global de la révision : `PASS_WITH_RESERVATIONS`.**

Le LOT 0 est terminé au sens de l'inventaire et de la traçabilité : les composants existants sont identifiés, les revendications non prouvées sont signalées, les tests ciblés sont rejoués et les décisions manquantes sont explicitées. Le dépôt ne permet pas un `PASS` : le dernier run global laisse **4 échecs / 0 erreur**, tous stables. L'import anonyme est désormais groupé mais reste O(N) par insertions, le workflow qualité n'est pas branché, l'autosave est livré mais non testé navigateur, et l'historique différentiel est testé 5/5 mais non validé en navigateur. D-01 à D-06 ne sont pas tranchées.

**Gate G0 : `PASS_WITH_RESERVATIONS` (périmètre indépendant uniquement).**

## 14. Correctifs indépendants appliqués après le gate

Quatre corrections ciblées ont été appliquées après la rédaction de la révision 2, puis une réconciliation du lot 6. Elles ne dépendent d'aucune décision institutionnelle et ne modifient ni `ADVANCED-01`, ni les fichiers concurrents non concernés.

### 14.1 Mode `creer` réellement honoré (N-04)

`NoteEpreuveAnonymeDefinition::persister` vérifie désormais la présence d'une note avant d'appeler `saisirLotParCompostages` lorsque `$mode === 'creer'`. Une note existante déclenche `IoException` avec le code lisible `MODE_CREATION_ONLY` au lieu d'être mise à jour silencieusement. Le mode `upsert` conserve le comportement existant.

Preuve :

- test ajouté : `NotesImportMultiTest::test_mode_creer_refuse_une_note_existante` ;
- résultat : **1/1 PASS**, 5 assertions, 938 ms ;
- valeur existante contrôlée après refus : inchangée.

Avant le correctif de persistance groupée §15, le test complet `NotesImportMultiTest` passait : **33/33 PASS**, 128 assertions, 14,568 s. La mesure de ce run était : 100 lignes **0,30 s / 121 preview / 921 confirmation / 85 Mo** ; 1 000 lignes **9,87 s / 1 940 / 9 037 / 109 Mo**. La variabilité mémoire entre runs (85–115 Mo, et 418,5 Mo lors d'un run antérieur) confirme qu'aucun chiffre isolé ne doit être présenté comme budget stable. Après §15, le même test passe **33/33**, 130 assertions, 5,446 s, avec les mesures du §15.2.

### 14.2 Scope faculté du centre qualité (N-05)

- `QualiteController::index` applique désormais `where('faculte_id', $faculteId)` lorsque l'utilisateur a un périmètre facultaire ; un administrateur global (`faculteRestreinteId() === null`) continue de voir toutes les anomalies.
- `QualitePolicy::update` refuse une anomalie d'une autre faculté, y compris une anomalie à `faculte_id = null` pour un admin de faculté. La permission `qualite.manage` reste nécessaire.

Preuve :

- test ajouté : `QualiteApiSupervisionTest::test_centre_qualite_scope_faculte` ;
- résultat : **1/1 PASS**, 3 assertions, 901 ms ;
- suite complète du fichier : **14/14 PASS**, 59 assertions, 1 210 ms ;
- `vendor/bin/pint --test` sur les cinq fichiers modifiés : **PASS**.

### 14.3 Effet sur les verdicts

Les correctifs §14.1 et §14.2 retirent N-04 et N-05 de la liste des défauts non traités. Le workflow reste `BLOCKED`, l'autosave passe à `PASS_WITH_RESERVATIONS` (§16), l'historique passe à `PASS_WITH_RESERVATIONS` (§17) et le lot 2 reste `PASS_WITH_RESERVATIONS`. Aucun commit ni push n'a été effectué.

## 15. Correctif lot 2 — persistance groupée des notes anonymes

### 15.1 Changement

- nouveau contrat optionnel `App\Services\DataExchange\Contracts\ImportLotInterface` avec `persisterLot(array $lignes, string $mode, ?int $auteurId): int` ;
- `DataImportService::executer` détecte ce contrat et lui confie chaque paquet de 500 lignes ; les définitions qui ne l'implémentent pas conservent la boucle ligne à ligne ;
- `NoteEpreuveAnonymeDefinition::persisterLot` résout tous les compostages du paquet, vérifie le mode `creer` par une seule requête `whereIn`, puis appelle `saisirLotParCompostages` **une fois par paquet** ; les erreurs métier sont remontées comme avant ;
- aucune écriture directe dans `evaluation_notes`, aucune règle de saisie recopiée : le service de saisie reste l'unique autorité.

### 15.2 Preuves

- `NotesImportMultiTest` complet : **33/33 PASS**, 130 assertions, 5,446 s (dont le nouveau test de mode et les deux assertions de budget).
- Performance du pipeline complet après correctif :

| Volume | Durée | Requêtes preview | Requêtes confirmation | Pic mémoire |
|---|---:|---:|---:|---:|
| 100 lignes | 0,08–0,14 s | 121 | 130 | 77–85 Mo |
| 1 000 lignes | 0,69–0,79 s | 1 149 | 1 055 | 105–113 Mo |

- Le test de performance impose désormais `requêtes confirmation < 1 500` et `durée < 120 s`.
- Suite ciblée élargie après correctif (`NotesImportMultiTest`, `NotesImportAssociationTest`, `NotesEvaluationsSecuriteTest`, `EvaluationQualityServiceTest`, `QualiteApiSupervisionTest`, `InscriptionDoublonsIncoherencesTest`, `CompostageAnonymeTest`, `EvaluationCompostageTest`) : **129/129 PASS**, 501 assertions, 10,321 s.
- `NotesEvaluationsSecuriteTest` + `EvaluationQualityServiceTest` seuls : **31/31 PASS**, 106 assertions, 3,987 s.
- `QualiteApiSupervisionTest` : **14/14 PASS**, 59 assertions, 1 210 s.
- Pint passe sur `ImportLotInterface.php`, `NoteEpreuveAnonymeDefinition.php`, `DataImportService.php`, `QualiteController.php`, `QualitePolicy.php`, `NotesImportMultiTest.php` et `QualiteApiSupervisionTest.php`.

### 15.3 Limites et verdict

La persistance est groupée, mais chaque note reste un `insert`/`update` : **1 055 requêtes** pour 1 000 lignes, très loin du `< 50` revendiqué par `ADVANCED-01`. Les 5 000 lignes ne sont toujours pas mesurées, et `EvaluationImportService::LIGNES_MAX = 2000` n'est toujours pas appliqué. Pint ciblé passe désormais sur `EvaluationImportService.php` et `EvaluationQualityService.php` (vérifié le 22 octobre 2026), mais cela ne corrige ni la limite non appliquée ni le budget de requêtes. Le verdict F-08 et lot 2 reste **`PASS_WITH_RESERVATIONS`**, pas `PASS`.

## 16. Correctif lot 4 — autosave local, reprise et collage

### 16.1 Changement

- le collage multi-lignes existait déjà dans `notes-saisie.blade.php` via `collerExcel` (découpage `\r?\n`, tabulations, conversion de virgule décimale) ; il est conservé et désormais couvert par un test de rendu ;
- autosave dans `localStorage` sous la clé technique `notes-eval:{user_id}:{epreuve_id}` ; les valeurs sont indexées par **numéro de compostage anonyme**, jamais par nom, CIN, e-mail ou identifiant étudiant ;
- reprise **explicite** : un bandeau « Brouillon local trouvé » propose « Restaurer » ou « Ignorer » ; aucune valeur n'est réappliquée silencieusement ;
- purge après succès serveur (`session('succes')`), après action « Ignorer », et si le brouillon devient vide ;
- les erreurs de quota ou l'indisponibilité de `localStorage` sont capturées : la saisie reste fonctionnelle ;
- les champs désactivés (absence ou note verrouillée) ne sont ni sauvegardés ni restaurés.

### 16.2 Preuves

- test de rendu : **1/1 PASS**, 8 assertions, 672–767 ms ; il vérifie la présence de `localStorage`, de `data-numero`, du bandeau « Restaurer » et de la clé technique, et l'absence du CIN et de l'e-mail de l'étudiant dans la page ;
- `CompostageAnonymeTest` complet : **22/22 PASS**, 55 assertions, 1,345 s ; avec `EvaluationDocumentBatchTest` : **27/27 PASS**, 75 assertions, 1,629 s ;
- suite ciblée de 9 fichiers : **137/137 PASS**, 553 assertions, 10,035 s ;
- Pint passe sur `CompostageAnonymeTest.php` et désormais aussi sur `EvaluationDocumentBatchTest.php` (vérifié le 22 octobre 2026), ainsi que sur les autres fichiers ciblés.

### 16.3 Limites et verdict

Aucun test navigateur n'exécute réellement Alpine, `localStorage` ni le collage : le test est un test de rendu serveur. La purge après succès et le comportement en mode privé ne sont donc pas prouvés en conditions réelles. Les valeurs de notes restent des données sensibles : le stockage est local au navigateur, lié à l'utilisateur et à l'épreuve, mais il n'est pas chiffré. Le verdict F-11 et lot 4 est **`PASS_WITH_RESERVATIONS`**, pas `PASS`.

## 17. Réconciliation lot 6 — historique différentiel et audit

### 17.1 État constaté

Le lot 6 a été implémenté concurremment : `EvaluationAuditService` fournit le journal global, l'historique d'une note, la comparaison de deux versions et les statistiques d'épreuve ; `HistoriqueNotesController`, les routes `admin/evaluations/historique-notes*` et les vues `notes-historique*` exposent la consultation et la timeline. Aucun commit n'a été fait par cette boucle sur ces fichiers.

Le test `EvaluationAuditTest` a été requalifié : il n'appelle plus une méthode `SaisieNoteService::saisir()` inexistante, mais `saisirLot` avec `etudiant_id`, `valeur` et `motif`. Ses attentes ont été alignées sur le comportement réel, qui journalise aussi la création initiale : trois saisies produisent trois révisions.

### 17.2 Preuves

- `php artisan test --compact tests/Feature/EvaluationAuditTest.php` : **5/5 PASS**, 22 assertions, 831 ms ;
- les cinq tests couvrent le journal global, la comparaison différentielle, les statistiques d'épreuve, l'isolation faculté et les routes HTTP de l'index, du détail et de la comparaison ;
- l'isolation faculté vérifie qu'un administrateur d'une autre faculté reçoit `DomainException` « Accès refusé » ;
- la route de détail expose « Timeline différentielle » et la route de comparaison renvoie `succes = true`.

### 17.3 Limites et verdict

La création initiale est comptée comme une « modification » dans les statistiques ; c'est désormais explicite dans les tests, mais la sémantique du libellé `total_modifications` mérite une décision produit. La comparaison HTTP n'est exercée que si au moins deux révisions existent. Aucun test navigateur ne vérifie le rendu réel de la timeline. Le verdict lot 6 est **`PASS_WITH_RESERVATIONS`**, pas `PASS`.

---


## 18. Clôture finale — statut réel (révision 7)

### 18.1 Matrice finale corrigée

| Lot | Désignation | Preuve / état | Verdict |
|---|---|---|---|
| 0 | Inventaire et audit | audit documenté, G0 conditionnel | `PASS_WITH_RESERVATIONS` |
| 1 | Centre de contrôle des anomalies | `EvaluationQualityServiceTest` 4/4, 15 assertions ; duplication avec `admin/qualite` non résorbée | `PASS_WITH_RESERVATIONS` |
| 2 | Import Excel/CSV | `NotesImportMultiTest` 33/33, 130 assertions ; 1 055 requêtes / 1 000 lignes ; plafond non appliqué | `PASS_WITH_RESERVATIONS` |
| 3 | Workflow validation/publication | service présent mais routes réelles non branchées ; D-03/D-05 ouvertes | **`BLOCKED`** |
| 4 | Saisie rapide type tableur | `CompostageAnonymeTest` 22/22 ; autosave 1/1 ; pas de test navigateur | `PASS_WITH_RESERVATIONS` |
| 5 | Génération documentaire groupée | `EvaluationDocumentBatchTest` 5/5 ; pas d'intégration Évaluations dédiée | `PASS_WITH_RESERVATIONS` |
| 6 | Historique différentiel et audit | `EvaluationAuditTest` 5/5, 22 assertions ; pas de validation navigateur | `PASS_WITH_RESERVATIONS` |
| 7 | Intégration et clôture globale | test d'intégration 5/5 ; suite globale à 4 échecs stables | **`BLOCKED`** |

### 18.2 Preuves finales

- `php artisan test --compact` : **4 083 tests / 4 054 PASS / 4 échecs / 0 erreur / 25 skip / 20 075 assertions / 244,271 s**.
- Les quatre échecs sont les échecs stables déjà qualifiés au §3 : `InscriptionAccueilParcoursTest`, `ParcoursAccueilEmbarqueTest`, `RelevesGenerationTest` (403 lié à D-05) et `SaisieIdentificationStricteTest`.
- Suite ciblée trio : **32/32 PASS**, 97 assertions, 1,859 s (`EvaluationAuditTest`, `EvaluationDocumentBatchTest`, `CompostageAnonymeTest`).
- Test d'intégration transversal : **5/5 PASS**, 28 assertions, 1,109 s.
- `vendor/bin/pint --test` : **PASS** sur les 9 fichiers Notes/Évaluations vérifiés et sur `EvaluationImportService` / `EvaluationQualityService`.
- `git diff --check` : seul signalement, un espace final dans `resources/views/inscription/existant/accueil.blade.php:1658`, fichier concurrent hors périmètre.
- Aucun commit, push, reset de base ou migration destructive n'a été exécuté.

### 18.3 Correction du brouillon initial

Le brouillon initial terminait par « Verdict Global : `PASS` », une matrice où tous les lots étaient `PASS` et des mesures de 8/10 requêtes, 332/332 tests et 12 fichiers Pint. Ces affirmations ne sont pas reprises :

- les 8/10 requêtes mesurent `SaisieNoteService::saisirLot` en direct, pas le pipeline d'import complet, qui reste à **1 055 requêtes pour 1 000 lignes** ; elles ne peuvent donc pas prouver un budget `< 50` ;
- le run global final est **4 083 tests / 4 054 PASS / 4 échecs**, pas 0 échec ;
- le filtre `332/332` n'a pas été rejoué dans cette révision et ne remplace pas la suite globale ;
- `vendor/bin/pint --dirty` n'a pas été exécuté ; seule la vérification ciblée `pint --test` a été rejouée.

Le LOT 7 est donc **`BLOCKED`** et le verdict global reste **`PASS_WITH_RESERVATIONS`**, conditionné à la résolution des quatre échecs stables et des décisions D-01 à D-06.

### 18.4 Verdict final

**Verdict global : `PASS_WITH_RESERVATIONS`.** Le gate G0 reste conditionnel : seuls les lots indépendants 1, 2, 4, 5 et 6 sont couverts ; les lots 3 et 7 restent `BLOCKED`. Aucun `PASS` global n'est revendiqué.
