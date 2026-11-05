# RÉSULTATS D'EXÉCUTION DES TESTS ET NON-RÉGRESSION — LOOP 146

**Référence :** `05-resultats-tests.md`  
**Application :** UniRegistre  
**Date :** 2026-10-29  

---

## 1. Campagne de Tests Unitaires et d'Intégration Ciblée

### A. Preuves de Remise et Notifications (`NotificationPreuveRemiseTest`)
- **Commande :** `php artisan test tests/Feature/NotificationPreuveRemiseTest.php --compact`
- **Résultat :** **14 tests passés, 60 assertions, 0 échec** (Temps d'exécution : 1.24 s).
- **Assertions vérifiées :**
  1. `test_acheminement_externe_reussi_ne_vaut_jamais_remise_confirmee` (Q114, Q116) : `PASS`
  2. `test_confirmation_formelle_remise_avec_tracabilite_et_mode_preuve` (Q120, Q125) : `PASS`
  3. `test_refus_validation_preuve_exige_motif_et_maintient_remise_non_confirmee` (Q126) : `PASS`
  4. `test_refus_motive_preserve_statut_non_confirme_et_pieces` (Q124, Q127) : `PASS`
  5. `test_enregistrement_preuve_tardive_preserve_historique_anterieur` (Q121, Q122, Q123) : `PASS`
  6. `test_contestation_remise_enregistree_avec_motif_et_piece` (Q128, Q129, Q132) : `PASS`
  7. `test_application_recours_officiel_preserve_refus_sans_muter_dossiers_associes` (Q130, Q131, Q133, Q134) : `PASS`
  8. `test_cloture_administrative_sans_fondement_officiel_est_bloquee` (Q117) : `PASS`
  9. `test_cloture_administrative_autorisee_distingue_clos_et_remise_non_confirmee` (Q118, Q119) : `PASS`

### B. Échéances et Dérogations (`EcheanceDerogationServiceTest`)
- **Commande :** `php artisan test tests/Feature/EcheanceDerogationServiceTest.php --compact`
- **Résultat :** **10 tests passés, 52 assertions, 0 échec** (Temps d'exécution : 1.26 s).
- **Assertions vérifiées :**
  1. `test_constat_depassement_preserve_echeance_initiale_et_refuse_forclusion_automatique` (Q135, Q136) : `PASS`
  2. `test_depot_demande_derogation_exige_motif_circonstancie_et_preserve_attente` (Q137) : `PASS`
  3. `test_statuer_derogation_fail_closed_puis_controle_competence_autorite` (Q138) : `PASS`
  4. `test_relance_derogation_fail_closed_puis_canal_officiel_sans_delai_invente` (Q139, Q140) : `PASS`
  5. `test_information_attente_prolongee_est_transparente_et_ne_prejuge_rien` (Q141) : `PASS`
  6. `test_contestation_attente_prolongee_enregistree_sans_mutation_dossier` (Q142) : `PASS`
  7. `test_transmission_reexamen_fail_closed_puis_voie_officielle_sans_delai_garanti` (Q143) : `PASS`
  8. `test_decision_reexamen_insuffisamment_motivee_preservee_verbatim_sans_extrapolation` (Q144) : `PASS`
  9. `test_decision_ambiguë_figee_sans_presumer_issue` (Q145) : `PASS`
  10. `test_clarification_decision_conserve_decision_initiale_sans_mutation_automatique` (Q146) : `PASS`

### C. Parcours Utilisateur & HTTP (`Q132ContestationRemiseHttpTest`, `Q142ContestationAttenteProlongeeHttpTest`, `Loop146ConsultationEtIntegrationTest`)
- **Commandes :**
  - `php artisan test tests/Feature/Q132ContestationRemiseHttpTest.php --compact` -> **7 tests passés, 29 assertions**.
  - `php artisan test tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php --compact` -> **6 tests passés, 23 assertions**.
  - `php artisan test tests/Feature/Loop146ConsultationEtIntegrationTest.php --compact` -> **9 tests passés, 72 assertions**.
- **Assertions vérifiées :**
  1. `test_q132_http_proprietaire_conteste_sans_changer_le_statut` : `PASS`
  2. `test_q132_http_autre_etudiant_recoit_404_sans_mutation` (Anti-IDOR) : `PASS`
  3. `test_q132_http_invite_redirige_vers_la_connexion` : `PASS`
  4. `test_q132_http_motif_invalide_rejete_sans_mutation` : `PASS`
  5. `test_q132_http_notification_non_refusee_rejetee` : `PASS`
  6. `test_q132_http_double_soumission_idempotente` : `PASS`
  7. `test_q132_http_piece_justificative_hash_conserve` : `PASS`
  8. `test_etudiant_connecte_peut_consulter_la_page_derogations` (Phase 4 UI) : `PASS`
  9. `test_invite_redirige_vers_connexion` : `PASS`
  10. `test_etudiant_peut_contester_attente_prolongee_sans_mutation_dossier` (Q142, RECOURS-005) : `PASS`
  11. `test_contestation_attente_rejetee_si_motif_trop_court` : `PASS`
  12. `test_contestation_attente_idempotente` : `PASS`
  13. `test_anti_idor_autre_etudiant_ne_voit_pas_les_demandes_d_autrui` : `PASS`
  14. `test_q146_consultation_ne_presume_ni_accord_ni_refus_apres_clarification` : `PASS`
  15. `test_q146_deux_clarifications_successives_laissent_le_statut_final_ouvert` : `PASS`
  16. `test_q146_la_consultation_reflete_l_evenement_le_plus_recent` : `PASS`
  17. `test_q142_contestation_ne_modifie_pas_le_statut_du_dossier` : `PASS`
  18. `test_q142_contestation_apres_decision_ne_surcharge_pas_la_decision` : `PASS`
  19. `test_q135_consultation_conserve_l_echeance_initiale_et_signale_le_risque` : `PASS`
  20. `test_q133_cycle_contestation_puis_recours_conserve_l_historique` : `PASS`
  21. `test_q132_http_le_onzieme_envoi_est_refuse_par_le_quota` : `PASS`
  22. `test_q132_http_piece_de_type_interdit_rejetee_sans_trace` : `PASS`

---

## 2. Suite de Non-Régression Combinée

- **Commande :**
  ```bash
  php artisan test \
    tests/Feature/Q132ContestationRemiseHttpTest.php \
    tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php \
    tests/Feature/Loop146ConsultationEtIntegrationTest.php \
    tests/Feature/NotificationPreuveRemiseTest.php \
    tests/Feature/EcheanceDerogationServiceTest.php \
    tests/Feature/InscriptionEligibiliteOrientationTest.php \
    tests/Feature/ExamCapaciteSalleNonConfirmeeTest.php \
    tests/Feature/ExamGroupesVirtuelsConsultationExportTest.php \
    tests/Feature/Loop146QuestionsComplementairesTest.php \
    tests/Feature/LoopGlobalUniRegistreAmeliorationsTest.php --compact
  ```
- **Résultat :** **129 tests passés, 612 assertions, 0 échec — 100% VERT** (8.54 s).

---

## 3. Conformité du Style de Code (Pint)

- **Commande :** `vendor/bin/pint --dirty --test`
- **Résultat :** `{"tool":"pint","result":"passed"}` — 100% conforme.
