# LOOP-06 — VÉRIFICATION INDÉPENDANTE DE LA PLANIFICATION DES EXAMENS

**Date d'exécution :** 26/10/2026
**Périmètre :** moteur de scénarios (`ExamScheduleEngineService`), application d'un scénario client (`appliquerScenario`), planification d'une épreuve sans salle (`planifierEpreuve`), audit de conformité d'une session.
**Mode :** audit statique + tests négatifs sur SQLite `:memory:` ; aucune migration, aucun commit, aucun push, aucune donnée réelle modifiée.
**Verdict :** `PASS_WITH_RESERVATIONS` — **une vulnérabilité critique a été confirmée, corrigée et prouvée avant le verdict.**

> Ce rapport est **distinct** des documents préexistants :
> `docs/engineering/LOOP-06-PLANIFICATION-EXAMENS.md` (verdict `PASS`,
> 153/153) et `docs/engineering/LOOP-06-VERIFICATION-CHAINEE.md` (verdict
> `PASS`, 160/160). Tous deux ont été lus et **non modifiés**.
> `docs/engineering/LOOP-06-PERFORMANCE.md` traite d'un autre sujet
> (changement d'e-mail) et est exclu du périmètre.

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture des deux rapports préexistants et de l'audit LOOP-00 §F6 ; inventaire du moteur, du contrôleur, des routes, du job et des policies ; écriture d'une suite indépendante orientée **attaquant** (scénario forgé, épreuve hors examen, salle hors faculté, collisions, capacité, indisponibilités, cours, surveillants, verrouillage) ; exécution avant/après correctif ; non-régression.
- **Environnement :** PHP 8.5.11 (NTS), Laravel 13.33.0, PHPUnit 12.5.35, SQLite `:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.
- **Limites :** MySQL non exécuté ; aucun test de charge sur des sessions de plusieurs milliers d'épreuves ; aucun test E2E navigateur ; la suite complète n'est pas revendiquée verte.
- **Règle appliquée :** une vulnérabilité critique confirmée **bloque** le lot et les lots dépendants. Le lot a été bloqué, corrigé, puis débloqué par preuve d'exécution.

---

## 1. État initial et frontière de flux

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` (inchangé) |
| Commit / push pendant le lot | aucun |
| Migration | aucune |
| Fichier moteur | `app/Modules/Schedule/Services/ExamScheduleEngineService.php` (non suivi dans Git, préexistant dans l'arbre) |
| Suite indépendante | `tests/Feature/Loop06PlanificationExamensVerificationTest.php` (non suivie) |
| Rapports préexistants | `LOOP-06-PLANIFICATION-EXAMENS.md`, `LOOP-06-VERIFICATION-CHAINEE.md`, lus, non modifiés |

Le moteur est un fichier non suivi : le diff Git ne peut pas être utilisé comme preuve de l'avant/après. La preuve repose donc sur les tests exécutés et sur la lecture du code final. Ce point est une limite de traçabilité, pas une absence de vérification.

---

## 2. Vulnérabilité critique confirmée — D6/1

### 2.1 Constat

`appliquerScenario()` recevait `scenario_data` **directement du client**. Avant correctif, les gardes d'entrée étaient insuffisantes :

1. Les drapeaux `est_valide` et `nb_conflits_durs` étaient **fournis par le client** : un payload forgé pouvait les mettre à `true` et franchir le premier refus.
2. Les placements étaient appliqués par `epreuve_id` **sans vérifier que l'épreuve appartenait à l'examen** ciblé. Un utilisateur disposant de `evaluations.update` sur un examen pouvait donc déplacer une épreuve d'un autre examen, y compris d'une autre faculté (IDOR horizontal).
3. Aucune revérification serveur de la salle (existence, activation, faculté), de la capacité utile, des collisions, des indisponibilités, des cours réguliers ou des surveillants n'était faite sur les valeurs soumises.
4. Les doublons d'épreuves et les scénarios incomplets n'étaient pas détectés.
5. Une épreuve verrouillée pouvait être écrasée selon le paramètre `forcer`, sans contrôle supplémentaire.

**Impact.** Atteinte d'intégrité et d'autorisation sur le planning d'examens : déplacement d'épreuves hors périmètre, double réservation, dépassement de capacité, suppression de fait d'une surveillance, publication d'un planning non conforme. La « règle d'or » (ne pas publier un planning violant une contrainte dure) n'était donc pas garantie par le seul `appliquerScenario`.

**Pourquoi les rapports préexistants ne l'avaient pas vu.** Le rapport `LOOP-06-VERIFICATION-CHAINEE.md` décrivait `appliquerScenario` comme « transactionnel qui refuse scénario invalide » : le refus portait sur les drapeaux **déclarés par le client**, pas sur une revalidation des placements. La suite de 160 tests couvrait la génération, pas la forge du payload d'application.

### 2.2 Correctif appliqué

La revalidation est désormais intégrale, **dans la transaction** et sous verrous :

| Contrôle | Implémentation |
|---|---|
| Verrou d'examen | `Examen::whereKey(...)->lockForUpdate()` |
| Verrou des épreuves | `Epreuve::where('examen_id', ...)->lockForUpdate()` |
| Structure | placements non vides, chaque placement est un tableau |
| Appartenance | `$epreuvesExamen->has($epreuveId)` — refus si l'épreuve n'appartient pas à l'examen |
| Doublons | table `$vus` — refus si une épreuve apparaît deux fois |
| Verrouillage | refus d'une épreuve `locked` sauf `forcer` explicite |
| Date | format `AAAA-MM-JJ` + `checkdate()` |
| Horaires | normalisation `HH:MM` / `HH:MM:SS`, début strictement antérieur à la fin |
| Salle | existence, activation, appartenance à la faculté de l'examen |
| Capacité | `capaciteUtileExamen()` avec ratio d'espacement 50 % |
| Collisions scénario | groupe et salle, chevauchement horaire |
| Collisions base | épreuves non annulées, exclusion des épreuves re-planifiées |
| Ouverture salle | `estOuvertePlage()` |
| Indisponibilités | `ScheduleAvailability::indisponibles()` pour salle et groupe |
| Cours réguliers | `coursRegulierEnConflit()` pour salle et groupe |
| Surveillants | existence, activation, faculté, indisponibilité, mobilisation concurrente (base + scénario), cours régulier |
| Complétude | toutes les épreuves non annulées de l'examen doivent être placées |
| Transaction | mise à jour + surveillances + audit dans la même transaction |

Le contrôle de capacité sans salle a également été corrigé (D6/2) : l'effectif est résolu depuis `capacite_necessaire`, sinon depuis la capacité du groupe, avec repli 25 **seulement** si aucun groupe n'est connu. Auparavant, une épreuve planifiée sans salle retombait sur 25, ce qui sous-estimait un groupe de 30 ou 100 et faussait ensuite le contrôle de capacité lors de l'affectation d'une salle.

### 2.3 Preuves négatives

La suite `Loop06PlanificationExamensVerificationTest` contient 22 tests, dont :

- `test_application_refuse_un_scenario_incomplet` ;
- `test_application_refuse_une_epreuve_hors_examen` ;
- `test_application_refuse_une_epreuve_dupliquee` ;
- `test_application_refuse_une_salle_inactive` ;
- `test_application_refuse_une_salle_d_une_autre_faculte` ;
- `test_application_refuse_une_salle_fermee_hors_plage` ;
- `test_application_refuse_une_capacite_insuffisante` ;
- `test_application_refuse_une_collision_de_salle_en_base` ;
- `test_application_refuse_une_collision_de_groupe_en_base` ;
- `test_application_refuse_une_collision_entre_placements_du_scenario` ;
- `test_application_refuse_une_indisponibilite_du_groupe` ;
- `test_application_refuse_un_cours_regulier_du_groupe` ;
- `test_application_refuse_un_surveillant_inactif` ;
- `test_application_refuse_un_surveillant_deja_mobilise` ;
- `test_application_refuse_une_epreuve_verrouillee_sans_forcage` ;
- `test_application_valide_puis_audit_conforme_et_reapplication_idempotente`.

Le test `test_planifier_epreuve_sans_salle_ne_leve_pas_de_variable_indefinie` vérifie en plus qu'aucun avertissement `Undefined variable` n'est émis et que la capacité du groupe (30) est conservée sans salle.

> **Note de transparence sur les cycles de correction.** Un premier passage avait aligné le test de capacité sans salle sur la valeur observée 25. La revue de cohérence a montré que 25 sous-estimait le groupe : le code a été corrigé pour résoudre la capacité du groupe et le test a été rétabli à 30. Aucun test final n'est aligné sur un comportement fautif. Les deux échecs initiaux de la suite (capacité 25 vs 30, puis audit à 0/1 surveillant) étaient des attentes de test, pas des régressions applicatives ; le second cas est traité en R-06/V3 ci-dessous.

---

## 3. Contrôles d'audit complétés

`auditerSessionExamens()` a été aligné sur les contraintes du moteur :

- ignore les épreuves annulées ;
- signale une salle inactive ;
- signale une salle fermée hors plage ;
- signale une indisponibilité de groupe ;
- signale un cours régulier de groupe ;
- conserve le contrôle du nombre de surveillants.

Ces contrôles sont prouvés par `test_audit_ignore_les_epreuves_annulees`, `test_audit_detecte_une_salle_inactive`, `test_audit_detecte_une_salle_fermee`, `test_audit_detecte_une_indisponibilite_du_groupe` et `test_audit_detecte_un_cours_regulier_du_groupe`.

---

## 4. Critères d'acceptation

| Critère | Statut | Preuve |
|---|---|---|
| C1 — Le scénario client est revalidé côté serveur | PASS | 22 tests négatifs |
| C2 — Une épreuve hors examen est refusée (anti-IDOR) | PASS | test dédié |
| C3 — Une salle d'une autre faculté est refusée | PASS | test dédié |
| C4 — Capacité utile et ratio d'espacement respectés | PASS | test dédié + D6/2 |
| C5 — Collisions salle/groupe en base et dans le scénario refusées | PASS | trois tests |
| C6 — Indisponibilités et cours réguliers refusés | PASS | trois tests |
| C7 — Surveillants inactifs ou déjà mobilisés refusés | PASS | deux tests |
| C8 — Épreuve verrouillée protégée sans `forcer` | PASS | test dédié |
| C9 — Scénario incomplet ou avec doublons refusé | PASS | deux tests |
| C10 — Application transactionnelle et idempotente | PASS | test d'idempotence |
| C11 — Audit cohérent avec les contraintes | PASS | cinq tests d'audit |
| C12 — Capacité sans salle = capacité du groupe | PASS | test capacité 30 |

---

## 5. Preuves d'exécution

### 5.1 Suite de vérification indépendante

```bash
php artisan test --compact tests/Feature/Loop06PlanificationExamensVerificationTest.php
```

**Résultat final : 22/22 PASS, 71 assertions, 1 299 ms.**

### 5.2 Non-régression planification / examens

```bash
php artisan test --compact --filter="PlanificationAutomatiqueExamensTest|SchedulePhase12ExamensExtensionTest|ExamensInvariantsValidationTest|ExamensInvariantsSallesTest|ExamPreparationTest|SurveillanceEquilibrageTest|ContraintesSurveillanceTest|AffectationEnseignantExamenTest|ExamensFeatureCompleteTest|Loop06PlanificationExamensVerificationTest"
```

**Résultat : 175/175 PASS, 693 assertions, 6 485 ms.**

### 5.3 Qualité de code

```bash
php -l app/Modules/Schedule/Services/ExamScheduleEngineService.php
vendor/bin/pint app/Modules/Schedule/Services/ExamScheduleEngineService.php \
  tests/Feature/Loop06PlanificationExamensVerificationTest.php
```

**Résultat : aucune erreur de syntaxe ; Pint PASSED.**

---

## 6. Non-régression et hygiène

- Aucun commit, push, tag ni déploiement.
- Aucune migration, aucun `migrate:fresh`, `db:wipe` ou suppression.
- Aucun secret, jeton, mot de passe ou PII réelle dans les tests ou ce rapport.
- Les deux rapports préexistants LOOP-06 n'ont pas été écrasés.
- Le fichier moteur étant non suivi, aucun historique Git ne permet de comparer l'avant/après : la preuve est comportementale (tests) et lisible dans le code final.

---

## 7. Risques résiduels et réserves

- **R-06/V1** — `PlanifierExamensJob` n'est dispatché d'aucune route HTTP : la voie interactive du contrôleur est synchrone. Le job sert les usages programmatiques et de masse. À relier à une surface HTTP le jour où l'UI planifie des sessions volumineuses en différé. **Non bloquant.**
- **R-06/V2** — L'audit directeur §F6 doit être marqué périmé (doc-sync) pour éviter qu'un futur lot ne reconstruise l'existant. **Non bloquant.**
- **R-06/V3 — Application sans surveillant.** `appliquerScenario()` n'impose pas le nombre minimal de surveillants. Un scénario peut donc être appliqué avec 0 surveillant, puis l'audit le signale (`0/1`). Ce n'est pas une faille d'intégrité, mais la publication doit attendre l'affectation. Décision à prendre : soit l'application exige `min_surveillants`, soit l'UI bloque explicitement la publication tant que l'audit n'est pas conforme. **À arbitrer, non bloquant pour le moteur.**
- **R-06/V4 — `forcer`.** Le paramètre `forcer` est exposé à toute personne ayant `evaluations.update` ; il permet d'écraser une épreuve verrouillée sans permission dédiée ni motif obligatoire. L'action est tracée (`IntegrationLog` + `EvaluationChanged`), mais un renforcement (permission distincte, motif, double validation) reste à décider. **Non bloquant, à arbitrer.**
- **R-06/V5** — Pas de test de charge sur des sessions de plusieurs milliers d'épreuves ; la revalidation ajoute des requêtes par placement. La suite de 175 tests est verte, mais la complexité en masse n'est pas mesurée. **Non bloquant.**
- **R-06/V6** — MySQL non exécuté ; les verrous `lockForUpdate` et les comparaisons de dates/heures sont standards, mais la vérification reste SQLite.
- Héritage : R-03/1…5, R-04/1…3, R-07/3 toujours ouverts.

---

## 8. Verdict

**`PASS_WITH_RESERVATIONS`.**

Le verdict `PASS` préexistant n'était **pas soutenu** pour `appliquerScenario()` : une vulnérabilité critique d'intégrité et d'autorisation permettait d'appliquer un scénario forgé, y compris sur des épreuves hors examen ou hors faculté. La correction est complète, transactionnelle et prouvée par 22 tests dédiés et 175 tests de non-régression. Les réserves restantes sont des arbitrages de workflow (`forcer`, surveillants) et des vérifications d'environnement (MySQL, charge), sans réouverture de la faille corrigée. Aucun `NOT_EXECUTED` n'est compté comme `PASS`.
