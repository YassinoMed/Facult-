# SIM-14 — 00 · Audit et compatibilité

**Scénario :** SIM-14 — Blocage en L1
**Sous-LOOP :** ST-14.14
**Date :** 2026-11-06
**Compatibilité préliminaire :** `extension_par_niveau`
**Compatibilité confirmée :** `extension_par_niveau` — surcharge `taux_par_niveau` strictement localisée au niveau L1

---

## A. État initial du scénario (avant ouverture)

| Élément | Constat |
|---|---|
| Catalogue | Entrée planifiée : `cohortes_lmd`, lot B, compatibilité préliminaire `extension_par_niveau`, dépendance SOCLE-EFFECTIFS |
| Version | `null` (planifié) |
| Schéma | `null` |
| Disponibilité | `false` |
| Règle de domaine | absente |

## B. Socle technique audité

| Élément | Constat |
|---|---|
| Moteur | `ServiceSimulationTemporelle` — `VERSION_MOTEUR = 2.0` (identité de coho
rte livrée par SIM-17/18, réutilisable sans modification) |
| Mécanisme par niveau | `taux_par_niveau` : surcharge explicite du jeu global, triplet complet par niveau surchargé, somme ≤ 1.0001 |
| Trajectoire témoin | Jeu global sans surcharge, admissions identiques (même `delta`) |
| Sortants / OAT / MC | `sortants_par_periode`, `effet_sortants_cumules`, `analyserNiveaux()`, `ecarts_par_niveau` |
| Tests avant ouverture | **187 tests / 2098 assertions** au vert (`--filter="SimulationTemporelle"`) |

## C. Question d'audit centrale

> « Bloquer une progression en L1 (redoublement strictement supérieur, aucune amélioration) » peut-il se réaliser par **configuration** du mécanisme `taux_par_niveau`, ou exige-t-il une extension du moteur ?

### Constat décisif

Le moteur applique déjà `tauxParNiveau[$code] ?? $taux` niveau par niveau, sans compensation entre niveaux. SIM-14 n'ajoute **aucune formule de flux** : son apport est un **contrat de domaine catalogue** :

1. la surcharge porte **EXACTEMENT sur L1** (niveau focal `L1`) ;
2. le redoublement de L1 est **STRICTEMENT supérieur** au redoublement global ;
3. aucune amélioration : passage de L1 ≤ passage global, abandon de L1 ≥ abandon global ;
4. les autres niveaux conservent le jeu global.

### Verdict d'audit

**Aucune extension moteur, aucune route, aucune permission, aucune table.** La compatibilité `extension_par_niveau` est **confirmée**.

---

## D. Points d'attention (constats d'audit)

| ID | Constat | Gravité | Traitement |
|---|---|---|---|
| SIM14-A1 | Le redoublement strictement supérieur est une règle de modélisation, pas un seuil institutionnel | MOYEN | Réserve `SIM14-R1` |
| SIM14-A2 | L'absence de compensation entre niveaux est garantie par le moteur (`?? $taux` fallback) | FAIBLE | Documenté (`SIM14-R2`) |
| SIM14-A3 | Le niveau focal `L1` est la seule valeur admise pour le descripteur SIM-14 | FAIBLE | Documenté (`SIM14-R3`) |
| SIM14-A4 | Hash `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) : à documenter, pas à recomputer | FAIBLE | Documenté (`SIM14-R4`) |

---

## E. Décision de LOOP

- **Scénario ouvert** : SIM-14 — Blocage en L1.
- **Compatibilité** : `extension_par_niveau` (confirmée).
- **Dépendances techniques** : SOCLE-EFFECTIFS + `taux_par_niveau` (déjà présent).
- **Périmètre** : catalogue uniquement — `sim14()`, `schemaSim14()`, `regleTauxParNiveau()`, `validerReglesSim14()`, retrait de `planifies()`.
- **Non-régression attendue** : historique SIM-01 → SIM-13 inchangé.

---

*Preuves : `ST-14/SIM-14/` — documents 01 → 09.*
