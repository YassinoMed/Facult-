# LOOP 42 — RÉSULTATS DES TESTS

**Date :** 31 octobre 2026
**Environnement :** PHPUnit, SQLite `:memory:`, `APP_ENV=testing`
**Objet :** fail-closed, isolation, double calcul, 7 classes de divergence, dénominateurs, lecture seule.

> Tous les chiffres proviennent de commandes exécutées dans cette session.

---

## 1. Synthèse

| Campagne | Commande | Tests | Assertions | Durée | Code de sortie | Résultat |
|---|---|---:|---:|---:|---:|---|
| Banc LOOP 42 | `php artisan test tests/Feature/Acquis/PiloteAcademiqueLoop42Test.php --compact` | **15** | **59** | 2 557 ms | `0` | **PASS** |
| Non-régression domaine Acquis | `php artisan test --filter="Acquis" --compact` | **126** | **1 447** | 15 837 ms | `0` | **PASS** |
| Formatage | `vendor/bin/pint --test <3 fichiers>` | — | — | — | `0` | **PASS** |

Sorties brutes :

```json
{"tool":"phpunit","result":"passed","tests":15,"passed":15,"assertions":59,"duration_ms":2557}
{"tool":"phpunit","result":"passed","tests":126,"passed":126,"assertions":1447,"duration_ms":15837}
{"tool":"pint","result":"passed"}
```

`126 = 111 (LOOP 41) + 15 (LOOP 42)` : aucune régression, aucun test désactivé.

---

## 2. Détail des 15 tests

### Fail-closed

| # | Test | Vérification | Statut |
|---|---|---|---|
| 1 | `test_pilote_inactif_par_defaut_bloque_sans_traiter_de_dossier` | Configuration par défaut : `BLOQUE`, `resultats=[]`, `dossiers_analyses=0`, code `PILOTE_INACTIF`, `lecture_seule=true` | PASS |
| 2 | `test_validation_institutionnelle_absente_bloque` | `A_VALIDER` + référence nulle : codes `VALIDATION_INSTITUTIONNELLE_MANQUANTE` et `REFERENCE_VALIDATION_MANQUANTE` | PASS |
| 3 | `test_perimetre_vide_bloque_meme_si_pilote_actif_et_valide` | Actif + validé + référence, mais périmètre vide : `PERIMETRE_VIDE`, zéro dossier | PASS |
| 4 | `test_mode_ecriture_refuse` | `mode=ECRITURE` : `MODE_NON_SUPPORTE` | PASS |

### Isolation

| # | Test | Vérification | Statut |
|---|---|---|---|
| 5 | `test_perimetre_isole_strictement_les_facultes` | Périmètre FST : 1 seul dossier analysé, `faculte_id=FST`, l'étudiant ENIT absent des résultats (2 inscriptions en base) | PASS |

### Double calcul et classes de divergence

| # | Test | Vérification | Statut |
|---|---|---|---|
| 6 | `test_concordance_exacte_quand_moteur_et_reference_coincident` | Moteur : 6 crédits / 1 UE (vérifié explicitement) ; référence publiée identique : `concordant=true`, `classe=null`, `ecarts=[]` | PASS |
| 7 | `test_ecart_credits` | Référence 9 vs moteur 6 : `ECART_CREDITS`, `delta=3` | PASS |
| 8 | `test_ecart_ues` | Référence 2 UE vs moteur 1 : `ECART_UES` | PASS |
| 9 | `test_ecart_decision_entre_reference_et_decision_historique` | Référence `admis` vs historique `ajourne` : `ECART_DECISION` | PASS |
| 10 | `test_ecart_moyenne_au_dela_de_la_tolerance` | 12,0 vs 14,5 : `ECART_MOYENNE` | PASS |
| 11 | `test_ecart_multiple_quand_plusieurs_dimensions_divergent` | Crédits + décision : `ECART_MULTIPLE`, deux clés d'écart | PASS |
| 12 | `test_reference_non_publiee_n_est_jamais_comparable` | `publie=false` : `comparable=false`, `REFERENCE_NON_PUBLIEE` | PASS |
| 13 | `test_reference_absente_n_est_jamais_comparable` | Aucune décision : `comparable=false`, `REFERENCE_ABSENTE` | PASS |

### Indicateurs et lecture seule

| # | Test | Vérification | Statut |
|---|---|---|---|
| 14 | `test_indicateurs_exposent_des_denominateurs_explicites` | 3 dossiers : 2 comparables, 1 concordant, 1 non comparable ⇒ `taux_concordance = 1/2 = 0,5` et **pas** `1/3` | PASS |
| 15 | `test_simulation_ne_modifie_aucune_donnee` | Comptage avant/après de `etudiants`, `inscriptions_academiques`, `notes`, `evaluation_decisions`, `parcours_plans` : identique | PASS |

---

## 3. Contrôles statiques complémentaires

| Contrôle | Commande | Résultat |
|---|---|---|
| Absence d'écriture | `grep -n "save(\|create(\|update(\|delete(\|insert(\|DB::" app/Services/Acquis/PilotAcademiqueService.php` | `NO_WRITE_CALLS` |
| Absence de PII | `grep -nE -- "->(nom\|prenom\|cin\|email)" app/Services/Acquis/PilotAcademiqueService.php` | `NO_PII_FIELDS` |
| Configuration par défaut inactive | `PilotAcademiqueService::etat()` sur l'environnement réel | `actif=false`, `A_VALIDER`, `perimetre_configure=false` |
| Exécution réelle | `executerSimulation()` sur l'environnement réel | `BLOQUE`, 0 dossier, 4 blocages |

---

## 4. Ce que ces tests ne prouvent pas

1. **Ils ne prouvent pas la conformité réglementaire du pilote** : aucun programme réel n'a de fiche
   de formation confirmée (0/12).
2. **Ils ne prouvent pas qu'un pilote réel est autorisé** : la validation institutionnelle n'existe pas.
3. **Ils ne couvrent pas les 2 076 inscriptions sans décision publiée** : elles sont classées
   `REFERENCE_ABSENTE` par construction, pas testées individuellement.
4. **Ils ne testent pas l'écriture** : `mode=ECRITURE` est refusé, il n'y a rien à tester.
5. **Ils ne remplacent pas une recette métier** sur les 14 dossiers comparables.

---

## 5. Reproductibilité

```bash
php artisan test tests/Feature/Acquis/PiloteAcademiqueLoop42Test.php --compact
php artisan test --filter="Acquis" --compact
vendor/bin/pint --test app/Services/Acquis/PilotAcademiqueService.php \
  config/pilote_academique.php tests/Feature/Acquis/PiloteAcademiqueLoop42Test.php
```

Les tests sont déterministes (jeu de fixtures, aucune dépendance à l'horloge au-delà de `now()` pour
les horodatages de décision).
