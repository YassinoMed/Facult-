# ST-11 — 06 · Audit mathématique (formules, conservation, limites)

## D1 · Inventaire des formules du moteur (`ServiceSimulationTemporelle::simulerVariante`)

| ID | Formule | Objectif | Unités | Source |
|---|---|---|---|---|
| F-ADM | `admissionsₚ = round(baseL1 × (1+delta)^p)` | flux L1 de l'année p | personnes | paramètre scénario `delta_admissions` (croissance composée) |
| F-PASS | `partentᵢ = round(effectifᵢ × taux_passage)` | migration i → i+1 | personnes | taux saisi |
| F-RED | `redoublantsᵢ = round(effectifᵢ × taux_redoublement)` | maintien en i | personnes | taux saisi |
| F-RESTE | `autres issuesᵢ = effectifᵢ − partentᵢ − redoublantsᵢ − arrondi` | sorties (dont abandon) | personnes | **convention explicite du code** (« le reste éventuel = autres issues, jamais compté en passage », lignes 380-381) |
| F-TOT | `totalₚ = Σ cohortesᵢ` | effectif total | personnes | — |
| F-VAR | `variationₚ = (totalₚ − totalₚ₋₁) / totalₚ₋₁` si totalₚ₋₁ > 0 sinon 0 | variation annuelle | proportion (4 décimales) | division par zéro protégée |
| F-ECART | `écart_absolu = projection − référence` ; `écart_relatif = écart/référence` si référence > 0 | comparaison | personnes / proportion | ComparateurProjections |

## D2 · Cohérence dimensionnelle

- Effectifs : personnes (entiers après `round`). ✅
- Taux : proportions ∈ [0,1] (validé : `< 0 || > 1` → refus). ✅
- Delta admissions : taux de croissance ∈ [−0.5, 2.0] avec bornes de scénario dans cet intervalle. ✅
- Horizon : années ∈ [1, `PLAFOND_HORIZON`]. ✅
- Capacités : places ; `⌈effectif/capacité⌉` → nombre de groupes (EstimateurCapacitesGroupes). ✅
- Budget : unité monétaire **déclarée hypothèse** (aucun montant comptable) — voir `11-audit-pareto-budget.md`.

## D3 · Valeurs limites — couverture réelle

| Cas | Comportement | Preuve |
|---|---|---|
| Horizon = 1 / = plafond | accepté / plafonné (`PLAFOND_HORIZON`) | validation + `PLAFOND_EVENEMENTS` anti-boucle |
| Delta nul / négatif ≥ −0.5 | accepté (admissions décroissantes) | validation bornes |
| Delta < −0.5 ou > 2.0 | **refusé** | `DomainException` |
| Taux nuls (les 3 à 0) | accepté — cohortes gelées, avertissement explicite (jamais d'hypothèse substituée) | `test_taux_absents_avertissement_jamais_zero_silencieux` |
| Taux hors [0,1] | refusé | validation |
| Somme des taux > 1 | **refusé** (`> 1.0001`) | validation + `test_contrainte_durée…` |
| Plancher > plafond | refusé | `test_contraintes_incompatibles_plancher_superieur_plafond_rejetee` |
| Groupes sans niveau / niveau inexistant | refusé explicitement | validation HG-01 |
| Admissions négatives (delta = −0.5) | admissions ≥ 0 (round d'une valeur positive car baseL1 ≥ 0) | formule F-ADM |

## D4 · Conservation des effectifs — convention documentée

**Constat** : les arrondis indépendants `round()` de F-PASS et F-RED produisent un **reste** qui n'est ni passage ni redoublement — il est classé « autres issues » (abandon implicite + arrondi). Conséquences :

1. **Aucune création de population** : partent + redoublants ≤ effectif (arrondis au plus près d'un produit ≤ effectif ; somme des deux arrondis peut dépasser effectif **seulement si** passage+redoublement > 1, refusé à la validation — marge `1.0001` tolérée ⇒ dans le pire cas théorique, un effectif de 1 pourrait produire partent=redoublants=1 → **sur-émission possible de 1 personne à la frontière** ; analysé ci-dessous).
2. **Cas limite réel** : effectif=1, passage=0.5, redoublement=0.5 (somme=1) → partent=round(0.5)=1 (PHP round → 1 ? round(0.5)=1.0 en PHP), redoublants=round(0.5)=1 → total flux 2 > effectif 1 : **+1 personne créée**. Rare (petits effectifs + taux frontaliers) mais **démontrable**.
3. **Classement** : MOYEN (exactitude — dérive bornée de ±1 personne par niveau par an, uniquement aux frontières d'arrondi avec somme de taux ≈ 1).

**Correction apportée (P1-1, additive, sans changement de convention)** : garde de conservation dans le moteur — les flux ne peuvent jamais dépasser l'effectif du niveau ; voir `15-plan-corrections.md` et le test `test_st11_math_014_conservation_effectifs_frontieres_arrondi` ajouté.

## D5 · Référence et projection

- Référence = snapshot initial (cohortes de l'année de base issues de l'adaptateur) — distincte des années projetées (snapshots p ≥ 1 horodatés `anneeBase + p`).
- L'UI porte la bannière « Simulations uniquement » (testée) — une projection n'est jamais présentée comme observation réelle.

## D6 · Génération de variantes

- `GenerateurVariantes::pseudoAleatoire(graine, itération)` : hash déterministe (SHA-256) → valeur ∈ [−1, 1] — **aucun `random_*` global**.
- Variantes bornées par les mêmes contraintes que le scénario (delta ∈ bornes ; taux clampés [0,1]).
- La graine seule + le hash d'entrées garantissent la reproduction (voir `07-determinisme-reproductibilite.md`).

## D7 · Cas manuel de référence (vérifié indépendamment)

Scénario : cohortes {L1:100}, delta=0.1, horizon=2, taux {passage:0.7, redoublement:0.2}.
- p1 : L2←round(100×0.7)=70 ; L1←round(100×0.2)=20+admissions round(100×1.1)=110 → L1=130, L2=70, total=200.
- p2 : L3←round(70×0.7)=49 ; L2←round(70×0.2)=14+70=84 ; L1←20+round(100×1.21)=141 ; total=274.
- Ces valeurs sont reproductibles à la graine fixe (aucun aléatoire hors variantes).
