# SIM-07 — 06 · Stratégie et couverture de tests

**Date :** 2026-11-04  
**Suites :** `SimulationTemporelleSim07Test` (créée), `SimulationTemporelleCatalogueTest` (adaptée)  
**Principe :** toute valeur attendue est calculée **hors moteur** dans les commentaires ; aucune assertion ne reproduit la formule testée.

---

## G.1 Niveaux de test

| Niveau | Suite | Objet |
|---|---|---|
| Contrat catalogue | `SimulationTemporelleCatalogueTest` | Schéma, règles, compteurs, intégrité |
| Moteur (feature) | `SimulationTemporelleSim07Test` | Comparaison exacte, effets opposés selon l'origine déclarée, déterminisme, analyses, sécurité |
| Non-régression | suite module complète | SOCLE + SIM-01 → SIM-06 inchangés |

---

## G.2 Sim07Test — les 7 tests

| # | Test | Prouve |
|---|---|---|
| 1 | `test_sim07_hausse_puisee_dans_le_redoublement_effets_opposes` | Référence `[60,43,36]` + sortants `[15,10]` ; amélioré `[60,37,28]` + sortants `[21,12]` ; écarts `[-6,-8]` ; effet sortants `+8` ; hausse `0.2` ; snapshots `{11,9,17}` puis `{11,9,8}` |
| 2 | `test_sim07_hausse_puisee_dans_le_reste_effets_positifs` | Référence `[60,37]` vs amélioré `[60,43]` : la hausse puisée dans le « reste » **augmente** les effectifs ; effet sortants `+6` |
| 3 | `test_sim07_admissions_identiques_quelle_que_soit_la_reussite` | Delta +10 % : admissions `[11,12]` identiques ; écarts `[-6,-8]` — l'écart isole strictement les taux |
| 4 | `test_sim07_definition_refuse_hausse_absente_ou_invalide_et_incompatibilites` | Refus moteur : passage ≤ référence (×2), somme > 1, hors [0,1], jeu incomplet, référence absente, gel (SIM-03), choc (SIM-04), cible (SIM-05), creux (SIM-06) ; 0 scénario créé |
| 5 | `test_sim07_idempotence_et_absence_de_cles_sans_taux_ameliore` | Même graine → même exécution ; **sans jeu amélioré : aucune clé de réussite**, totaux de référence `[60,43,36]` |
| 6 | `test_sim07_analyses_sensibilite_et_monte_carlo_autour_des_taux_ameliores` | OAT expose `valeur_base = 0.7` (passage) et `0.1` (redoublement) — améliorés, jamais les valeurs de référence ; Monte-Carlo actif |
| 7 | `test_sim07_permissions_isolation_facultaire_et_zero_ecriture_metier` | 403 ×2 ; compteurs métier et empreinte étudiant inchangés ; 1 exécution + 3 snapshots techniques |

---

## G.3 CatalogueTest — nouvelles preuves

| Preuve | Assertion |
|---|---|
| Compteurs | 45 entrées, **8 disponibles / 37 planifiés** |
| SIM-07 disponible | Index 7 du catalogue HTTP, statut `disponible` |
| Schéma | `taux` requis ; `taux_ameliore` requis et complet (passage/redoublement/abandon) |
| Compatibilité | `analyse_nouvelle` publiée |
| Règles | Passage égal / inférieur refusé ; somme > 1 refusée ; hors [0,1] refusé ; jeu absent ou partiel refusé ; référence absente refusée |
| Origine libre | Hausse puisée dans le redoublement **et** hausse puisée dans le reste : acceptées (aucune compensation imposée) |
| Incompatibilités | Gel / choc / cible / creux refusés structurellement (« inconnu ») |
| Planifié suivant | SIM-08 reste `planifie` et non disponible |

---

## G.4 Cas limites couverts

| Cas | Test | Résultat attendu |
|---|---|---|
| Hausse via redoublement↓ | 1, 3 | Effectifs en baisse, sortants en hausse |
| Hausse via reste (somme < 1) | 2 | Effectifs en hausse, sortants en hausse |
| Tendance d'admissions non nulle | 3 | Admissions identiques, écart de flux conservé |
| Somme améliorée exactement 1 | 1, 2, 3 | Acceptée |
| Horizon 1 | 2 | Une seule période comparée |
| Absence de jeu amélioré | 5 | Aucune clé de réussite ; trajectoire unique |

---

## G.5 Ce qui n'est **pas** testé (limites assumées)

| Limite | Raison |
|---|---|
| Taux différenciés par niveau | Non contractuel (LOT B) |
| Combinaisons avec gel/choc/cible/creux | **Interdites par contrat** — testées comme refus, jamais comme calcul |
| Vraisemblance pédagogique des taux | Hors modèle : le scénario mesure le jeu déclaré |
| Volumétrie réelle | Données synthétiques, comme toute la campagne |

---

## G.6 Critères d'acceptation

1. `SimulationTemporelleSim07Test` : 7/7, 0 échec, 0 ignoré.
2. `SimulationTemporelleCatalogueTest` : 18/18, 0 échec, 0 ignoré.
3. Suite module : 120/120, 0 échec, 0 ignoré.
4. Pint `--test` : 0 erreur.
5. Aucune assertion affaiblie, aucun test désactivé pour verdir.
