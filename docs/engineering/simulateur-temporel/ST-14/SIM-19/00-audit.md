# SIM-19 — 00 · Audit et compatibilité

**Scénario :** SIM-19 — Rééquilibrage des niveaux
**Sous-LOOP :** ST-14.19
**Date :** 2026-11-06
**Compatibilité préliminaire :** `extension_par_niveau`
**Compatibilité confirmée :** `extension_par_niveau` — surcharge `taux_par_niveau` sur ≥ 2 niveaux, directions de passage opposées, dépend de SIM-14, SIM-15, SIM-16

---

## A. État initial du scénario (avant ouverture)

| Élément | Constat |
|---|---|
| Catalogue | Entrée planifiée : `cohortes_lmd`, lot B, compatibilité préliminaire `extension_par_niveau`, dépendances SIM-14, SIM-15, SIM-16 |
| Version | `null` (planifié) |
| Schéma | `null` |
| Disponibilité | `false` |
| Règle de domaine | absente |

## B. Socle technique audité

| Élément | Constat |
|---|---|
| Moteur | `ServiceSimulationTemporelle` — `VERSION_MOTEUR = 2.0` |
| Mécanisme par niveau | `taux_par_niveau` : surcharge explicite du jeu global, triplet complet par niveau surchargé, somme ≤ 1.0001 |
| Trajectoire témoin | Jeu global sans surcharge, admissions identiques |
| Dépendances | SIM-14 (L1), SIM-15 (L2), SIM-16 (≥ 2 niveaux) — même mécanisme |
| Tests avant ouverture | **187 tests / 2098 assertions** au vert (`--filter="SimulationTemporelle"`) |

## C. Question d'audit centrale

> « Surcharger ≥ 2 niveaux avec des directions de passage opposées (rééquilibrage) » peut-il se réaliser par **configuration** du mécanisme `taux_par_niveau`, ou exige-t-il une extension du moteur ?

### Constat décisif

Le moteur applique `tauxParNiveau[$code] ?? $taux` niveau par niveau. SIM-19 réutilise le même mécanisme avec ≥ 2 niveaux surchargés dont les directions de passage sont opposées (l'un augmente, l'autre diminue par rapport au global). **Aucune extension moteur**.

### Verdict d'audit

**Aucune extension moteur, aucune route, aucune permission, aucune table.** La compatibilité `extension_par_niveau` est **confirmée**.

---

## D. Points d'attention (constats d'audit)

| ID | Constat | Gravité | Traitement |
|---|---|---|---|
| SIM19-A1 | ≥ 2 niveaux surchargés requis ; un seul niveau relève de SIM-14/15 | FAIBLE | Documenté (`SIM19-R1`) |
| SIM19-A2 | Directions de passage opposées requises ; même direction refuse | FAIBLE | Documenté (`SIM19-R2`) |
| SIM19-A3 | Un niveau exactement au taux global ne compte pas comme direction | FAIBLE | Documenté (`SIM19-R3`) |
| SIM19-A4 | Dépend de SIM-14, SIM-15, SIM-16 | MOYEN | Respecté (tous clôturés) |
| SIM19-A5 | `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | FAIBLE | Documenté (`SIM19-R4`) |

---

## E. Décision de LOOP

- **Scénario ouvert** : SIM-19 — Rééquilibrage des niveaux.
- **Compatibilité** : `extension_par_niveau` (confirmée).
- **Dépendances techniques** : SOCLE-EFFECTIFS + SIM-14 + SIM-15 + SIM-16.
- **Périmètre** : catalogue uniquement — `sim19()`, `schemaSim19()`, `validerReglesSim19()`, retrait de `planifies()`.
- **Non-régression attendue** : SIM-14/15/16 inchangés.

---

*Preuves : `ST-14/SIM-19/` — documents 01 → 09.*
