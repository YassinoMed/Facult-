# SIM-05 — 06 · Stratégie et couverture de tests

**Date :** 2026-11-03  
**Suites :** `SimulationTemporelleSim05Test` (créée), `SimulationTemporelleCatalogueTest` (adaptée)  
**Principe :** toute valeur attendue est calculée **hors moteur** dans les commentaires ; aucune assertion ne reproduit la formule testée.

---

## G.1 Niveaux de test

| Niveau | Suite | Objet |
|---|---|---|
| Contrat catalogue | `SimulationTemporelleCatalogueTest` | Schéma, règles de domaine, compteurs, intégrité |
| Moteur (feature) | `SimulationTemporelleSim05Test` | Calcul exact, arrondis, conservation, déterminisme, sécurité |
| Non-régression | suite module complète | SOCLE + SIM-01 → SIM-04 inchangés |

---

## G.2 Sim05Test — les 7 tests

| # | Test | Prouve |
|---|---|---|
| 1 | `test_sim05_cible_5_pct_contre_croissance_10_pct_ecarts_par_periode` | Cas 1 : réelles `[11,12,13]`, cibles `[11,11,12]`, écarts `[0,1,1]`, cumul 2, dépassement vrai, totaux `[60,71,83,96]` |
| 2 | `test_sim05_cible_10_pct_contre_croissance_20_pct_cumul_7` | Cas 2 : écarts `[1,2,4]`, cumul 7, dépassement sur les 3 pas, totaux `[60,72,86,103]` |
| 3 | `test_sim05_flux_actifs_conservation_et_ecart_zero_au_premier_pas` | Cas 3 : conservation stricte (totaux vs somme des niveaux), snapshots `[14,11,19]` puis `[16,10,12]`, écart 0 au pas 1 puis 1 |
| 4 | `test_sim05_arrondi_peut_annuler_un_depassement_exact_marginal` | Cas 4 : `0.02 > 0.01` exact mais `round` égal → écart 0, cumul 0, `depassement = false` — l'assertion n'est jamais affaiblie |
| 5 | `test_sim05_definition_refuse_cible_inferieure_ou_incompatible` | Refus du moteur : `delta ≤ cible`, cible hors bornes, cible négative, cible + gel (SIM-03), cible + choc (SIM-04), delta négatif (SIM-02) ; 0 scénario créé |
| 6 | `test_sim05_idempotence_reproductibilite_et_absence_de_cle_sans_cible` | Même graine → même exécution (id + hash) ; autre graine → variante 0 identique ; **sans cible : aucune clé de cible ajoutée**, totaux identiques |
| 7 | `test_sim05_permissions_isolation_facultaire_et_zero_ecriture_metier` | 403 sans permission, 403 faculté B ; compteurs métier inchangés ; empreinte étudiant inchangée ; 1 exécution + 4 snapshots techniques |

---

## G.3 CatalogueTest — nouvelles preuves

| Preuve | Assertion |
|---|---|
| Compteurs | 45 entrées, **6 disponibles / 39 planifiés**, codes uniques |
| SIM-05 disponible | Index 5 du catalogue HTTP, statut `disponible` |
| Schéma | `taux` requis, `taux_cible_croissance` requis, bornes `[0 ; 2.0]`, unité « ratio annuel composé » |
| Compatibilité | `analyse_nouvelle` publiée |
| Règles | Cible 0 acceptée ; cible `= delta` et `> delta` refusées ; cible négative / `2.5` refusées ; cible absente refusée ; gel / choc refusés (« inconnu ») |
| Détournements | Delta négatif et borne min négative refusés (orientation SIM-02) |
| Planifié suivant | SIM-06 reste `planifie` et non disponible |

---

## G.4 Cas limites couverts

| Cas | Test | Résultat attendu |
|---|---|---|
| Écart nul sur un pas malgré `delta > cible` | 1 et 3 | `ecart = 0`, `depassement = false` sur ce pas, booléen global vrai |
| Écart exact annulé par arrondi sur tout l'horizon | 4 | Cumul 0, dépassement faux — conforme au contrat |
| Cible nulle (stagnation) | catalogue | Acceptée (`min = 0.0`) |
| Horizon 1 | 4 | Un seul pas, un seul écart |
| Population sans flux (`redoublement = 1`) | 1, 2, 4 | Conservations et totaux exacts |
| Flux actifs | 3 | Conservation stricte prouvée |
| Absence de cible | 6 | Aucune clé de cible ; rétrocompatibilité stricte |

---

## G.5 Ce qui n'est **pas** testé (limites assumées)

| Limite | Raison |
|---|---|
| Cible par niveau | Non contractuelle (LOT B) |
| Cible non linéaire | Hors SIM-05 (SIM-06 / CR-05) |
| Interaction cible + gel/choc | **Interdite par contrat** — testée comme refus, jamais comme calcul |
| Volumétrie réelle | Données synthétiques uniquement, comme toute la campagne |
| Concurrence multi-processus | `pcntl` absent localement (limite d'environnement ST-14) |

---

## G.6 Critères d'acceptation

1. `SimulationTemporelleSim05Test` : 7/7, 0 échec, 0 ignoré.
2. `SimulationTemporelleCatalogueTest` : 16/16, 0 échec.
3. Suite module : 103/103, 0 échec, 0 ignoré.
4. Pint `--test` : 0 erreur.
5. Aucune assertion affaiblie, aucun test désactivé pour verdir.
