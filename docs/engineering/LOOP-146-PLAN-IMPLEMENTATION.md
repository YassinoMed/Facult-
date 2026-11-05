# PLAN D'IMPLÉMENTATION — LOOP 146

**Référence :** `LOOP-146-PLAN-IMPLEMENTATION.md`
**Version :** 3.2 (ajoute la surface HTTP administrative et étudiante, les tests RBAC et la preuve HTTP Q146 ; totaux à 102 tests)
**Date :** 2026-10-30 05:12 CET

---

## 1. RÈGLE DIRECTRICE

> Aucun lot **issu des Q** ne peut être ouvert sans traçabilité `question → décision → exigence → composant → test`.

| Périmètre | Traçabilité | Autorisation |
| :--- | :--- | :--- |
| Q001–Q131 | 0 / 131 | `BLOQUÉ` : aucune question, aucune réponse |
| Q132–Q145 | 14 / 14 | `OUVERT` : réponses confirmées via la source désignée, formulations à vérifier |
| Q146 | 0 / 1 (option) | `PARTIEL` : seuls les comportements indépendants de l'option sont autorisés |

**Exception encadrée :** le lot `S-SEC-01` reste indépendant des Q ; il ferme un contournement démontré et ne présuppose aucune décision institutionnelle.

---

## 2. LOT 0 — Récupération des sources (`PARTIEL`)

| Champ | Valeur |
| :--- | :--- |
| **Objectif** | Obtenir le corpus Q001–Q146 et les réponses confirmées. |
| **Acquis** | Q132–Q145 : réponses confirmées via `questions_reponses_LOOP_146.md` désigné par l'utilisateur ; Q146 : options connues, réponse non confirmée. |
| **Manquant** | Q001–Q131 (texte et réponses) ; vérification des formulations Q132–Q145 ; choix Q146. |
| **Actions interdites** | Reconstituer une question Q001–Q131, déduire une réponse, choisir une option Q146. |
| **Critère d'acceptation** | 146 questions avec formulation vérifiée et réponse, ou justification écrite de l'absence. |
| **Statut** | `PARTIEL` — 15 / 146 documentées, 131 manquantes. |
| **Rollback** | Sans objet. |

---

## 3. LOT `S-SEC-01` — DÉROGATION AUTO-DÉCLARÉE (`IMPLÉMENTÉ / VALIDÉ`)

| Champ | Valeur |
| :--- | :--- |
| **Exigences couvertes** | Aucune exigence Q. Correctif d'une faille démontrée, autorisé par le §7 du mandat. |
| **Comportement avant** | `POST /inscription` (public) acceptait `derogation_administrative` en texte libre et transmettait `$request->all()` au service. Une chaîne non vide suffisait à dépasser la capacité et à accéder à un niveau avancé sans admission. |
| **Comportement après** | Champ public `prohibited` ; service reçoit `validated()` ; dérogation honorée uniquement sur opt-in serveur explicite (`evaluer($data, true)`), aucun appelant ne l'utilise. |
| **Fichiers** | `app/Http/Requests/InscriptionRequest.php`, `app/Http/Controllers/InscriptionController.php`, `app/Services/EligibiliteParcoursService.php`, `tests/Feature/InscriptionEligibiliteOrientationTest.php`. |
| **Migrations** | Aucune. |
| **Tests** | 14 tests, 64 assertions, PASS (T-06) ; non-régression 209 tests, 1 106 assertions, PASS (T-07/T-08). |
| **Statut** | `IMPLÉMENTÉ / VALIDÉ` techniquement. Aucune validation métier. |
| **Rollback** | Réversible par `git checkout` des 4 fichiers ; aucune donnée migrée. |

---

## 4. LOT 6 — Q132 À Q146 (`IMPLÉMENTÉ / PASS_WITH_RESERVATIONS`)

### 4.1 Périmètre

| Champ | Valeur |
| :--- | :--- |
| **Exigences** | `RECOURS-001` à `007`, `DELAI-001` à `004`, `DEROG-001` à `004`, `NOTIF-001`. |
| **Source** | `questions_reponses_LOOP_146.md` (désignation utilisateur) ; formulations reconstruites à vérifier ; Q146 non confirmée. |
| **Principe** | Fail-closed : aucune autorité, aucun canal, aucune voie, aucun délai n'est reconnu s'il n'est pas configuré dans `config/loop146.php`. |

### 4.2 Composants

| Composant | Rôle | Q |
| :--- | :--- | :--- |
| `config/loop146.php` | Listes institutionnelles vides par défaut (`mecanisme_identifie=false`, autorités/canaux/voies vides) | Toutes |
| `EcheanceDerogationService.php` | Constat, dépôt, relance, information, contestation, décision, transmission, motivation, ambiguïté, clarification | Q135–Q146 |
| `NotificationService.php` | Contestation de remise, recours, clôture distincte | Q132–Q134 |
| `NotificationController.php` | Frontière HTTP Q132 et Q133/Q134 | Q132–Q134 |
| `Admin/DerogationGestionController.php` | Frontière HTTP administrative Q135–Q146 (lecture, mutations) | Q135–Q146 |
| `Etudiant/DerogationController.php` | Frontière HTTP étudiante Q137, Q141, Q142 | Q137–Q142 |
| `routes/web.php` | `POST etudiant/notifications/{notification}/contester` ; `POST admin/notifications/{notification}/recours` ; `etudiant/derogations` ; `admin/derogations/*` | Q132–Q146 |
| `resources/views/notifications/index.blade.php` | Formulaire de contestation pour preuve refusée | Q132 |
| `resources/views/etudiant/derogations/index.blade.php` | Consultation, dépôt et contestation d'attente | Q137, Q141, Q142 |
| `resources/views/admin/derogations/*` | Écrans de gestion, constat, décision, relance, transmission et clarification | Q135–Q146 |

### 4.3 Comportements garantis

1. L'échéance initiale n'est jamais écrasée ; aucune forclusion automatique.
2. Aucune autorité n'est réputée compétente par défaut ; `statuerDerogation` échoue si le mécanisme ou l'autorité manque.
3. Aucun canal de relance, aucune voie de réexamen, aucune voie de recours n'est inventé.
4. Aucun délai n'est promis, fixé ou garanti (`null` explicite).
5. Le silence ne vaut ni accord ni refus.
6. Les décisions et motifs sont conservés verbatim ; aucune interprétation, aucun complément.
7. Une décision ambiguë n'est ni acceptée ni refusée ; l'original reste consultable.
8. Q146 : la clarification est enregistrée avec référence, auteur, date ; `statut_final=null`, `option_q146_confirmee=false`, aucune mutation automatique.
9. Aucune contestation ne modifie automatiquement un statut.
10. Aucun dossier associé n'est muté sans fondement officiel.
11. Idempotence : Q132 (JSON notification), Q139/Q142/Q143 (audit canonique).
12. Aucun second journal, aucun second système de notification ou d'audit.
13. Surface HTTP : côté étudiant, session serveur et anti-IDOR ; côté administration, `auth`, `admin`, `mfa.obligatoire`, `secondaire.verrou`, lecture `demandes.view`, mutation `demandes.process` + `throttle:20,1` ; Q133/Q134 derrière `campagnes.manage` + `throttle:10,1`.
14. Un rôle en lecture seule (`consultation`) obtient `403` sur les mutations et ne laisse aucune trace d'audit.

### 4.4 Tests et preuves

| Test | Cas | Assertions | Résultat |
| :--- | ---: | ---: | :--- |
| `EcheanceDerogationServiceTest` | 10 | 52 | PASS |
| `NotificationPreuveRemiseTest` | 14 | 60 | PASS |
| `Q132ContestationRemiseHttpTest` | 7 | 29 | PASS |
| `Q142ContestationAttenteProlongeeHttpTest` | 6 | 23 | PASS |
| `Loop146QuestionsComplementairesTest` | 32 | 132 | PASS |
| `Loop146ConsultationEtIntegrationTest` | 9 | 72 | PASS |
| `Q141InformationAttenteNotificationTest` | 5 | 27 | PASS |
| `AdminDerogationGestionHttpTest` | 11 | 62 | PASS |
| `Q133Q134NotificationRecoursAdminHttpTest` | 4 | 16 | PASS |
| `Q137DerogationDepotHttpTest` | 4 | 16 | PASS |
| **Consolidé** | **102** | **489** | **PASS** |
| Non-régression notifications/éligibilité/échéances | 53 | 240 | PASS |
| `pint --test` | — | — | PASS |

### 4.5 Limites et réserves

- **Formulations** : Q132–Q145 reconstruites, non vérifiées contre le questionnaire original.
- **Reachability** : Q132–Q146 sont exposées et protégées (sauf Q140, sans endpoint propre, couverte par la route mutualisée `informer-attente`) ; les autorisations sont testées en HTTP, mais les compétences institutionnelles ne sont pas fournies.
- **Textes officiels** : autorités, délais, voies et valeur du silence absents ; configuration vide par défaut.
- **Q146** : option A/B/C non confirmée ; aucun statut final.
- **Concurrence** : un bloc de méthodes dupliquées a été retiré après un fatal `Cannot redeclare` ; les tests ont été rejoués après stabilisation.

### 4.6 Statut et rollback

| Champ | Valeur |
| :--- | :--- |
| **Statut** | `IMPLÉMENTÉ / PASS_WITH_RESERVATIONS` (Q132–Q145) ; `SANS VERDICT FINAL` (Q146). |
| **Rollback** | Retrait des fichiers non suivis (`EcheanceDerogationService.php`, `config/loop146.php`, tests, docs) et `git checkout` des fichiers suivis modifiés (`NotificationService.php`, `NotificationController.php`, `routes/web.php`, `resources/views/notifications/index.blade.php`). Aucune migration, aucune donnée migrée. |

---

## 5. LOTS POTENTIELS ISSUS DES Q001–Q131 (`BLOQUÉS`)

### LOT 1 — Notifications et preuve de remise

- **Périmètre :** distinction acheminement / remise, preuves tardives et contradictoires, refus motivé.
- **Acquis partiel :** Q132–Q134 couvrent contestation et recours ; les mécanismes de preuve existent.
- **Manquant :** Q001–Q131 et textes officiels sur la notification réputée acquise.
- **Statut :** `PARTIEL` (Q132–Q134) / `BLOQUÉ` pour le reste.

### LOT 2 — Clôture administrative

- **Périmètre :** séparation état de suivi / état de remise, conditions de clôture.
- **Acquis partiel :** `distinguerClotureAdministrative` et tests dédiés ; la clôture sans fondement reste bloquée.
- **Manquant :** texte officiel autorisant la clôture sans remise confirmée.
- **Statut :** `PARTIEL` service-level ; `BLOQUÉ` métier.

### LOT 3 — Recours et contestations

- **Périmètre :** enregistrement d'une contestation, conservation du refus initial, effets d'une décision de recours.
- **Acquis partiel :** Q132–Q134, Q142–Q145.
- **Manquant :** circuit officiel de recours et autorité compétente.
- **Statut :** `PARTIEL` service-level ; `BLOQUÉ` métier.

### LOT 4 — Échéances et dérogations

- **Périmètre :** conservation de l'échéance initiale, délégation de signature, traitement du silence.
- **Acquis partiel :** Q135–Q143.
- **Manquant :** tableau de délégation et textes sur les délais.
- **Statut :** `PARTIEL` service-level ; `BLOQUÉ` métier.

### LOT 5 — Réexamens et décisions ambiguës

- **Périmètre :** réexamen sans délai connu, décision insuffisamment motivée, dispositif ambigu.
- **Acquis partiel :** Q143–Q146 (comportements option-indépendants).
- **Manquant :** procédure officielle de réexamen et option Q146.
- **Statut :** `PARTIEL` service-level ; `SANS VERDICT FINAL` pour Q146.

---

## 6. ORDRE D'OUVERTURE POUR LE RESTE

1. Vérifier les formulations Q132–Q145 contre le questionnaire original.
2. Obtenir la décision Q146 (A/B/C).
3. Récupérer Q001–Q131.
4. Fournir les textes institutionnels (autorités, délais, voies, silence, délégation).
5. Ouvrir un seul lot à la fois, en commençant par le risque le plus élevé : intégrité des décisions, autorisations, transitions de statut, historique, échéances, notifications.
6. Pour chaque lot : test écrit avant le code, diff complet, tests ciblés, revue de sécurité, documentation.

---

## 7. ÉTAT GIT APRÈS CE LOOP

- Aucun commit.
- Aucun push.
- Aucune migration exécutée.
- Aucune opération destructive (`migrate:fresh`, `db:wipe`).
- **Fichiers applicatifs modifiés par ce LOOP :** `app/Services/EcheanceDerogationService.php` (nouveau), `app/Services/NotificationService.php`, `app/Http/Controllers/NotificationController.php`, `app/Http/Controllers/Admin/DerogationGestionController.php` (nouveau), `app/Http/Controllers/Etudiant/DerogationController.php` (nouveau), `config/loop146.php` (nouveau), `routes/web.php`, `resources/views/notifications/index.blade.php`, `resources/views/etudiant/derogations/index.blade.php` (nouveau), `resources/views/admin/derogations/*` (nouveau), ainsi que les fichiers du lot `S-SEC-01`.
- **Tests ajoutés :** `tests/Feature/Loop146QuestionsComplementairesTest.php`, `tests/Feature/Q132ContestationRemiseHttpTest.php`, `tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php`, `tests/Feature/Loop146ConsultationEtIntegrationTest.php`, `tests/Feature/Q141InformationAttenteNotificationTest.php`, `tests/Feature/AdminDerogationGestionHttpTest.php`, `tests/Feature/Q133Q134NotificationRecoursAdminHttpTest.php`, `tests/Feature/Q137DerogationDepotHttpTest.php` ; `tests/Feature/NotificationPreuveRemiseTest.php` étendu.
- **Documents :** `docs/engineering/LOOP-146-*` (inventaire, traçabilité, matrice, sécurité, tests, plan, fiches, verdict).
- Les travaux locaux préexistants et les écritures concurrentes hors périmètre restent intacts.
