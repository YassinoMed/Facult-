# SIM-07 — 01 · Spécification

**Date :** 2026-11-04  
**Scénario :** SIM-07 — Amélioration de la réussite  
**Version de scénario :** 1  
**Version de formules :** 1.0  
**Compatibilité :** `analyse_nouvelle` (reclassée par l'audit `00-audit.md`)  
**Dépendance :** `SOCLE-EFFECTIFS`

---

## B.1 Objectif

Comparer, dans une **même exécution**, une trajectoire simulée avec des taux
de flux **améliorés** (`taux_ameliore`) à une trajectoire de référence avec
les taux **déclarés** (`taux`), à admissions identiques, et restituer :

- la hausse de passage réellement déclarée ;
- les effectifs totaux des deux trajectoires, période par période ;
- les écarts et l'écart final ;
- les **sortants** (partants du dernier niveau du périmètre) et l'effet
  cumulé sur ceux-ci.

Le scénario ne décide pas si l'amélioration est atteignable : il mesure son
effet **dans le modèle de flux déclaré**.

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
| `taux_ameliore.passage` / `redoublement` / `abandon` | number | **oui** | `[0 ; 1]`, somme ≤ 1 | Taux **simulés** (trajectoire principale) |
| `niveau_id`, `groupes` | identifiants | non | — | Restriction de périmètre |

**Aucune valeur par défaut.** Les deux jeux de taux sont **complets** : le
moteur ne compense rien, ne redistribue rien, n'invente aucune origine à la
hausse.

---

## B.4 Règles de domaine

| # | Règle | Justification |
|---|---|---|
| R1 | `taux_ameliore.passage > taux.passage` (strict) | Le scénario mesure une **hausse** de passage ; une stabilité ou une baisse relève de SIM-08/SIM-09. |
| R2 | Chaque taux des deux jeux ∈ `[0 ; 1]` | Invariant de taux du moteur. |
| R3 | Chaque somme ≤ 1 | Aucune population créée par les taux. |
| R4 | `taux` de référence requis dès que `taux_ameliore` est fourni | Sans référence, aucune comparaison. |
| R5 | `duree_gel_annees` interdit | Un gel relève de SIM-03. |
| R6 | `pas_exceptionnel` / `admissions_exceptionnelles` interdits | Un choc relève de SIM-04. |
| R7 | `taux_cible_croissance` interdit | Une cible de croissance relève de SIM-05. |
| R8 | Trio de récupération interdit | Un creux relève de SIM-06. |
| R9 | `groupes` exige `niveau_id` | Invariant commun de la cascade. |

**Aucune règle sur l'origine de la hausse** : le redoublement et l'abandon
améliorés sont libres (en baisse, stables, ou consommant le « reste »
1 − somme). C'est un choix explicite : mesurer l'effet du jeu déclaré, pas
juger sa vraisemblance.

R1→R4 sont vérifiées par le catalogue (`validerReglesSim07`) **et** par le
moteur (`validerCriteres`). R5→R8 sont structurelles au catalogue (clés hors
schéma) et explicites dans le moteur.

---

## B.5 Sorties

Pour **chaque variante** :

| Champ | Type | Description |
|---|---|---|
| `hausse_passage` | number | `taux_ameliore.passage − taux.passage` (4 décimales) |
| `totaux_reference_reussite` | array\<integer\> | Totaux de la trajectoire témoin (pas 0 → H) |
| `ecarts_reussite_par_periode` | array | `[{pas, total_simule, total_reference, ecart}]` |
| `ecart_reussite_final` | integer | `total_final simulé − total_final référence` |
| `sortants_par_periode` | array | `[{pas, sortants, sortants_reference}]` |
| `effet_sortants_cumules` | integer | `Σ sortants simulés − Σ sortants référence` |
| `totaux`, `total_final`, `admissions_par_periode` | existants | Trajectoire **simulée** (taux améliorés) |

**Définition des sortants :** étudiants qui `partent` du **dernier niveau du
périmètre simulé** à un pas donné (passage réussi hors du niveau terminal).
Si `niveau_id` restreint à un niveau intermédiaire, « sortants » = sortants
de ce niveau, pas diplômés du programme (réserve explicite).

Les champs ne sont présents **que** si `taux_ameliore` est fourni.

---

## B.6 Cas non applicables

| Cas | Traitement |
|---|---|
| Hausse sans origine déclarée | Impossible : les trois taux améliorés sont requis ; aucune compensation implicite. |
| Dégradation (passage en baisse) | Refusée : SIM-08. |
| Variation simultanée du delta et des taux | Le delta est identique aux deux trajectoires ; plusieurs profils relèvent de SIM-13/CR-02. |
| Taux différenciés par niveau | Non couverts (LOT B, extension par niveau). |
| Population vide (`baseL1 = 0`) | Totaux nuls, écarts nuls ; aucune division. |

---

## B.7 Critères d'acceptation

1. `CatalogueScenarios::estDisponible('SIM-07') === true`.
2. Catalogue : **8 disponibles / 37 planifiés** (45 entrées).
3. `validerParametres('SIM-07', ...)` refuse : `taux_ameliore` incomplet,
   passage non strictement supérieur, somme > 1, gel, choc, cible, creux.
4. Le moteur produit les six champs de réussite conformes aux cas de
   référence hors moteur (`[60,37,28]` vs `[60,43,36]`, sortants `[21,12]`
   vs `[15,10]`).
5. Les admissions sont **identiques** dans les deux trajectoires (même
   delta) ; seule la progression diffère.
6. Zéro écriture métier ; idempotence conservée ; aucune clé ajoutée sans
   `taux_ameliore`.

---

## B.8 Réserves anticipées

| ID | Réserve |
|---|---|
| `SIM07-S1` | Le résultat mesure l'effet **dans le modèle déclaré** : la conversion d'une politique réelle en taux améliorés reste une expertise humaine. |
| `SIM07-S2` | « Sortants » = dernier niveau du périmètre, pas nécessairement diplômés du programme. |
| `SIM07-S3` | Les taux améliorés sont globaux (identiques pour tous les niveaux). |
| `SIM07-S4` | La création HTTP n'appelle pas le catalogue : la validation moteur s'active dès que `taux_ameliore` est présent. |
| `SIM07-S5` | Sans effet de report inter-niveaux différencié : un passage plus élevé profite à tous les niveaux de la même façon (taux globaux). |
