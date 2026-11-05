# MATRICE MÉTIER CONSOLIDÉE — LOOP 146

**Référence :** `LOOP-146-MATRICE-METIER.md`
**Version :** 3.2 (ajoute la surface HTTP administrative et étudiante, les tests RBAC et la preuve HTTP Q146 ; totaux à 102 tests)
**Date :** 2026-10-30 05:12 CET

---

## 1. PÉRIMÈTRE RÉEL

| Indicateur | Valeur |
| :--- | ---: |
| Questions avec source | **15 / 146** (Q132–Q146) |
| Questions sans aucune source | **131 / 146** (Q001–Q131) |
| Réponses confirmées avec réserve de formulation | **14** (Q132–Q145) |
| Réponse finale non confirmée | **1** (Q146) |
| Exigences créées à partir des réponses | **15** (`RECOURS-001` à `007`, `DELAI-001` à `004`, `DEROG-001` à `004`, `NOTIF-001`) |

**Ce que ce document ne fait pas :** il ne crée aucune règle pour Q001–Q131, il ne choisit aucune option Q146, et il ne transforme pas une constante de mandat en décision institutionnelle.

> Les exigences ci-dessous sont fondées sur la source désignée par l'utilisateur (E7). Leurs **formulations de questions sont reconstruites** et restent à vérifier. Les réponses sont traitées comme confirmées ; les textes institutionnels (autorités, délais, voies de recours) restent absents et sont modélisés par une configuration vide par défaut.

---

## 2. RÈGLES Q132–Q146

| ID | Question | Règle implémentée | Composant | Tests | Verdict |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `RECOURS-001` | Q132 | Enregistrer la contestation d'un refus de remise et orienter vers la voie officielle, sans modifier le statut. | `NotificationService::enregistrerContestationRemise` + `NotificationController::contesterEtudiant` | 12 tests | `PASS_WITH_RESERVATIONS` |
| `RECOURS-002` | Q133 | Conserver la décision du recours, n'accepter que `remise_confirmee` / `remise_non_confirmee`, mettre à jour le statut de remise selon l'issue. | `NotificationService::appliquerRecoursRemise` + `NotificationController::appliquerRecoursAdmin` + route `POST admin/notifications/{notification}/recours` | 9 tests (5 service + 4 HTTP) | `PASS_WITH_RESERVATIONS` |
| `RECOURS-003` | Q134 | Lister les conséquences possibles sans muter les dossiers associés sans fondement officiel. | `NotificationService::appliquerRecoursRemise` + route admin mutualisée | 1 test dédié + preuves HTTP mutualisées | `PASS_WITH_RESERVATIONS` |
| `DELAI-001` | Q135 | Conserver le délai enregistré, signaler le risque, ne pas modifier avant vérification d'un fondement officiel. | `EcheanceDerogationService::constaterDepassementEcheance` + `Admin\DerogationGestionController::constaterDepassement` | 6 tests + 1 HTTP mutualisé | `PASS_WITH_RESERVATIONS` |
| `DELAI-002` | Q136 | Enregistrer le dépassement, conserver le délai initial, n'appliquer que les conséquences expressément prévues. | `EcheanceDerogationService::constaterDepassementEcheance` + route admin mutualisée | 3 tests + 1 HTTP mutualisé | `PASS_WITH_RESERVATIONS` |
| `DEROG-001` | Q137 | Conserver demande et preuves, rechercher le mécanisme officiel, ne changer délai/conséquences qu'après décision autorisée. | `EcheanceDerogationService::deposerDemandeDerogation` + `Etudiant\DerogationController::deposer` + route `POST etudiant/derogations` | 7 tests (3 service/consultation + 4 HTTP) | `PASS_WITH_RESERVATIONS` |
| `DEROG-002` | Q138 | Documenter l'incertitude de compétence, ne décider qu'après établissement, préserver délai et statut. | `EcheanceDerogationService::deposerDemandeDerogation` / `::statuerDerogation` + route `POST admin/derogations/.../statuer` | 5 tests (4 service + 1 HTTP) | `PASS_WITH_RESERVATIONS` |
| `DEROG-003` / `DELAI-003` | Q139 | Relancer uniquement via les mécanismes institutionnels existants, sans inventer de délai ni d'escalade. | `EcheanceDerogationService::relancerDemandeDerogation` + route `POST admin/derogations/.../relancer` | 6 tests (5 service + 1 HTTP) | `PASS_WITH_RESERVATIONS` |
| `DEROG-004` / `DELAI-004` | Q140 | Maintenir le dossier en attente, conserver la preuve de relance, ne changer ni délai ni statut sans fondement. | `EcheanceDerogationService::informerAttenteProlongee` + route mutualisée `informer-attente` | 3 tests + preuve HTTP mutualisée | `PASS_WITH_RESERVATIONS` |
| `NOTIF-001` | Q141 | Informer factuellement de l'attente, des démarches et de l'absence de réponse, sans promettre de délai ni préjuger ; notification centralisée au bon destinataire et anti-doublon. | `EcheanceDerogationService::informerAttenteProlongee` + `NotificationService::notifierEtudiant` + route `POST admin/derogations/.../informer-attente` | 9 tests (3 service + 5 notification + 1 HTTP) | `PASS_WITH_RESERVATIONS` |
| `RECOURS-004` | Q142 | Enregistrer la contestation d'attente, expliquer l'état, rechercher les voies officielles, sans procédure inventée ni mutation ; session serveur et anti-IDOR. | `EcheanceDerogationService::enregistrerContestationAttente` + `Etudiant\DerogationController::contesterAttente` | 12 tests | `PASS_WITH_RESERVATIONS` |
| `RECOURS-005` | Q143 | Transmettre par la voie officielle, enregistrer la transmission et son destinataire, informer qu'aucun délai confirmé n'est connu, sans décision déduite du silence. | `EcheanceDerogationService::transmettrePourReexamen` + route `POST admin/derogations/.../transmettre-reexamen` | 6 tests (5 service + 1 HTTP) | `PASS_WITH_RESERVATIONS` |
| `RECOURS-006` | Q144 | Conserver décision et motifs verbatim, sans interpréter ni compléter. | `EcheanceDerogationService::enregistrerDecisionInsuffisammentMotivee` + route `POST admin/derogations/.../decision-insuffisamment-motivee` | 4 tests (3 service + 1 HTTP) | `PASS_WITH_RESERVATIONS` |
| `RECOURS-007` | Q145 | Enregistrer la décision ambiguë, signaler l'ambiguïté, ne présumer ni acceptation ni refus. | `EcheanceDerogationService::enregistrerDecisionAmbiguë` + route `POST admin/derogations/.../decision-ambigue` | 4 tests (3 service + 1 HTTP) | `PASS_WITH_RESERVATIONS` |
| *(aucune règle finale)* | Q146 | Conserver l'original, enregistrer la clarification (référence, auteur, date), aucune mutation automatique, aucun statut final. | `EcheanceDerogationService::appliquerClarificationDecision` + route `POST admin/derogations/.../appliquer-clarification` | 10 tests (9 service/consultation + 1 HTTP) | `SANS VERDICT FINAL` |

---

## 3. GARDE-FOUS DU MANDAT (ÉTAT RÉEL)

Légende : `CONFORME`, `PARTIEL`, `NON_IMPLÉMENTÉ`, `NON_APPLICABLE`.

| ID | Garde-fou | État constaté | Preuve |
| :--- | :--- | :--- | :--- |
| `MAND-01` | Un envoi technique ne vaut jamais remise confirmée. | `CONFORME` | `Courriel` nomme l'état `accepted_by_smtp` ; `confirmerRemise` exige une preuve et un mode ; test `test_acheminement_externe_reussi_ne_vaut_jamais_remise_confirmee`. |
| `MAND-02` | Tout échec d'acheminement est enregistré, avec reprise. | `CONFORME` | `NotificationService::reprendreEchecsExterieurs()` ; commande planifiée toutes les 30 min. |
| `MAND-03` | Un canal alternatif ne peut être utilisé que s'il est autorisé. | `CONFORME` (par absence) | Aucune escalade automatique ; canaux issus de `config/loop146.php`, vides par défaut. |
| `MAND-04` | Le statut de remise reste fidèle. | `CONFORME` (Q133) | `appliquerRecoursRemise` n'accepte que les issues explicites et conserve l'ancien statut dans l'audit. |
| `MAND-05` | Une clôture sans remise confirmée exige un texte officiel. | `CONFORME` (par absence) | Aucun job ne clôture sur expiration ; tests `test_cloture_administrative_sans_fondement_officiel_est_bloquee`. |
| `MAND-06` | Clôture administrative et remise confirmée sont distinctes. | `CONFORME` (Q133+) | `test_cloture_administrative_autorisee_distingue_clos_et_remise_non_confirmee`. |
| `MAND-07` | Une preuve tardive est conservée avec source, date et provenance. | `PARTIEL` | Mécanisme testé ; aucune décision utilisateur ne définit la recevabilité. |
| `MAND-08` | Les preuves contradictoires sont toutes conservées. | `PARTIEL` | `historique_remise` conservé ; pas de règle de conservation des pièces. |
| `MAND-09` | Un refus de validation conserve ses motifs et pièces. | `CONFORME` | `NotificationService` exige un motif et conserve les pièces ; tests dédiés. |
| `MAND-10` | Une contestation est enregistrée et orientée. | `CONFORME` (Q132) | Service + route HTTP + formulaire ; orientation limitée à la configuration officielle, vide par défaut. |
| `MAND-11` | Une décision de recours conserve l'acte initial. | `CONFORME` (Q133/Q145) | Audit ancienne/nouvelle valeur ; décision ambiguë conservée verbatim. |
| `MAND-12` | Aucune mutation automatique des dossiers associés. | `CONFORME` (Q132–Q146) | `mutation_dossiers_associes=false`, `dossier_associe_mute=false` ; tests dédiés. |
| `MAND-13` | L'échéance initiale ne doit jamais être écrasée. | `CONFORME` (Q135/Q136) | `echeance_initiale` retournée à l'identique ; aucun service ne la réécrit. |
| `MAND-14` | Une dérogation exige l'autorité compétente et une trace. | `PARTIEL` | `statuerDerogation` fail-closed ; autorité issue de `config/loop146.php`, vide par défaut ; aucune procédure légitime complète. |
| `MAND-15` | Le silence administratif ne vaut ni acceptation ni refus. | `CONFORME` (Q139–Q143) | `decision_deduite_du_silence=false` ; aucun minuteur décisionnel. |
| `MAND-16` | Aucun délai non confirmé ne doit être fixé. | `CONFORME` (Q139/Q143) | `delai_confirme/fixe/garanti=null` ; aucun délai généré. |
| `MAND-17` | Une décision ambiguë ne produit aucun effet automatique. | `CONFORME` (Q145/Q146) | `acceptation_presumee=false`, `refus_presume=false`, `statut_final=null`. |

---

## 4. CE QUI RESTE INTERDIT

1. Transformer une constante `MAND-xx` en règle institutionnelle.
2. Créer une exigence pour Q001–Q131 sans réponse utilisateur explicite.
3. Choisir l'option A, B ou C de Q146 à la place de l'utilisateur.
4. Coder une clôture, un rejet, une acceptation ou une prolongation automatique sur la base d'un délai non documenté.
5. Considérer les 102 tests verts comme une validation des textes institutionnels : ils prouvent le comportement, pas la légalité.

---

## 5. CONDITIONS DE DÉBLOCAGE

1. Fournir le corpus original Q001–Q131.
2. Vérifier les formulations Q132–Q145 contre le questionnaire original.
3. Choisir l'option Q146 (A, B ou C).
4. Fournir les textes institutionnels : délais de recours et de forclusion, autorité compétente, valeur du silence, notification réputée acquise, tableau de délégation.

Tant que ces éléments manquent, les Q001–Q131 restent `SOURCE MANQUANTE` et Q146 reste `SANS VERDICT FINAL`. Ce n'est pas un oubli : c'est la conséquence directe de l'absence de décision.
