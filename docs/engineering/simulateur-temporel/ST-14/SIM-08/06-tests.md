# SIM-08 — 06 · Stratégie et couverture de tests

**Date :** 2026-11-04  
**Suites :** `SimulationTemporelleSim08Test` (créée), `SimulationTemporelleCatalogueTest` (adaptée)  
**Principe :** toute valeur attendue est calculée **hors moteur** dans les commentaires ; aucune assertion ne reproduit la formule testée.

---

## G.1 Niveaux de test

| Niveau | Suite | Objet |
|---|---|---|
| Contrat catalogue | `SimulationTemporelleCatalogueTest` | Schéma, règles, compteurs, intégrité |
| Moteur (feature) | `SimulationTemporelleSim08Test` | Miroir exact de SIM-07, exclusion mutuelle, analyses, sécurité |
| Non-régression | suite module complète | SOCLE + SIM-01 → SIM-07 inchangés (SIM-07 inclus) |

---

## G.2 Sim08Test — les 7 tests

| # | Test | Prouve |
|---|---|---|
| 1 | `test_sim08_baisse_puisee_dans_le_redoublement_accumulation_et_diplomes_perdus` | Dégradé `[60,43,36]` + sortants `[15,10]` vs référence `[60,37,28]` + sortants `[21,12]` ; écarts `[+6,+8]` ; effet `−8` ; `baisse_passage 0.2` ; absence de `hausse_passage` ; snapshots `{13,11,19}` puis `{14,10,12}` |
| 2 | `test_sim08_baisse_absorbee_par_le_reste_effets_negatifs_sur_les_effectifs` | Dégradé `[60,37]` vs référence `[60,43]` : la baisse absorbée par le « reste » **réduit** les effectifs ; effet sortants `−6` |
| 3 | `test_sim08_admissions_identiques_quelle_que_soit_la_reussite` | Delta +10 % : admissions `[11,12]` ; écarts `[+6,+8]` — isolement strict des taux |
| 4 | `test_sim08_definition_refuse_baisse_absente_ou_invalide_et_incompatibilites` | Refus moteur : passage ≥ référence (×2), somme > 1, hors [0,1], jeu incomplet, référence absente, gel (SIM-03), choc (SIM-04), cible (SIM-05), creux (SIM-06), **amélioration simultanée (SIM-08 via bloc SIM-07)** ; 0 scénario créé |
| 5 | `test_sim08_idempotence_et_absence_de_cles_sans_taux_degrade` | Même graine → même exécution ; **sans jeu dégradé : aucune clé de réussite** (7 `assertArrayNotHasKey`), totaux de référence `[60,37,28]` |
| 6 | `test_sim08_analyses_sensibilite_et_monte_carlo_autour_des_taux_degrades` | OAT expose `valeur_base = 0.5` (passage) et `0.3` (redoublement) — dégradés, jamais les références ; Monte-Carlo actif |
| 7 | `test_sim08_permissions_isolation_facultaire_et_zero_ecriture_metier` | 403 ×2 ; compteurs métier et empreinte étudiant inchangés ; 1 exécution + 3 snapshots techniques |

---

## G.3 CatalogueTest — nouvelles preuves

| Preuve | Assertion |
|---|---|
| Compteurs | 45 entrées, **9 disponibles / 36 planifiés** |
| SIM-08 disponible | Index 8 du catalogue HTTP, statut `disponible` |
| Schéma | `taux` requis ; `taux_degrade` requis et complet |
| Compatibilité | `analyse_nouvelle` publiée |
| Règles | Passage égal / supérieur refusé ; somme > 1 refusée ; hors [0,1] refusé ; jeu absent ou partiel refusé ; référence absente refusée |
| Origine libre | Baisse puisée dans le redoublement **et** baisse absorbée par le reste : acceptées |
| Incompatibilités | Gel / choc / cible / creux / `taux_ameliore` refusés structurellement (« inconnu ») |
| Planifié suivant | SIM-09 reste `planifie` et non disponible |

---

## G.4 Cas limites couverts

| Cas | Test | Résultat attendu |
|---|---|---|
| Baisse via redoublement↑ | 1, 3 | Effectifs en hausse, sortants en baisse |
| Baisse absorbée par le reste | 2 | Effectifs en baisse, sortants en baisse |
| Tendance d'admissions non nulle | 3 | Admissions identiques, effet conservé |
| Somme dégradée exactement 1 | 1, 3 | Acceptée |
| Horizon 1 | 2 | Une seule période comparée |
| Absence de jeu dégradé | 5 | Aucune clé de réussite ; trajectoire unique |
| Hausse + baisse simultanées | 4 | Refus explicite (exclusion mutuelle) |

---

## G.5 Ce qui n'est **pas** testé (limites assumées)

| Limite | Raison |
|---|---|
| Taux différenciés par niveau | Non contractuel (LOT B) |
| Combinaisons avec gel/choc/cible/creux | **Interdites par contrat** — testées comme refus |
| Vraisemblance pédagogique des taux dégradés | Hors modèle : le scénario mesure le jeu déclaré |
| Volumétrie réelle | Données synthétiques, comme toute la campagne |

---

## G.6 Critères d'acceptation

1. `SimulationTemporelleSim08Test` : 7/7, 0 échec, 0 ignoré.
2. `SimulationTemporelleCatalogueTest` : 19/19, 0 échec, 0 ignoré.
3. Suite module : 128/128, 0 échec, 0 ignoré.
4. Pint `--test` : 0 erreur.
5. Aucune assertion affaiblie, aucun test désactivé pour verdir.
