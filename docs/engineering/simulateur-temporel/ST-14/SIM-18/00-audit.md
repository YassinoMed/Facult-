# SIM-18 — 00 · Audit et compatibilité

**Scénario :** SIM-18 — Double génération
**Sous-LOOP :** ST-14.18
**Date :** 2026-11-06
**Compatibilité préliminaire :** `extension_cohorte`
**Compatibilité confirmée :** `extension_cohorte` — `cohortes_identifiees` (≥ 2 cohortes externes, même niveau d'entrée, pas distincts, chaque effectif ≥ référence du niveau), dépend de SIM-17

---

## A. État initial du scénario (avant ouverture)

| Élément | Constat |
|---|---|
| Catalogue | Entrée planifiée : `cohortes_lmd`, lot B, compatibilité préliminaire `extension_cohorte`, dépendance SIM-17 |
| Version | `null` (planifié) |
| Schéma | `null` |
| Disponibilité | `false` |
| Règle de domaine | absente |

## B. Socle technique audité

| Élément | Constat |
|---|---|
| Moteur | `ServiceSimulationTemporelle` — `VERSION_MOTEUR = 2.0` (identité de cohorte) |
| Identité de cohorte | `cohortes_identifiees` : liste non vide, codes uniques, même niveau d'entrée, pas distincts, effectif ≥ 1 |
| Coexistence mesurée | `coexistence_par_periode`, `duree_coexistence`, `pic_coexistence` (jamais supposée) |
| Dépendance | SIM-17 (une cohorte) → SIM-18 (double génération) |
| Tests avant ouverture | **187 tests / 2098 assertions** au vert (`--filter="SimulationTemporelle"`) |

## C. Question d'audit centrale

> « Suivre deux cohortes externes du même niveau à des pas distincts et mesurer leur coexistence » peut-il se réaliser par **configuration** du mécanisme existant, ou exige-t-il une extension du moteur ?

### Constat décisif

L'identité de cohorte a été implémentée dans le moteur (SIM-17/18). SIM-18 déclare ≥ 2 cohortes du même niveau d'entrée, à des pas distincts, chaque effectif ≥ génération de référence du niveau. La coexistence est **mesurée par pas et par niveau**, jamais supposée.

### Verdict d'audit

**Extension moteur livrée (VERSION_MOTEUR = 2.0), aucune route, aucune permission, aucune table.** La compatibilité `extension_cohorte` est **confirmée**.

---

## D. Points d'attention (constats d'audit)

| ID | Constat | Gravité | Traitement |
|---|---|---|---|
| SIM18-A1 | ≥ 2 cohortes requises ; une seule relève de SIM-17 | FAIBLE | Documenté (`SIM18-R1`) |
| SIM18-A2 | Pas d'entrée distincts ; entrée simultanée ne décrit pas de coexistence | FAIBLE | Documenté (`SIM18-R2`) |
| SIM18-A3 | Même niveau d'entrée pour toutes les cohortes | FAIBLE | Documenté (`SIM18-R3`) |
| SIM18-A4 | Chaque effectif ≥ génération de référence du niveau (contrôle exécution) | MOYEN | Réserve `SIM18-R4` |
| SIM18-A5 | Dépend de SIM-17 | MOYEN | Respecté (SIM-17 clôturé) |
| SIM18-A6 | `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | FAIBLE | Documenté (`SIM18-R5`) |

---

## E. Décision de LOOP

- **Scénario ouvert** : SIM-18 — Double génération.
- **Compatibilité** : `extension_cohorte` (confirmée).
- **Dépendances techniques** : SOCLE-EFFECTIFS + SIM-17 + moteur v2.0.
- **Périmètre** : `schemaSim18()`, `validerReglesSim18()`, retrait de `planifies()`.
- **Non-régression attendue** : SIM-17 inchangé.

---

*Preuves : `ST-14/SIM-18/` — documents 01 → 09.*
