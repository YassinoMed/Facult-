# SIM-15 — 00 · Audit et compatibilité

**Scénario :** SIM-15 — Blocage en L2
**Sous-LOOP :** ST-14.15
**Date :** 2026-11-06
**Compatibilité préliminaire :** `extension_par_niveau`
**Compatibilité confirmée :** `extension_par_niveau` — surcharge `taux_par_niveau` strictement localisée au niveau L2, dépend de SIM-14

---

## A. État initial du scénario (avant ouverture)

| Élément | Constat |
|---|---|
| Catalogue | Entrée planifiée : `cohortes_lmd`, lot B, compatibilité préliminaire `extension_par_niveau`, dépendance SIM-14 |
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
| Dépendance | SIM-14 (même mécanisme, niveau focal différent : L2) |
| Tests avant ouverture | **187 tests / 2098 assertions** au vert (`--filter="SimulationTemporelle"`) |

## C. Question d'audit centrale

> « Bloquer une progression en L2 » peut-il se réaliser par **configuration** du mécanisme `taux_par_niveau`, ou exige-t-il une extension du moteur ?

### Constat décisif

Le moteur applique déjà `tauxParNiveau[$code] ?? $taux` niveau par niveau. SIM-15 réutilise le même mécanisme que SIM-14, avec le niveau focal `L2` et les mêmes règles de domaine (redoublement strictement supérieur, aucune amélioration, surcharge exclusive). **Aucune extension moteur**.

### Verdict d'audit

**Aucune extension moteur, aucune route, aucune permission, aucune table.** La compatibilité `extension_par_niveau` est **confirmée**.

---

## D. Points d'attention (constats d'audit)

| ID | Constat | Gravité | Traitement |
|---|---|---|---|
| SIM15-A1 | Même contrat de domaine que SIM-14, niveau focal `L2` | FAIBLE | Documenté (`SIM15-R1`) |
| SIM15-A2 | Dépend de SIM-14 : SIM-15 ne peut ouvrir avant clôture SIM-14 | MOYEN | Respecté (SIM-14 clôturé) |
| SIM15-A3 | Hash `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | FAIBLE | Documenté (`SIM15-R2`) |

---

## E. Décision de LOOP

- **Scénario ouvert** : SIM-15 — Blocage en L2.
- **Compatibilité** : `extension_par_niveau` (confirmée).
- **Dépendances techniques** : SOCLE-EFFECTIFS + SIM-14.
- **Périmètre** : catalogue uniquement — `sim15()`, `schemaSim15()`, `validerReglesSim15()`, retrait de `planifies()`.
- **Non-régression attendue** : SIM-14 inchangé.

---

*Preuves : `ST-14/SIM-15/` — documents 01 → 09.*
