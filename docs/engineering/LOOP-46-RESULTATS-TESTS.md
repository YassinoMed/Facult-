# LOOP 46 — RÉSULTATS DES TESTS ET PREUVES D'EXÉCUTION

**Date :** 31 octobre 2026
**Environnement :** `APP_ENV=testing`, SQLite `:memory:` (garde anti-catastrophe de `tests/TestCase.php`),
modules activés, Vite désactivé.
**Base réelle :** MySQL `inscription_etudiants` à `127.0.0.1:3306`, **lecture seule** dans cette
étape (aucune migration, aucune écriture).

---

## 1. Synthèse

| Exécution | Tests | Assertions | Durée | Résultat |
|---|---|---|---|---|
| `ExplicationDecisionAcademiqueLoop46Test` | **24** | **128** | 3 355 ms | ✅ passed |
| `OutilsMetierEtDeveloppeurTest` (registre corrigé) | **5** | **53** | — | ✅ passed |
| Non-régression `--filter="AssistantIa"` | **266** | **1 076** | 31 695 ms | ✅ passed |
| Non-régression `--filter="Acquis"` | **185** | **1 726** | 27 545 ms | ✅ passed |
| Non-régression `ExamGroupesVirtuelsLoopGlobalTest` | **13** | **110** | 2 957 ms | ✅ passed |
| Non-régression `AiAssistantOutilsTest` + `AiAssistantFondationsTest` | **47** | **125** | 4 937 ms | ✅ passed |
| Non-régression LOOP 45 + `PermissionsCoherenceTest` | **40** | **210** | 12 066 ms | ✅ passed |
| `vendor/bin/pint` (7 fichiers LOOP 46) | — | — | — | ✅ passed |
| `php artisan route:list` | — | — | — | ✅ exit 0 |

Le filtre `Acquis` passe de 161 tests (LOOP 45) à 185 : +24 tests LOOP 46.

---

## 2. Couverture du dispositif (24 tests)

### 2.1 Fail-closed avant lecture (6)

| Test | Garantie |
|---|---|
| `test_decision_non_publiee_refusee_sans_lecture_moteur` | `publie=false` ⇒ `DECISION_NON_PUBLIEE`, aucune lecture moteur, aucune citation, empreinte nulle |
| `test_deliberation_introuvable_refusee` | Délibération absente ⇒ `DELIBERATION_INTROUVABLE` |
| `test_deliberation_non_publiee_refusee` | Statut `validated` ⇒ `DELIBERATION_NON_PUBLIEE` + alerte d'intégrité |
| `test_inscription_non_rattachee_refusee` | `inscription_academique_id=null` ⇒ refus |
| `test_programme_indetermine_refuse_sans_appeler_le_moteur` | Aucun programme ⇒ `PROGRAMME_INDETERMINE`, `lecture_moteur=null` |
| `test_regime_indetermine_refuse_au_lieu_de_deviner` | Type de diplôme inconnu ⇒ `REGIME_INDETERMINE` (aucune règle devinée) |

### 2.2 Concordance mesurée, non décisionnaire (5)

| Test | Garantie |
|---|---|
| `test_decision_concordante_avec_la_lecture_du_moteur` | Crédits officiels = crédits moteur, UE acquises concordantes, `decision_officielle_fait_foi` |
| `test_ecart_mesure_reste_un_constat_sans_consequence` | Écart = `constat_technique`, `consequence_automatique=false`, mention « décision officielle fait foi » |
| `test_notes_cumulees_sur_plusieurs_inscriptions_rendent_la_comparaison_non_comparable` | Périmètre cumulé ⇒ `NON_COMPARABLE`, aucun fait comparé, raisons explicites |
| `test_moyenne_et_total_officiels_ne_sont_pas_compares_abusivement` | Moyenne et total officiels exposés sans comparaison abusive |
| `test_alertes_integrite_signalees_sans_bloquer` | Plusieurs décisions sur une même inscription ⇒ alerte, pas de refus |

### 2.3 Lecture seule, anonymisation, traçabilité (4)

| Test | Garantie |
|---|---|
| `test_aucune_ecriture_en_base_pendant_l_explication` | 0 requête d'écriture, comptages inchangés |
| `test_aucune_recommandation_ni_sanction_dans_le_payload` | Garanties déclarées + absence des clés `recommandation`/`sanction`/`plan_action`/`plan_deblocage`/`mesure_corrective`/`orientation` |
| `test_identite_anonymisee_et_matricule_masque` | Matricule masqué, aucun nom/CIN/email/téléphone, aucun identifiant en clair dans le JSON |
| `test_citations_reelles_et_traçables` | Références `evaluation_decisions#`, `evaluation_deliberations#`, `inscriptions_academiques#`, références moteur `verifiee=false` |

### 2.4 Empreinte (1)

| Test | Garantie |
|---|---|
| `test_empreinte_deterministe_et_verifiable` | SHA-256 stable entre deux appels ; altération des crédits officiels ou de la lecture moteur détectée |

### 2.5 Outil Assistant IA (8)

| Test | Garantie |
|---|---|
| `test_outil_refuse_un_identifiant_invalide` | `decision_id<=0` ⇒ échec explicite |
| `test_outil_refuse_une_decision_introuvable` | Décision absente ⇒ échec explicite |
| `test_outil_refuse_un_profil_sans_habilitation` | Rôle `auditeur` ⇒ `SecuriteIaException` |
| `test_outil_controle_le_perimetre_facultaire` | `admin_faculte` d'une autre faculté ⇒ refus ; faculté de rattachement ⇒ succès |
| `test_outil_retourne_un_succes_lecture_seule_et_anonymise` | `lecture_seule`, anonymisation, sources, message « sans recommandation ni sanction », aucun matricule en clair |
| `test_outil_refus_non_publie_retourne_un_echec_explicite` | Échec métier avec `motif_indisponibilite` et `lecture_seule=true` |
| `test_outil_declare_son_contrat_et_est_enregistre` | Nom, mode, permission, schéma, présence au registre, refus pour rôle non habilité |
| `test_outil_est_interdit_au_fournisseur_distant` | Retiré des définitions distantes, exception `OutilNonAutoriseException` à l'exécution distante |

---

## 3. Correction d'un test obsolète

`OutilsMetierEtDeveloppeurTest::test_registre_contient_tous_les_outils_requis`
attendait **18** outils alors que **19** étaient enregistrés avant LOOP 46 :
`expliquer_acquis_redoublement` n'était pas déclaré dans le test.

- Avant LOOP 46 : `{"result":"failed","tests":266,"passed":265,"failed":1}` — écart 19 vs 18.
- Correction : déclaration des deux outils manquants (`expliquer_acquis_redoublement`,
  `expliquer_decision_academique`) et compte porté à **20**.
- Après : `--filter="AssistantIa"` ⇒ **266/266** passés.

Aucune assertion de comportement n'a été modifiée ; seul le contrat du registre a été mis à jour.

---

## 4. Preuves sur la base réelle (lecture seule)

### 4.1 État des décisions et délibérations

```json
{
  "decisions_total": 16,
  "decisions_publiees": 14,
  "decisions_publiees_sans_inscription": 0,
  "deliberations_par_statut": { "validee": 1, "prepared": 2, "published": 2 },
  "etudiants_avec_plusieurs_inscriptions": 18,
  "inscriptions_avec_plusieurs_decisions": 0,
  "notes_sans_inscription": 0,
  "outils_enregistres": 20
}
```

### 4.2 Explicabilité réelle des décisions publiées

```json
{
  "decisions_publiees_sur_deliberation_publiee": 9,
  "decisions_publiees_sur_deliberation_non_publiee": 5,
  "deliberations_publiees_non_verrouillees": 2,
  "statuts_non_reconnus_par_le_modele": { "6": "validee", "4": "prepared", "5": "prepared" }
}
```

- **9 décisions publiées sur 14** sont explicables en l'état.
- **5 décisions publiées** sont refusées fail-closed car leur délibération n'est pas publiée.
- Le statut réel `validee` n'existe pas dans `Deliberation::STATUTS` (qui déclare `validated`) :
  décalage modèle/données constaté, **aucun mapping inventé**.

### 4.3 Exécution réelle du service sur la décision #1

```json
{
  "decision_id": 1,
  "explicable": true,
  "statut_concordance": "ECART_MESURE",
  "perimetre_comparable": true,
  "faits_compares": [
    { "fait": "credits_acquis", "officiel": 30, "moteur": 12, "comparables": true, "ecart": -18 },
    { "fait": "ues_validees", "officiel": 0, "moteur": 1, "comparables": false }
  ],
  "credits_moteur": { "capitalises": 12, "non_acquis": 0, "restants": 168, "total_requis": 180 },
  "nombre_unites_lues": 1,
  "nombre_citations": 9,
  "alertes_integrite": ["La délibération est publiée mais non verrouillée : l'acte officiel peut encore évoluer."],
  "lecture_seule": true,
  "empreinte_presente": true
}
```

L'écart est restitué comme constat, sans conséquence automatique et sans jugement
sur la décision du jury.

### 4.4 Exécution réelle de l'outil Assistant IA

```json
{
  "role_utilisateur": "super_admin",
  "succes": true,
  "necessite_approbation": false,
  "nombre_sources": 9,
  "lecture_seule": true,
  "anonymisation": {
    "matricule_masque": true,
    "nom_expose": false,
    "cin_expose": false,
    "email_expose": false,
    "telephone_expose": false
  },
  "matricule_masque": "IN**********26",
  "statut_concordance": "ECART_MESURE",
  "outil": "expliquer_decision_academique"
}
```

---

## 5. Limites de cette exécution

- La suite complète (plusieurs milliers de tests) n'a pas été exécutée ; seules les
  suites ciblées ci-dessus l'ont été.
- Aucun test HTTP : le service n'expose ni route ni contrôleur.
- Aucun test de charge ou de concurrence : le dispositif est en lecture seule.
- Les tests SQLite ne reproduisent pas exactement les contraintes MySQL ; le service
  n'écrit rien, la question est sans effet ici.
- Aucune donnée réelle n'a été créée, modifiée ou supprimée.

---

## 6. Commandes exécutées

```bash
php artisan test tests/Feature/Acquis/ExplicationDecisionAcademiqueLoop46Test.php --compact
# {"tool":"phpunit","result":"passed","tests":24,"passed":24,"assertions":128,"duration_ms":3355}

php artisan test tests/Feature/AssistantIa/OutilsMetierEtDeveloppeurTest.php --compact
# inclus dans la vérification croisée : 29 tests / 181 assertions sur les deux fichiers

php artisan test --filter="AssistantIa" --compact
# {"tool":"phpunit","result":"passed","tests":266,"passed":266,"assertions":1076,"duration_ms":31695}

php artisan test --filter="Acquis" --compact
# {"tool":"phpunit","result":"passed","tests":185,"passed":185,"assertions":1726,"duration_ms":27545}

php artisan test --filter="ExamGroupesVirtuelsLoopGlobalTest" --compact
# {"tool":"phpunit","result":"passed","tests":13,"passed":13,"assertions":110,"duration_ms":2957}

php artisan test tests/Feature/AiAssistantOutilsTest.php tests/Feature/AiAssistantFondationsTest.php --compact
# {"tool":"phpunit","result":"passed","tests":47,"passed":47,"assertions":125,"duration_ms":4937}

php artisan test tests/Feature/OnlineRequests/RecoursAcademiqueLoop45Test.php tests/Feature/PermissionsCoherenceTest.php --compact
# {"tool":"phpunit","result":"passed","tests":40,"passed":40,"assertions":210,"duration_ms":12066}

vendor/bin/pint app/Services/Acquis/ExplicationDecisionAcademiqueService.php \
  app/Modules/AssistantIa/Tools/Metier/ExpliquerDecisionAcademiqueTool.php \
  app/Modules/AssistantIa/Contracts/OutilIaDonneesAcademiquesInterface.php \
  app/Modules/AssistantIa/Services/RegistreOutils.php \
  app/Providers/ModuleServiceProvider.php \
  tests/Feature/Acquis/ExplicationDecisionAcademiqueLoop46Test.php \
  tests/Feature/AssistantIa/OutilsMetierEtDeveloppeurTest.php
# {"tool":"pint","result":"passed"}

php artisan route:list
# exit 0
```
