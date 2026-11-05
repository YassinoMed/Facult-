# LOOP 41 — RÉSULTATS DES TESTS ET NON-RÉGRESSION

**Date d'exécution :** 31 octobre 2026
**Environnement :** `local` (PHPUnit, SQLite `:memory:`, `APP_ENV=testing`)
**Objet :** vérifier les 10 exigences du dry-run de migration historique et l'absence de régression sur le domaine Acquis.

> Tous les chiffres de ce document proviennent de commandes réellement exécutées dans cette session.
> Aucun résultat n'est estimé, extrapolé ou déclaré sans sortie de commande correspondante.
> Une version antérieure de ce fichier annonçait `7 tests / 27 assertions` et `109 tests / 1 379 assertions` :
> ces valeurs étaient inexactes et ont été remplacées par les mesures ci-dessous.

---

## 1. Synthèse

| Campagne | Commande | Tests | Assertions | Durée | Code de sortie | Résultat |
|---|---|---:|---:|---:|---:|---|
| Banc LOOP 41 | `php artisan test tests/Feature/Acquis/MigrationHistoriqueDryRunLoop41Test.php --compact` | **9** | **36** | 2 521 ms | `0` | **PASS** |
| Non-régression domaine Acquis | `php artisan test --filter="Acquis" --compact` | **111** | **1 388** | 15 916 ms | `0` | **PASS** |
| Formatage | `vendor/bin/pint --test <4 fichiers modifiés>` | — | — | — | `0` | **PASS** |

Sorties brutes :

```json
{"tool":"phpunit","result":"passed","tests":9,"passed":9,"assertions":36,"duration_ms":2521}
{"tool":"phpunit","result":"passed","tests":111,"passed":111,"assertions":1388,"duration_ms":15916}
{"tool":"pint","result":"passed"}
```

### Périmètre exact de la non-régression

Le filtre `Acquis` couvre les 9 fichiers de `tests/Feature/Acquis/`, soit 111 tests :

| Fichier | Tests |
|---|---:|
| `CertificationLoop37FinalAcquisWorkflowTest.php` | 8 |
| `CertificationLoop38FinalAcquisWorkflowTest.php` | 10 |
| `CertificationLoop39RegimesAcademiquesTest.php` | 9 |
| `DecisionAcademiqueWorkflowTest.php` | 14 |
| `GestionAcquisRedoublementTest.php` | 32 |
| `IndustrialisationAcquisWorkflowTest.php` | 12 |
| `MigrationHistoriqueDryRunLoop41Test.php` | 9 |
| `RecetteMetierCorrectiveLoop40CTest.php` | 8 |
| `RecetteMetierValidationLoop40Test.php` | 9 |
| **Total** | **111** |

Ce filtre ne couvre **pas** l'intégralité de l'application (autres modules, auth, API, vues).
Il établit la non-régression du domaine concerné par LOOP 41, pas une certification globale du produit.

---

## 2. Détail des 9 tests du banc LOOP 41

| # | Test | Ce qui est réellement vérifié | Statut |
|---|---|---|---|
| 1 | `test_exigence1_et_2_dry_run_ne_modifie_aucune_donnee` | Comptage `etudiants`, `inscriptions_academiques`, `notes`, `parcours_plans` avant/après `simulerDossierEtudiant()` ; empreintes non vides | PASS |
| 2 | `test_exigence3_et_4_mapping_ambigu_bloque_et_ue_similaire_non_assimilee` | Code identique → `MAPPING_CONFIRME` (`certifie=true`) ; libellé identique sans code → `MAPPING_A_VALIDER` (`certifie=false`) ; libellés proches → `MAPPING_AMBIGU` + motif « Similarité textuelle » | PASS |
| 3 | `test_exigence5_et_6_credits_non_dupliques_et_etancheite_entre_etudiants` | Étudiant à 14,5/20 → 3 crédits et 1 élément capitalisé ; étudiant à 8,0/20 sur la même matière → 0 crédit, 0 élément | PASS |
| 4 | `test_exigence7_isolation_interfacultes` | `faculte_id` restitué à l'identique pour FST et ENIT, sans collision | PASS |
| 5 | `test_exigence8_idempotence_et_reprise_dry_run` | Deux exécutions successives → `empreinte_source`, `empreinte_resultat` et `statut_migration` identiques | PASS |
| 6 | `test_exigence9_cas_reglementaires_non_confirmes_exclus_de_la_migration_automatique` | Programme en co-construction → jamais `MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME` ; statut `MIGRABLE_APRES_VALIDATION` ; justification mentionnant l'homologation ministérielle | PASS |
| 7 | `test_anom41_02_absence_de_decision_officielle_exige_validation` | Sans acte officiel : `MIGRABLE_APRES_VALIDATION`, justification `ANOM-41-02`, `source_officielle_suffisante=false`, avertissements non vides, note source intacte (14,0), aucun statut d'exclusion | PASS |
| 8 | `test_anom41_02_decision_textuelle_origine_suffit_a_la_simulation` | Décision textuelle d'origine présente → `decision_textuelle_origine=true`, `MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME`, aucun avertissement | PASS |
| 9 | `test_exigence10_empreinte_detecte_toute_alteration_source` | Note 11,0 → 13,0 : `empreinte_source` recalculée différente de l'initiale | PASS |

### Portée exacte des garanties

- Les tests 1 à 9 valident le **comportement du service de simulation**, pas la conformité réglementaire des règles appliquées.
- Le test 6 valide le comportement **fail-closed** pour un programme explicitement marqué `REGLE_A_VALIDER` dans la configuration. Il ne prouve pas que les 12 programmes réels sont couverts par une fiche de formation : voir `LOOP-41-DRY-RUN.md` §4 et `LOOP-41-VERDICT.md`.
- Le test 7 valide l'application d'`ANOM-41-02`. Sur le jeu réel, ce contrôle produit **0 avertissement** (les 42 étudiants porteurs de crédits ont une trace officielle), ce qui signifie qu'il est actif mais non déclenché — pas qu'il est inutile.

---

## 3. Contrôle de non-régression

Les 102 tests hors LOOP 41 restent verts, ce qui couvre notamment :

- régimes LMD, ingénieur et mastère (`CertificationLoop39RegimesAcademiquesTest`) ;
- moteur de décision académique et priorités (`DecisionAcademiqueWorkflowTest`) ;
- contrôle des droits de redoublement et gestion des acquis (`GestionAcquisRedoublementTest`, 32 tests) ;
- industrialisation du workflow (`IndustrialisationAcquisWorkflowTest`) ;
- recettes métier LOOP 40 et LOOP 40-C (`RecetteMetierValidationLoop40Test`, `RecetteMetierCorrectiveLoop40CTest`) ;
- certifications LOOP 37 et LOOP 38.

Aucun test n'a été désactivé, marqué `skip` ou contourné pour obtenir ce résultat.

---

## 4. Formatage

```bash
vendor/bin/pint --test \
  app/Console/Commands/DryRunMigrationHistoriqueCommand.php \
  app/Services/Acquis/SimulationMigrationHistoriqueService.php \
  app/Services/Acquis/ReglementationAcquisService.php \
  tests/Feature/Acquis/MigrationHistoriqueDryRunLoop41Test.php
```

Résultat : `{"tool":"pint","result":"passed"}`, code de sortie `0`.

---

## 5. Ce que ces tests ne prouvent pas

1. **Ils ne prouvent pas que la migration est autorisée.** Aucun test ne remplace une validation métier, réglementaire ou institutionnelle.
2. **Ils ne prouvent pas la couverture réglementaire des formations réelles.** `evaluerStatutRegle()` répond au niveau du régime national ; aucun code des 12 programmes réels ne correspond à `config/acquis_reglementation.php > matrice_formations`.
3. **Ils ne prouvent pas l'absence d'anomalies de données.** 134 dossiers restent en `REVUE_HUMAINE_REQUISE` (100 sans inscription, 34 sur matières orphelines).
4. **Ils ne constituent pas une recette de production.** L'environnement est SQLite en mémoire avec un jeu de fixtures, pas la base MySQL réelle : le dry-run réel (`LOOP-41-DRY-RUN.md`) est la preuve d'exécution sur données réelles.

---

## 6. Reproductibilité

```bash
php artisan test tests/Feature/Acquis/MigrationHistoriqueDryRunLoop41Test.php --compact
php artisan test --filter="Acquis" --compact
vendor/bin/pint --test app/Console/Commands/DryRunMigrationHistoriqueCommand.php \
  app/Services/Acquis/SimulationMigrationHistoriqueService.php \
  app/Services/Acquis/ReglementationAcquisService.php \
  tests/Feature/Acquis/MigrationHistoriqueDryRunLoop41Test.php
php artisan acquis:dry-run-migration --json   # lecture seule sur MySQL local
```

Les trois premières commandes sont déterministes. La quatrième dépend de l'état de la base au moment de l'exécution.
