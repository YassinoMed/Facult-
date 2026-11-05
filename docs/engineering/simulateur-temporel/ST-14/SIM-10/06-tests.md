# SIM-10 — 06 · Stratégie et couverture de tests

**Date :** 2026-11-05  
**Suites :** `SimulationTemporelleSim10Test` (créée), `SimulationTemporelleCatalogueTest` (adaptée)  
**Principe :** toute valeur attendue est calculée **hors moteur** dans les commentaires ; aucune assertion ne reproduit la formule testée.

---

## G.1 Niveaux de test

| Niveau | Suite | Objet |
|---|---|---|
| Contrat catalogue | `SimulationTemporelleCatalogueTest` | Schéma, règles, compteurs, intégrité |
| Moteur (feature) | `SimulationTemporelleSim10Test` | Sens `baisse`, refus de définition, idempotence, rétrocompatibilité, analyses, sécurité |
| Non-régression | suite module complète | SOCLE + SIM-01 → SIM-09 inchangés |

---

## G.2 Sim10Test — les 7 tests

| # | Test | Prouve |
|---|---|---|
| 1 | `test_sim10_reduction_du_redoublement_reduit_les_effectifs` | Modifié `[60,34,26]` + sortants `[18,9]` vs référence `[60,40,31]` + sortants `[18,11]` ; écarts `[-6,-5]` ; effet `-2` ; `baisse_redoublement 0.1` ; absence des alias passage et des autres axes ; snapshots `{11,8,15}` puis `{11,8,7}` |
| 2 | `test_sim10_baisse_absorbee_par_le_reste_effet_aval_nul_a_horizon_un` | Modifié `[60,34]` vs référence `[60,40]` ; écart `-6` ; **effet sortants 0** (passage inchangé) |
| 3 | `test_sim10_admissions_identiques_quelle_que_soit_la_reussite` | Delta +10 % : admissions `[11,12]` dans les deux trajectoires ; modifié `[60,35,28]` vs référence `[60,41,35]` ; écarts `[-6,-7]` ; effet `-2` |
| 4 | `test_sim10_definition_refuse_baisse_absente_ou_invalide_et_incompatibilites` | Refus moteur : redoublement égal (×1) et supérieur (×1), somme > 1, hors [0,1], jeu incomplet, trio incomplet, axe/sens invalides, référence absente, gel, choc, cible, creux, **alias SIM-07 et SIM-08 simultanés** ; 0 scénario créé |
| 5 | `test_sim10_idempotence_et_absence_de_cles_sans_variation` | Même graine → même exécution et même hash ; **sans variation, aucune clé de réussite** (11 `assertArrayNotHasKey`), totaux de référence `[60,40,31]` |
| 6 | `test_sim10_analyses_sensibilite_et_monte_carlo_autour_des_taux_modifies` | OAT expose `valeur_base = 0.6` (passage) et `0.1` (redoublement modifié) — jamais `0.2` (référence) ; Monte-Carlo 50 itérations, `taux_absents = false` |
| 7 | `test_sim10_permissions_isolation_facultaire_et_zero_ecriture_metier` | 403 ×2 ; compteurs métier et empreinte étudiant inchangés ; 1 exécution + 3 snapshots techniques |

---

## G.3 CatalogueTest — nouvelles preuves

| Preuve | Assertion |
|---|---|
| Compteurs | 45 entrées, **11 disponibles / 34 planifiés** |
| SIM-10 disponible | Index 10 du catalogue HTTP, statut `disponible` |
| Définition publiée | Version 1, formules 1.0, `analyse_nouvelle`, dépendance `SOCLE-EFFECTIFS` |
| Schéma | `taux` requis ; `taux_modifie` requis et complet ; axe `redoublement` et sens `baisse` épinglés |
| Règles | Redoublement égal / supérieur refusé ; somme > 1 refusée ; hors bornes refusé ; jeu absent ou partiel refusé ; axe/sens absents ou hors contrat refusés ; référence absente refusée |
| Origine libre | Baisse puisée dans l'abandon **et** baisse absorbée par le reste : acceptées |
| Incompatibilités | Gel / choc / cible / creux / `taux_ameliore` / `taux_degrade` refusés structurellement (« inconnu ») |
| Planifié suivant | **SIM-11** reste `planifie` et non disponible |

---

## G.4 Cas limites couverts

| Cas | Test | Résultat attendu |
|---|---|---|
| Baisse via abandon↑ | 1, 3 | Effectifs réduits, sortants réduits (`-2`) |
| Baisse absorbée par le reste | 2 | Effectifs réduits, sortants stables (`0`) |
| Tendance d'admissions non nulle | 3 | Admissions identiques, effet conservé |
| Somme modifiée exactement 1 | 1, 3 | Acceptée |
| Somme modifiée < 1 | 2 | Acceptée, le reste n'est pas réinjecté |
| Horizon 1 | 2 | Une seule période comparée |
| Redoublement égal | 4 | Refusé (« strictement inférieur ») |
| Absence de descripteur | 5 | Aucune clé de réussite ; trajectoire unique |
| Alias passage + générique | 4 | Refus explicite (exclusion mutuelle) |

---

## G.5 Ce qui n'est **pas** testé (limites assumées)

| Limite | Raison |
|---|---|
| Taux différenciés par niveau | Non contractuel (LOT B) |
| Combinaisons multi-axes | **Interdites par contrat** — SIM-13 |
| Vraisemblance pédagogique de la baisse | Hors modèle : le scénario mesure le jeu déclaré |
| Baisse alimentée par le passage | Cas non revendiqué par SIM-10 (effet aval de signe opposé) |
| Volumétrie réelle | Données synthétiques, comme toute la campagne |

---

## G.6 Critères d'acceptation

1. `SimulationTemporelleSim10Test` : 7/7, 0 échec, 0 ignoré.
2. `SimulationTemporelleCatalogueTest` : 21/21, 0 échec, 0 ignoré.
3. Suite module : 144/144, 0 échec, 0 ignoré.
4. Pint `--test` : 0 erreur.
5. Aucune assertion affaiblie, aucun test désactivé pour verdir.
