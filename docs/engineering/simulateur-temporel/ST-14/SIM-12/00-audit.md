# SIM-12 — 00 · Audit

**Date :** 2026-11-05  
**Scénario :** SIM-12 — Réduction de l'abandon  
**Famille :** 2 — Réussite, redoublement et abandon  
**Lot :** A (Admissions, démographie et réussite)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS et SIM-01 à SIM-11 disponibles ; SIM-12 à CR-06 planifiés (**12 disponibles / 33 planifiés**). |
| **Moteur** | `ServiceSimulationTemporelle` — version **`1.8`** ; descripteur d'axe générique livré par SIM-09 (`taux_modifie` + `axe_variation` + `sens_variation`), double trajectoire, `analyserReussite()` neutre, champs `hausse_|baisse_<axe>` déjà exposés. |
| **Direction `baisse`** | Déjà implémentée et validée : `sens_variation = 'baisse'` exige `taux_modifie[axe]` **strictement inférieur** à la référence ; `$reussiteVariation` est une magnitude positive ; la clé produite est `baisse_<axe>`. |
| **Champ de restitution** | `baisse_abandon` figure déjà dans `champsSpecifiques()` (liste unique), livré avec SIM-09. |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune nouvelle table nécessaire. |
| **Tests existants** | **152 tests / 1640 assertions** à 100 % au vert (vérifié à la clôture de SIM-11). |

**Conclusion de périmètre :** SIM-12 ne nécessite **aucune modification du
moteur**. Le travail attendu est un **contrat catalogue** (schéma, règles,
définition) et une **suite de tests** propre, avec des valeurs de référence
recalculées pour l'axe `abandon` en sens `baisse`.

---

## B. État initial de SIM-12 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version / formules / schéma | `null` |
| Disponibilité | `false` |
| Compatibilité déclarée | `configuration` |
| Dépendances | `['SIM-11']` |
| Objectif déclaré | « Analyser l'effet d'une meilleure rétention. » |

---

## C. Analyse du besoin fonctionnel

### C.1 Définition métier

La « réduction de l'abandon » mesure l'effet d'un **abandon déclaré en
baisse** sur :

1. les **effectifs** : les étudiants qui n'abandonnent plus restent-ils dans
   le système, et à quel niveau ?
2. les **sortants** : l'effet aval dépend des taux qui financent la baisse ;
3. la **baisse réellement déclarée** de l'abandon.

### C.2 Point critique : le modèle ne réinjecte que passage et redoublement

Lecture du moteur (`simulerVariante`, lignes 648 → 681) :

```php
$partent     = round($effectif * $taux['passage']);
$redoublants = round($effectif * $taux['redoublement']);
// nouveau niveau = redoublants + partants du niveau précédent
```

**`abandon` n'entre dans aucun calcul de progression.** Il ne sert qu'à la
contrainte `passage + redoublement + abandon ≤ 1` : le « reste » non déclaré
n'est pas réinjecté, et un abandon déclaré n'en retire pas davantage.

Conséquences, à documenter sans détour :

| Situation | Effet sur les trajectoires |
|---|---|
| `abandon` ↓, `passage` et `redoublement` **inchangés** (le slack augmente) | **Exactement nul** : les effectifs et les sortants sont identiques |
| `abandon` ↓ financé par `redoublement` ↑ | Davantage de redoublants réinjectés : effectifs en hausse |
| `abandon` ↓ financé par `passage` ↑ | Davantage de partants : effectifs et sortants en hausse |
| `abandon` ↓ financé par `passage` ↑ **et** `redoublement` ↑ | Effets cumulés, à mesurer |

**Aucun effet ne peut être attribué à `abandon` seul.** SIM-12 mesure un
**mouvement joint déclaré** ; il ne prétend pas isoler une causalité que le
modèle ne représente pas. Une baisse absorbée par le slack est **prouvée
neutre** (cas B), même si elle est intuitivement « favorable ».

### C.3 Conséquence sur l'analyse de sensibilité (OAT)

`AnalyseurSensibilite` ne teste que `delta_admissions`, `taux_passage` et
`taux_redoublement`. Il n'existe **aucune** ligne `taux_abandon`, car
`abandon` n'est pas un flux réinjecté. SIM-12 ne doit donc **pas** inventer
une sensibilité à l'abandon : la suite asserttera l'absence structurelle de
cette ligne et la présence des deux autres, calculées sur le jeu **modifié**.
Réserve documentée (`SIM12-R7`).

### C.4 Écart par rapport au moteur v1.8

| Besoin SIM-12 | Capacité existante (v1.8) | Écart |
|---|---|---|
| Deux jeux de taux déclarés | Oui | Réutilisés tels quels |
| Trajectoire témoin parallèle | Oui | Réutilisée telle quelle |
| Descripteur mono-axe générique | Oui (`taux_modifie`, `axe_variation`, `sens_variation`) | Réutilisé tel quel |
| Direction `baisse` stricte | Oui (garde générique) | Réutilisée telle quelle |
| Restitution `baisse_abandon` | Oui (`champsSpecifiques`) | Réutilisée telle quelle |
| Comparaison neutre (référence, écarts, sortants, effet) | Oui (`analyserReussite`) | Réutilisée telle quelle |
| Analyses OAT/MC sur le jeu modifié | Oui (`criteresAnalyses['taux'] = $reussiteTaux`) | Réutilisées telles quelles |
| **Contrat catalogue SIM-12** | Absent (`planifie`) | **À créer** : schéma, règles, définition |
| **Suite de tests SIM-12** | Absente | **À créer** : cas `baisse` recalculés |

### C.5 Cas de référence calculés hors moteur

Périmètre synthétique : L1 = 10, L2 = 20, L3 = 30 (total 60), δ = 0 sauf
mention, arrondi par flux (`round(effectif × taux)`, demi-entiers à l'écart
de zéro), progression du dernier niveau vers le premier. `abandon` n'est
jamais réinjecté.

**Cas A — baisse financée par le redoublement↑, H = 2**

- Référence `{p 0.6, r 0.2, a 0.2}` → totaux `[60, 40, 31]`, sortants
  `[18, 11]`.
- Modifié `{p 0.6, r 0.3, a 0.1}` → pas 1 : L1 = 13, L2 = 12, L3 = 21
  (total 46) ; pas 2 : L1 = 14, L2 = 12, L3 = 13 (total 39) ; sortants
  `[18, 13]`.
- Écarts `[+6, +8]` ; écart final `+8` ; effet sortants
  `(18+13) − (18+11) = +2` ; `baisse_abandon = 0.1`.

**Cas B — baisse absorbée par le slack, H = 2 (neutralité exacte)**

- Référence `{p 0.6, r 0.2, a 0.2}` (somme 1.0) → totaux `[60, 40, 31]`,
  sortants `[18, 11]`.
- Modifié `{p 0.6, r 0.2, a 0.1}` (somme 0.9) → **mêmes flux réinjectés** :
  totaux `[60, 40, 31]` et sortants `[18, 11]` identiques.
- Écarts `[0, 0]` ; effet sortants `0` ; `baisse_abandon = 0.1`.
- **Preuve :** le moteur n'invente aucun effet à partir de `abandon` seul ;
  une baisse « favorable » peut être **sans effet** dans le modèle.

**Cas C — baisse financée par le passage↑, δ = +10 %, H = 2**

- Admissions `[11, 12]` dans les deux trajectoires.
- Référence `{p 0.6, r 0.2, a 0.2}` → totaux `[60, 41, 35]`, sortants
  `[18, 11]`.
- Modifié `{p 0.7, r 0.2, a 0.1}` → pas 1 : L1 = 13, L2 = 11, L3 = 20
  (total 44), sortants 21 ; pas 2 (base 13, 11, 20) : L1 = 15, L2 = 11,
  L3 = 12 (total 38), sortants 14.
- Écarts `[+3, +3]` ; écart final `+3` ; effet sortants
  `(21+14) − (18+11) = +6` ; `baisse_abandon = 0.1`.
- **Lecture :** la baisse financée par le passage augmente le débit ; l'effet
  aval est positif et ne se déduit pas du seul stock.

---

## D. Options d'implémentation examinées

### Option 1 — Réutiliser le descripteur générique v1.8, contrat catalogue seul (retenue)

- **Moteur :** aucun changement. `taux_modifie` + `axe_variation =
  'abandon'` + `sens_variation = 'baisse'` sont déjà acceptés, validés et
  restitués.
- **Catalogue :** `schemaSim12()` épingle l'axe `abandon` et le sens
  `baisse` ; `validerReglesSim12()` impose la baisse stricte et la somme ≤ 1 ;
  `sim12()` publie le contrat (`analyse_nouvelle`, dépendance
  `SOCLE-EFFECTIFS`).
- **Tests :** suite `SimulationTemporelleSim12Test` avec les trois cas
  ci-dessus, plus la neutralité structurelle et les refus.

### Option 2 — Créer une clé dédiée `taux_abandon_baisse`

- **Rejet :** dupliquerait le contrat générique v1.8, créerait une seconde
  surface d'entrée et contredirait la décision SIM-09 (descripteur commun,
  alias strictement réservés à SIM-07/08).

### Option 3 — Réutiliser `taux_ameliore` ou SIM-07 pour approximer

- **Rejet :** l'axe serait faux. Une baisse de l'abandon à passage constant
  n'est pas une hausse du passage ; le champ produit doit nommer l'objet
  étudié (`baisse_abandon`) et la validation de direction doit porter sur
  `abandon`.

### Option 4 — Incrémenter le moteur en v1.9

- **Rejet à ce stade :** aucun comportement moteur nouveau. Un incrément
  sans changement de comportement ferait diverger les hashs existants sans
  justification. Toute découverte contraire en Phase D déclencherait une
  décision explicite.

---

## E. Reclassement de compatibilité

| Avant | Après | Justification |
|---|---|---|
| `configuration` | **`analyse_nouvelle`** | Même famille que SIM-07/08/09/10/11 : double trajectoire, restitution des sortants et analyse d'un axe déclaré — non réalisable par simple configuration des paramètres existants. SIM-09, SIM-10 et SIM-11 ont déjà établi ce précédent pour des scénarios symétriques. |

Ce reclassement est **confirmé par l'audit** et sera publié dans `sim12()`.

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM12-A1` | Croire qu'une baisse de l'abandon a un effet favorable par elle-même | HAUTE | Cas B : baisse absorbée par le slack = effet **exactement nul**, asserté `[0,0]` et effet `0`. |
| `SIM12-A2` | Attribuer au seul `abandon` un effet produit par le taux compensateur (passage↑ ou redoublement↑) | HAUTE | Documentation explicite : mouvement **joint déclaré** ; aucune causalité isolée revendiquée. |
| `SIM12-A3` | Le moteur accepte tout axe du vocabulaire ; l'épinglage `abandon/baisse` est porté par le schéma catalogue, non appelé par la création HTTP | MOYEN | Réserve héritée `SIM11-R6` ; validation moteur de la forme et de la direction. |
| `SIM12-A4` | Somme du jeu modifié > 1 ou taux hors [0,1] | MOYEN | Schéma borné + `validerReglesSim12()` + garde moteur. |
| `SIM12-A5` | Combinaison avec gel / choc / cible / creux / alias SIM-07-08 / autres axes | MOYEN | Refus explicites hérités ; exclusion mutuelle par ordre des blocs. |
| `SIM12-A6` | Attendre une sensibilité OAT à l'abandon | MOYEN | `AnalyseurSensibilite` ne modélise pas `abandon` ; test assertant l'absence de `taux_abandon`, réserve `SIM12-R7`, aucune valeur inventée. |
| `SIM12-A7` | Dérive des compteurs catalogue (13/32, index 12) | FAIBLE | Assertions explicites mises à jour et vérifiées. |
| `SIM12-A8` | Symétrie apparente avec le cas A de SIM-09 (même mouvement joint `redoublement↑ + abandon↓`) | MOYEN | Contrats distincts par l'axe focal et la clé produite (`baisse_abandon` vs `hausse_redoublement`) ; trajectoire commune documentée, moteur unique. |
| `SIM12-A9` | Effet aval faible ou nul à horizon 1 | FAIBLE | Naturel (pipeline lent) ; documenté, jamais compensé. |

---

## G. Décision et Gate A

- **Compatibilité confirmée :** `analyse_nouvelle`.
- **Incrément moteur :** **aucun** — le moteur reste en `1.8`.
- **Périmètre :**
  - `Support/CatalogueScenarios.php` : `sim12()`, `schemaSim12()`,
    `validerReglesSim12()`, branche `validerParametres`, retrait de
    `planifies()` → **13 disponibles / 32 planifiés** ;
  - `tests/Feature/SimulationTemporelleSim12Test.php` (créé) ;
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (compteurs,
    index 12, assertions de schéma et de règles).
- **Aucune** nouvelle table, route, permission, vue ou service.
- **Cas de référence hors moteur (vérifiés) :**
  - Cas A : ref `{0.6,0.2,0.2}` vs mod `{0.6,0.3,0.1}`, δ=0, H=2 →
    mod `[60,46,39]` sortants `[18,13]` vs ref `[60,40,31]` sortants
    `[18,11]` ; écarts `[+6,+8]` ; effet `+2`.
  - Cas B : ref `{0.6,0.2,0.2}` vs mod `{0.6,0.2,0.1}`, δ=0, H=2 →
    `[60,40,31]` et sortants `[18,11]` **identiques** ; écarts `[0,0]` ;
    effet `0`.
  - Cas C : δ=+10 % → admissions `[11,12]` ; mod `[60,44,38]` sortants
    `[21,14]` vs ref `[60,41,35]` sortants `[18,11]` ; écarts `[+3,+3]` ;
    écart final `+3` ; effet `+6`.
- **Gate A :** validé. Le sous-LOOP passe en Phase B.

**Interdits maintenus :** aucune écriture métier, aucune donnée de
production, aucun commit / push / branche, aucune migration destructive.
