# SIM-07 — 00 · Audit

**Date :** 2026-11-04  
**Scénario :** SIM-07 — Amélioration de la réussite  
**Famille :** 2 — Réussite, redoublement et abandon  
**Lot :** A (Admissions, démographie et réussite)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS et SIM-01 à SIM-06 disponibles ; SIM-07 à CR-06 planifiés (7 disponibles / 38 planifiés). |
| **Moteur** | `ServiceSimulationTemporelle` — version `1.5` ; trajectoire d'admissions paramétrable (delta, gel, choc, cible, creux) mais **un seul jeu de taux de flux par scénario** (`taux` global identique pour tous les niveaux). |
| **Variantes** | `GenerateurVariantes::deltasAdmissions` ne fait varier que le **delta d'admissions** : les taux de flux sont figés pour toutes les variantes d'une exécution. |
| **Sortants** | Le moteur calcule les `partent` du dernier niveau à chaque pas mais **ne les restitue pas** : aucune mesure de sortie (diplômés) n'existe. |
| **Analyses** | `AnalyseurSensibilite` et `SimulateurMonteCarlo` lisent `criteres['taux']` en interne (OAT ±5 pts sur passage / redoublement, MC ±4 pts sur passage). |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune nouvelle table nécessaire. |
| **Tests existants** | **112 tests / 1085 assertions** à 100 % au vert (vérifié avant ouverture). |

---

## B. État initial de SIM-07 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version / formules / schéma | `null` |
| Disponibilité | `false` |
| Compatibilité déclarée | `configuration` |
| Dépendances | `[]` |
| Objectif déclaré | « Mesurer l'effet d'une hausse du taux de passage. » |

---

## C. Analyse du besoin fonctionnel

### C.1 Définition métier

L'« amélioration de la réussite » mesure l'effet d'une **hausse du taux de
passage** sur la progression des cohortes et sur les **sortants** (étudiants
qui achèvent le dernier niveau du périmètre).

Trois questions :

1. Combien d'étudiants **sortent** en plus (diplômés) sur l'horizon ?
2. Comment les **effectifs** évoluent-ils (progression plus rapide, moins
   de redoublants en attente) ?
3. Quelle est la **hausse de passage réellement déclarée** (et non
   supposée) ?

### C.2 Écart par rapport au moteur v1.5

| Besoin SIM-07 | Capacité existante | Écart |
|---|---|---|
| Deux jeux de taux (référence / amélioré) dans la même exécution | Un seul `taux` par scénario | Second jeu requis + validation croisée |
| Comparaison période par période des effectifs | Non | Trajectoire de référence parallèle + écarts |
| Sortants (diplômés) par période | Calculés puis jetés | Capture et restitution |
| Effet cumulé sur les sortants | Non | Indicateur d'effet |

---

## D. Options d'implémentation examinées

### Option 1 — Comparaison externe (deux scénarios exécutés manuellement)

- **Rejet :** l'effet n'est pas mesuré par le moteur ; aucun écart, aucun
  cumul, aucune trace dans une même exécution ; le résultat n'est ni
  déterministe ni auditable en un seul artefact.

### Option 2 — Faire varier `taux` dans le générateur de variantes

- **Rejet :** les variantes ne varient qu'un axe (delta) ; y ajouter les
  taux changerait la sémantique de l'index de variante pour **toutes** les
  exécutions existantes (régression majeure), et le scénario se lirait comme
  une sensibilité, pas comme une amélioration déclarée.

### Option 3 — `taux_ameliore` déclaré + trajectoire de référence parallèle (retenue)

- Le scénario déclare **les deux jeux complets** : `taux` (référence) et
  `taux_ameliore` (simulé). Aucune compensation inventée : l'origine de la
  hausse (redoublement en baisse, abandon en baisse, ou reste non modélisé)
  est **entièrement déclarée** par l'appelant.
- Le moteur exécute la variante avec `taux_ameliore` (trajectoire
  principale, snapshots persistés) **et** une trajectoire de référence
  parallèle avec `taux`, mêmes admissions, même delta.
- Restitutions : totaux comparés, écarts par période, écart final,
  sortants par période, effet cumulé sur les sortants, hausse déclarée.
- Le delta et les admissions sont **identiques** dans les deux trajectoires :
  l'écart observé isole strictement l'effet des taux de flux.

---

## E. Reclassement de compatibilité

| Avant | Après | Justification |
|---|---|---|
| `configuration` | **`analyse_nouvelle`** | La comparaison de deux jeux de taux et la restitution des sortants constituent une nouvelle capacité analytique du moteur (double simulation parallèle). Aucune configuration des paramètres existants ne peut produire les écarts dans une même exécution. |

**Note :** SIM-08 (« Dégradation de la réussite »), également déclaré
`configuration`, devra probablement subir le même reclassement lors de son
propre audit — symétrie assumée et documentée.

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM07-A1` | Régression SOCLE / SIM-01→06 si le mode double taux s'active par défaut | HAUTE | `taux_ameliore` strictement optionnel (`null` par défaut) ; branche existante inchangée. |
| `SIM07-A2` | Origine de la hausse inventée (compensation automatique) | HAUTE | **Aucune compensation implicite** : les trois taux améliorés sont déclarés ; le moteur ne redistribue rien. |
| `SIM07-A3` | Somme améliorée > 1 (populations créées) | MOYEN | Validation stricte des deux jeux : chaque taux ∈ [0,1], somme ≤ 1 (catalogue + moteur). |
| `SIM07-A4` | Passage amélioré ≤ passage de référence (pas une amélioration) | MOYEN | Règle stricte `taux_ameliore.passage > taux.passage`. |
| `SIM07-A5` | Comparaison faussée par gel / choc / cible / creux | MOYEN | Refus explicites (SIM-03, SIM-04, SIM-05, SIM-06). |
| `SIM07-A6` | Analyses OAT / Monte-Carlo basées sur le taux de référence au lieu du taux amélioré | MOYEN | `criteres` passé aux analyses avec `taux` remplacé par `taux_ameliore` ; cohérence de la trajectoire principale. |
| `SIM07-A7` | Sortants du « dernier niveau du périmètre » ≠ diplômés du programme si `niveau_id` filtre un niveau intermédiaire | FAIBLE | Définition explicite « dernier niveau du périmètre » ; réserve documentée. |
| `SIM07-A8` | Doublement du coût de calcul (trajectoire de référence) | FAIBLE | Variantes ≤ 20, horizon ≤ 6 : coût borné ; seule l'exécution SIM-07 est concernée. |

---

## G. Décision et Gate A

- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée depuis
  `configuration`).
- **Incrément moteur :** passage à la version `1.6` (`VERSION_MOTEUR = '1.6'`).
- **Périmètre :** un paramètre (`taux_ameliore` complet), six champs de
  mesures (`hausse_passage`, `totaux_reference_reussite`,
  `ecarts_reussite_par_periode`, `ecart_reussite_final`,
  `sortants_par_periode`, `effet_sortants_cumules`), aucune table.
- **Gate A :** validé. Le besoin, les options, les cas de référence hors
  moteur (totaux `[60,37,28]` vs `[60,43,36]`, sortants `[21,12]` vs
  `[15,10]`) et la non-régression sont maîtrisés. Le sous-LOOP passe en
  Phase B.
