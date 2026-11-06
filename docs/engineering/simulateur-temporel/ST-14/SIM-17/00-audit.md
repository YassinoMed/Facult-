# SIM-17 — 00 · Audit et compatibilité

**Scénario :** SIM-17 — Cohorte exceptionnelle
**Sous-LOOP :** ST-14.17
**Date :** 2026-11-06
**Compatibilité préliminaire :** `extension_cohorte`
**Compatibilité confirmée :** `extension_cohorte` — `cohortes_identifiees` (1 cohorte externe, effectif ≠ référence du niveau d'entrée), dépend de SOCLE-EFFECTIFS

---

## A. État initial du scénario (avant ouverture)

| Élément | Constat |
|---|---|
| Catalogue | Entrée planifiée : `cohortes_lmd`, lot B, compatibilité préliminaire `extension_cohorte`, dépendance SOCLE-EFFECTIFS |
| Version | `null` (planifié) |
| Schéma | `null` |
| Disponibilité | `false` |
| Règle de domaine | absente |

## B. Socle technique audité

| Élément | Constat |
|---|---|
| Moteur | `ServiceSimulationTemporelle` — `VERSION_MOTEUR = 2.0` (identité de cohorte) |
| Identité de cohorte | `cohortes_identifiees` : liste non vide, `{code, niveau_entree, pas_entree, effectif}`, codes uniques, pas dans [1, horizon], effectif ≥ 1 |
| Partition des agrégats | La cohorte est suivie comme partition des agrégats : `suivi_cohortes`, `bilan_cohortes`, `sortants_identifies` ; aucune formule de flux dupliquée |
| Trajectoire témoin | Mêmes admissions, mêmes variantes, SANS les entrées externes identifiées |
| Sorties dédiées | `cohortes_identifiees_appliquees`, `suivi_cohortes`, `bilan_cohortes`, `entrees_cohortes_par_periode`, `sortants_cohortes_par_periode`, `ecarts_cohortes_par_periode`, `ecart_cohortes_final`, `poids_cohortes_par_periode`, `coexistence_par_periode`, `duree_coexistence`, `pic_coexistence` |
| Tests avant ouverture | **187 tests / 2098 assertions** au vert (`--filter="SimulationTemporelle"`) |

## C. Question d'audit centrale

> « Suivre une cohorte externe à effectif inhabituel comme partition des agrégats » peut-il se réaliser par **configuration** du mécanisme existant, ou exige-t-il une extension du moteur ?

### Constat décisif

L'identité de cohorte a été implémentée dans le moteur (SIM-17/18) : `repartirIdentites()` (plus fort reste), `analyserCohortes()`, trajectoire témoin sans cohortes, OAT/MC propagent `$cohortesIdentifiees`. SIM-17 déclare **une seule cohorte** dont l'effectif doit **différer** de la génération de référence du niveau d'entrée — sinon elle relève de SIM-18.

### Verdict d'audit

**Extension moteur livrée (VERSION_MOTEUR = 2.0), aucune route, aucune permission, aucune table.** La compatibilité `extension_cohorte` est **confirmée**.

---

## D. Points d'attention (constats d'audit)

| ID | Constat | Gravité | Traitement |
|---|---|---|---|
| SIM17-A1 | Une seule cohorte : pas de coexistence de générations possible (`duree_coexistence = 0`) | FAIBLE | Documenté (`SIM17-R1`) |
| SIM17-A2 | L'effectif déclaré doit différer de la référence du niveau d'entrée (contrôle exécution) | MOYEN | Réserve `SIM17-R2` |
| SIM17-A3 | Entrée additive aux admissions ordinaires (jamais de remplacement) | FAIBLE | Documenté (`SIM17-R3`) |
| SIM17-A4 | `VERSION_MOTEUR = 2.0` : `hash_entrees` historiques incompatibles | FAIBLE | Documenté (`SIM17-R4`) |

---

## E. Décision de LOOP

- **Scénario ouvert** : SIM-17 — Cohorte exceptionnelle.
- **Compatibilité** : `extension_cohorte` (confirmée).
- **Dépendances techniques** : SOCLE-EFFECTIFS + moteur v2.0 (identité de cohorte).
- **Périmètre** : `schemaSim17()`, `regleCohortesIdentifiees()`, `validerCohortesIdentifiees()`, `validerReglesSim17()`, retrait de `planifies()`.
- **Non-régression attendue** : historique SIM-01 → SIM-16 inchangé.

---

*Preuves : `ST-14/SIM-17/` — documents 01 → 09.*
