# SIM-12 — 06 · Stratégie et couverture de tests

**Date :** 2026-11-05  
**Suites :** `SimulationTemporelleSim12Test` (créée), `SimulationTemporelleCatalogueTest` (adaptée)  
**Principe :** toute valeur attendue est calculée **hors moteur** dans les commentaires ; aucune assertion ne reproduit la formule testée.

---

## G.1 Niveaux de test

| Niveau | Suite | Objet |
|---|---|---|
| Contrat catalogue | `SimulationTemporelleCatalogueTest` | Schéma, règles, compteurs, intégrité |
| Moteur (feature) | `SimulationTemporelleSim12Test` | Sens `baisse`, neutralité structurelle, refus de définition, idempotence, rétrocompatibilité, analyses, sécurité |
| Non-régression | suite module complète | SOCLE + SIM-01 → SIM-11 inchangés |

---

## G.2 Sim12Test — les 7 tests

| # | Test | Prouve |
|---|---|---|
| 1 | `test_sim12_baisse_financee_par_le_redoublement_augmente_les_effectifs` | Modifié `[60,46,39]` + sortants `[18,13]` vs référence `[60,40,31]` + sortants `[18,11]` ; écarts `[+6,+8]` ; effet `+2` ; `baisse_abandon 0.1` ; absence des alias passage et des autres axes ; snapshots `{13,12,21}` puis `{14,12,13}` |
| 2 | `test_sim12_baisse_absorbee_par_le_slack_est_exactement_neutre` | Modifié `[60,40,31]` vs référence `[60,40,31]` ; écarts `[0,0]` ; effet `0` ; `baisse_abandon 0.1` — preuve que le moteur n'invente aucun effet à partir de `abandon` seul |
| 3 | `test_sim12_admissions_identiques_quelle_que_soit_la_reussite` | Delta +10 % : admissions `[11,12]` dans les deux trajectoires ; modifié `[60,44,38]` vs référence `[60,41,35]` ; écarts `[+3,+3]` ; effet `+6` |
| 4 | `test_sim12_definition_refuse_baisse_absente_ou_invalide_et_incompatibilites` | Refus moteur : abandon égal (×1) et supérieur (×1), somme > 1, hors [0,1], jeu incomplet, trio incomplet, axe/sens invalides, référence absente, gel, choc, cible, creux, **alias SIM-07 et SIM-08 simultanés** ; 0 scénario créé |
| 5 | `test_sim12_idempotence_et_absence_de_cles_sans_variation` | Même graine → même exécution et même hash ; **sans variation, aucune clé de réussite** (11 `assertArrayNotHasKey`), totaux de référence `[60,40,31]` |
| 6 | `test_sim12_analyses_sensibilite_et_monte_carlo_autour_des_taux_modifies` | OAT expose `valeur_base = 0.6` (passage) et `0.3` (redoublement modifié) — jamais `0.2` (référence) ; **aucune ligne `taux_abandon`** ; Monte-Carlo 50 itérations, `taux_absents = false` |
| 7 | `test_sim12_permissions_isolation_facultaire_et_zero_ecriture_metier` | 403 ×2 ; compteurs métier et empreinte étudiant inchangés ; 1 exécution + 3 snapshots techniques |

---

## G.3 CatalogueTest — nouvelles preuves

| Preuve | Assertion |
|---|---|
| Compteurs | 45 entrées, **13 disponibles / 32 planifiés** |
| SIM-12 disponible | Index 12 du catalogue HTTP, statut `disponible` |
| Définition publiée | Version 1, formules 1.0, `analyse_nouvelle`, dépendance `SOCLE-EFFECTIFS` |
| Schéma | `taux` requis ; `taux_modifie` requis et complet ; axe `abandon` et sens `baisse` épinglés |
| Règles | Abandon égal / supérieur refusé ; somme > 1 refusée ; hors bornes refusé ; jeu absent ou partiel refusé ; axe/sens absents ou hors contrat refusés ; référence absente refusée |
| Origine libre | Baisse puisée dans le redoublement **et** baisse absorbée par le reste : acceptées |
| Incompatibilités | Gel / choc / cible / creux / `taux_ameliore` / `taux_degrade` refusés structurellement (« inconnu ») |
| Planifié suivant | **SIM-13** reste `planifie` et non disponible |

---

## G.4 Cas limites couverts

| Cas | Test | Résultat attendu |
|---|---|---|
| Baisse via redoublement↑ | 1 | Effectifs accrus, sortants accrus (`+2`) |
| Baisse absorbée par le reste | 2 | Trajectoires **identiques** (`[0,0]`, effet `0`) |
| Tendance d'admissions non nulle | 3 | Admissions identiques, effet conservé (`+6`) |
| Somme modifiée exactement 1 | 1, 3 | Acceptée |
| Somme modifiée < 1 | 2 | Acceptée, le reste n'est pas réinjecté |
| Horizon 2 | tous | Deux périodes comparées |
| Abandon égal | 4 | Refusé (« strictement inférieur ») |
| Absence de descripteur | 5 | Aucune clé de réussite ; trajectoire unique |
| Alias passage + générique | 4 | Refus explicite (exclusion mutuelle) |
| Absence de sensibilité `taux_abandon` | 6 | Aucune ligne inventée |

---

## G.5 Ce qui n'est **pas** testé (limites assumées)

| Limite | Raison |
|---|---|
| Taux différenciés par niveau | Non contractuel (LOT B) |
| Combinaisons multi-axes | **Interdites par contrat** — SIM-13 |
| Vraisemblance pédagogique de la baisse | Hors modèle : le scénario mesure le jeu déclaré |
| Effet causal isolé de l'abandon | **Non représenté par le moteur** ; mouvement joint déclaré seulement |
| Économie budgétaire de la rétention | Aucun coût validé (LOT D) |
| Volumétrie réelle | Données synthétiques, comme toute la campagne |

---

## G.6 Critères d'acceptation

1. `SimulationTemporelleSim12Test` : 7/7, 0 échec, 0 ignoré.
2. `SimulationTemporelleCatalogueTest` : 23/23, 0 échec, 0 ignoré.
3. Suite module : 160/160, 0 échec, 0 ignoré.
4. Pint : 0 erreur.
5. Aucune assertion affaiblie, aucun test désactivé pour verdir.

**Résultats mesurés :** 7/7 · 88 assertions ; 23/23 · 590 assertions ;
160/160 · 1763 assertions ; Pint OK.
