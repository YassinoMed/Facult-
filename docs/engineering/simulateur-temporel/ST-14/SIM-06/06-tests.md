# SIM-06 — 06 · Stratégie et couverture de tests

**Date :** 2026-11-03  
**Suites :** `SimulationTemporelleSim06Test` (créée), `SimulationTemporelleCatalogueTest` (adaptée)  
**Principe :** toute valeur attendue est calculée **hors moteur** dans les commentaires ; aucune assertion ne reproduit la formule testée.

---

## G.1 Niveaux de test

| Niveau | Suite | Objet |
|---|---|---|
| Contrat catalogue | `SimulationTemporelleCatalogueTest` | Schéma, règles, compteurs, intégrité |
| Moteur (feature) | `SimulationTemporelleSim06Test` | Creux, retour exact, arrondis, conservation, déterminisme, sécurité |
| Non-régression | suite module complète | SOCLE + SIM-01 → SIM-05 inchangés |

---

## G.2 Sim06Test — les 8 tests

| # | Test | Prouve |
|---|---|---|
| 1 | `test_sim06_creux_20_pct_sur_reference_plate_et_retour_exact` | Référence `[10,10,10,10]`, facteurs `[0.9,0.8,0.9,1]`, admissions `[9,8,9,10]`, écarts `[-1,-2,-1,0]`, cumul −4, creux −2, totaux `[60,69,77,86,96]` |
| 2 | `test_sim06_creux_sur_tendance_croissante_et_deficit_identique` | Référence `[11,12,13,15]`, admissions `[10,10,12,15]`, déficit identique (−4) : la déviation est **relative** au fond ; retour exact au pas 4 |
| 3 | `test_sim06_flux_actifs_conservation_stricte` | Totaux `[60,41,34]`, snapshots `[11,11,19]` puis `[13,9,12]`, conservation stricte, écarts `[-2,0]` |
| 4 | `test_sim06_profondeur_maximale_creux_total_sans_admissions_negatives` | Profondeur 1 (borne incluse) : admissions `[0,10]`, cumul −10, aucune cohorte négative |
| 5 | `test_sim06_arrondi_standard_sur_la_frontiere_demi` | `round(11 × 0.5) = 6` : convention d'arrondi documentée, jamais contournée |
| 6 | `test_sim06_definition_refuse_trio_incomplet_hors_horizon_et_incompatibilites` | Refus moteur : trio (×3), profondeur 0 / 1.5, durée 0, `d+r > H`, gel (SIM-03), choc (SIM-04), cible (SIM-05) ; 0 scénario créé |
| 7 | `test_sim06_idempotence_reproductibilite_et_absence_de_cles_sans_recuperation` | Même graine → même exécution (id + hash) ; autre graine → variante 0 identique ; **sans trio : aucune clé de récupération**, admissions de fond `[10,10,10,10]` |
| 8 | `test_sim06_permissions_isolation_facultaire_et_zero_ecriture_metier` | 403 ×2 ; compteurs métier et empreinte étudiant inchangés ; 1 exécution + 5 snapshots techniques |

---

## G.3 CatalogueTest — nouvelles preuves

| Preuve | Assertion |
|---|---|
| Compteurs | 45 entrées, **7 disponibles / 38 planifiés** |
| SIM-06 disponible | Index 6 du catalogue HTTP, statut `disponible` |
| Schéma | taux requis ; trio requis ; `profondeur_baisse ∈ [0 ; 1]`, durées `min 1`, unité années |
| Compatibilité | `extension_temporelle` publiée |
| Règles | Profondeur 0 refusée ; 1.5 refusée (schéma) ; durées 0 refusées ; `d+r > H` refusé ; trio incomplet refusé (×3) ; taux absents refusés |
| Incompatibilités | Gel / choc / cible refusés structurellement (« inconnu ») |
| Bornes incluses | Profondeur 1 + durées 1+1 + horizon 2 acceptés |
| Planifié suivant | SIM-07 reste `planifie` et non disponible |

---

## G.4 Cas limites couverts

| Cas | Test | Résultat attendu |
|---|---|---|
| Retour exact au pas `d + r` | 1, 2 | admissions = référence, écart 0 |
| Creux total (profondeur 1) | 4 | admissions 0 au creux, jamais négatives |
| Frontière d'arrondi .5 | 5 | `round(5.5) = 6`, convention explicite |
| Horizon minimal (2) avec phases 1+1 | 3, 4, 5 | Une seule période de baisse et de retour |
| Tendance croissante sous creux | 2 | Déficit relatif identique au cas plat |
| Absence de trio | 7 | Aucune clé de récupération ; trajectoire de fond intacte |
| Symétrie exigée | 6 | `d + r > H` refusé (retour non observable) |

---

## G.5 Ce qui n'est **pas** testé (limites assumées)

| Limite | Raison |
|---|---|
| Profil non linéaire (S-curve, exponentiel) | Non contractuel ; exigerait un paramètre de forme (réserve) |
| Creux par niveau différencié | LOT B (extension par niveau) |
| Volumétrie réelle | Données synthétiques, comme toute la campagne |
| Concurrence multi-processus | `pcntl` absent localement (limite d'environnement ST-14) |

---

## G.6 Critères d'acceptation

1. `SimulationTemporelleSim06Test` : 8/8, 0 échec, 0 ignoré.
2. `SimulationTemporelleCatalogueTest` : 17/17, 0 échec.
3. Suite module : 112/112, 0 échec, 0 ignoré.
4. Pint `--test` : 0 erreur.
5. Aucune assertion affaiblie, aucun test désactivé pour verdir.
