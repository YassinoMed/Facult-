# SIM-06 — 01 · Spécification

**Date :** 2026-11-03  
**Scénario :** SIM-06 — Récupération démographique  
**Version de scénario :** 1  
**Version de formules :** 1.0  
**Compatibilité :** `extension_temporelle` (confirmée par l'audit `00-audit.md`)  
**Dépendance :** `SOCLE-EFFECTIFS`

---

## B.1 Objectif

Simuler une **baisse progressive des admissions** jusqu'à un creux, puis un
**retour progressif au niveau de référence**, et restituer :

- les admissions réellement produites période par période ;
- la trajectoire de référence (fond) que le creux a déviée ;
- l'écart signé par période, son cumul (déficit total vs référence) et le
  creux maximal atteint.

Le scénario ne décide pas si la baisse est acceptable ni si la récupération
est suffisante : il mesure la forme temporelle.

---

## B.2 Acteurs et permissions

| Élément | Valeur |
|---|---|
| Lecture du catalogue | `simulation.view` |
| Exécution | `simulation.gerer`, `simulation.executer` |
| Périmètre | faculté du scénario ou global (`FacultePerimetre`) |
| Écriture métier | **aucune** |

Aucune permission nouvelle.

---

## B.3 Paramètres d'entrée

| Paramètre | Type | Requis | Bornes | Rôle |
|---|---|---|---|---|
| `delta_admissions` | number | oui | `[-0.5 ; 2.0]` | Taux de la trajectoire de référence (fond) |
| `bornes.min` / `bornes.max` | number | oui | `[-0.5 ; 2.0]` | Bornes des variantes |
| `horizon_annees` | integer | oui | `[1 ; 6]` | Nombre de périodes simulées |
| `taux.passage` / `redoublement` / `abandon` | number | oui | `[0 ; 1]` | Taux de flux, somme ≤ 1 |
| `profondeur_baisse` | number | **oui** | `]0 ; 1]` | Profondeur du creux, relative à la référence |
| `duree_baisse_annees` | integer | **oui** | `[1 ; horizon]` | Périodes pour atteindre le creux |
| `duree_recuperation_annees` | integer | **oui** | `[1 ; horizon]` | Périodes pour revenir à la référence |
| `niveau_id`, `groupes` | identifiants | non | — | Restriction de périmètre |

**Aucune valeur par défaut.** Le trio est **indissociable** : fournir une ou
deux clés sans les autres est refusé (schéma pour le catalogue, règle
explicite pour le moteur).

---

## B.4 Règles de domaine

| # | Règle | Justification |
|---|---|---|
| R1 | `profondeur_baisse > 0` | Un creux nul relève de la trajectoire de fond. |
| R2 | `profondeur_baisse ≤ 1` | On ne peut pas passer sous zéro admission ; 1 = creux total autorisé. |
| R3 | `duree_baisse_annees ≥ 1` et `duree_recuperation_annees ≥ 1` | Une phase de durée nulle n'est pas une phase. |
| R4 | `duree_baisse + duree_recuperation ≤ horizon_annees` | Le retour à la référence doit être **observable** dans l'horizon. |
| R5 | Indissociabilité du trio | Une profondeur sans durées (ou l'inverse) est ininterprétable. |
| R6 | `duree_gel_annees` interdit | Un gel relève de SIM-03. |
| R7 | `pas_exceptionnel` / `admissions_exceptionnelles` interdits | Un choc relève de SIM-04. |
| R8 | `taux_cible_croissance` interdit | Une cible de croissance relève de SIM-05. |
| R9 | `passage + redoublement + abandon ≤ 1` | Invariant commun du moteur. |

R1→R4 sont vérifiées par le catalogue (`validerReglesSim06`) **et** par le
moteur (`validerCriteres`). R6→R8 sont structurelles au catalogue (clés
hors schéma) et explicites dans le moteur.

---

## B.5 Sorties

Pour **chaque variante** :

| Champ | Type | Description |
|---|---|---|
| `admissions_par_periode` | array | Admissions réelles (creux inclus) |
| `admissions_reference_par_periode` | array | `[{pas, admissions_reference}]` — fond sans creux |
| `ecarts_recuperation_par_periode` | array | `[{pas, admissions, admissions_reference, ecart, phase, facteur}]` |
| `ecart_recuperation_cumule` | integer | Somme des écarts (négatif = déficit vs référence) |
| `creux_recuperation` | integer | Écart le plus négatif d'une période (0 si aucun) |
| `totaux`, `total_final`, `variations_annuelles` | existants | Inchangés |

`phase` ∈ `baisse` | `recuperation` | `reference`.  
Les champs ne sont présents **que** si le trio est fourni.

---

## B.6 Cas non applicables

| Cas | Traitement |
|---|---|
| Retour au-delà de l'horizon | Refusé (R4) : la récupération ne serait pas démontrée. |
| Creux en V asymétrique (montée instantanée) | Imposé par les durées : `d` et `r` sont libres, une remontée en 1 an est possible. |
| Profil non linéaire (exponentiel, S-curve) | Non couvert : exigerait un paramètre de forme (réservé). |
| Récupération sans creux préalable | Impossible : la profondeur est strictement positive. |
| Population vide (`baseL1 = 0`) | Reference nulle, écarts nuls ; aucune division. |

---

## B.7 Critères d'acceptation

1. `CatalogueScenarios::estDisponible('SIM-06') === true`.
2. Catalogue : **7 disponibles / 38 planifiés** (45 entrées).
3. `validerParametres('SIM-06', ...)` refuse : trio incomplet, profondeur 0
   ou > 1, durées < 1, `d + r > horizon`, gel, choc, cible.
4. Le moteur produit les quatre champs de récupération conformes aux cas de
   référence hors moteur.
5. Retour exact à la référence au pas `d + r` (facteur 1).
6. Aucune cohorte, aucun total, aucune capacité modifiés par la restitution.
7. Zéro écriture métier ; idempotence conservée.

---

## B.8 Réserves anticipées

| ID | Réserve |
|---|---|
| `SIM06-S1` | Rampe **linéaire** : la forme progressive est minimale et documentée ; un autre profil exigerait un paramètre de forme. |
| `SIM06-S2` | Le creux porte sur les **admissions**, pas sur les effectifs totaux (absorption par les flux). |
| `SIM06-S3` | Arrondis à deux étages (référence arrondie, puis produit arrondi) — documentés et testés. |
| `SIM06-S4` | La création HTTP n'appelle pas le catalogue : la validation moteur s'active dès qu'une clé du trio est présente. |
