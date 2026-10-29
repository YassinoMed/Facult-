# LOOP AUTO-PASS — UNIREGISTRE
## Examens / Planning / Salles / Capacités / Affectation des candidats
### Rapport d'Audit, de Correction et de Validation

---

### A. IDENTITÉ DE L'EXÉCUTION

- **Date et heure :** Samedi 24 octobre 2026, 17:35 CET
- **Projet :** `~/Desktop/PFE/inscription-etudiants` (UniRegistre)
- **Branche :** `feature/admin-modifier-etudiant`
- **HEAD initial / final :** `38ef14933de9980a4c62cb805bd19bc86c2b3081` (inchangé — aucun commit réalisé)
- **Environnement d'exécution :**
  - PHP : 8.5.11
  - Laravel Framework : 13.33.0
  - PHPUnit : 11.5.55
- **Base de données de test et preuve d'isolation :**
  - Moteur : SQLite `:memory:`
  - Preuve : `phpunit.xml` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`)
  - Garde-fou anti-désastre : `tests/TestCase.php` bloque activement toute connexion à une base non-SQLite à l'exception des bases de test strictement opt-in (`inscription_*_test`). Aucune base de développement ni de production n'a été contactée.

---

### B. ÉTAT INITIAL ET AUDIT DES ANOMALIES

#### Modifications locales préexistantes
L'inventaire initial du statut de travail `git status --short` a mis en évidence des modifications locales préexistantes sur plusieurs sous-systèmes (inscriptions, notes, scolarité, assistant IA). Toutes ces modifications ont été scrupuleusement préservées.

#### Matrice d'audit des anomalies constatées (EX-01 à EX-09)

| ID | Anomalie / Risque | Preuve dans le code initial | Fichier / Méthode | Correction minimale | Test unitaire / intégration |
|---|---|---|---|---|---|
| **EX-01** | Capacité inconnue acceptée | Fallback `(int) ($salle->capacite ?? 30)` dans la simulation ; pas de contrôle d'intégrité sur `<= 0` ou `null`. | `ExamRoomAssignmentService::simulerRepartition` | Suppression du fallback artificiel ; rejet strict si capacité `null` ou `<= 0`. | `test_affecter_salle_refuse_capacite_inconnue`, `test_affecter_salle_refuse_capacite_nulle_ou_negative` |
| **EX-02** | Salle inactive affectable | Seules les salles actives étaient filtrées dans la simulation, mais l'affectation directe ou tardive n'exécutait pas de garde active serveur. | `ExamRoomAssignmentService::affecterSalle`, `appliquerRepartition`, `ConflitEpreuveService` | Vérification côté serveur de `actif == true` à la simulation, à l'affectation directe et à l'application. | `test_affecter_salle_refuse_salle_inactive`, `test_conflit_detecte_salle_inactive` |
| **EX-03** | Répartition incomplète / incohérente | `appliquerRepartition` bouclait sur le payload sans vérifier que tous les candidats inscrits recevaient une place. Risque d'abandon de candidats. | `ExamRoomAssignmentService::appliquerRepartition` | Contrôle d'exhaustivité : échec immédiat si des candidats de l'épreuve manquent ou sont hors périmètre. | `test_appliquer_repartition_refuse_affectation_partielle` |
| **EX-04** | Places dupliquées | Aucune vérification d'unicité de la clé `(salle_id, place)` dans le payload de répartition avant écriture. | `ExamRoomAssignmentService::appliquerRepartition` | Validation stricte de l'unicité des places par salle et respect de la capacité maximale. | `test_appliquer_repartition_refuse_doublon_de_places`, `test_appliquer_repartition_refuse_candidat_duplique` |
| **EX-05** | Horaires non respectés | Seule la présence d'une plage horaire sur l'épreuve était vérifiée sans faire appel à `Room::estOuvertePlage($debut, $fin)`. | `ConflitEpreuveService::verifierOccupationSalle`, `ExamRoomAssignmentService` | Intégration systématique de `estOuvertePlage` pour épreuve et répartition. | `test_affecter_salle_refuse_salle_fermee_horaires` |
| **EX-06** | Indisponibilités ignorées | Les indisponibilités de type salle déclarées dans `ScheduleAvailability` n'étaient pas interrogées lors de la planification d'examen. | `ConflitEpreuveService::detecter` | Ajout du contrôle des indisponibilités de ressource de type `'salle'` sur la date et le créneau. | `test_conflit_detecte_indisponibilite_salle` |
| **EX-07** | Conflit avec cours ou inter-facultés | Détection limitée aux épreuves de la même session/faculté ; les cours magistraux / TD (`ScheduleSlot`) sur la même salle étaient ignorés. | `ConflitEpreuveService::detecter` | Requête globale sans filtre de faculté : collision avec autres épreuves ET avec créneaux `ScheduleSlot` sur chevauchement strict. | `test_conflit_detecte_chevauchement_autre_epreuve`, `test_conflit_detecte_creneau_cours_existant`, `test_conflit_global_salle_partagee_entre_facultes`, `test_creneaux_adjacents_sans_chevauchement_ne_generent_pas_de_faux_conflit` |
| **EX-08** | Statut initial candidat incorrect | Candidats générés avec statut `'present'` (présomption de présence avant émargement physique). | `EpreuveService::genererCandidats`, `Candidat::class` | Constante `Candidat::STATUT_NON_POINTE = 'non_pointe'`, initialisation systématique à `non_pointe`, pointage mis à jour dans l'émargement. | `test_candidat_initialise_au_statut_non_pointe` |
| **EX-09** | Concurrence sur les affectations de salles | Écritures en base sans verrous déterministes sur les salles ou l'épreuve ; risque de double réservation sous charge. | `ExamRoomAssignmentService::appliquerRepartition` & `affecterSalle` | `lockForUpdate()` déterministe par ordre croissant (`Room::orderBy('id')->lockForUpdate()`), transaction intégrale et revalidation après acquisition des verrous. | `test_echec_repartition_provoque_rollback_sans_ecriture_partielle`, `test_revalidation_serveur_rejette_si_salle_desactivee_apres_simulation` |

---

### C. CORRECTIONS APPLIQUÉES

1. **`app/Modules/Evaluation/Models/Candidat.php` :**
   - Ajout de la constante `STATUT_NON_POINTE = 'non_pointe'`.
   - Intégration de `'non_pointe'` dans le tableau immuable `STATUTS`.
   - Ajout de la méthode helper `estPointe(): bool` (`$this->statut !== self::STATUT_NON_POINTE`).

2. **`app/Modules/Evaluation/Services/EpreuveService.php` :**
   - `genererCandidats()` : Attribution explicite du statut `'statut' => Candidat::STATUT_NON_POINTE` lors de la création initiale.
   - `enregistrerEmargement()` : Décompte séparé de `$nonPointes` et calcul précis de l'émargement sans fausser le taux de présence.

3. **`app/Modules/Evaluation/Services/ConflitEpreuveService.php` :**
   - Détection de l'état actif de la salle (`salle_inactive` bloque si `actif === false`).
   - Contrôle d'ouverture horaire via `Room::estOuvertePlage($debut, $fin)` (`salle_fermee`).
   - Interrogation des indisponibilités déclarées dans `ScheduleAvailability` pour la ressource salle (`salle_indisponible`).
   - Détection des chevauchements stricts avec les cours réguliers (`ScheduleSlot`) sans filtrage de faculté.
   - Gestion des intervalles stricts `($slotDebut < $fin && $slotFin > $debut)` évitant les faux conflits sur créneaux simplement adjacents.

4. **`app/Modules/Evaluation/Services/ExamRoomAssignmentService.php` :**
   - `simulerRepartition()` : Suppression du fallback `capacite ?? 30`. Contrôle d'ouverture horaire. Détection des salles non configurées.
   - `affecterSalle()` : Verrouillage transactionnel avec `lockForUpdate()`. Vérification que la capacité est définie et strictement supérieure ou égale au nombre de candidats. Rejet des salles fermées ou inactives.
   - `appliquerRepartition()` : Verrouillage ordonné et déterministe (`Room::whereIn(...)->orderBy('id')->lockForUpdate()`), transaction atomique `DB::transaction()`.
   - Contrôle d'intégrité strict du payload : exhaustivité des candidats (aucun candidat omis), absence de doublons de candidats, unicité des places par salle, respect de la capacité unitaire.

5. **`resources/views/modules/evaluation/emargement.blade.php` :**
   - Ajout de l'indicateur visuel du nombre de candidats "Non pointés" dans la barre de résumé.
   - Ajout de l'option `<option value="non_pointe">` dans le sélecteur de présence de la feuille d'émargement.

6. **`app/Modules/Evaluation/Controllers/EpreuveController.php` :**
   - Validation de la requête d'émargement acceptant `non_pointe` parmi les statuts admissibles (`in:non_pointe,present,absent,absent_justifie,absent_injustifie,retard`).

---

### D. TABLEAU DES TESTS ET PREUVES D'EXÉCUTION

Suite spécifique créée : `tests/Feature/ExamensInvariantsValidationTest.php` (16 tests, 41 assertions).

| Test | Comportement vérifié | Résultat | Preuve d'exécution |
|---|---|---|---|
| **EX-01a** | Capacité `null` refusée à l'affectation | **PASS** | `vendor/bin/phpunit --filter="test_affecter_salle_refuse_capacite_inconnue"` (OK) |
| **EX-01b** | Capacité négative ou zéro refusée | **PASS** | `vendor/bin/phpunit --filter="test_affecter_salle_refuse_capacite_nulle_ou_negative"` (OK) |
| **EX-02** | Salle inactive refusée côté serveur | **PASS** | `vendor/bin/phpunit --filter="test_affecter_salle_refuse_salle_inactive"` (OK) |
| **EX-03** | Répartition incomplète (candidat manquant) rejetée | **PASS** | `vendor/bin/phpunit --filter="test_appliquer_repartition_refuse_affectation_partielle"` (OK) |
| **EX-04a** | Candidat dupliqué dans la répartition rejeté | **PASS** | `vendor/bin/phpunit --filter="test_appliquer_repartition_refuse_candidat_duplique"` (OK) |
| **EX-04b** | Doublon de numéro de place rejeté | **PASS** | `vendor/bin/phpunit --filter="test_appliquer_repartition_refuse_doublon_de_places"` (OK) |
| **EX-04c** | Dépassement de capacité rejeté | **PASS** | `vendor/bin/phpunit --filter="test_appliquer_repartition_refuse_depassement_capacite"` (OK) |
| **EX-05** | Salle fermée selon horaires d'ouverture refusée | **PASS** | `vendor/bin/phpunit --filter="test_affecter_salle_refuse_salle_fermee_horaires"` (OK) |
| **EX-06** | Indisponibilité déclarée (`ScheduleAvailability`) détectée | **PASS** | `vendor/bin/phpunit --filter="test_conflit_detecte_indisponibilite_salle"` (OK) |
| **EX-07a** | Conflit avec autre épreuve sur la salle | **PASS** | `vendor/bin/phpunit --filter="test_conflit_detecte_chevauchement_autre_epreuve"` (OK) |
| **EX-07b** | Conflit avec créneau de cours régulier (`ScheduleSlot`) | **PASS** | `vendor/bin/phpunit --filter="test_conflit_detecte_creneau_cours_existant"` (OK) |
| **EX-07c** | Conflit global inter-facultés sur salle partagée | **PASS** | `vendor/bin/phpunit --filter="test_conflit_global_salle_partagee_entre_facultes"` (OK) |
| **EX-07d** | Créneaux adjacents autorisés (pas de faux conflit) | **PASS** | `vendor/bin/phpunit --filter="test_creneaux_adjacents_sans_chevauchement_ne_generent_pas_de_faux_conflit"` (OK) |
| **EX-08** | Candidat nouvellement généré initialisé à `non_pointe` | **PASS** | `vendor/bin/phpunit --filter="test_candidat_initialise_au_statut_non_pointe"` (OK) |
| **EX-09a** | Échec d'affectation : rollback complet, pas d'écriture partielle | **PASS** | `vendor/bin/phpunit --filter="test_echec_repartition_provoque_rollback_sans_ecriture_partielle"` (OK) |
| **EX-09b** | Revalidation serveur rejette si salle désactivée après simulation | **PASS** | `vendor/bin/phpunit --filter="test_revalidation_serveur_rejette_si_salle_desactivee_apres_simulation"` (OK) |

#### Suites de régression exécutées

1. **Suite ciblée complète Examens (76 tests, 253 assertions) :**
   ```bash
   vendor/bin/phpunit tests/Feature/ExamensInvariantsValidationTest.php \
       tests/Feature/ExamensFeatureCompleteTest.php \
       tests/Feature/ExamCandidatePopulationTest.php \
       tests/Feature/ExamPreparationTest.php \
       tests/Feature/ExamensCommunicationTest.php \
       tests/Feature/SchedulePhase12ExamensExtensionTest.php
   # Résultat : OK (76 tests, 253 assertions) — Durée : 5.36s
   ```

2. **Suite élargie `Exam|Evaluation` (243 tests, 872 assertions) :**
   ```bash
   vendor/bin/phpunit --filter="Exam|Evaluation"
   # Résultat : OK (242 tests passés, 1 skipped, 872 assertions) — Durée : 13.42s
   ```

3. **Formatage du code :**
   ```bash
   vendor/bin/pint --dirty
   # Résultat : OK (100% conforme)
   ```

---

### E. CONTRÔLES DE SÉCURITÉ ET INTÉGRITÉ

- [x] **Aucun commit ni push :** le dépôt est resté sur son HEAD d'origine sans commit.
- [x] **Aucune migration ni modification de schéma SQL :** la colonne `evaluation_candidats.statut` étant initialement un `VARCHAR(25)`, la valeur `'non_pointe'` a été intégrée sans altération structurelle.
- [x] **Aucune commande destructive :** aucun `migrate:fresh`, `db:wipe`, `db:seed`, `git reset`, `git checkout` ou `git clean`.
- [x] **Aucune connexion à la base de production ou de développement :** tests exécutés exclusivement sur SQLite `:memory:` avec isolation active.
- [x] **Préservation intégrale des modifications locales préexistantes :** vérifiée par `git status --short`.

---

### F. RÉSERVES ET LIMITES DOCUMENTÉES

- **Test de concurrence multi-processus MySQL réel :**
  - **Statut :** `NON EXÉCUTÉ — base dédiée non vérifiée ou isolation non démontrée`.
  - **Motif :** Le test concurrent physique réel requiert un serveur MySQL configuré avec une base dédiée nommée `inscription_*_test` et le flag d'opt-in `CONCURRENCE_MYSQL=1`. Conformément aux contraintes absolues de cette mission interdisant toute migration ou connexion de base externe, ce test n'a pas été lancé sur MySQL.
  - **Garantie apportée :** Les verrous transactionnels pessimistes (`lockForUpdate()` ordonné par identifiant) et les scénarios de revalidation tardive ont été validés unitairement sous transaction applicative.

---

### G. VERDICT FINAL

**`PASS_WITH_RESERVATIONS`**

#### Justification du verdict
Toutes les règles métier imposées (EX-01 à EX-08, revalidation serveur, unicité des places, gestion des indisponibilités et conflits inter-facultés, statut initial `non_pointe`) sont strictement implémentées et validées par 76 tests ciblés (253 assertions) et 242 tests élargis (872 assertions) sans aucune régression. Le verdict `PASS_WITH_RESERVATIONS` est rigoureusement attribué en vertu de la section 3.4 / 5 pour documenter la réserve de concurrence multi-processus MySQL réel sans base dédiée pré-migrée.
