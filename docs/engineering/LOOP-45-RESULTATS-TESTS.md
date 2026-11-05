# LOOP 45 — RÉSULTATS DES TESTS ET PREUVES D'EXÉCUTION

**Date :** 31 octobre 2026
**Environnement :** `APP_ENV=testing`, SQLite `:memory:` (garde anti-catastrophe de `tests/TestCase.php`),
modules activés, Vite désactivé.
**Base réelle :** MySQL `inscription_etudiants` à `127.0.0.1:3306`, **lecture seule** dans cette
étape (aucune migration LOOP 45, aucune écriture).

---

## 1. Synthèse

| Exécution | Tests | Assertions | Durée | Résultat |
|---|---|---|---|---|
| `RecoursAcademiqueLoop45Test` | **38** | **202** | 8 624 ms | ✅ passed |
| Non-régression `--filter="Acquis"` | **161** | **1 598** | 28 080 ms | ✅ passed |
| Non-régression demandes (`WorkflowDemandesValidationTest`, `OnlineRequestTest`, `OnlineRequestSecurityTest`) | **27** | **210** | 3 946 ms | ✅ passed |
| Non-régression workflows (`WorkflowValidationConfigurableTest`, `DocumentWorkflowTest`) | **21** | **59** | 8 666 ms | ✅ passed |
| `PermissionsCoherenceTest` | **2** | **8** | 198 ms | ✅ passed |
| `vendor/bin/pint` (8 fichiers LOOP 45) | — | — | — | ✅ passed |
| `php artisan route:list` | — | — | — | ✅ exit 0 |

Aucun test existant n'a été modifié. La non-régression `Acquis` inclut les tests LOOP 42 à 44.

---

## 2. Couverture du circuit (38 tests)

### 2.1 Fail-closed et règles non inventées (8)

| Test | Garantie |
|---|---|
| `test_circuit_inactif_par_defaut_refuse_tout_depot` | `actif=false` ⇒ dépôt refusé, 0 demande créée |
| `test_reference_reglementaire_absente_bloque_meme_circuit_actif` | `actif=true` sans référence ⇒ refus |
| `test_type_de_demande_absent_bloque_le_circuit` | Type support absent ⇒ refus explicite |
| `test_type_de_demande_inactif_bloque_le_circuit` | Type support inactif ⇒ refus explicite |
| `test_aucun_type_de_decision_n_est_conteste_par_defaut` | `types_decisions_contestables=[]` ⇒ aucun dépôt |
| `test_decision_non_publiee_refusee` | Décision non publiée ⇒ refus |
| `test_deliberation_non_publiee_refusee` | Délibération non publiée ⇒ refus |
| `test_decision_d_un_autre_etudiant_refusee` | Décision d'autrui ⇒ refus |

### 2.2 Dépôt, délai et recevabilité (6)

| Test | Garantie |
|---|---|
| `test_depot_valide_cree_un_recours_soumis_et_trace` | Statut `submitted`, version 1, référence `REC-`, décision épinglée, audit `online_request.submitted` |
| `test_doublon_de_recours_pour_la_meme_decision_refuse` | Un seul recours ouvert par décision |
| `test_depot_hors_delai_reste_a_verifier_sans_rejet_automatique` | Délai dépassé ⇒ `A_VERIFIER`, `dans_delai=false`, aucun rejet |
| `test_depot_dans_le_delai_signale_dans_delai` | Délai respecté ⇒ `dans_delai=true`, recevabilité toujours humaine |
| `test_etat_recevabilite_explique_l_absence_de_delai_officiel` | `delai_configure=false`, `dans_delai=null`, explication explicite |
| `test_motif_vide_refuse` | Motif obligatoire |

### 2.3 Recevabilité et instruction (6)

| Test | Garantie |
|---|---|
| `test_recevabilite_exige_la_permission_dediee` | `direction_etudes` sans `recours.recevoir` ⇒ refus |
| `test_recevabilite_exige_une_reference_humaine` | Référence vide ⇒ refus |
| `test_declaration_de_recevabilite_tracee_et_transition_received` | `received`, `RECEVABLE`, agent/date/référence, audit `online_request.received` |
| `test_instruction_assigne_nominalement_l_instructeur` | `in_review`, `assigned_to`, instruction `EN_COURS` |
| `test_instruction_exige_la_permission_dediee` | `direction_etudes` sans `recours.instruire` ⇒ refus |
| `test_un_second_instructeur_ne_supplante_pas_l_assignation` | Réassignation par un autre agent refusée |

### 2.4 Décision, quatre yeux et non-écriture (6)

| Test | Garantie |
|---|---|
| `test_l_instructeur_assigne_ne_peut_pas_decider_lui_meme` | Même `super_admin` instructeur ⇒ refus quatre yeux |
| `test_decider_exige_la_permission_dediee` | `admin_scolarite` sans `recours.decider` ⇒ refus |
| `test_decision_sans_instruction_prealable_refusee` | Aucune décision sans `assigned_to` |
| `test_decision_approuvee_par_un_second_agent_sans_toucher_aux_resultats` | `approved`, application manuelle à faire, décision et notes inchangées |
| `test_decision_rejetee_est_tracee_et_permet_un_nouveau_depot` | `rejected`, motif, nouveau dépôt possible |
| `test_application_automatique_configuree_est_refusee` | `application_automatique_autorisee=true` ⇒ décision refusée |

### 2.5 Application, clôture et annulation (5)

| Test | Garantie |
|---|---|
| `test_cloture_exige_une_application_manuelle_avec_reference` | Clôture sans application ⇒ refus ; référence vide ⇒ refus |
| `test_application_manuelle_puis_cloture` | Application tracée, `completed`, audits `application_manuelle` et `completed` |
| `test_double_application_refusee` | Double application impossible |
| `test_annulation_etudiante_avant_reception` | `cancelled` avec motif et audit |
| `test_annulation_impossible_apres_reception` | Annulation refusée dès `received` |

### 2.6 Isolation, garde générique et cohérence (7)

| Test | Garantie |
|---|---|
| `test_annulation_par_un_autre_etudiant_refusee` | Propriété étudiante vérifiée |
| `test_consultation_limitee_aux_recours_de_l_etudiant` | Seuls les recours du demandeur sont listés |
| `test_le_transitionneur_generique_ne_peut_pas_faire_evoluer_un_recours` | Garde sur `transitionner()` et `approuver()` |
| `test_le_garde_generique_s_applique_meme_circuit_desactive` | Un recours existant ne redevient jamais une demande ordinaire |
| `test_permissions_separees_entre_instruction_et_decision` | `admin_scolarite` instruit sans décider, `direction_etudes` décide sans instruire |
| `test_definition_du_workflow_n_invente_aucun_delai_et_interdit_les_sauts` | `delaiMaxHeures=null` partout, sauts interdits, permissions correctes |
| `test_aucune_ecriture_dans_les_tables_academiques_pendant_le_circuit` | Notes, décisions et délibérations inchangées sur le cycle complet |

---

## 3. Défauts réels détectés puis corrigés

### 3.1 Statut non appliqué par le moteur

Premier passage : 37 tests sur 38, `received` attendu mais `submitted` observé.

**Cause :** `MoteurWorkflowService::transiter()` utilisait `$entite->update([...])`. Or `status` n'est
pas dans `$fillable` de `OnlineRequest` (protection contre le mass-assignment). La transition était
validée et auditée, mais le statut restait inchangé.

**Correction :** `forceFill(...)->save()` après les cinq contrôles (topologie, permission, isolation,
quatre yeux, gardes). Le moteur est l'autorité serveur ; le champ d'état est choisi par
`determinerChampEtat()`.

### 3.2 Audit agent hors vocabulaire du module

**Constat :** les transitions agent n'émettaient que `modification_dossier` (audit générique du
moteur), sans événement `online_request.*`.

**Correction :** ajout de `tracerActeur()` dans `RecoursAcademiqueService`, appelé après chaque
transition agent : `received`, `in_review`, `waiting_student`, `approved`/`rejected`, `completed`.
Le motif et les pièces ne sont jamais recopiés dans l'audit.

---

## 4. Preuves sur la base réelle (lecture seule)

### 4.1 Configuration effective

```json
{
  "actif": false,
  "reference": null,
  "types_contestables": [],
  "delai": null
}
```

### 4.2 Absence de support et de données

```json
{
  "type_existe": 0,
  "demandes": 0,
  "recours": 0,
  "statuts": []
}
```

- Aucun type `RECOURS_ACADEMIQUE` n'existe.
- Aucune demande en ligne n'existe.
- Aucun recours ne peut donc exister.

### 4.3 Workflow et permissions résolus en conditions réelles

```json
{
  "workflow": "recours",
  "version": 1,
  "delai_max": null,
  "agent_instruit": true,
  "agent_decide": false,
  "direction_decide": true,
  "direction_instruit": false
}
```

- La définition `recours` v1 est bien enregistrée.
- Aucun délai maximal n'est inventé.
- La séparation instruction/décision est effective dans la matrice réelle.

---

## 5. Limites de cette exécution

- La suite complète (plusieurs milliers de tests) n'a pas été exécutée ; seules les suites ciblées
  ci-dessus l'ont été.
- Aucun test HTTP : le circuit n'expose ni route ni contrôleur.
- Aucun test de charge ou de concurrence réelle : les verrous `lockForUpdate` sont exercés
  fonctionnellement, pas sous charge parallèle.
- Les tests SQLite ne reproduisent pas le comportement exact d'un `ENUM` MySQL ; les statuts utilisés
  sont strictement ceux de `OnlineRequest::STATUTS`.
- Aucune donnée réelle n'a été créée, modifiée ou supprimée.

---

## 6. Commandes exécutées

```bash
php artisan test tests/Feature/OnlineRequests/RecoursAcademiqueLoop45Test.php --compact
# {"tool":"phpunit","result":"passed","tests":38,"passed":38,"assertions":202,"duration_ms":8624}

php artisan test --filter="Acquis" --compact
# {"tool":"phpunit","result":"passed","tests":161,"passed":161,"assertions":1598,"duration_ms":28080}

php artisan test tests/Feature/WorkflowDemandesValidationTest.php tests/Feature/OnlineRequestTest.php tests/Feature/OnlineRequestSecurityTest.php --compact
# {"tool":"phpunit","result":"passed","tests":27,"passed":27,"assertions":210,"duration_ms":3946}

php artisan test tests/Feature/WorkflowValidationConfigurableTest.php tests/Feature/DocumentWorkflowTest.php --compact
# {"tool":"phpunit","result":"passed","tests":21,"passed":21,"assertions":59,"duration_ms":8666}

php artisan test tests/Feature/PermissionsCoherenceTest.php --compact
# {"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":8,"duration_ms":198}

vendor/bin/pint app/Modules/OnlineRequests/Services/OnlineRequestService.php \
  app/Modules/OnlineRequests/Services/RecoursAcademiqueService.php \
  app/Services/Workflow/MoteurWorkflowService.php \
  app/Services/Workflow/Definitions/WorkflowRecoursAcademiqueDefinition.php \
  app/Services/Workflow/RegistreWorkflow.php \
  app/Support/Permissions.php config/recours_academiques.php \
  tests/Feature/OnlineRequests/RecoursAcademiqueLoop45Test.php
# {"tool":"pint","result":"passed"}

php artisan route:list
# exit 0
```
