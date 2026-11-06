# SIM-14 — 02 · Formules

**Scénario :** Blocage en L1
**Version formules :** 1.0 (aucune formule nouvelle — réutilise le moteur)

---

## A. Formules du moteur (réutilisation, pas de duplication)

Les formules de flux restent celles du moteur `ServiceSimulationTemporelle` (SIM-01 → SIM-13, `VERSION_MOTEUR = 2.0`) :

- **Admissions par pas** : `admissions = round(base_L1 × (1 + delta_admissions)^pas)`
- **Passage** : `partants = round(effectif_niveau × passage_niveau)`
- **Redoublement** : `redoublants = round(effectif_niveau × redoublement_niveau)` (plafonné si `partants + redoublants > effectif`)
- **Progression** : les partants du niveau `n` rejoignent le niveau `n+1` au pas suivant ; les redoublants restent au niveau `n`.
- **Sortants** : partants du dernier niveau du périmètre (restitution SIM-07).

`taux_niveau = taux_par_niveau[code] ?? taux` (jeu global par défaut, surcharge si déclarée).

---

## B. Aucune formule nouvelle

SIM-14 n'introduit **aucune formule, aucun seuil et aucun paramètre institutionnel**. Son apport est le contrat de domaine catalogue (règle de blocage sur L1) appliqué au descripteur `taux_par_niveau` existant.

**Hash de définition** : `hash('sha256', json_encode([scenario, annee_source, faculte, graine, nb_variantes, version_moteur]))` — déterministe, recomputé à chaque exécution (idempotence par graine).

**Hash des entrées historiques** : les `hash_entrees` antérieurs (`VERSION_MOTEUR = 1.x`) ne matchent plus après le passage à `2.0` — documenté comme réserve, non recommandé de recomputer.

---

## C. Trajectoires

| Trajectoire | Taux appliqués | Admissions |
|---|---|---|
| Témoin | Jeu global `taux` (tous les niveaux) | identiques |
| Principale | `taux` + surcharge `taux_par_niveau[L1]` | identiques |

L'écart `simulé − témoin` isole strictement l'effet de la surcharge L1.
