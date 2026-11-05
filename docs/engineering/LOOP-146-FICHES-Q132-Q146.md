# FICHES D'EXÉCUTION — Q132 à Q146 (LOOP 146)

**Référence :** `LOOP-146-FICHES-Q132-Q146.md`
**Version :** 1.3 (surface HTTP administrative et étudiante, tests RBAC, preuve HTTP Q146 ; totaux à 102 tests)
**Date :** 2026-10-30 05:12 CET
**Source des règles :** `~/Downloads/Nouveau dossier contenant des éléments 5/questions_reponses_LOOP_146.md`, désigné par l'utilisateur le 2026-10-29. Les formulations de questions y sont déclarées reconstruites et **à vérifier** ; Q146 n'a **aucune réponse finale**.

**Légende des verdicts :**

| Verdict | Signification |
| :--- | :--- |
| `AUTO_PASS` | Règle, implémentation, sécurité et tests complets, sans réserve bloquante. |
| `PASS_WITH_RESERVATIONS` | Comportement prouvé par tests exécutés, mais réserve documentée (formulation, compétence institutionnelle, texte officiel). |
| `SANS VERDICT FINAL` | La décision source manque : aucun statut final ne peut être posé. |
| `BLOQUÉE` | Aucune source, aucune implémentation. |

**Preuves communes :**

- Suite ciblée (10 fichiers) : `php artisan test tests/Feature/EcheanceDerogationServiceTest.php tests/Feature/NotificationPreuveRemiseTest.php tests/Feature/Q132ContestationRemiseHttpTest.php tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php tests/Feature/Loop146QuestionsComplementairesTest.php tests/Feature/Loop146ConsultationEtIntegrationTest.php tests/Feature/Q141InformationAttenteNotificationTest.php tests/Feature/AdminDerogationGestionHttpTest.php tests/Feature/Q133Q134NotificationRecoursAdminHttpTest.php tests/Feature/Q137DerogationDepotHttpTest.php --compact` → **102 tests, 489 assertions, 0 échec**.
- Non-régression : 53 tests, 240 assertions, 0 échec (notifications, éligibilité, échéances).
- Formatage : `vendor/bin/pint --test` sur les fichiers modifiés → PASS.
- Aucune donnée réelle : SQLite `:memory:`, `APP_ENV=testing`.
- Registre des 146 phases : `questions/Q001.md` à `questions/Q146.md` + `questions/README.md`.

---

## Q132 — Contestation du refus de validation d'une preuve de remise

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire si le destinataire conteste le refus de valider une preuve de remise ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Enregistrer la contestation et orienter vers la voie officielle de recours ; ne pas modifier automatiquement le statut. |
| **Exigence** | `RECOURS-001`. |
| **Critères d'acceptation** | 1) Seule une notification dont la remise est `refusee` est contestable. 2) La contestation est enregistrée avec motif (10–2000 caractères) et pièce éventuelle (PDF/image ≤ 5 Mo, hash seul). 3) `statut_remise` reste inchangé. 4) Un étudiant ne peut pas contester la notification d'un autre (404, pas 403). 5) Double soumission = même contestation, pas de doublon. 6) Aucune voie de recours n'est inventée : elle vient de `config/loop146.php`, vide par défaut. |
| **Composants** | `NotificationService::enregistrerContestationRemise`, `NotificationController::contesterEtudiant`, `POST etudiant/notifications/{notification}/contester` (`throttle:10,1`), formulaire Blade. |
| **Tests exécutés** | `test_q132_contestation_remise_enregistree_sans_modifier_statut`, `test_q132_contestation_doublon_est_idempotente`, `test_q132_contestation_d_un_autre_dossier_refusee`, `test_q132_http_proprietaire_conteste_sans_changer_le_statut`, `test_q132_http_autre_etudiant_recoit_404_sans_mutation`, `test_q132_http_invite_redirige_vers_la_connexion`, `test_q132_http_motif_invalide_rejete_sans_mutation`, `test_q132_http_notification_non_refusee_rejetee`, `test_q132_http_double_soumission_idempotente`, `test_q132_http_piece_justificative_hash_conserve`. |
| **Preuve** | 12 tests dédiés PASS (3 service + 9 HTTP), inclus dans les 102/489. Route vérifiée par `php artisan route:list --name=notifications.contester`. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Formulation de la question non vérifiée ; aucune voie de recours officielle n'est configurée, donc l'orientation reste une liste vide documentée ; le fichier de preuve n'est pas archivé (hash uniquement, conforme à la règle « pièce temporaire »). |

---

## Q133 — Recours officiel confirmant la remise

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire si le recours officiel confirme que la remise a eu lieu ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Conserver la décision du recours, mettre à jour le statut de notification selon ses conclusions et tracer les effets possibles ; toute autre modification d'une décision ou d'un délai suit les règles officielles. |
| **Exigence** | `RECOURS-002`. |
| **Critères d'acceptation** | 1) Seules les issues explicites `remise_confirmee` / `remise_non_confirmee` sont acceptées ; `confirme`, `admis`, `rejete` sont rejetés comme ambigus. 2) Référence officielle obligatoire. 3) Une même référence avec une issue différente est un conflit rejeté. 4) Le statut antérieur est conservé dans l'audit. 5) Les conséquences possibles sont retournées, jamais appliquées. |
| **Composants** | `NotificationService::appliquerRecoursRemise`, `NotificationController::appliquerRecoursAdmin`, `POST admin/notifications/{notification}/recours` (`can:campagnes.manage`, `throttle:10,1`). |
| **Tests exécutés** | `test_q133_recours_explicite_applique_la_remise_sans_muter_les_dossiers`, `test_q133_decision_confirme_ambigue_rejetee`, `test_q133_decision_sans_reference_officielle_rejetee`, `test_q133_conflit_de_reference_avec_decision_differente_rejete`, `test_q133_cycle_contestation_puis_recours_conserve_l_historique`, `test_q133_q134_admin_applique_recours_confirmant_la_remise`, `test_q133_admin_applique_recours_maintenant_non_confirme`, `test_q133_admin_sans_permission_de_gestion_ne_peut_pas_appliquer_le_recours`, `test_q133_utilisateur_non_authentifie_est_redirige`. |
| **Preuve** | 9 tests PASS (5 service + 4 HTTP), inclus dans les 102/489 ; le cycle conteste → recours conserve la contestation et n'applique le nouveau statut qu'après décision explicite. Un rôle en lecture seule reçoit `403` et aucune trace d'audit n'est créée. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Route admin protégée, mais aucune voie de recours institutionnelle configurée ; `campagnes.manage` est un habilitant technique, pas une délégation de compétence métier. La formulation reste à vérifier. |

---

## Q134 — Conséquences sur les dossiers associés non précisées

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire si la décision confirme la remise mais ne précise pas ses conséquences sur les dossiers associés ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Appliquer la conclusion relative à la remise, lister les conséquences possibles et ne pas modifier les dossiers associés sans fondement officiel suffisant. |
| **Exigence** | `RECOURS-003`. |
| **Critères d'acceptation** | 1) La conclusion « remise » est appliquée à la notification. 2) Les conséquences possibles sont listées explicitement. 3) Aucun dossier associé (inscription, groupe, convocation) n'est muté. 4) `mutation_dossiers_associes=false`. |
| **Composants** | `NotificationService::appliquerRecoursRemise`, route admin mutualisée `admin.notifications.recours`. |
| **Tests exécutés** | `test_q133_recours_explicite_applique_la_remise_sans_muter_les_dossiers` (couvre Q133 et Q134), `test_q133_q134_admin_applique_recours_confirmant_la_remise` (preuve HTTP mutualisée). |
| **Preuve** | 1 test dédié PASS + preuves HTTP mutualisées, inclus dans les 102/489. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Aucun fondement officiel pour appliquer une conséquence ; le service se limite donc à l'information. La route admin est protégée et ne mute jamais les dossiers associés. |

---

## Q135 — Délai potentiellement dépassé, règle incertaine

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire si un délai peut être dépassé mais que la règle applicable est incertaine ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Conserver le délai enregistré, signaler le risque et ne pas le modifier avant d'avoir vérifié un fondement officiel. |
| **Exigence** | `DELAI-001`. |
| **Critères d'acceptation** | 1) `depassement_constate=true` si l'échéance est passée. 2) `regle_officielle_identifiee=false` si aucune référence n'est fournie (y compris référence vide). 3) `verification_requise=true`. 4) Échéance initiale retournée à l'identique. 5) `consequences_appliquees=false` et `dossier_associe_mute=false`. 6) Aucune PII dans le journal d'intégration. |
| **Composants** | `EcheanceDerogationService::constaterDepassementEcheance`, `Admin\DerogationGestionController::constaterDepassement`, `POST admin/derogations/etudiant/{etudiant}/constater-depassement` (`can:demandes.process`, `throttle:20,1`). |
| **Tests exécutés** | `test_constat_depassement_preserve_echeance_initiale_et_refuse_forclusion_automatique`, `test_q135_sans_regle_officielle_signale_verification_sans_consequence`, `test_q135_reference_vide_equivaut_a_absence_de_regle`, `test_q135_journal_integration_ne_contient_aucune_donnee_personnelle`, `test_q136_echeance_future_ne_produit_aucun_constat`, `test_q135_consultation_conserve_l_echeance_initiale_et_signale_le_risque`, `test_q135_q136_admin_constate_depassement_echeance`. |
| **Preuve** | 6 tests PASS + 1 test HTTP mutualisé, inclus dans les 102/489 ; la consultation relit l'échéance depuis `ancienne_valeur` sans la recalculer. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Aucun texte officiel de délai fourni ; la « règle identifiée » n'est qu'une référence déclarée, elle n'est pas interprétée. La route admin exige `demandes.process`. |

---

## Q136 — Dépassement confirmé sans correction ou prolongation automatique

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire lorsqu'un délai est confirmé dépassé, sans mécanisme de correction ou de prolongation automatique ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Enregistrer le dépassement, conserver le délai initial et appliquer uniquement les conséquences expressément prévues par les règles officielles. |
| **Exigence** | `DELAI-002`. |
| **Critères d'acceptation** | 1) Le dépassement est constaté et tracé. 2) L'échéance initiale n'est jamais écrasée. 3) Aucune forclusion, prolongation ou sanction automatique. 4) Une référence officielle peut qualifier la règle mais ne déclenche aucune conséquence à elle seule. 5) `forclusion_automatique=false`. |
| **Composants** | `EcheanceDerogationService::constaterDepassementEcheance`, route admin mutualisée `admin.derogations.constater-depassement`. |
| **Tests exécutés** | `test_constat_depassement_preserve_echeance_initiale_et_refuse_forclusion_automatique`, `test_q136_regle_identifiee_ne_declenche_toujours_aucune_consequence`, `test_q136_echeance_future_ne_produit_aucun_constat`, `test_q135_q136_admin_constate_depassement_echeance`. |
| **Preuve** | 3 tests PASS + 1 test HTTP mutualisé, inclus dans les 102/489. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Les « conséquences expressément prévues » ne sont pas fournies : le service ne peut donc rien appliquer. Formulation à vérifier. |

---

## Q137 — Demande de dérogation après échéance sans mécanisme identifié

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Comment traiter une demande d'exception après le délai si aucun mécanisme de dérogation n'a été identifié ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Conserver la demande et les preuves, rechercher le mécanisme officiel et, si nécessaire, transmettre à l'acteur compétent ; ne changer délai ou conséquences qu'après une décision autorisée et traçable. |
| **Exigence** | `DEROG-001`. |
| **Critères d'acceptation** | 1) Objet et motif obligatoires. 2) Pièce éventuelle réduite à un hash (aucun stockage de fichier). 3) `mecanisme_officiel_identifie=false` par défaut. 4) `competence_etablie=false`, `competence_incertaine=true`, statut `en_attente_etablissement_competence`. 5) `delai_modifie=false`, `consequences_appliquees=false`. 6) Identifiant unique. |
| **Composants** | `EcheanceDerogationService::deposerDemandeDerogation`, `Etudiant\DerogationController::deposer`, `POST etudiant/derogations` (`throttle:5,1`), formulaire Blade. |
| **Tests exécutés** | `test_depot_demande_derogation_exige_motif_circonstancie_et_preserve_attente`, `test_q137_objet_vide_rejete`, `test_q137_depot_par_defaut_reste_en_attente_sans_modifier_delai`, `test_q137_etudiant_connecte_peut_deposer_demande_avec_piece`, `test_q137_invite_redirige_vers_connexion`, `test_q137_validation_objet_trop_court_rejete`, `test_q137_validation_motif_trop_court_rejete`. |
| **Preuve** | 7 tests PASS (3 service/consultation + 4 HTTP), inclus dans les 102/489. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Aucun mécanisme officiel de dérogation n'est configuré : la demande reste en attente par construction. La route est session-scopée mais aucune policy facultaire n'encadre le dépôt. Formulation à vérifier. |

---

## Q138 — Mécanisme existant, autorité compétente incertaine

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire si un mécanisme de dérogation existe mais que l'autorité compétente n'est pas claire ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Conserver la demande et les preuves, documenter l'incertitude, rechercher une clarification officielle et ne prendre aucune décision avant d'avoir établi la compétence ; préserver délai et statut sans effet automatique. |
| **Exigence** | `DEROG-002`. |
| **Critères d'acceptation** | 1) Une autorité hors liste configurée = compétence non établie. 2) `statuerDerogation` échoue si le mécanisme n'est pas identifié ou si l'autorité n'est pas compétente. 3) Décision invalide ou référence d'acte vide rejetées. 4) Statut et délai préservés. 5) Aucune décision par défaut. |
| **Composants** | `EcheanceDerogationService::deposerDemandeDerogation`, `::statuerDerogation`, `POST admin/derogations/etudiant/{etudiant}/statuer` (`can:demandes.process`, `throttle:20,1`). |
| **Tests exécutés** | `test_statuer_derogation_fail_closed_puis_controle_competence_autorite`, `test_q138_autorite_designee_mais_non_etablie_reste_en_attente`, `test_q138_decision_invalide_rejetee`, `test_q138_decision_sans_reference_officielle_rejetee`, `test_q138_admin_statuer_fail_closed_puis_succes_si_autorite_reconnue`. |
| **Preuve** | 5 tests PASS (4 service + 1 HTTP), inclus dans les 102/489. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | `config/loop146.php` est vide par défaut : aucune autorité n'est reconnue. La route admin est protégée mais ne vaut pas délégation métier. Formulation à vérifier. |

---

## Q139 — Aucune réponse à une demande de clarification, sans délai officiel

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire en l'absence de réponse à une demande de clarification lorsqu'aucun délai officiel n'est prévu ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Enregistrer l'absence de réponse et relancer uniquement via les mécanismes institutionnels existants ; ne pas inventer de délai ni d'escalade. |
| **Exigence** | `DEROG-003` / `DELAI-003`. |
| **Critères d'acceptation** | 1) Identifiant de demande et canal obligatoires. 2) Canal hors liste configurée rejeté. 3) Clé d'idempotence : même relance = même identifiant, `deja_enregistree=true`. 4) Deux relances distinctes ne sont pas confondues. 5) `echeance_generee=null`, `delai_invente=false`. 6) Preuve facultative mais traçable. |
| **Composants** | `EcheanceDerogationService::relancerDemandeDerogation`, `POST admin/derogations/etudiant/{etudiant}/relancer` (`can:demandes.process`, `throttle:20,1`). |
| **Tests exécutés** | `test_relance_derogation_fail_closed_puis_canal_officiel_sans_delai_invente`, `test_q139_canal_vide_rejete`, `test_q139_relance_identique_rejouee_est_idempotente`, `test_q139_deux_relances_distinctes_ne_sont_pas_confondues`, `test_q139_relance_sans_preuve_reste_traçable_sans_delai`, `test_q139_admin_relance_fail_closed_puis_succes`. |
| **Preuve** | 6 tests PASS (5 service + 1 HTTP), inclus dans les 102/489. Déduplication sur `historique_academique` (journal canonique, pas de second journal). |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Aucun canal institutionnel configuré par défaut. Formulation à vérifier. |

---

## Q140 — Absence de réponse après relance, compétence toujours inconnue

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire si une relance officielle reste sans réponse et que l'autorité compétente demeure inconnue ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Maintenir le dossier en attente, conserver la preuve de relance et rechercher uniquement les autres voies de clarification officiellement prévues ; ne changer ni délai ni statut sans fondement ; s'il n'existe aucune autre voie, documenter sans inventer. |
| **Exigence** | `DEROG-004` / `DELAI-004`. |
| **Critères d'acceptation** | 1) Statut d'instruction inchangé. 2) `delai_promis=null`. 3) `decision_prejugee=false`. 4) `reponse_recue` reflète les faits. 5) L'information utilisateur est factuelle, sans engagement de délai. 6) Aucune autre voie n'est inventée. |
| **Composants** | `EcheanceDerogationService::informerAttenteProlongee`, route mutualisée `POST admin/derogations/etudiant/{etudiant}/informer-attente` (aucun endpoint propre à Q140). |
| **Tests exécutés** | `test_information_attente_prolongee_est_transparente_et_ne_prejuge_rien`, `test_q140_reponse_recue_reste_sans_prejuge_ni_delai`, `test_q140_second_silence_apres_relance_ne_vaut_pas_decision`, `test_q141_admin_informer_attente_prolongee` (preuve HTTP mutualisée). |
| **Preuve** | 3 tests PASS + preuve HTTP mutualisée, inclus dans les 102/489 ; audit vérifié (`delai_promis=null`, `decision_prejugee=false`). |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Aucune voie de clarification configurée ; aucun endpoint dédié à Q140. Formulation à vérifier. |

---

## Q141 — Informer la personne d'une attente prolongée

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Comment informer la personne de l'attente prolongée de sa demande ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Donner une information factuelle et transparente sur la clarification de compétence en attente, les démarches entreprises et l'absence de réponse à ce jour ; ne promettre aucun délai et ne préjuger aucune décision. |
| **Exigence** | `NOTIF-001`. |
| **Critères d'acceptation** | 1) Le message mentionne la clarification en attente, les démarches et l'absence de réponse. 2) Aucun délai promis. 3) Aucune décision préjugée. 4) Aucun statut modifié. 5) La notification est centralisée et adressée au bon étudiant, sans doublon. |
| **Composants** | `EcheanceDerogationService::informerAttenteProlongee`, `NotificationService::notifierEtudiant`, `POST admin/derogations/etudiant/{etudiant}/informer-attente` (`can:demandes.process`, `throttle:20,1`). |
| **Tests exécutés** | `test_information_attente_prolongee_est_transparente_et_ne_prejuge_rien`, `test_q140_reponse_recue_reste_sans_prejuge_ni_delai`, `test_q140_second_silence_apres_relance_ne_vaut_pas_decision`, plus les 5 tests de `Q141InformationAttenteNotificationTest` (`test_q141_notification_envoyee_au_bon_destinataire_sans_delai_ni_prejuge`, `test_q141_meme_information_rejouee_ne_duplique_pas_la_notification`, `test_q141_nouvelle_demarche_produit_une_information_distincte`, `test_q141_aucune_mutation_de_statut_et_information_journalisee`, `test_q141_information_ne_part_pas_vers_un_autre_etudiant`), et `test_q141_admin_informer_attente_prolongee`. |
| **Preuve** | 9 tests PASS (3 service + 5 notification + 1 HTTP), inclus dans les 102/489. Notification `derogation_attente_prolongee`, template `q141-v1`, `delai_promis=null`, `decision_prejugee=false`. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Le circuit éditorial du template n'est pas institutionnalisé ; la route admin est protégée, mais aucune validation métier du contenu n'est fournie. Formulation à vérifier. |

---

## Q142 — Contestation de l'attente prolongée sans voie de recours identifiée

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Comment traiter la contestation d'une attente prolongée lorsqu'aucune nouvelle voie de recours n'est identifiée ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Enregistrer la contestation, expliquer l'état du dossier et rechercher les voies officielles de réexamen ou de recours ; ne pas inventer de procédure et ne modifier aucun statut automatiquement. |
| **Exigence** | `RECOURS-004`. |
| **Critères d'acceptation** | 1) Motif obligatoire. 2) Contestation idempotente (même demande + même motif = même identifiant). 3) `voies_recours_officielles` limitées à la configuration, vide par défaut. 4) `procedure_inventee=false`. 5) `statut_modifie=false`, `mutation_dossier_initial=false`. |
| **Composants** | `EcheanceDerogationService::enregistrerContestationAttente`, `Etudiant\DerogationController::contesterAttente`, `POST etudiant/derogations/contester-attente` (`throttle:10,1`). |
| **Tests exécutés** | `test_contestation_attente_prolongee_enregistree_sans_mutation_dossier`, `test_q142_motif_vide_rejete`, `test_q142_sans_voie_configuree_absence_documentee_et_statut_preserve`, `test_q142_contestation_identique_rejouee_est_idempotente`, `test_q142_contestation_ne_modifie_pas_le_statut_du_dossier`, `test_q142_contestation_apres_decision_ne_surcharge_pas_la_decision`, plus les 6 tests HTTP de `Q142ContestationAttenteProlongeeHttpTest` (`test_etudiant_connecte_peut_consulter_la_page_derogations`, `test_invite_redirige_vers_connexion`, `test_etudiant_peut_contester_attente_prolongee_sans_mutation_dossier`, `test_contestation_attente_rejetee_si_motif_trop_court`, `test_contestation_attente_idempotente`, `test_anti_idor_autre_etudiant_ne_voit_pas_les_demandes_d_autrui`). |
| **Preuve** | 12 tests PASS (6 service/consultation + 6 HTTP), inclus dans les 102/489 ; la consultation consolidée confirme que la contestation ne surcharge ni l'attente ni une décision explicite. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Aucune voie de recours configurée. Formulation à vérifier. |

---

## Q143 — Voie officielle de réexamen sans délai de traitement connu

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire si une voie officielle de réexamen existe mais qu'aucun délai de traitement n'est connu ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Vérifier les sources officielles, transmettre la demande par la voie prévue et enregistrer la transmission ; informer qu'aucun délai confirmé n'est connu ; ne pas fixer de délai arbitraire ni déduire une décision du silence. |
| **Exigence** | `RECOURS-005`. |
| **Critères d'acceptation** | 1) Voie de réexamen obligatoire et configurée. 2) Référence de transmission obligatoire (ou référence officielle de la voie). 3) `delai_confirme=null`, `delai_fixe=null`, `delai_garanti=null`. 4) `preuve_transmission_enregistree=true`. 5) `decision_deduite_du_silence=false`. 6) Transmission identique idempotente. 7) Destinataire officiel, source et horodatage tracés. |
| **Composants** | `EcheanceDerogationService::transmettrePourReexamen`, `POST admin/derogations/etudiant/{etudiant}/transmettre-reexamen` (`can:demandes.process`, `throttle:20,1`). |
| **Tests exécutés** | `test_transmission_reexamen_fail_closed_puis_voie_officielle_sans_delai_garanti`, `test_q143_reference_vide_rejetee`, `test_q143_transmission_identique_rejouee_est_idempotente`, `test_q143_alias_reference_officielle_voie_accepte_sans_delai`, `test_q143_destinataire_officiel_et_horodatage_traces`, `test_q143_admin_transmettre_reexamen_fail_closed_puis_succes`. |
| **Preuve** | 6 tests PASS (5 service + 1 HTTP), inclus dans les 102/489. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Aucune voie de réexamen configurée. Formulation à vérifier. |

---

## Q144 — Décision de réexamen insuffisamment motivée

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Comment traiter une décision de réexamen dont la motivation est insuffisante ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Conserver la décision et les motifs communiqués tels qu'ils ont été reçus ; ne pas interpréter la décision ni compléter les motifs manquants. |
| **Exigence** | `RECOURS-006`. |
| **Critères d'acceptation** | 1) Texte de décision obligatoire. 2) Texte et motifs conservés **verbatim** (espaces et retours à la ligne compris). 3) `motifs_completes=false`. 4) `interpretation_supplee=false`. 5) Le signalement est possible mais n'ajoute aucun motif. |
| **Composants** | `EcheanceDerogationService::enregistrerDecisionInsuffisammentMotivee`, `POST admin/derogations/etudiant/{etudiant}/decision-insuffisamment-motivee` (`can:demandes.process`, `throttle:20,1`). |
| **Tests exécutés** | `test_decision_reexamen_insuffisamment_motivee_preservee_verbatim_sans_extrapolation`, `test_q144_decision_vide_rejetee`, `test_q144_texte_et_motifs_conserves_verbatim_dans_audit`, `test_q144_admin_enregistrer_decision_insuffisamment_motivee`. |
| **Preuve** | 4 tests PASS (3 service + 1 HTTP), inclus dans les 102/489 ; contrôle du JSON d'audit. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | Aucun circuit de signalement officiel configuré. Formulation à vérifier. |

---

## Q145 — Décision ambiguë quant à l'acceptation ou au refus d'une dérogation

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Que faire si une décision ne permet pas de savoir clairement si une dérogation est acceptée ou refusée ? » — formulation reconstruite, à vérifier. |
| **Réponse source** | Enregistrer la réception, signaler l'ambiguïté et ne considérer la dérogation ni comme acceptée ni comme refusée avant clarification. |
| **Exigence** | `RECOURS-007`. |
| **Critères d'acceptation** | 1) Contenu obligatoire. 2) Contenu intégral + hash conservés. 3) `acceptation_presumee=false`, `refus_presume=false`. 4) `derogation_accordee=false`, `derogation_refusee=false`. 5) `issue_presumee=null`. 6) Alias `referenceActe` accepté. |
| **Composants** | `EcheanceDerogationService::enregistrerDecisionAmbiguë`, `POST admin/derogations/etudiant/{etudiant}/decision-ambigue` (`can:demandes.process`, `throttle:20,1`). |
| **Tests exécutés** | `test_decision_ambiguë_figee_sans_presumer_issue`, `test_q145_contenu_vide_rejete`, `test_q145_alias_reference_acte_et_contenu_integral_dans_audit`, `test_q145_admin_enregistrer_decision_ambigue`. |
| **Preuve** | 4 tests PASS (3 service + 1 HTTP), inclus dans les 102/489 ; hash vérifié dans l'audit. |
| **Verdict** | `PASS_WITH_RESERVATIONS`. |
| **Réserves** | La clarification n'existe pas encore ; aucune issue ne peut être posée. Formulation à vérifier. |

---

## Q146 — Clarification officielle confirmant le refus après une décision ambiguë

| Champ | Valeur |
| :--- | :--- |
| **Question** | « Si une clarification officielle et autorisée confirme finalement le refus de dérogation après une première décision ambiguë, quelle conduite adopter ? » — formulation reconstruite. |
| **Réponse finale** | **`NON CONFIRMÉE`**. Options A, B et C connues, aucune choisie. |
| **Ce qui est implémenté (indépendant de l'option)** | 1) La décision initiale ambiguë reste consultable et inchangée. 2) La clarification est enregistrée avec référence, auteur et date. 3) `statut_final=null`. 4) `decision_finale_appliquee=false`. 5) `option_q146_confirmee=false`. 6) `mutations_automatiques_effectuees=false`. |
| **Ce qui est volontairement bloqué** | Toute règle qui dépendrait du choix A/B/C : mise à jour du statut de dérogation, examen des conséquences sur délais/dossiers, refus définitif. |
| **Composants** | `EcheanceDerogationService::appliquerClarificationDecision`, `POST admin/derogations/etudiant/{etudiant}/appliquer-clarification` (`can:demandes.process`, `throttle:20,1`). |
| **Tests exécutés** | `test_clarification_decision_conserve_decision_initiale_sans_mutation_automatique`, `test_q146_decision_initiale_reste_consultable_apres_clarification`, `test_q146_auteur_competent_ne_declenche_pas_l_option_finale`, `test_q146_reference_vide_rejetee`, `test_q146_issue_invalide_rejetee`, `test_q146_date_invalide_rejetee`, `test_q146_consultation_ne_presume_ni_accord_ni_refus_apres_clarification`, `test_q146_deux_clarifications_successives_laissent_le_statut_final_ouvert`, `test_q146_la_consultation_reflete_l_evenement_le_plus_recent`, `test_q146_admin_appliquer_clarification_officielle`. |
| **Preuve** | 10 tests PASS (9 service/consultation + 1 HTTP), inclus dans les 102/489 ; audit de la décision initiale relu après clarification, consultation consolidée avec `statut_final=null` et `decision_finale_appliquee=false`. |
| **Verdict** | `SANS VERDICT FINAL`. |
| **Réserves** | **La décision Q146 (A/B/C) manque.** Aucun statut final, aucune conséquence, aucune mise à jour de dérogation ne peut être validée. Les tests prouvent uniquement la conservation et l'absence de mutation automatique. La route admin est protégée, mais aucun statut final n'est posé. |

---

## Synthèse

| Verdict | Questions | Nombre |
| :--- | :--- | ---: |
| `AUTO_PASS` | — | 0 |
| `PASS_WITH_RESERVATIONS` | Q132–Q145 | 14 |
| `SANS VERDICT FINAL` | Q146 | 1 |
| `BLOQUÉE` | Q001–Q131 | 131 |

**Aucun `AUTO_PASS` n'est revendiqué** : les formulations Q132–Q145 restent reconstruites et les compétences institutionnelles (autorités, délais, voies, valeur du silence) ne sont pas fournies. Les surfaces HTTP sont désormais exposées et protégées, mais elles appliquent des règles fail-closed, pas une légalité validée.
