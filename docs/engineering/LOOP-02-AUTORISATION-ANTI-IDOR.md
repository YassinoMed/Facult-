# LOOP-02 — AUTORISATION SERVEUR ET ANTI-IDOR

**Date d'exécution :** 26/10/2026
**Périmètre :** portail étudiant — dossier GED, documents officiels, archives, convocations, demandes en ligne, rendez-vous, notifications, assiduité, paiements/reçus, simulations de parcours et falsification d'identifiants dans les formulaires POST.
**Mode :** audit statique + tests ciblés sur SQLite `:memory:` ; aucune migration, aucun commit, aucun push, aucune donnée réelle modifiée.
**Verdict :** `PASS_WITH_RESERVATIONS`

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture des routes, contrôleurs, services, modèles et policies des parcours étudiants ; vérification que chaque réponse 404 provient d'un contrôle d'appartenance serveur et non d'une route absente, d'un module désactivé ou d'une ressource inexistante ; exécution de la suite LOOP-02 avant et après réparation des fixtures ; non-régression par les suites voisines.
- **Environnement :** PHP 8.5.11, Laravel 13.33.0, PHPUnit 12.5.35, SQLite `:memory:` via `phpunit.xml`, stockage `Storage::fake('local')` et `Storage::fake('private')`, modules `student-dossiers`, `online-requests`, `finances`, `attendance` et `parcours-academique` activés dans `setUp()`.
- **Distinction permanente :** un contrôle lu dans le code n'est pas un contrôle exécuté ; un test ciblé vert ne vaut pas validation de la suite complète. Les éléments non exécutés sont marqués `NOT_EXECUTED` et ne sont jamais comptés comme PASS.
- **Limites :** suite complète non exécutée ; 4 tests MySQL opt-in skippés ; aucun parcours navigateur/E2E ; aucune vérification sur un environnement de production ; la matrice exhaustive du back-office n'est pas rejouée dans ce lot, elle s'appuie sur les suites de sécurité existantes.
- **Périmètre de données :** deux étudiants fictifs A et B, deux facultés fictives, des documents et fichiers générés dans les disques de test. Aucune PII réelle, aucun secret, aucun jeton.

---

## 1. État initial et périmètre

### 1.1 État Git de référence

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` |
| Entrées `git status --porcelain` avant rapport | 807 (369 `M`, 436 `??`, 2 `A`) |
| `git diff --stat` | 369 fichiers, +20 082 / −3 143 |
| `git diff --cached --stat` | 2 fichiers, +4 |
| Commit / push pendant LOOP-02 | aucun |

L'arbre de travail contenait déjà des modifications de loops antérieures, ainsi que deux fichiers suivis modifiés supplémentaires apparus pendant la session et non revendiqués par LOOP-02. Le lot n'a modifié **aucun fichier applicatif suivi** : son seul livrable de code est la suite de tests non suivie `tests/Feature/Security/Loop02AutorisationAntiIdorTest.php`, complétée par le présent rapport.

### 1.2 Surfaces auditées

| Surface | Fichier principal |
|---|---|
| Dossier GED, versions, remplacement | `app/Modules/StudentDossiers/Controllers/MonDossierController.php` |
| Documents officiels (téléchargement étudiant) | `MonDossierController::telechargerOfficiel` |
| Documents officiels (back-office) | `app/Modules/StudentDossiers/Controllers/OfficialDocumentController.php` |
| Archives académiques | `app/Http/Controllers/Etudiant/ArchiveController.php` |
| Convocations | `app/Http/Controllers/Etudiant/ExamenController.php` |
| Demandes en ligne | `app/Modules/OnlineRequests/Controllers/MesDemandesController.php` |
| Rendez-vous de scolarité | `app/Http/Controllers/Etudiant/RendezVousScolariteController.php` |
| Notifications | `app/Http/Controllers/NotificationController.php` |
| Assiduité et justificatifs | `app/Modules/Attendance/Controllers/JustificationController.php` |
| Paiements et reçus | `app/Modules/Finances/Controllers/PaiementInscriptionEtudiantController.php` |
| Simulations self-service | `app/Modules/ParcoursAcademique/Controllers/MaSimulationParcoursController.php` |
| Profil et formulaires POST | `app/Http/Controllers/Etudiant/ProfilController.php` |
| Annonces et ressources | `MesAnnoncesController`, `RessourcesEtudiantController` |
| Suite de tests | `tests/Feature/Security/Loop02AutorisationAntiIdorTest.php` |

### 1.3 Base de tests

| Étape | Résultat |
|---|---|
| État initial du fichier LOOP-02 | **11 tests, 8 PASS, 1 échec, 2 erreurs, 22 assertions**, 1 180 ms |
| Après réparation des fixtures | **11/11 PASS, 29 assertions**, 1 303 ms |
| Après renforcement des assertions | **11/11 PASS, 39 assertions**, 1 191 ms |

---

## 2. Contrôles confirmés

| # | Parcours | Contrôle serveur | Preuve statique | Preuve d'exécution |
|---|---|---|---|---|
| C1 | GED — lecture, aperçu, téléchargement | Dossier résolu depuis `etudiant_auth_id`, puis comparaison `student_dossier_id` | `MonDossierController.php:128-133,175-180,193-198,233-238` | `Loop02AutorisationAntiIdorTest::test_etudiant_a_ne_peut_pas_acceder_aux_documents_ged_de_b` |
| C2 | GED — version historique | Vérification du document **et** de la version parente | `MonDossierController.php:211-220` | Même test, version de B → 404 |
| C3 | GED — remplacement | Même contrôle d'appartenance avant toute écriture | `MonDossierController.php:128-133` | Même test + document B inchangé (nom et nombre de versions) |
| C4 | Documents officiels | Comparaison `student_dossier_id`, statut `valide`, existence du fichier | `MonDossierController.php:268-274` | `test_etudiant_a_ne_peut_pas_telecharger_document_officiel_de_b` |
| C5 | Archives académiques | Comparaison `document.etudiant_id` avec l'étudiant de session | `ArchiveController.php:176-181` | `test_etudiant_a_ne_peut_pas_telecharger_archive_de_b` |
| C6 | Convocations | Comparaison `candidat.etudiant_id` avec l'étudiant de session | `ExamenController.php:36-44` | `test_etudiant_a_ne_peut_pas_acceder_a_la_convocation_de_b` |
| C7 | Demandes — consultation, soumission, annulation | `abort_if(demande.etudiant_id !== etudiant.id, 404)` avant le service | `MesDemandesController.php:74-112` | `test_etudiant_a_ne_peut_ni_consulter_ni_modifier_demande_de_b` |
| C8 | Demandes — messages, pièces, document | Contrôle d'appartenance dans le FormRequest d'upload, puis dans le contrôleur ; document réellement présent pour B | `UploadPieceRequest::authorize()`, `MesDemandesController.php:120-127,141-150,162-170` | Même test + `OnlineRequestDocument` et fichier privé de B |
| C9 | Rendez-vous — annulation, déplacement | Même contrôle avant le service ; statut serveur non injectable | `RendezVousScolariteController.php:101-120` | `test_etudiant_a_ne_peut_pas_annuler_ni_deplacer_rendez_vous_de_b` + statut et créneau inchangés |
| C10 | Notifications | Comparaison `notification.etudiant_id` avec la session | `NotificationController.php:73-79` | `test_etudiant_a_ne_peut_pas_marquer_comme_lue_notification_de_b` + `lu` reste faux |
| C11 | Assiduité — justification | Contrôle d'appartenance dans le FormRequest, puis dans le contrôleur, avant dépôt | `DeposerJustificationRequest::authorize()`, `JustificationController.php:69-73` | `test_etudiant_a_ne_peut_pas_justifier_presence_de_b` + aucune justification créée |
| C12 | Assiduité — pièce justificative | Appartenance + statut `approved` + fichier autorisé | `JustificationController.php:103-116` | `JustificatifAbsenceTelechargementTest` (propriétaire et tiers) |
| C13 | Paiements — détail, dépôt, reçu | Contrôle d'appartenance dans le FormRequest de dépôt, puis vérification de l'inscription et du lien reçu/inscription | `DeposerRecuPaiementRequest::authorize()`, `PaiementInscriptionEtudiantController.php:63-68,90-97,133-145,160-166` | `test_etudiant_a_ne_peut_pas_acceder_aux_paiements_de_b` + aperçu et téléchargement 404 + aucun reçu créé |
| C14 | Simulations de parcours | Appartenance stricte **et** simulation self-service (`auteur_id` nul) | `MaSimulationParcoursController.php:96-102` | `test_etudiant_a_ne_peut_pas_acceder_aux_simulations_de_b` |
| C15 | Falsification POST | Étudiant résolu depuis la session ; `safe()->only()` exclut tout identifiant | `ProfilController.php:47-71` | `test_falsification_etudiant_id_dans_formulaire_post_est_sans_effet` |
| C16 | Annonces et ressources | Visibilité calculée (statut, fenêtre, audience, faculté) ; aucun identifiant étudiant accepté | `MesAnnoncesController.php:34-40`, `RessourcesEtudiantController.php:40-62` | `FaculteScopeEcritureTest`, suites annonces/ressources |
| C17 | Back-office documents officiels | `Gate::authorize('downloadPdf', dossier)` et vérification de l'inscription dans le service | `OfficialDocumentController.php:50-63`, `OfficialDocumentService.php:65-67` | Suites back-office existantes, non rejouées exhaustivement ici |

### 2.1 Contrôles positifs (le propriétaire reste autorisé)

Les 404 négatifs ne sont pas des artefacts de route absente ou de ressource inexistante : les suites existantes prouvent le chemin propriétaire.

| Parcours | Propriétaire autorisé | Tiers refusé |
|---|---|---|
| GED | `StudentDocumentTest.php:236` | `StudentDocumentIsolationTest.php:130`, `InscriptionDepotSecuriseTest.php:173` |
| Documents officiels | `RelevesGenerationTest.php:183` | `RelevesGenerationTest.php:199` |
| Archives | `StudentArchiveDocumentTest`, `StudentArchiveIdorTest` | Même familles + LOOP-02 |
| Convocation | `ExamensFeatureCompleteTest.php:551` | `ExamensFeatureCompleteTest.php:556` |
| Demande et PDF | `OnlineRequestPdfTest.php:114` | `OnlineRequestSecurityTest.php:124` |
| Paiement/reçu | `PaiementsInscriptionsTest.php:716` | `PaiementsInscriptionsTest.php:723` |
| Simulation | `PortailSimulationParcoursTest.php:387` | `PortailSimulationParcoursTest.php:386` |
| Justificatif | `JustificatifAbsenceTelechargementTest.php:134` | `JustificatifAbsenceTelechargementTest.php:185` |

---

## 3. Défauts et faux positifs

### 3.1 Aucun défaut d'autorisation applicatif confirmé

Aucun contrôle d'appartenance manquant n'a été prouvé sur les parcours du périmètre. Les 404 obtenus sont produits par des comparaisons serveur explicites (`abort_if`/`abort_unless`), avant l'appel au service ou l'écriture, et les tests renforcés vérifient en plus l'absence d'effet de bord sur les données de B.

### 3.2 Faux positifs de fixtures corrigés

L'échec initial ne révélait pas une vulnérabilité : il révélait des fixtures incompatibles avec les invariants serveur.

| # | Symptôme initial | Cause | Traitement | Statut |
|---|---|---|---|---|
| F1 | `Route [etudiant.demandes.messages.store] not defined` | Le nom réel de la route est `etudiant.demandes.messages.repondre` | Nom corrigé | Corrigé |
| F2 | Statut de rendez-vous attendu `submitted`, obtenu `draft` | `OnlineRequest::$fillable` exclut volontairement `status` (machine à états serveur) | `forceFill(['status' => 'submitted'])->save()` dans la fixture, sans modifier le modèle | Corrigé |
| F3 | `NOT NULL constraint failed: recus_paiement.montant_declare_centimes` | Colonnes obligatoires du reçu absentes de la fixture | Ajout de `montant_declare_centimes`, `reference_paiement`, `date_paiement`, `depose_le` | Corrigé |
| F4 | 404 sur le document de demande de B non probant | Aucun document n'existait pour B : le 404 pouvait venir de l'absence de ressource | Création d'un `OnlineRequestDocument` réel et d'un fichier privé pour B | Renforcé |
| F5 | Absence de preuve d'étanchéité après les tentatives | Seul le code HTTP était vérifié | Ajout d'assertions : statut et messages/pièces de B, justification, reçu, version GED, créneau de rendez-vous | Renforcé |

**Aucun correctif applicatif n'a été nécessaire.** Aucun middleware, aucune policy, aucune route et aucun contrôle de sécurité n'a été désactivé, contourné ou assoupli.

---

## 4. Critères d'acceptation

| # | Critère | Résultat |
|---|---|---|
| CA1 | Pour chaque parcours du périmètre, A reçoit 404 sur une ressource de B | **PASS** — 11 tests, 39 assertions |
| CA2 | Chaque 404 provient d'un contrôle d'appartenance serveur, pas d'une route absente ou d'une ressource manquante | **PASS** — audit statique + fixtures réelles + contrôles positifs existants |
| CA3 | Les tentatives d'écriture de A ne modifient aucune donnée de B | **PASS** — statut, messages, pièces, justification, reçu, version GED, rendez-vous |
| CA4 | Un `etudiant_id`/`id` falsifié dans un POST est sans effet | **PASS** — profil de A modifié, profil de B intact |
| CA5 | Aucun contrôle de sécurité désactivé ; aucun changement de route, de schéma ou de dépendance | **PASS** — aucun fichier applicatif modifié |
| CA6 | Non-régression ciblée sur les parcours voisins | **PASS partiel** — 429 tests ciblés, 425 PASS, 4 skippés, 1 899 assertions ; suite complète `NOT_EXECUTED` |
| CA7 | Aucun secret, mot de passe, jeton ou PII dans les rapports et sorties de test | **PASS** — données fictives uniquement |
| CA8 | Aucune migration, aucun `migrate:fresh`/`db:wipe`, aucun commit/push | **PASS** — état Git vérifié |

Le caractère partiel de CA6 et les limites du §6.3 justifient le verdict `PASS_WITH_RESERVATIONS`.

---

## 5. Modifications appliquées

### 5.1 Suite de tests

Fichier : `tests/Feature/Security/Loop02AutorisationAntiIdorTest.php` (non suivi avant le lot).

| Modification | Détail |
|---|---|
| Fixture demande | Route `messages.repondre` ; document B réel (`OnlineRequestDocument` + fichier privé) pour rendre le 404 probant |
| Fixture rendez-vous | `status = submitted` forcé côté test, car le statut n'est pas mass-assignable |
| Fixture paiement | Colonnes obligatoires du reçu complétées |
| Assertions d'étanchéité | Statut/messages/pièces de la demande, justification, reçu, version GED, statut et créneau du rendez-vous |
| Couverture supplémentaire | `etudiant.paiements.recus.apercu` en plus de `telecharger` |

### 5.2 Code applicatif

Aucune modification. Le lot est un lot de vérification : le code d'autorisation existant a été confirmé, pas réécrit. Cette absence de correctif est intentionnelle et conforme à la règle « n'implémenter que le correctif minimal d'un défaut prouvé ».

---

## 6. Preuves d'exécution

### 6.1 Avant réparation des fixtures

```text
php artisan test --compact tests/Feature/Security/Loop02AutorisationAntiIdorTest.php
→ 11 tests, 8 passed, 1 failed, 2 errors, 22 assertions, 1 180 ms
```

Échec : `test_etudiant_a_ne_peut_pas_annuler_ni_deplacer_rendez_vous_de_b` (statut `draft` au lieu de `submitted`).
Erreurs : route `messages.store` inexistante ; colonne `montant_declare_centimes` manquante.

### 6.2 Après réparation et renforcement

```text
php artisan test --compact tests/Feature/Security/Loop02AutorisationAntiIdorTest.php
→ 11 tests, 11 passed, 39 assertions, 1 191 ms — PASS
```

```text
php artisan test --compact tests/Feature/Security
→ 181 tests, 177 passed, 4 skipped, 583 assertions, 7 175 ms — PASS
```

```text
php artisan test --compact \
  tests/Feature/StudentDossierTest.php \
  tests/Feature/StudentDossierPhase8Test.php \
  tests/Feature/RelevesGenerationTest.php \
  tests/Feature/NotesEvaluationsSecuriteTest.php \
  tests/Feature/CoffreFortAttestationsQrTest.php \
  tests/Feature/StudentArchiveIdorTest.php \
  tests/Feature/StudentArchiveDocumentTest.php \
  tests/Feature/ExamensFeatureCompleteTest.php \
  tests/Feature/EspaceEtudiantTest.php
→ 107 tests, 107 passed, 404 assertions, 16 712 ms — PASS
```

```text
php artisan test --compact \
  tests/Feature/OnlineRequestSecurityTest.php \
  tests/Feature/OnlineRequestPdfTest.php \
  tests/Feature/OnlineRequestTest.php \
  tests/Feature/PaiementsInscriptionsTest.php \
  tests/Feature/JustificatifAbsenceTelechargementTest.php \
  tests/Feature/PortailSimulationParcoursTest.php \
  tests/Feature/MaSimulationParcoursTest.php \
  tests/Feature/CentreNotificationsPortailTest.php \
  tests/Feature/EtudiantPagesV2Test.php
→ 115 tests, 115 passed, 743 assertions, 5 999 ms — PASS
```

```text
php artisan test --compact \
  tests/Feature/StudentDocumentTest.php \
  tests/Feature/StudentDocumentIsolationTest.php \
  tests/Feature/InscriptionDepotSecuriseTest.php
→ 26 tests, 26 passed, 169 assertions, 2 359 ms — PASS
```

```text
vendor/bin/pint --test tests/Feature/Security/Loop02AutorisationAntiIdorTest.php
→ PASS
```

### 6.3 Tests non exécutés

| Élément | Statut |
|---|---|
| Suite complète (4 948 entrées listées) | `NOT_EXECUTED` |
| Tests MySQL opt-in (4) | `NOT_EXECUTED` — skippés : `ArchivesConcurrenceMysqlTest::test_serialisation_double_suppression`, `ArchivesConcurrenceMysqlTest::test_suppression_autre_faculte_refusee`, `ArchivesStatsMysqlTest::test_registre_200_avec_stats_sur_mysql`, `ArchivesVolumetrieBenchTest::test_benchmark_registre` |
| Parcours navigateur / E2E | `NOT_EXECUTED` |
| Vérification production (HTTPS, proxies, journaux réels) | `NOT_EXECUTED` |
| Matrice back-office exhaustive | `NOT_EXECUTED` — les suites `Security` incluent `IdorSecurityTest`, `FaculteScopeEcritureTest` et les tests d'archives, mais aucun audit exhaustif de toutes les routes admin n'a été refait |
| Test de concurrence (deux requêtes simultanées) | `NOT_EXECUTED` — le lot vérifie l'autorisation, pas les courses d'écriture |

Aucun de ces éléments n'est compté comme PASS.

---

## 7. Non-régression et hygiène

- **Non-régression ciblée :** 429 tests, 425 PASS, 4 skippés, 1 899 assertions.
  - `tests/Feature/Security` : 181 tests, 177 PASS, 4 skippés, 583 assertions.
  - Parcours documents/archives/examens/portail : 107/107, 404 assertions.
  - Parcours demandes/paiements/simulations/notifications : 115/115, 743 assertions.
  - GED positif/négatif et dépôt sécurisé : 26/26, 169 assertions.
- **Formatage :** Pint `--test` vert sur la suite LOOP-02.
- **Pas de régression de contrôle :** aucun middleware de sécurité désactivé, aucune route supprimée, aucune policy assouplie, aucun `withoutMiddleware` ajouté.
- **Pas de données réelles :** SQLite `:memory:` uniquement ; disques `Storage::fake` ; aucune migration exécutée ; aucun `migrate:fresh`/`db:wipe`.
- **Pas de PII :** les tests utilisent des étudiants fictifs A/B ; le rapport ne contient ni email, ni CIN, ni téléphone, ni jeton.

---

## 8. Risques résiduels et réserves

| # | Risque | Niveau | Recommandation |
|---|---|---|---|
| R1 | Suite complète non exécutée (4 948 entrées) | Élevé pour la confiance | Exécuter la suite complète avant de lever les réserves. |
| R2 | Tests MySQL opt-in skippés : concurrence et volumétrie non vérifiées sur MySQL | Moyen | Exécuter les 4 tests MySQL avant mise en production. |
| R3 | Aucun parcours navigateur/E2E : la session est posée directement dans les tests | Moyen | Rejouer les scénarios après connexion réelle (LOOP-01 couvre l'authentification, pas l'intégration E2E). |
| R4 | Périmètre centré sur le portail étudiant ; le back-office n'a pas été audité route par route | Moyen | Conserver les suites `IdorSecurityTest`/`FaculteScopeEcritureTest` et planifier un audit admin dédié si le périmètre l'exige. |
| R5 | Les 404 sont volontaires (anti-énumération) ; aucun test de timing ne mesure une différence entre ressource existante et inexistante | Faible | Mesurer les temps de réponse si le modèle de menace inclut les canaux temporels. |
| R6 | La suite LOOP-02 est non suivie dans Git | Faible pour l'exécution, bloquant pour la livraison | L'ajouter au prochain commit autorisé, sans commit dans ce lot. |
| R7 | Deux fichiers suivis supplémentaires ont été modifiés dans l'arbre pendant la session, hors LOOP-02 | Faible pour ce lot | Ne pas les attribuer à LOOP-02 ; vérifier leur origine avant tout commit global. |

---

## 9. État Git final et conformité

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` |
| Commit / push | aucun |
| Migration / `migrate:fresh` / `db:wipe` | aucun |
| Fichiers applicatifs modifiés par LOOP-02 | aucun |
| Fichier de test LOOP-02 | `tests/Feature/Security/Loop02AutorisationAntiIdorTest.php` (non suivi) |
| Rapport | `docs/engineering/LOOP-02-AUTORISATION-ANTI-IDOR.md` (non suivi) |
| Diff total de l'arbre | préexistant, non revendiqué par LOOP-02 |

Le rapport et la suite de tests sont les seuls fichiers ajoutés par ce lot. Aucun secret, aucune donnée personnelle et aucun identifiant réel n'y figure.

---

## 10. Verdict

**`PASS_WITH_RESERVATIONS`**

- Les 11 scénarios anti-IDOR du périmètre passent : **11/11, 39 assertions**.
- Les 404 sont adossés à des contrôles d'appartenance serveur explicites, et les tests vérifient l'absence de mutation des données de B.
- Aucun défaut d'autorisation applicatif n'a été prouvé ; les échecs initiaux étaient des artefacts de fixtures, corrigés sans toucher au code applicatif.
- La non-régression ciblée est verte : **429 tests, 425 PASS, 4 skippés, 1 899 assertions**.
- La suite complète, les tests MySQL opt-in, l'E2E et la vérification de production restent `NOT_EXECUTED`.

**Pour lever les réserves :** exécuter la suite complète et les 4 tests MySQL opt-in, rejouer les parcours après connexion réelle, puis vérifier la configuration de production.
