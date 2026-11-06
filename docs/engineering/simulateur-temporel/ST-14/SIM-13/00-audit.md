# SIM-13 — 00 · Audit et compatibilité

**Scénario :** SIM-13 — Crise académique combinée
**Sous-LOOP :** ST-14.13
**Date :** 2026-11-06
**Compatibilité préliminaire :** `configuration`
**Compatibilité confirmée :** `configuration` — aucune extension moteur requise

---

## A. État initial du scénario (avant ouverture)

| Élément | Constat |
|---|---|
| Catalogue | Entrée planifiée : `reussite_flux`, lot A, compatibilité préliminaire `configuration`, dépendances SIM-07 → SIM-12 |
| Version | `null` (planifié) |
| Schéma | `null` |
| Disponibilité | `false` |
| Règle de domaine | absente |

## B. Socle technique audité

| Élément | Constat |
|---|---|
| Moteur | `ServiceSimulationTemporelle` — `VERSION_MOTEUR = 1.8` |
| Mécanisme générique | Descripteur mono-axe `taux_modifie` + `axe_variation` + `sens_variation` (livré par SIM-09, réutilisé par SIM-10/11/12) |
| Contraintes moteur | Jeu modifié complet (3 taux), focal strict, somme ≤ 1.0001, exclusions gel/choc/cible/creux/alias |
| Analyse double trajectoire | `totaux_reference_reussite`, `ecarts_reussite_par_periode`, `sortants_par_periode`, `effet_sortants_cumules` (livrés par SIM-07) |
| OAT / Monte-Carlo | Recentrés sur le jeu modifié (`criteresAnalyses['taux']`) |
| Tests avant ouverture | **160 tests / 1763 assertions** au vert |

---

## C. Question d'audit centrale

> « Combiner plusieurs dégradations dans les limites du modèle » peut-il se
> réaliser par **configuration** du mécanisme existant, ou exige-t-il une
> extension du moteur ?

### Constat décisif

Le moteur **contraint uniquement l'axe focal déclaré** ; les deux autres taux
du jeu modifié « restent libres (origine déclarée, aucune compensation
inventée) ». Un jeu modifié combinant **plusieurs dégradations simultanées**
(passage↓ + redoublement↑ + abandon↑) est donc **déjà exécutable** par le
descripteur générique (v1.8).

L'apport propre de SIM-13 est un **contrat de domaine catalogue** :

1. la paire focale doit être une **dégradation** souhaitée :
   `passage/baisse`, `redoublement/hausse` ou `abandon/hausse` ;
2. **aucune amélioration** : `passage_mod ≤ passage_ref`,
   `redoublement_mod ≥ redoublement_ref`, `abandon_mod ≥ abandon_ref` ;
3. **au moins deux axes strictement dégradés** — une dégradation isolée
   relève de SIM-08 (passage), SIM-09 (redoublement) ou SIM-11 (abandon).

### Verdict d'audit

**Aucune extension moteur, aucune route, aucune permission, aucune table.**
La compatibilité `configuration` est **confirmée**.

---

## D. Points d'attention (constats d'audit)

| ID | Constat | Gravité | Traitement |
|---|---|---|---|
| `SIM13-A1` | Le moteur accepte un jeu modifié combiné dès lors qu'un axe focal strict est déclaré : la règle « crise combinée » ne peut vivre que dans le catalogue | MOYEN | Réserve `SIM13-R1` (même famille que SIM-02-R1) |
| `SIM13-A2` | Les messages d'erreur du descripteur (moteur) renvoient « SIM-09→SIM-12 » même pour un scénario SIM-13 : artefact de libellé, pas un défaut de contrat | FAIBLE | Réserve `SIM13-R2` — moteur non modifié |
| `SIM13-A3` | `abandon` n'est pas réinjecté : une composante abandon absorbée par le slack est neutre ; tout effet provient des mouvements joints déclarés | MOYEN | Documenté ; aucun effet inventé (`SIM13-R3`, héritée SIM-11/12) |
| `SIM13-A4` | Les règles « aucune amélioration » et « ≥ 2 strictes » sont des règles de MODÉLISATION, pas des seuils institutionnels | FAIBLE | Documentées comme telles (`SIM13-R4`) |
| `SIM13-A5` | Arrondis au plus proche (half-up) : les effets combinés peuvent s'annuler partiellement (démontré par test) | FAIBLE | Documenté, testé |

---

## E. Décision de LOOP

- **Scénario ouvert** : SIM-13 — Crise académique combinée.
- **Compatibilité** : `configuration` (confirmée).
- **Dépendances techniques** : SOCLE-EFFECTIFS + mécanisme générique v1.8.
- **Périmètre** : catalogue uniquement — `sim13()`, `schemaSim13()`,
  `validerReglesSim13()`, retrait de `planifies()` (32 → 31).
- **Non-régression attendue** : 160 tests / 1763 assertions inchangés.

---

*Preuves : `ST-14/SIM-13/` — documents 01 → 09.*
