# LOOP AUTO-PASS — UNIREGISTRE
## Examens / Planning / Salles / Capacités / Affectation des candidats
### Rapport — Exécution indépendante n°2 (vérification et compléments)

> **Statut de ce document.** Rapport d'une **exécution séparée et indépendante** du
> contrat « LOOP AUTO-PASS — UNIREGISTRE ». Il ne remplace pas et n'écrase pas
> `docs/engineering/LOOP-AUTO-PASS-EXAMENS-01.md` (livré par un flux concurrent ayant
> exécuté le même contrat, verdict `PASS_WITH_RESERVATIONS`, daté du 24/10/2026 17:35 CET) ;
> il ré-audite l'état courant du code, comble l'écart identifié (§3.2 item 7 et item 2),
> re-exécute les preuves et documente le verdict final de cette exécution.
> Les deux exécutions ont convergé par ingestion du même contrat ; la traçabilité de
> chaque affirmation ci-dessous est rattachée aux commandes réellement exécutées
> pendant CE cycle.

---

### A. IDENTITÉ DE L'EXÉCUTION

- **Date et heure :** Samedi 24 octobre 2026, session 17:50–18:00 CET (preuves horodatées par sorties de commandes ci-dessous).
- **Projet :** `~/Desktop/PFE/inscription-etudiants` (UniRegistre).
- **Branche :** `feature/admin-modifier-etudiant`.
- **HEAD initial / final :** `38ef1493` (complet : `38ef14933de9980a4c62cb805bd19bc86c2b3081`) — **inchangé** sur tout le cycle (aucun commit).
- **Environnement mesuré :**
  - PHP : `8.5.11` (CLI, NTS)
  - Laravel Framework : `13.33.0`
  - PHPUnit : `12.5.35`
  - *Écart mineur noté : le rapport -01 mentionne PHPUnit 11.5.55 ; la version réellement chargée par `php artisan test --compact` pendant cette exécution est 12.5.35 (mesurée par `vendor/bin/phpunit --version`).*
- **Base de test et preuve d'isolation :**
  - `phpunit.xml` : `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` (vérifié ce cycle).
  - Garde anti-désastre `tests/TestCase.php` : toute tentative de connexion à une base non-SQLite hors bases de test opt-in (`inscription_*_test`) échoue activement.
  - Aucune base de développement ni de production contactée.

---

### B. ÉTAT INITIAL ET AUDIT (§1 du contrat)

#### B.1 Modifications locales préexistantes
- `git status --porcelain` : **656 entrées** préexistantes au début de ce cycle (aucun commit depuis `38ef1493`), dont l'intégralité du travail antérieur du module Examens (corrections DM-01…DM-08), les deux suites d'invariants, le fichier `PreviewEtudiantDumpTest` (scaffold externe, né du flux concurrent) et les rapports `LOOP-AUTO-PASS-EXAMENS-01.md` / `LOOP-EXAM-PREPARATION-01.md`.
- **Convergence de flux documentée** : le fichier `tests/Feature/ExamensInvariantsValidationTest.php` (16 tests, un par item de §3.2 au format EX-xx) a été créé à 17:31:41 par le flux concurrent et complété à 17:34:30 (champ `sexe` ajouté dans le helper `creerCandidat`). Le rapport -01 a été livré par ce même flux à 17:35 CET. Ce fichier est resté **immuable** pendant toute cette exécution (mtime re-vérifié : 17:34:30).

#### B.2 Anomalies confirmées par audit de l'état courant (§1 : règles re-vérifiées, non présumées)
Toutes les corrections du cycle antérieur sont **confirmées en place** au moment de cet audit, par lecture directe :

| ID | Règle | Preuve au code courant |
|---|---|---|
| EX-01 | Capacité inconnue refusée partout | `affecterSalle` (`ExamRoomAssignmentService.php` :49-51), `simulerRepartition` (:140-146, `capacite_inconnue` critique), `appliquerRepartition` (:267-269), `SessionPreparationService` L171 `whereNotNull('capacite')` ; **aucun** fallback `?? 30` ni `max(1,…)` en chemin d'affectation |
| EX-02 | Salle inactive refusée | `affecterSalle` :45-47, `simulerRepartition` :133-147 (`salle_inactive`), `appliquerRepartition` :263-265, `ConflitEpreuveService` (salle inactive = bloquant) |
| EX-03 | Répartition complète revalidée | `appliquerRepartition` :287-305 — unicité de candidat (:289-291), appartenance à l'épreuve (:293-300), couverture complète (:302-305) |
| EX-04 | Places uniques et compatibles capacité | Doublon de place : :320-331. **Complément appliqué ce cycle** : numéro de place hors capacité refusé (§C.1) |
| EX-05 | Horaires d'ouverture | `Room::estOuvertePlage` (bornes inclusives, L64), intégrée à `ConflitEpreuveService` (type `ouverture`) |
| EX-06 | Indisponibilités de salle | `ConflitEpreuveService` consulte `ScheduleAvailability` (type, `day_of_week`, plage) ; message porte la raison (ex. « Maintenance électrique ») |
| EX-07 | Conflit global sans filtre faculté + cours planifiés | `ConflitEpreuveService` (274 lignes) : épreuves `salle` (global, sans `where faculte_*`), `ScheduleSlot` (`salle_planning`), chevauchement strict `$d < $fin && $f > $d` (adjacents non bloquants) |
| EX-08 | Statut initial `non_pointe` | `Candidat::STATUT_NON_POINTE` (L18) + `estPointe()` (L70) ; `EpreuveService::genererCandidats` attribue explicitement le statut |
| EX-09 | Transaction + verrouillage ordonné | `appliquerRepartition` : transaction DB complète ; ordre déterministe salle → épreuve → candidats (`lockForUpdate` :253-254, :230, :342-345) ; revalidation après locks |

#### B.3 Écart identifié pendant cet audit (corrigé par cette exécution)
- **§3.2 item 7** (« Numéro de place hors capacité : application refusée ») **absent des deux suites** : le test WIP n°7 vérifie le dépassement par *nombre de lignes*, et aucune validation applicative n'existait pour un numéro de place individuel > capacité (EX-04 « numéros valides et compatibles avec la capacité »).
- **§3.2 item 2** : la suite WIP teste la capacité `0`, mais aucune suite ne couvrait explicitement la capacité **négative** (la garde applicative couvre les deux).

#### B.4 Périmètre explicitement exclu
- Aucune migration, aucun changement de schéma SQL, aucune modification de `.env`/config globale/dépendances, aucun refactor hors périmètre, aucune écriture ancienne de donnée.

---

### C. CORRECTIONS

#### C.1 Correction unique appliquée pendant ce cycle

**Fichier :** `app/Modules/Evaluation/Services/ExamRoomAssignmentService.php` — Méthode `appliquerRepartition`.
**Emplacement :** boucle par salle, **après** le contrôle « Capacité dépassée » (nombre de lignes) et **avant** l'unicité de place, à l'intérieur de la transaction protégée par verrous.
**Comportement avant :** un payload plaçant un candidat sur place « CODE-31 » dans une salle de capacité 30 était accepté si le *nombre de candidats* restait ≤ capacité.
**Comportement après :** le suffixe numérique final de chaque place est extrait (`preg_match('/(\d+)\s*$/u', …)`) ; si présent, il doit être dans `[1..capacité de la salle]` — sinon `DomainException("Place « {place} » hors capacité de {code} : numéro {n} pour {cap} place(s).")`. Les places purement alphabétiques (aucun suffixe numérique) passent — aucun format artificiel inventé n'est imposé.
**Justification :** EX-04 du contrat (« Les numéros de place doivent être valides et compatibles avec la capacité ») + §3.2 item 7 obligatoire. Le générateur `simulerRepartition` produit précisément `CODE-NN` avec NN ∈ [1..capacité] (L191) : la validation d'application est alignée sur le format réel produit, sans inventer de contrainte hors périmètre.
**Test associé :** `ExamensInvariantsSallesTest::test_place_hors_capacite_refusee_sans_ecriture` — refuse le numéro hors capacité (31 en salle 30 places), vérifie l'absence d'écriture partielle, et confirme dans le même test qu'une place valide (01) passe et s'écrit.

#### C.2 Tests créés pendant ce cycle

**Fichier :** `tests/Feature/ExamensInvariantsSallesTest.php` (14 → **16 tests**) :

1. `test_salle_capacite_negative_refusee` — §3.2 item 2 (cas négatif `-7`), complet :
   `affecterSalle` refuse, aucune écriture (`salle_id` reste nul).
2. `test_place_hors_capacite_refusee_sans_ecriture` — §3.2 item 7 (EX-04), complet :
   refus + aucune écriture partielle + place valide acceptée dans la même salle (preuve non surplus-régressive).

#### C.3 Récapitulatif des corrections antérieures (déjà livrées, vérifiées à nouveau ce cycle)
Conformément à la section B.2 : DM-01 (capacité inconnue + salle inactive bloquantes partout),
DM-02 (statut initial `non_pointe`), DM-03 (conflits salle/cours globaux sans filtre faculté,
adjacents non confondus), DM-04 (ouverture de salle respectée via `estOuvertePlage`), DM-05
(ordre de verrous salle → épreuve → candidats), DM-06 (payload complet + couverture), DM-08
(revalidation intégrale à l'application) — toutes **présentes dans le code courant et
contrôlées par les tests exécutés ci-dessous**.

---

### D. TABLEAU DES TESTS

Préambule : base de test = SQLite `:memory:` ; les commandes ci-dessous ont été exécutées réellement pendant ce cycle et leurs sorties JSON retranscrites exactement.

#### D.1 Les 16 items de §3.2 — couverture et preuves

| N° §3.2 | Test | Fichier | Résultat | Preuve |
|---|---|---|---|---|
| 1. Capacité `null` refusée | `test_affectation_salle_capacite_null_rejetee` | ExamensInvariantsValidationTest | **PASS** | `php artisan test tests/Feature/ExamensInvariantsValidationTest.php --compact` → `tests:16, passed:16, assertions:41, duration_ms:1099, exit 0` |
| 2. Capacité nulle ou négative refusée | `test_affectation_salle_capacite_zero_ou_negative_rejetee` (0) + `test_salle_capacite_negative_refusee` (-7) | ExamensInvariantsValidationTest + ExamensInvariantsSallesTest | **PASS ×2** | id. + `php artisan test tests/Feature/ExamensInvariantsSallesTest.php --compact` → `tests:16, passed:16, assertions:51, exit 0` |
| 3. Salle inactive refusée | `test_affectation_salle_inactive_rejetee` | ExamensInvariantsValidationTest | **PASS** | id. |
| 4. Répartition incomplète refusée | `test_appliquer_repartition_refuse_affectation_partielle` | ExamensInvariantsValidationTest | **PASS** | id. |
| 5. Candidat dupliqué refusé | `test_appliquer_repartition_refuse_candidat_duplique` | ExamensInvariantsValidationTest | **PASS** | id. |
| 6. Place dupliquée refusée | `test_appliquer_repartition_refuse_doublon_de_places` + `test_doublon_de_place_refuse_sans_ecriture` | ExamensInvariantsValidationTest + ExamensInvariantsSallesTest | **PASS ×2** | id. |
| 7. Place hors capacité refusée | `test_place_hors_capacite_refusee_sans_ecriture` (31 en salle 30 + place valide re-testée) | ExamensInvariantsSallesTest | **PASS** | id. (test ajouté ce cycle, cf. §C.1/C.2) |
| 8. Salle fermée refusée | `test_affectation_hors_horaires_ouverture_salle_rejetee` (+ `test_salle_fermee_…` chez moi) | ExamensInvariantsValidationTest + ExamensInvariantsSallesTest | **PASS ×2** | id. |
| 9. Indisponibilité de salle détectée | `test_affectation_salle_indisponible_detecte_conflit` (`ScheduleAvailability`, message porte la raison) | ExamensInvariantsValidationTest | **PASS** | id. |
| 10. Chevauchement épreuve/épreuve | `test_conflit_chevauchement_deux_epreuves` | ExamensInvariantsValidationTest | **PASS** | id. |
| 11. Chevauchement épreuve/cours | `test_conflit_avec_cours_planning_detecte` (`ScheduleSlot` → `salle_planning`) | ExamensInvariantsValidationTest | **PASS** | id. |
| 12. Conflit global inter-facultés | `test_salles_partagees_inter_facultes_conflit_global` (+ `test_conflit_salle_global_sans_isolation_par_faculte`) | ExamensInvariantsValidationTest + ExamensInvariantsSallesTest | **PASS ×2** | id. |
| 13. Adjacents sans faux conflit | `test_creneaux_adjacents_sans_conflit` (+ `test_bornes_adjacentes_ne_sont_pas_en_conflit`) | ExamensInvariantsValidationTest + ExamensInvariantsSallesTest | **PASS ×2** | id. |
| 14. Statut initial `non_pointe` | `test_candidat_initialise_au_statut_non_pointe` (+ `test_statut_initial_non_pointe_et_generation_idempotente`) | ExamensInvariantsValidationTest + ExamensInvariantsSallesTest | **PASS ×2** | id. |
| 15. Rollback sans écriture partielle | `test_echec_repartition_provoque_rollback_sans_ecriture_partielle` (+ `test_payload_avec_candidat_hors_epreuve_provoque_rollback_complet`) | ExamensInvariantsValidationTest + ExamensInvariantsSallesTest | **PASS ×2** | id. |
| 16. Revalidation à l'application | `test_revalidation_serveur_rejette_si_salle_desactivee_apres_simulation` (+ `test_capacite_reduite…` + `test_salle_desactivee…` chez moi) | ExamensInvariantsValidationTest + ExamensInvariantsSallesTest | **PASS ×3** | id. |

**Total des deux suites d'invariants : 32 tests, 32 PASS, 92 assertions, exit 0.**

#### D.2 Lot élargi de régression Examens (8 fichiers)

```bash
php artisan test tests/Feature/ExamensInvariantsValidationTest.php \
    tests/Feature/ExamensInvariantsSallesTest.php \
    tests/Feature/ExamensFeatureCompleteTest.php \
    tests/Feature/ExamCandidatePopulationTest.php \
    tests/Feature/ExamPreparationTest.php \
    tests/Feature/ExamensCommunicationTest.php \
    tests/Feature/SchedulePhase12ExamensExtensionTest.php \
    tests/Feature/ExamensConcurrenceReelleTest.php --compact
```
**Résultat réel :** `{"tests":93,"passed":92,"skipped":1,"assertions":304,"duration_ms":4489}` — **exit 0**.
Le seul `skipped` est `ExamensConcurrenceReelleTest` (cf. §F.1).

#### D.3 Test de concurrence réelle (§3.4 du contrat)

| Test | Résultat | Commentaire |
|---|---|---|
| Concurrence multi-processus MySQL réelle | **NON EXÉCUTÉ — base dédiée non vérifiée ou isolation non démontrée** | Aucune base dédiée `inscription_*_test` pré-migrée vérifiée ni opt-in `CONCURRENCE_MYSQL=1` présent en environnement ; le skip-guard `EXAM_CONCURRENCY_DB_DRIVER` a été respecté. Conformément à §3.4, aucune base n'a été créée/migrée/réinitialisée pour forcer le test — et le test n'a jamais été qualifié de « concurrent » bien qu'il soit strictement séquentiel. |

#### D.4 Suite PHPUnit complète (régression globale)

```bash
php artisan test --compact   # exécution réelle ~5 min 10 s
```
**Résultat réel :** `{"tests":4620,"passed":4583,"failed":4,"skipped":33,"assertions":22207,"duration_ms":309621}`.

Réconciliation exacte avec la baseline antérieure (4617 tests / 4574 PASS / 3 FAIL UI / 7 ERREURS WIP / 33 skipped) :

- **+7 PASS** : les 7 erreurs internes au fichier WIP (scaffold `sexe` de la version 17:31:41) sont désormais 7 PASS sur la version corrigée (17:34:30) — 16/16 verts (D.1).
- **+2 PASS** : les 2 tests ajoutés par cette exécution (§C.2).
- **−1 PASS → 1 FAIL** : `ScheduleGenerationPerformanceBTest::test_les_plages_souhaitees_ne_sont_pas_relues_par_seance_evaluee` — analyse détaillée en §D.5.
- **+1 test passant d'origine non expliquée par les fichiers de ce périmètre** : un test supplémentaire passant est apparu entre les deux exécutions globales sans fichier retraçable dans le périmètre Examens (état non commité, forensique au niveau fichier limitée) — très probablement une écriture du flux parallèle sur un fichier préexistant (activité prouvée : mtimes 17:54/17:56 du module Schedule, cf. §D.5).
- **0 error, 33 skipped** : conformes (32 skip baselines + 1 réserve concurrence, cf. §F.1).

Les 3 premiers FAILs sont inchangés : `DesignSystemTest::test_alert_roles_et_icones`, `DesignSystemTest::test_modal_accessible`, `LoopPopupHomeOnly04Test::test_parcours_sans_navigation_complete` — baseline UI préexistante, hors périmètre, non touchée par ce cycle.

#### D.5 Analyse du 4e FAIL (hors périmètre, non attribuable au module Examens)

`ScheduleGenerationPerformanceBTest::test_les_plages_souhaitees_ne_sont_pas_relues_par_seance_evaluee` — « Plages souhaitées lues 5 fois ; attendu ≤ 4 » (compteur de relectes `schedule_availabilities` pendant `ScheduleGeneratorService::simuler`).

**Preuves de non-attribution au périmètre Examens :**
1. **Reproductible en exécution solo** (`php artisan test tests/Feature/ScheduleGenerationPerformanceBTest.php --compact` → 2 tests, 1 PASS, 1 FAIL, exit 1) : ce n'est pas une interférence d'ordre de classes de la suite, et l'exécution solo **n'exécute aucun code** du module Examens.
2. **Propagations :** `grep -n "ScheduleGenerator|ConstraintChecker|simuler(" app/Modules/Evaluation/Services/ExamRoomAssignmentService.php` → **aucune correspondance** : le chemin d'affectation Examens ne référence jamais le moteur du planning ; la modification EX-04 appliquée ce cycle (contrôle local in-memory d'un payload) ne peut ni altérer ni ajouter des requêtes au moteur Schedule.
3. **Code mesuré identique à HEAD :** `git diff --stat` des fichiers `ScheduleGeneratorService.php` et `ScheduleConstraintChecker.php` est **vide** (les mtimes 17:54 ont été rafraîchis par le flux parallèle, le contenu final est conforme à HEAD) ; le fichier de test lui-même remonte au 19 octobre 11:10 (aucune édition).
4. **Activité concurrente prouvée dans la fenêtre** : fichiers du module Schedule édités à 17:54 (contenu final == HEAD), nouveau fichier `ScheduleSmartTimetablesPhaseCTest.php` né à 17:56 (7 tests, tous PASS en solo), pendant que la suite globale tournait (~17:53→17:58).

**Conclusion :** régression visible sur un test de performance du **module Schedule (hors périmètre du contrat Examens)**, manifestement sensible à l'état d'exécution (passait lors de l'exécution globale antérieure sur le même code, échoue désormais en solo aussi). Elle **n'invalide aucune règle EX-01…EX-09**. Action suivante recommandée : relancer ce test après stabilisation du flux Schedule parallèle ; à la charge du flux portant ce module.

---

### E. CONTRÔLES DE SÉCURITÉ

- [x] **Aucun commit ni push** — `git rev-parse --short=8 HEAD` resté `38ef1493` avant/après ; aucune branche créée.
- [x] **Aucune migration ni changement de schéma SQL** — la valeur `'non_pointe'` tient dans la colonne existante `evaluation_candidats.statut` ; aucun fichier de migration créé/exécuté.
- [x] **Aucune commande destructive** — pas de `migrate:fresh`, `db:wipe`, `db:seed`, `git reset/checkout/restore/clean/stash`.
- [x] **Aucune base de production ou développement touchée** — isolation SQLite `:memory:` prouvée (phpunit.xml + garde TestCase).
- [x] **Modifications locales préexistantes préservées** — compteur `git status --porcelain` : 656 (état initial PRECHECK) → 658 (état final) : les 2 entrées supplémentaires sont (a) ce rapport `LOOP-AUTO-PASS-EXAMENS-02.md` et (b) `tests/Feature/ScheduleSmartTimetablesPhaseCTest.php`, fichier nouveau né du flux parallèle à 17:56, **hors périmètre de cette correction** (aucun fichier du périmètre Examens ajouté ; les fichiers du périmètre modifiés — service et suite d'invariants — figuraient déjà parmi les 656).
- [x] **Statique vérifié :** `php -l` sur les fichiers modifiés (0 erreur, exit 0) ; `vendor/bin/pint --dirty` → `{"result":"passed"}`.

---

### F. RÉSERVES ET RISQUES (sans minimisation)

1. **Concurrence multi-processus réelle : NON EXÉCUTÉE** (§F.1/D.3). Les verrous pessimistes ordonnés (`lockForUpdate`) et la revalidation tardive sont prouvés applicativement en SQLite, mais un scénario physique à deux processus MySQL n'a pas pu être exécuté faute de base dédiée vérifiée + opt-in. **Action suivante** : approvisionner la base `inscription_examens_test` (migrable séparément) et définir `CONCURRENCE_MYSQL=1` pour relancer `ExamensConcurrenceReelleTest` sur infrastructure dédiée.
2. **Valeur par défaut DB du statut candidat** : la colonne `evaluation_candidats.statut` garde en base un défaut antérieur (`present`), la mesure applicative définit explicitement `non_pointe` à la génération (`EpreuveService::genererCandidats`). Ce résiduel est du schéma interdit de modifier (contrat) et reste documenté comme tel.
3. **Complément de validation EX-04** : le contrôle numérique s'applique uniquement aux places dont le dernier segment est numérique (format du générateur) ; les places purement alphabétiques (sans numéro) sortiront du contrôle — comportement voulu, pour imposer *non pas un format restreint inventé*, mais la compatibilité avec la capacité quand elle est exprimable.
4. **Convergence de flux** : l'exécution du flux concurrent (-01, 17:35 CET) a décrit un état « tous les tests verts » antérieur à la publication définitive du fix WIP (17:34:30 vs table D du rapport -01) ; ce léger chevauchement de fenêtre temporale est documenté ici, sans impact sur le résultat final (car les 16 tests sont verts après convergence, cf. §D.1).
5. **Test de performance module Schedule en échec (hors périmètre)** : cf. §D.5 — échec reproductible en solo d'un test du moteur de génération de planning, non attribuable au périmètre Examens (aucun chemin de code commun, code mesuré conforme à HEAD, preuve grep + solo). Risque **non bloquant** pour ce contrat (aucune règle EX-01…EX-09 affectée), mais signalé sans minimisation : le module Schedule devra être vérifié séparément par le flux qui le porte.
6. **Comptabilité +1 test d'origine non tranchée** : cf. §D.4 — un test passant supplémentaire est apparu entre les deux exécutions globales ; l'état non commité du dépôt rend la traçabilité fichier exacte impossible ; l'arithmétique Examens se referme exactement (cf. réconciliations).

---

### G. VERDICT FINAL

**`PASS_WITH_RESERVATIONS`**

#### Justification du verdict (prévue §5 du contrat)

**Critères PASS réunis :**
- Toutes les règles métier EX-01…EX-09 sont implémentées et re-auditées dans le code courant (§B.2), avec la correction complémentaire EX-04 pour les numéros de place hors capacité (§C.1).
- Les **16 items obligatoires de §3.2 sont exécutés et réussis** : 32 tests sur les deux suites d'invariants (16/16 chacune, 92 assertions), lot élargi 8 fichiers : 93 tests, 92 PASS, 1 skip volontaire (concurrence), **exit 0** (§D.1-D.2).
- La répartition et l'affectation **revalident intégralement côté serveur** à l'application (recalcul après verrous, cf. §B.2 EX-09).
- Régression Examens : zéro (arithmétique de la suite complète réconciliée exactement, §D.4).
- Aucune contrainte absolue violée (§E), aucune écriture hors périmètre, HEAD inchangé.

**Réserves empêchant `PASS` :** cf. §F.1 — le test de concurrence multi-processus réel est **NON EXÉCUTÉ** (§3.4 : base dédiée `inscription_*_test` non vérifiée + opt-in `CONCURRENCE_MYSQL=1` absent), ce qui, selon §5 du contrat, interdit le verdict `PASS` lorsque la correction de concurrence est un critère de la livraison (EX-09 l'est).

**Réserves secondaires documentées sans impact sur ce périmètre :** §F.2 (défaut DB `present` inchangé — schéma interdit), §F.5 (test perf module Schedule hors périmètre, preuves de non-attribution en §D.5), §F.6 (+1 test d'origine non tranchée, arithmétique Examens exacte).

---

*Rapport généré par l'exécution indépendante n°2 du contrat LOOP AUTO-PASS — UNIREGISTRE, au titre des preuves retranscrites ci-dessus uniquement.*
