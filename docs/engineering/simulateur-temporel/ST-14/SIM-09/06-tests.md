# SIM-09 — 06 · Stratégie et couverture de tests

**Date :** 2026-11-05  
**Suites :** `SimulationTemporelleSim09Test` (créée), `SimulationTemporelleCatalogueTest` (adaptée)  
**Principe :** toute valeur attendue est calculée **hors moteur** dans les commentaires ; aucune assertion ne reproduit la formule testée.

---

## G.1 Niveaux de test

| Niveau | Suite | Objet |
|---|---|---|
| Contrat catalogue | `SimulationTemporelleCatalogueTest` | Schéma, règles, compteurs, intégrité |
| Moteur (feature) | `SimulationTemporelleSim09Test` | Descripteur générique, exclusion mutuelle, analyses, sécurité |
| Non-régression | suite module complète | SOCLE + SIM-01 → SIM-08 inchangés (alias préservés) |

---

## G.2 Sim09Test — les 7 tests

| # | Test | Prouve |
|---|---|---|
| 1 | `test_sim09_hausse_du_redoublement_accumule_les_effectifs` | Modifié `[60,46,39]` + sortants `[18,13]` vs référence `[60,40,31]` + sortants `[18,11]` ; écarts `[+6,+8]` ; effet `+2` ; `hausse_redoublement 0.1` ; absence des alias passage ; snapshots `{13,12,21}` puis `{14,12,13}` |
| 2 | `test_sim09_hausse_absorbee_par_le_reste_effet_aval_nul_a_horizon_un` | Modifié `[60,46]` vs référence `[60,40]` ; écart `+6` ; **effet sortants 0** (passage inchangé, aval non atteint) |
| 3 | `test_sim09_admissions_identiques_quelle_que_soit_la_reussite` | Delta +10 % : admissions `[11,12]` dans les deux trajectoires ; écarts `[+6,+6]` ; effet `+2` |
| 4 | `test_sim09_definition_refuse_hausse_absente_ou_invalide_et_incompatibilites` | Refus moteur : direction non stricte (×2), somme > 1, hors [0,1], jeu incomplet, trio incomplet, axe/sens invalides, référence absente, gel, choc, cible, creux, **alias SIM-07 et SIM-08 simultanés** ; 0 scénario créé |
| 5 | `test_sim09_idempotence_et_absence_de_cles_sans_variation` | Même graine → même exécution ; **sans variation, aucune clé de réussite** (11 `assertArrayNotHasKey`), totaux de référence `[60,40,31]` |
| 6 | `test_sim09_analyses_sensibilite_et_monte_carlo_autour_des_taux_modifies` | OAT expose `valeur_base = 0.6` (passage) et `0.3` (redoublement modifié) — jamais 0.2 (référence) ; Monte-Carlo actif |
| 7 | `test_sim09_permissions_isolation_facultaire_et_zero_ecriture_metier` | 403 ×2 ; compteurs métier et empreinte étudiant inchangés ; 1 exécution + 3 snapshots techniques |

---

## G.3 CatalogueTest — nouvelles preuves

| Preuve | Assertion |
|---|---|
| Compteurs | 45 entrées, **10 disponibles / 35 planifiés** |
| SIM-09 disponible | Index 9 du catalogue HTTP, statut `disponible` |
| Schéma | `taux` requis ; `taux_modifie` requis et complet ; axe `redoublement` et sens `hausse` épinglés |
| Compatibilité | `analyse_nouvelle` publiée |
| Règles | Redoublement égal / inférieur refusé ; somme > 1 refusée ; hors [0,1] refusé ; jeu absent ou partiel refusé ; axe/sens absents ou hors contrat refusés ; référence absente refusée |
| Origine libre | Hausse puisée dans l'abandon **et** hausse absorbée par le reste : acceptées |
| Incompatibilités | Gel / choc / cible / creux / `taux_ameliore` / `taux_degrade` refusés structurellement (« inconnu ») |
| Planifié suivant | SIM-10 reste `planifie` et non disponible |

---

## G.4 Cas limites couverts

| Cas | Test | Résultat attendu |
|---|---|---|
| Hausse via abandon↓ | 1, 3 | Effectifs en hausse, sortants en hausse |
| Hausse absorbée par le reste | 2 | Effectifs en hausse, sortants stables |
| Tendance d'admissions non nulle | 3 | Admissions identiques, effet conservé |
| Somme modifiée exactement 1 | 2 | Acceptée |
| Horizon 1 | 2 | Une seule période comparée |
| Absence de descripteur | 5 | Aucune clé de réussite ; trajectoire unique |
| Alias passage + générique | 4 | Refus explicite (exclusion mutuelle) |

---

## G.5 Ce qui n'est **pas** testé (limites assumées)

| Limite | Raison |
|---|---|
| Taux différenciés par niveau | Non contractuel (LOT B) |
| Combinaisons multi-axes | **Interdites par contrat** — SIM-13 |
| Vraisemblance pédagogique des taux | Hors modèle : le scénario mesure le jeu déclaré |
| Volumétrie réelle | Données synthétiques, comme toute la campagne |

---

## G.6 Critères d'acceptation

1. `SimulationTemporelleSim09Test` : 7/7, 0 échec, 0 ignoré.
2. `SimulationTemporelleCatalogueTest` : 20/20, 0 échec, 0 ignoré.
3. Suite module : 136/136, 0 échec, 0 ignoré.
4. Pint `--test` : 0 erreur.
5. Aucune assertion affaiblie, aucun test désactivé pour verdir.
