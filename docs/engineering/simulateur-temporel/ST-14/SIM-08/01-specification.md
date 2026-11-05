# SIM-08 — 01 · Spécification

**Date :** 2026-11-04  
**Scénario :** SIM-08 — Dégradation de la réussite  
**Version de scénario :** 1  
**Version de formules :** 1.0  
**Compatibilité :** `analyse_nouvelle` (reclassée par l'audit `00-audit.md`)  
**Dépendance :** `SOCLE-EFFECTIFS`

---

## B.1 Objectif

Comparer, dans une **même exécution**, une trajectoire simulée avec des taux
de flux **dégradés** (`taux_degrade`) à une trajectoire de référence avec
les taux **déclarés** (`taux`), à admissions identiques, et restituer :

- la baisse de passage réellement déclarée ;
- les effectifs totaux des deux trajectoires, période par période ;
- les écarts et l'écart final ;
- les **sortants** (partants du dernier niveau du périmètre) et l'effet
  cumulé sur ceux-ci.

Miroir strict de SIM-07 : seule la direction de la variation change
(hausse → baisse), la mécanique de comparaison est partagée.

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
| `delta_admissions` | number | oui | `[-0.5 ; 2.0]` | Taux des admissions (identique aux deux trajectoires) |
| `bornes.min` / `bornes.max` | number | oui | `[-0.5 ; 2.0]` | Bornes des variantes |
| `horizon_annees` | integer | oui | `[1 ; 6]` | Nombre de périodes simulées |
| `taux.passage` / `redoublement` / `abandon` | number | **oui** | `[0 ; 1]`, somme ≤ 1 | Taux de **référence** (trajectoire témoin) |
| `taux_degrade.passage` / `redoublement` / `abandon` | number | **oui** | `[0 ; 1]`, somme ≤ 1 | Taux **simulés** (trajectoire principale) |
| `niveau_id`, `groupes` | identifiants | non | — | Restriction de périmètre |

**Aucune valeur par défaut.** Les deux jeux sont **complets** : le moteur ne
compense rien, ne redistribue rien, n'invente aucune origine à la baisse.

---

## B.4 Règles de domaine

| # | Règle | Justification |
|---|---|---|
| R1 | `taux_degrade.passage < taux.passage` (strict) | Le scénario mesure une **baisse** de passage ; une stabilité ou une hausse relève de SIM-07/SIM-09. |
| R2 | Chaque taux des deux jeux ∈ `[0 ; 1]` | Invariant de taux du moteur. |
| R3 | Chaque somme ≤ 1 | Aucune population créée par les taux. |
| R4 | `taux` de référence requis dès que `taux_degrade` est fourni | Sans référence, aucune comparaison. |
| R5 | `taux_ameliore` interdit simultanément | Un seul axe de réussite à la fois ; l'exclusion est vérifiée par le bloc SIM-07 (exécuté en premier) et par la garde d'exécution. |
| R6 | `duree_gel_annees` interdit | Un gel relève de SIM-03. |
| R7 | Choc ponctuel interdit | Un choc relève de SIM-04. |
| R8 | `taux_cible_croissance` interdit | Une cible relève de SIM-05. |
| R9 | Trio de récupération interdit | Un creux relève de SIM-06. |

**Aucune règle sur l'origine de la baisse** : redoublement et abandon
dégradés sont libres (en hausse, stables, ou laissant filer vers le
« reste »). Mesurer l'effet du jeu déclaré, jamais juger sa vraisemblance.

---

## B.5 Sorties

Pour **chaque variante** :

| Champ | Type | Description |
|---|---|---|
| `baisse_passage` | number | `taux.passage − taux_degrade.passage` (4 décimales, magnitude positive) |
| `totaux_reference_reussite` | array\<integer\> | Totaux de la trajectoire témoin (pas 0 → H) |
| `ecarts_reussite_par_periode` | array | `[{pas, total_simule, total_reference, ecart}]` |
| `ecart_reussite_final` | integer | `total_final simulé − total_final référence` |
| `sortants_par_periode` | array | `[{pas, sortants, sortants_reference}]` |
| `effet_sortants_cumules` | integer | `Σ sortants simulés − Σ sortants référence` (négatif = diplômés perdus) |
| `totaux`, `total_final`, `admissions_par_periode` | existants | Trajectoire **simulée** (taux dégradés) |

Les cinq champs neutres sont **identiques** à ceux de SIM-07 ; seul le nom
du champ de variation change (`hausse_passage` → `baisse_passage`).

---

## B.6 Cas non applicables

| Cas | Traitement |
|---|---|
| Baisse sans origine déclarée | Impossible : les trois taux dégradés sont requis ; aucune compensation implicite. |
| Amélioration (passage en hausse) | Refusée : SIM-07. |
| Combinaison hausse + baisse | Refusée : un seul axe de réussite. |
| Taux différenciés par niveau | Non couverts (LOT B). |
| Population vide (`baseL1 = 0`) | Totaux nuls, écarts nuls ; aucune division. |

---

## B.7 Critères d'acceptation

1. `CatalogueScenarios::estDisponible('SIM-08') === true`.
2. Catalogue : **9 disponibles / 36 planifiés** (45 entrées).
3. `validerParametres('SIM-08', ...)` refuse : `taux_degrade` incomplet,
   passage non strictement inférieur, somme > 1, gel, choc, cible, creux,
   hausse simultanée.
4. Le moteur produit les six champs conformes aux cas de référence hors
   moteur (simulé `[60,43,36]` + sortants `[15,10]` vs référence
   `[60,37,28]` + sortants `[21,12]`).
5. Les admissions sont **identiques** dans les deux trajectoires.
6. Zéro écriture métier ; idempotence conservée ; aucune clé ajoutée sans
   `taux_degrade`.

---

## B.8 Réserves anticipées

| ID | Réserve |
|---|---|
| `SIM08-S1` | Le résultat mesure l'effet **dans le modèle déclaré** : la conversion d'une crise réelle en taux dégradés reste une expertise humaine. |
| `SIM08-S2` | « Sortants » = dernier niveau du périmètre, pas nécessairement diplômés du programme. |
| `SIM08-S3` | Les taux dégradés sont globaux (identiques pour tous les niveaux). |
| `SIM08-S4` | La création HTTP n'appelle pas le catalogue : la validation moteur s'active dès que `taux_degrade` est présent. |
| `SIM08-S5` | Arrondis : la baisse exacte peut ne pas produire d'écart entier à chaque pas — convention identique à SIM-07. |
