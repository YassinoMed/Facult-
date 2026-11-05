# SIM-01 — 06 · Plan et couverture des tests

**Scénario :** SIM-01 — Croissance des admissions
**Fichiers :**
- `tests/Feature/SimulationTemporelleSim01Test.php` (7 tests)
- `tests/Feature/SimulationTemporelleCatalogueTest.php` (12 tests)

---

## G.1 Principe

Les assertions de SIM-01 **ne réutilisent pas** la formule du moteur : les
valeurs attendues sont calculées à la main et écrites en commentaire, puis
comparées aux sorties réelles. Les tests d'intégration du catalogue vérifient
le contrat de définition, pas le calcul.

Périmètre synthétique commun : `L1 = 10`, `L2 = 20`, `L3 = 30`, total `60`,
faculté A. Aucune donnée métier réelle n'est lue ou modifiée.

---

## G.2 Matrice exigence → test

| Exigence | Test(s) |
|---|---|
| Admissions composées exactes | `test_sim01_croissance_composee_avec_reference_calculee_independamment` |
| Admissions constantes et conservation | `test_sim01_admissions_constantes_et_conservation_exacte_des_effectifs` |
| Horizon 1 et 6 | `test_sim01_horizon_minimal_et_maximal` |
| Idempotence et graine | `test_sim01_memes_entrees_idempotentes_et_graine_reproductible` |
| Écart à la cible | `test_sim01_ecart_a_la_cible_et_groupes_requis_sans_modifier_les_groupes_reels` |
| Capacités et places manquantes | même test |
| Permissions et isolation facultaire | `test_sim01_permissions_et_isolation_facultaire` |
| Zéro écriture métier | `test_sim01_zero_ecriture_metier_et_tables_techniques_seules` |
| Croissance stricte (delta ≥ 0) | `test_sim01_est_une_configuration_de_croissance_stricte` |
| Taux requis / taux partiel | `test_sim01_exige_des_taux_explicites_alors_que_le_socle_les_tolere` |
| SIM-01 disponible / 43 planifiés | `test_integrite_du_catalogue_codes_versions_et_statuts` |
| Hash de définition déterministe | `test_hash_de_definition_deterministe_et_complet` |
| Catalogue en lecture seule | `test_catalogue_ne_modifie_aucune_table_ni_donnee_metier` |

---

## G.3 Les 7 tests SIM-01

1. **Croissance composée** — delta 0.1, horizon 3, progression gelée :
   `totaux = [60, 71, 83, 96]`, admissions `[11, 12, 13]`, état initial
   inchangé.
2. **Admissions constantes** — delta 0, taux 0.5/0.3/0.2, horizon 2 :
   `totaux = [60, 43, 36]`, snapshots niveau par niveau, conservation.
3. **Horizons extrêmes** — horizon 1 (`71`) et horizon 6
   (admissions `11, 12, 13, 15, 16, 18`, total `145`).
4. **Idempotence** — mêmes entrées → même exécution et même hash ; graine
   différente → nouvelle exécution, variante 0 identique.
5. **Cible et capacités** — périmètre L1, total `46`, cible `100`
   (`−54`), groupe réel de capacité `15` : `4` groupes requis, `3` de déficit,
   `31` places manquantes ; groupe réel intact.
6. **Sécurité** — sans permission : `403` ; faculté B : `403` ; aucune
   exécution créée.
7. **Lecture seule** — 6 tables métier inchangées, étudiant témoin identique
   attribut par attribut ; 1 exécution et 3 snapshots techniques.

---

## G.4 Les 2 tests catalogue ajoutés pour SIM-01

1. `test_sim01_est_une_configuration_de_croissance_stricte` :
   - delta 0 accepté ;
   - delta négatif refusé avec renvoi à SIM-02 ;
   - borne minimale négative refusée ;
   - somme des taux > 1 refusée.
2. `test_sim01_exige_des_taux_explicites_alors_que_le_socle_les_tolere` :
   - socle sans taux accepté ;
   - SIM-01 sans taux refusé ;
   - SIM-01 avec taux partiel refusé ;
   - `schemaSim01()['taux']['requis'] === true` et
     `schemaSocle()['taux']['requis'] === false`.

Les 10 autres tests du catalogue couvrent l'empreinte, l'intégrité, les
permissions, l'absence de fuite et la lecture seule.

---

## G.5 Commandes

```bash
php artisan test --filter="SimulationTemporelleSim01Test"
php artisan test --filter="SimulationTemporelleCatalogueTest"
php artisan test --filter="SimulationTemporelle"
vendor/bin/pint --dirty
```

---

## G.6 Critères de sortie

| Critère | Seuil |
|---|---|
| Tests SIM-01 | 7/7 |
| Tests catalogue | 12/12 |
| Suite module | 71/71 |
| Assertions | ≥ 650 |
| Pint | aucun fichier modifié après passage |
| Écriture métier | 0 |
| Suite historique | 52/52 inchangée |
