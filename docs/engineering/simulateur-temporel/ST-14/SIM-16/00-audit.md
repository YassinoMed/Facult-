# SIM-16 — 00 · Audit et compatibilité

**Scénario :** SIM-16 — Flux déséquilibrés entre niveaux
**Sous-LOOP :** ST-14.16
**Date :** 2026-11-06
**Compatibilité préliminaire :** `extension_par_niveau`
**Compatibilité confirmée :** `extension_par_niveau` — surcharge `taux_par_niveau` sur ≥ 2 niveaux, ≥ 2 triplets distincts, dépend de SIM-14 et SIM-15

---

## A. État initial du scénario (avant ouverture)

| Élément | Constat |
|---|---|
| Catalogue | Entrée planifiée : `cohortes_lmd`, lot B, compatibilité préliminaire `extension_par_niveau`, dépendances SIM-14, SIM-15 |
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
| Dépendances | SIM-14 (L1), SIM-15 (L2) — même mécanisme, niveaux multiples |
| Tests avant ouverture | **187 tests / 2098 assertions** au vert (`--filter="SimulationTemporelle"`) |

## C. Question d'audit centrale

> « Surcharger ≥ 2 niveaux avec des triplets distincts (déséquilibre multi-niveaux) » peut-il se réaliser par **configuration** du mécanisme `taux_par_niveau`, ou exige-t-il une extension du moteur ?

### Constat décisif

Le moteur applique `tauxParNiveau[$code] ?? $taux` niveau par niveau, sans compensation entre niveaux. SIM-16 réutilise le même mécanisme avec ≥ 2 niveaux surchargés et ≥ 2 triplets distincts. **Aucune extension moteur**.

### Verdict d'audit

**Aucune extension moteur, aucune route, aucune permission, aucune table.** La compatibilité `extension_par_niveau` est **confirmée**.

---

## D. Points d'attention (constats d'audit)

| ID | Constat | Gravité | Traitement |
|---|---|---|---|
| SIM16-A1 | ≥ 2 niveaux surchargés requis ; un seul niveau relève de SIM-14 ou SIM-15 | FAIBLE | Documenté (`SIM16-R1`) |
| SIM16-A2 | ≥ 2 triplets distincts requis ; des triplets identiques ne constituent pas un déséquilibre | FAIBLE | Documenté (`SIM16-R2`) |
| SIM16-A3 | Dépend de SIM-14 et SIM-15 | MOYEN | Respecté (SIM-14/15 clôturés) |
| SIM16-A4 | Hash `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | FAIBLE | Documenté (`SIM16-R3`) |

---

## E. Décision de LOOP

- **Scénario ouvert** : SIM-16 — Flux déséquilibrés entre niveaux.
- **Compatibilité** : `extension_par_niveau` (confirmée).
- **Dépendances techniques** : SOCLE-EFFECTIFS + SIM-14 + SIM-15.
- **Périmètre** : catalogue uniquement — `sim16()`, `schemaSim16()`, `validerReglesSim16()`, retrait de `planifies()`.
- **Non-régression attendue** : SIM-14/15 inchangés.

---

*Preuves : `ST-14/SIM-16/` — documents 01 → 09.*
