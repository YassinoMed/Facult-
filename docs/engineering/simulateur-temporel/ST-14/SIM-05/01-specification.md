# SIM-05 — 01 · Spécification

**Date :** 2026-11-03  
**Scénario :** SIM-05 — Croissance supérieure à la cible  
**Version de scénario :** 1  
**Version de formules :** 1.0  
**Compatibilité :** `analyse_nouvelle` (reclassée par l'audit `00-audit.md`)  
**Dépendance :** `SOCLE-EFFECTIFS`

---

## B.1 Objectif

Comparer la trajectoire d'admissions produite par le moteur à une
**trajectoire de croissance cible saisie** et restituer :

- la cible année par année ;
- l'écart signé année par année ;
- le cumul des écarts ;
- l'existence d'au moins un dépassement.

Le scénario ne décide pas si un dépassement est souhaitable ou non : il le
mesure. Aucune conséquence automatique (réduction d'admissions, sanction,
réaffectation) n'est déclenchée.

---

## B.2 Acteurs et permissions

| Élément | Valeur |
|---|---|
| Permission de lecture du catalogue | `simulation.view` |
| Permissions d'exécution | `simulation.gerer`, `simulation.executer` |
| Périmètre | faculté du scénario ou global, contrôlé par `FacultePerimetre` |
| Écriture métier | **aucune** |

Aucune permission nouvelle n'est introduite.

---

## B.3 Paramètres d'entrée

| Paramètre | Type | Requis | Bornes | Rôle |
|---|---|---|---|---|
| `delta_admissions` | number | oui | `[-0.5 ; 2.0]` | Taux de croissance annuel de la trajectoire réelle |
| `bornes.min` / `bornes.max` | number | oui | `[-0.5 ; 2.0]` | Bornes des variantes |
| `horizon_annees` | integer | oui | `[1 ; 6]` | Nombre de périodes simulées |
| `taux.passage` | number | oui | `[0 ; 1]` | Taux de passage |
| `taux.redoublement` | number | oui | `[0 ; 1]` | Taux de redoublement |
| `taux.abandon` | number | oui | `[0 ; 1]` | Taux d'abandon |
| `taux_cible_croissance` | number | **oui** | `[0 ; 2.0]` | Taux de croissance annuel de la trajectoire cible |
| `niveau_id` | integer | non | ≥ 1 | Restriction à un niveau |
| `groupes` | array\<integer\> | non | ≥ 1 | Restriction à des groupes (exige `niveau_id`) |

**Aucune valeur par défaut n'est injectée.** Le taux cible est une donnée
saisie, jamais une constante du code.

---

## B.4 Règles de domaine

| # | Règle | Justification |
|---|---|---|
| R1 | `delta_admissions > taux_cible_croissance` (strict) | Le scénario s'appelle « croissance **supérieure** à la cible » ; une égalité ou une infériorité relève d'un autre scénario. |
| R2 | `delta_admissions ≥ 0` et `bornes.min ≥ 0` | Scénario de croissance ; la baisse relève de SIM-02. |
| R3 | `taux_cible_croissance ≥ 0` | Une cible négative serait une cible de décroissance, hors du contrat. |
| R4 | `duree_gel_annees` interdit | Un gel rend la comparaison de croissance non interprétable ; il relève de SIM-03. |
| R5 | `pas_exceptionnel` / `admissions_exceptionnelles` interdits | Un choc ponctuel fausse la lecture de la croissance ; il relève de SIM-04. |
| R6 | `passage + redoublement + abandon ≤ 1` | Invariant commun du moteur. |
| R7 | `groupes` exige `niveau_id` | Invariant commun de la cascade. |

R1 est vérifiée **deux fois** : par le catalogue (`validerReglesSim05`) et par
le moteur (`validerCriteres`). Un scénario créé directement via le service,
sans passer par le catalogue, reste protégé.

---

## B.5 Sorties

Pour **chaque variante** (référence et variantes exploratoires) :

| Champ | Type | Description |
|---|---|---|
| `admissions_par_periode` | array | Admissions réelles par pas (existant) |
| `admissions_cible_par_periode` | array | `[{pas, admissions_cible}]` |
| `ecarts_cible_par_periode` | array | `[{pas, admissions, admissions_cible, ecart, depassement}]` |
| `ecart_cible_cumule` | integer | Somme des écarts arrondis par période |
| `depassement_cible` | boolean | `true` si au moins un écart est strictement positif |
| `totaux`, `total_final`, `variations_annuelles` | existants | Inchangés |

Les champs de cible ne sont présents **que** si `taux_cible_croissance` est
fourni. Aucune clé `null` n'est ajoutée aux scénarios existants.

---

## B.6 Cas non applicables

| Cas | Traitement |
|---|---|
| Cible constante en total (`cible_total`) | Reste disponible et **distincte** : elle compare le total à un scalaire, pas une trajectoire. |
| Cible par niveau différenciée | Non couverte : le taux cible est global, comme le delta. Réservé au LOT B. |
| Cible non linéaire (palier, rupture) | Non couverte : relève de SIM-06 / CR-05. |
| Cible budgétaire | Non couverte : LOT D. |
| Population vide (`baseL1 = 0`) | La cible est nulle à chaque pas ; les écarts valent les admissions réelles. Aucune division. |

---

## B.7 Critères d'acceptation

1. `CatalogueScenarios::estDisponible('SIM-05') === true`.
2. Le catalogue expose **6 disponibles / 39 planifiés** (45 entrées).
3. `validerParametres('SIM-05', ...)` refuse : cible absente, cible hors
   bornes, `delta ≤ cible`, gel, choc, taux absents.
4. Le moteur produit les quatre champs de cible avec des valeurs conformes
   aux cas de référence calculés hors moteur.
5. Aucune cohorte, aucun total et aucune capacité n'est modifié par l'ajout
   de la cible.
6. Deux exécutions identiques retournent la même exécution (idempotence).
7. Zéro écriture sur les tables métier.

---

## B.8 Réserves anticipées

| ID | Réserve |
|---|---|
| `SIM05-S1` | Le taux cible est global, non différencié par niveau (héritage du moteur à taux globaux). |
| `SIM05-S2` | Les écarts portent sur les **admissions**, pas sur les effectifs totaux : un dépassement d'admissions peut être absorbé par les abandons. |
| `SIM05-S3` | Les arrondis peuvent annuler un dépassement exact marginal (documenté, testé). |
| `SIM05-S4` | La création HTTP d'un scénario libre n'appelle pas le catalogue : la présence du taux cible déclenche la validation moteur, mais son absence n'est pas détectable comme un manquement SIM-05. |
