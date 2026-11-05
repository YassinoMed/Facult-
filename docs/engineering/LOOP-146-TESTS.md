# RAPPORT DE TESTS — LOOP 146

**Référence :** `LOOP-146-TESTS.md`
**Version :** 2.3 (ajoute T-20 — surface HTTP administrative et étudiante ; actualise T-19 à 102 tests)
**Date :** 2026-10-30 05:12 CET
**Environnement :** `APP_ENV=testing`, SQLite `:memory:` (`phpunit.xml`)
**Données :** aucune donnée réelle ; bases en mémoire détruites à la fin de chaque test.

---

## 1. TESTS RÉELLEMENT EXÉCUTÉS

### T-01 — Centre de notifications et reprise des échecs

```bash
php artisan test --compact \
  tests/Feature/CentreNotificationsUnifieTest.php \
  tests/Feature/NotificationsOutboxRepriseTest.php
```

**Résultat :** 16 tests, 16 réussis, 61 assertions, 1 339 ms.

### T-02 — Campagnes et rappels d'échéances

```bash
php artisan test --compact \
  tests/Feature/CampagneCourrielTest.php \
  tests/Feature/InscriptionNotificationsEcheancesTest.php
```

**Résultat :** 17 tests, 17 réussis, 97 assertions, 1 615 ms.

### T-03 — Notifications : portail et préférences

```bash
php artisan test --compact \
  tests/Feature/NotificationTest.php \
  tests/Feature/CentreNotificationsPortailTest.php \
  tests/Feature/NotificationsPreferencesTest.php
```

**Résultat :** 11 tests, 11 réussis, 47 assertions, 1 347 ms.

### T-04 — Ré-exécution après modification concurrente

```bash
php artisan test --compact \
  tests/Feature/CentreNotificationsUnifieTest.php \
  tests/Feature/NotificationsOutboxRepriseTest.php \
  tests/Feature/NotificationTest.php \
  tests/Feature/CentreNotificationsPortailTest.php \
  tests/Feature/NotificationsPreferencesTest.php
```

**Résultat :** 27 tests, 27 réussis, 108 assertions, 1 763 ms.

> Ré-exécution rendue nécessaire par la modification de `NotificationService.php` à 20:50 par un acteur concurrent. Les tests existants passent, mais ils ne couvraient pas les méthodes ajoutées.

### T-05 — Test créé hors LOOP pour les méthodes de preuve de remise

```bash
php artisan test --compact tests/Feature/NotificationPreuveRemiseTest.php
```

**Résultat initial :** 9 tests, 9 réussis, 35 assertions, 1 067 ms.

> Ce fichier a été créé à 20:53 par un acteur concurrent. Il prouve que le code fonctionne, pas que la règle métier est légitime.

### T-06 — Lot `S-SEC-01` : dérogation auto-déclarée

```bash
php artisan test --compact --filter="InscriptionEligibiliteOrientationTest"
```

**Résultat :** 14 tests, 14 réussis, 64 assertions, 2 120 ms.

| Test | Ce qu'il prouve |
| :--- | :--- |
| `test_derogation_administrative_auto_declaree_est_refusee` | `POST /inscription` avec le champ → erreur de validation, 0 étudiant créé, aucune trace `qualite.derogation_eligibilite`. |
| `test_service_ignore_une_derogation_sans_opt_in_explicite` | Le service ignore la dérogation : `exception_accordee = false`, `derogation_reference = null`, blocage sur `niveau`. |
| `test_service_honore_une_derogation_uniquement_sur_opt_in_explicite` | Avec `$derogationAutorisee = true`, la dérogation est honorée : contrat explicite réservé à un futur parcours administratif. |
| `test_derogation_sans_opt_in_ne_contourne_pas_la_capacite_atteinte` | Capacité saturée → blocage malgré la dérogation fournie. |

> Le test antérieur `test_exception_administrative_derogation_permet_inscription_et_est_tracee` encodait la vulnérabilité (il exigeait que le contournement réussisse). Il a été remplacé, pas complété.

### T-07 — Non-régression inscription (série 1)

```bash
php artisan test --compact \
  tests/Feature/CinPrefillTest.php \
  tests/Feature/ExperienceInscriptionLoopTest.php \
  tests/Feature/IdentificationFailClosedTest.php \
  tests/Feature/InscriptionAccessibiliteMobileTest.php \
  tests/Feature/InscriptionAccueilParcoursTest.php \
  tests/Feature/InscriptionAutomationTest.php \
  tests/Feature/InscriptionBrouillonTest.php \
  tests/Feature/InscriptionDoublonsIncoherencesTest.php \
  tests/Feature/InscriptionEtudiantsTest.php \
  tests/Feature/InscriptionPagePeriodeTest.php \
  tests/Feature/InscriptionParcoursAdaptatifTest.php
```

**Résultat :** 111 tests, 111 réussis, 618 assertions, 6 264 ms.

### T-08 — Non-régression inscription (série 2)

```bash
php artisan test --compact \
  tests/Feature/InscriptionParcoursCompletBoutEnBoutTest.php \
  tests/Feature/InscriptionPerformanceFiabiliteTest.php \
  tests/Feature/InscriptionResumeValidationTest.php \
  tests/Feature/InscriptionSecuriteTransversaleTest.php \
  tests/Feature/SaisiesFormatsTest.php \
  tests/Feature/Security/Loop05InscriptionPubliqueRepriseDossierTest.php \
  tests/Feature/Security/Loop06FormulairesCsrfIdempotenceCoherenceTest.php \
  tests/Feature/ParcoursAccueilEmbarqueTest.php \
  tests/Feature/IdentityScanTest.php
```

**Résultat :** 98 tests, 98 réussis, 488 assertions, 7 355 ms.

### T-09 — Q135 à Q146 : service d'échéances et dérogations

```bash
php artisan test --compact tests/Feature/EcheanceDerogationServiceTest.php
```

**Résultat :** 10 tests, 10 réussis, 52 assertions, 1 018 ms.

| Test | Question(s) |
| :--- | :--- |
| `test_constat_depassement_preserve_echeance_initiale_et_refuse_forclusion_automatique` | Q135/Q136 |
| `test_depot_demande_derogation_exige_motif_circonstancie_et_preserve_attente` | Q137 |
| `test_statuer_derogation_fail_closed_puis_controle_competence_autorite` | Q138 |
| `test_relance_derogation_fail_closed_puis_canal_officiel_sans_delai_invente` | Q139 |
| `test_information_attente_prolongee_est_transparente_et_ne_prejuge_rien` | Q140/Q141 |
| `test_contestation_attente_prolongee_enregistree_sans_mutation_dossier` | Q142 |
| `test_transmission_reexamen_fail_closed_puis_voie_officielle_sans_delai_garanti` | Q143 |
| `test_decision_reexamen_insuffisamment_motivee_preservee_verbatim_sans_extrapolation` | Q144 |
| `test_decision_ambiguë_figee_sans_presumer_issue` | Q145 |
| `test_clarification_decision_conserve_decision_initiale_sans_mutation_automatique` | Q146 |

### T-10 — Q132/Q133/Q134 : preuve de remise, contestation et recours

```bash
php artisan test --compact tests/Feature/NotificationPreuveRemiseTest.php
```

**Résultat :** 14 tests, 14 réussis, 60 assertions, 1 290 ms.

Dont les tests Q :
- `test_q132_contestation_remise_enregistree_sans_modifier_statut`
- `test_q132_contestation_doublon_est_idempotente`
- `test_q132_contestation_d_un_autre_dossier_refusee`
- `test_q133_recours_explicite_applique_la_remise_sans_muter_les_dossiers`
- `test_q133_decision_confirme_ambigue_rejetee`
- `test_q133_decision_sans_reference_officielle_rejetee`
- `test_q133_conflit_de_reference_avec_decision_differente_rejete`

### T-11 — Q132 : frontière HTTP de contestation

```bash
php artisan test --compact tests/Feature/Q132ContestationRemiseHttpTest.php
```

**Résultat :** 7 tests, 7 réussis, 29 assertions, 1 151 ms.

| Test | Ce qu'il prouve |
| :--- | :--- |
| `test_q132_http_proprietaire_conteste_sans_changer_le_statut` | Le propriétaire peut contester une preuve refusée ; le statut reste `refusee`. |
| `test_q132_http_autre_etudiant_recoit_404_sans_mutation` | Isolation horizontale ; 404, aucune mutation. |
| `test_q132_http_invite_redirige_vers_la_connexion` | Accès non authentifié refusé. |
| `test_q132_http_motif_invalide_rejete_sans_mutation` | Validation serveur du motif. |
| `test_q132_http_notification_non_refusee_rejetee` | Seule une preuve refusée est contestable. |
| `test_q132_http_double_soumission_idempotente` | Double soumission = une seule contestation. |
| `test_q132_http_piece_justificative_hash_conserve` | Le fichier n'est pas stocké ; seul le hash est conservé. |

### T-12 — Q135 à Q146 : cas limites et idempotence

```bash
php artisan test --compact tests/Feature/Loop146QuestionsComplementairesTest.php
```

**Résultat :** 32 tests, 32 réussis, 132 assertions, 1 515 ms.

Ce fichier complète T-09 sans modifier le fichier édité concurremment. Il couvre : absence de règle officielle, référence vide, échéance future, journal sans PII, objet/motif vides, autorité non établie, décision invalide, canal vide, idempotence des relances, deux relances distinctes, information sans délai, silence après relance, contestation sans voie, transmission idempotente, alias de référence, destinataire officiel et horodatage de transmission, texte/motifs verbatim, contenu ambigu, alias d'acte, préservation après clarification, auteur compétent sans option finale, référence/issue/date invalides.

### T-13 — Suite ciblée consolidée

```bash
php artisan test --compact \
  tests/Feature/EcheanceDerogationServiceTest.php \
  tests/Feature/NotificationPreuveRemiseTest.php \
  tests/Feature/Q132ContestationRemiseHttpTest.php \
  tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php \
  tests/Feature/Loop146QuestionsComplementairesTest.php \
  tests/Feature/Loop146ConsultationEtIntegrationTest.php \
  tests/Feature/Q141InformationAttenteNotificationTest.php
```

**Résultat historique (7 fichiers) :** 83 tests, 83 réussis, 395 assertions, 0 échec. La suite consolidée finale est **T-19** (102 tests / 489 assertions).

Détail historique : 10 + 14 + 7 + 6 + 32 + 9 + 5 = 83 cas ; 52 + 60 + 29 + 23 + 132 + 72 + 27 = 395 assertions.

### T-14 — Non-régression notifications / éligibilité / échéances

```bash
php artisan test --compact \
  tests/Feature/NotificationTest.php \
  tests/Feature/CentreNotificationsPortailTest.php \
  tests/Feature/CentreNotificationsUnifieTest.php \
  tests/Feature/NotificationsPreferencesTest.php \
  tests/Feature/NotificationsOutboxRepriseTest.php \
  tests/Feature/InscriptionEligibiliteOrientationTest.php \
  tests/Feature/InscriptionNotificationsEcheancesTest.php
```

**Résultat :** **53 tests, 53 réussis, 240 assertions, 0 échec**, 2 630 ms.

### T-15 — Formatage

```bash
vendor/bin/pint --test \
  app/Services/EcheanceDerogationService.php \
  app/Services/NotificationService.php \
  app/Http/Controllers/NotificationController.php \
  app/Http/Controllers/InscriptionController.php \
  app/Http/Requests/InscriptionRequest.php \
  app/Services/EligibiliteParcoursService.php \
  config/loop146.php routes/web.php \
  tests/Feature/NotificationPreuveRemiseTest.php \
  tests/Feature/Q132ContestationRemiseHttpTest.php \
  tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php \
  tests/Feature/Loop146QuestionsComplementairesTest.php \
  tests/Feature/Loop146ConsultationEtIntegrationTest.php \
  tests/Feature/Q141InformationAttenteNotificationTest.php \
  tests/Feature/InscriptionEligibiliteOrientationTest.php
```

**Résultat :** PASS.

### T-16 — Route Q132

```bash
php artisan route:list --name=notifications.contester
```

**Résultat :** route présente (`POST etudiant/notifications/{notification}/contester`).

### T-17 — Consultation consolidée et intégration Q132 → Q146

```bash
php -l tests/Feature/Loop146ConsultationEtIntegrationTest.php
php artisan test tests/Feature/Loop146ConsultationEtIntegrationTest.php --compact
```

**Résultat :** 9 tests, 9 réussis, 72 assertions, 1 129 ms.

Cas couverts :

| Test | Question | Preuve |
| :--- | :---: | :--- |
| `test_q146_consultation_ne_presume_ni_accord_ni_refus_apres_clarification` | Q146 | `statut_final=null`, `refuse=false`, décision ambiguë toujours consultable |
| `test_q146_deux_clarifications_successives_laissent_le_statut_final_ouvert` | Q146 | deux clarifications conservées, aucun statut final |
| `test_q146_la_consultation_reflete_l_evenement_le_plus_recent` | Q146 | l'état « ambigu » ancien n'écrase pas la clarification récente |
| `test_q142_contestation_ne_modifie_pas_le_statut_du_dossier` | Q142 | contestation tracée, statut `en_attente` conservé |
| `test_q142_contestation_apres_decision_ne_surcharge_pas_la_decision` | Q142 | décision `refusee` conservée malgré la contestation |
| `test_q135_consultation_conserve_l_echeance_initiale_et_signale_le_risque` | Q135 | échéance initiale identique, risque signalé, règle non certifiée |
| `test_q133_cycle_contestation_puis_recours_conserve_l_historique` | Q132/Q133 | statut inchangé après contestation, `remise_confirmee_apres_recours` après recours, historique complet |
| `test_q132_http_le_onzieme_envoi_est_refuse_par_le_quota` | Q132 | 10 envois acceptés, 11e = HTTP 429 |
| `test_q132_http_piece_de_type_interdit_rejetee_sans_trace` | Q132 | erreur de validation, aucune contestation enregistrée |

La suite consolidée finale, incluant Q141 et Q142, est décrite en **T-19** ci-dessous.

### T-18 — Q141 : information factuelle par le système central de notifications

```bash
php artisan test tests/Feature/Q141InformationAttenteNotificationTest.php --compact
php artisan test tests/Feature/Loop146QuestionsComplementairesTest.php --filter="q143_destinataire" --compact
```

**Résultat :** 5 tests, 5 réussis, 27 assertions pour Q141 ; 1 test Q143 (destinataire officiel et horodatage) dans les 32 tests du fichier complémentaire.

Ce que T-18 ajoute :

| Test | Preuve |
| :--- | :--- |
| `test_q141_notification_envoyee_au_bon_destinataire_sans_delai_ni_prejuge` | Notification créée pour l’étudiant titulaire ; message factuel ; aucun délai ni préjugé dans le contenu et les données. |
| `test_q141_meme_information_rejouee_ne_duplique_pas_la_notification` | Même demande + mêmes faits = une seule notification (clé de déduplication). |
| `test_q141_nouvelle_demarche_produit_une_information_distincte` | Nouveaux faits = nouvelle information, sans doublon. |
| `test_q141_aucune_mutation_de_statut_et_information_journalisee` | Statut inchangé ; audit avec `template_version=q141-v1`, `delai_promis=null`, `decision_prejugee=false`. |
| `test_q141_information_ne_part_pas_vers_un_autre_etudiant` | Aucune notification pour un autre étudiant. |
| `test_q143_destinataire_officiel_et_horodatage_traces` | Destinataire, source officielle, horodatage et absence de délai conservés dans l’audit. |

### T-19 — Suite ciblée consolidée finale (10 fichiers)

```bash
php artisan test --compact \
  tests/Feature/EcheanceDerogationServiceTest.php \
  tests/Feature/NotificationPreuveRemiseTest.php \
  tests/Feature/Q132ContestationRemiseHttpTest.php \
  tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php \
  tests/Feature/Loop146QuestionsComplementairesTest.php \
  tests/Feature/Loop146ConsultationEtIntegrationTest.php \
  tests/Feature/Q141InformationAttenteNotificationTest.php \
  tests/Feature/AdminDerogationGestionHttpTest.php \
  tests/Feature/Q133Q134NotificationRecoursAdminHttpTest.php \
  tests/Feature/Q137DerogationDepotHttpTest.php
```

**Résultat :** **102 tests, 102 réussis, 489 assertions, 0 échec**, 5 507 ms.

### T-20 — Surface HTTP administrative et étudiante (19 tests / 94 assertions)

| Fichier | Cas | Assertions | Couverture |
| :--- | ---: | ---: | :--- |
| `AdminDerogationGestionHttpTest.php` | 11 | 62 | Q135/Q136, Q138, Q139, Q141, Q143–Q146 ; permission `demandes.process` ; absence de statut final Q146 |
| `Q133Q134NotificationRecoursAdminHttpTest.php` | 4 | 16 | Q133/Q134 ; permission `campagnes.manage` ; refus non authentifié |
| `Q137DerogationDepotHttpTest.php` | 4 | 16 | Q137 ; session étudiante, validation, anti-injection |

**Résultat :** 19 tests, 19 réussis, 94 assertions, 0 échec. Ces tests sont inclus dans le total T-19.

## 2. SYNTHÈSE

| Ensemble | Cas | Assertions | Échecs |
| :--- | ---: | ---: | ---: |
| T-01 à T-05 — notifications (avant correctif) | 53 | 240 | 0 |
| T-06 à T-08 — `S-SEC-01` + non-régression inscription | 223 | 1 170 | 0 |
| T-09 à T-12 — Q132–Q146 (fichiers séparés) | 62 | 266 | 0 |
| T-13/T-19 — suite ciblée consolidée et rejeu final | 102 | 489 | 0 |
| T-14 — non-régression notifications/éligibilité/échéances | 53 | 240 | 0 |
| T-17 — consultation et intégration | 9 | 72 | 0 |
| T-18 — notification Q141 et destinataire Q143 | 6 | 34 | 0 |
| T-20 — surface HTTP admin/étudiante | 19 | 94 | 0 |
| **Suite ciblée consolidée finale** | **102** | **489** | **0** |

> Les ensembles T-09/T-13 et T-14 sont disjoints. T-01 à T-05 précèdent le lot correctif et les écritures concurrentes ; T-06 à T-14 les suivent. Le cumul brut ne décrit pas un instant unique du dépôt.

---

## 3. TESTS NON EXÉCUTÉS ET RAISONS

| Test | Raison |
| :--- | :--- |
| Scénarios métier Q001–Q131 | Aucune question, aucune règle : rien à tester. |
| Q140 en HTTP direct | La règle est couverte par le service et par la route `informer-attente` mutualisée avec Q141 ; aucun endpoint propre à Q140 n'existe. |
| Q146 option A/B/C | Option non confirmée : tout test d'une règle finale testerait une décision inventée. |
| Autorité compétente réelle, délais de recours, valeur du silence | Textes officiels absents ; la configuration est vide par défaut. |
| Parité MySQL | Les suites utilisent SQLite en mémoire ; le comportement MySQL n'a pas été vérifié. |
| Tests de bout en bout navigateur | Hors périmètre de ce LOOP. |
| Suite complète du projet | Non exécutée : les écritures concurrentes rendraient le résultat non reproductible. |
| Charge / concurrence réelle (deux requêtes simultanées) | Non testée ; l'idempotence est prouvée séquentiellement, pas sous course. |

---

## 4. LIMITES D'INTERPRÉTATION

1. **Les 102 tests verts ne démontrent aucune conformité institutionnelle.** Ils prouvent le comportement implémenté, pas la légalité des règles.
2. Les réponses Q132–Q145 sont issues d'une source désignée par l'utilisateur, mais dont les **formulations de questions sont reconstruites**. Un test vert ne vérifie pas que la question d'origine était bien celle-là.
3. Q132–Q146 disposent désormais d'une surface HTTP protégée, sauf Q140 qui reste couverte par le service et la route mutualisée `informer-attente`. Cette surface est testée (RBAC, anti-IDOR, quotas, CSRF) mais ne remplace pas une validation institutionnelle des compétences.
4. Q146 n'a aucun test de règle finale : seuls les comportements indépendants de l'option sont couverts.
5. Les tests ont été exécutés sur un dépôt modifié par un acteur concurrent ; le rejeu final a été effectué après retrait du bloc de méthodes dupliquées, mais de nouvelles modifications restent possibles.
6. L'idempotence est prouvée par rejeu séquentiel, pas par un test de concurrence réelle.
