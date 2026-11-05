# SIM-11 — 00 · Audit

**Date :** 2026-11-05  
**Scénario :** SIM-11 — Hausse de l'abandon  
**Famille :** 2 — Réussite, redoublement et abandon  
**Lot :** A (Admissions, démographie et réussite)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS et SIM-01 à SIM-10 disponibles ; SIM-11 à CR-06 planifiés (**11 disponibles / 34 planifiés**). |
| **Moteur** | `ServiceSimulationTemporelle` — version **`1.8`** ; descripteur d'axe générique livré par SIM-09 (`taux_modifie` + `axe_variation` + `sens_variation`), double trajectoire, `analyserReussite()` neutre, champs `hausse_|baisse_<axe>` déjà exposés. |
| **Direction `hausse`** | Déjà implémentée et validée : `sens_variation = 'hausse'` exige `taux_modifie[axe]` **strictement supérieur** à la référence ; `$reussiteVariation` est une magnitude positive ; la clé produite est `hausse_<axe>`. |
| **Champ de restitution** | `hausse_abandon` figure déjà dans `champsSpecifiques()` (liste unique), livré avec SIM-09. |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune nouvelle table nécessaire. |
| **Tests existants** | **144 tests / 1517 assertions** à 100 % au vert (vérifié avant ouverture : 144/144, Pint OK). |

**Conclusion de périmètre :** SIM-11 ne nécessite **aucune modification du
moteur**. Le travail attendu est un **contrat catalogue** (schéma, règles,
définition) et une **suite de tests** propre, avec des valeurs de référence
recalculées pour l'axe `abandon`.

---

## B. État initial de SIM-11 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version / formules / schéma | `null` |
| Disponibilité | `false` |
| Compatibilité déclarée | `configuration` |
| Dépendances | `[]` |
| Objectif déclaré | « Étudier l'effet d'une hausse des sorties du système. » |

---

## C. Analyse du besoin fonctionnel

### C.1 Définition métier

La « hausse de l'abandon » mesure l'effet d'un **abandon déclaré en hausse**
sur :

1. les **effectifs** : les étudiants qui abandonnent ne sont ni passés ni
   redoublants — ils ne sont pas réinjectés dans le niveau ;
2. les **sortants** : l'effet aval dépend des taux qui financent la hausse ;
3. la **hausse réellement déclarée** de l'abandon.

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
| `abandon` ↑, `passage` et `redoublement` **inchangés**, somme ≤ 1 (slack disponible) | **Exactement nul** : les effectifs et les sortants sont identiques |
| `abandon` ↑ financé par `passage` ↓ | Les effectifs et/ou les sortants changent selon l'horizon |
| `abandon` ↑ financé par `redoublement` ↓ | Moins de redoublants réinjectés : effectifs en baisse |
| `abandon` ↑ financé par `passage` ↓ **et** `redoublement` ↑ | Les effectifs peuvent augmenter (accumulation) alors que les sortants diminuent |

**Aucun effet ne peut être attribué à `abandon` seul.** SIM-11 mesure un
**mouvement joint déclaré** ; il ne prétend pas isoler une causalité que le
modèle ne représente pas. C'est une force de transparence, pas un défaut à
masquer : aucun signe « favorable » n'est supposé, et une hausse absorbée
par le slack est **prouvée neutre** (cas B).

### C.3 Conséquence sur l'analyse de sensibilité (OAT)

`AnalyseurSensibilite` ne teste que `delta_admissions`, `taux_passage` et
`taux_redoublement`. Il n'existe **aucune** ligne `taux_abandon`, car
`abandon` n'est pas un flux réinjecté. SIM-11 ne doit donc **pas** inventer
une sensibilité à l'abandon : la suite asserttera l'absence structurelle de
cette ligne et la présence des deux autres, calculées sur le jeu **modifié**.
Réserve documentée (`SIM11-R6`).

### C.4 Écart par rapport au moteur v1.8

| Besoin SIM-11 | Capacité existante (v1.8) | Écart |
|---|---|---|
| Deux jeux de taux déclarés | Oui | Réutilisés tels quels |
| Trajectoire témoin parallèle | Oui | Réutilisée telle quelle |
| Descripteur mono-axe générique | Oui (`taux_modifie`, `axe_variation`, `sens_variation`) | Réutilisé tel quel |
| Direction `hausse` stricte | Oui (garde générique) | Réutilisée telle quelle |
| Restitution `hausse_abandon` | Oui (`champsSpecifiques`) | Réutilisée telle quelle |
| Comparaison neutre (référence, écarts, sortants, effet) | Oui (`analyserReussite`) | Réutilisée telle quelle |
| Analyses OAT/MC sur le jeu modifié | Oui (`criteresAnalyses['taux'] = $reussiteTaux`) | Réutilisées telles quelles |
| **Contrat catalogue SIM-11** | Absent (`planifie`) | **À créer** : schéma, règles, définition |
| **Suite de tests SIM-11** | Absente | **À créer** : cas `hausse` recalculés |

### C.5 Cas de référence calculés hors moteur

Périmètre synthétique : L1 = 10, L2 = 20, L3 = 30 (total 60), δ = 0 sauf
mention, arrondi par flux (`round(effectif × taux)`), progression du dernier
niveau vers le premier. `abandon` n'est jamais réinjecté.

**Cas A — hausse financée par le redoublement↓, H = 2**

- Référence `{p 0.6, r 0.2, a 0.2}` → totaux `[60, 40, 31]`, sortants
  `[18, 11]`.
- Modifié `{p 0.6, r 0.1, a 0.3}` → pas 1 : L1 = 11, L2 = 8, L3 = 15
  (total 34) ; pas 2 : L1 = 11, L2 = 8, L3 = 7 (total 26) ; sortants
  `[18, 9]`.
- Écarts `[-6, -5]` ; écart final `-5` ; effet sortants
  `(18+9) − (18+11) = -2` ; `hausse_abandon = 0.1`.

**Cas B — hausse absorbée par le slack, H = 2 (neutralité exacte)**

- Référence `{p 0.6, r 0.2, a 0.1}` (somme 0.9) → totaux `[60, 40, 31]`,
  sortants `[18, 11]`.
- Modifié `{p 0.6, r 0.2, a 0.2}` (somme 1.0) → **mêmes flux** : p et r
  inchangés, donc mêmes totaux `[60, 40, 31]` et mêmes sortants `[18, 11]`.
- Écarts `[0, 0]` ; effet sortants `0` ; `hausse_abandon = 0.1`.
- **Preuve :** le moteur n'invente aucun effet à partir de `abandon` seul.

**Cas C — hausse financée par le passage↓, δ = +10 %, H = 2**

- Admissions `[11, 12]` dans les deux trajectoires.
- Référence `{p 0.6, r 0.2, a 0.2}` → pas 1 : `[13, 10, 18]` (41),
  sortants 18 ; pas 2 : `[15, 10, 10]` (35), sortants 11 → totaux
  `[60, 41, 35]`, sortants `[18, 11]`.
- Modifié `{p 0.5, r 0.2, a 0.3}` → pas 1 : `[13, 9, 16]` (38),
  sortants 15 ; pas 2 (base `13, 9, 16`) : L3 partent 8 / redoublants 3,
  L2 partent 5 (`round(4.5)`) / redoublants 2, L1 partent 7
  (`round(6.5)`) / redoublants 3, +12 admissions → `[15, 9, 8]` (32),
  sortants 8 → totaux `[60, 38, 32]`, sortants `[15, 8]`.
- Écarts `[-3, -3]` ; écart final `-3` ; effet sortants
  `(15+8) − (18+11) = -6` ; `hausse_abandon = 0.1`.
- **Lecture :** l'écart de stock se creuse encore au pas 2 (`-3`) et le
  déficit aval est plus marqué (`-6`) ; l'effet sur les sortants ne se
  déduit pas du seul écart de stock.

---

## D. Options d'implémentation examinées

### Option 1 — Réutiliser le descripteur générique v1.8, contrat catalogue seul (retenue)

- **Moteur :** aucun changement. `taux_modifie` + `axe_variation =
  'abandon'` + `sens_variation = 'hausse'` sont déjà acceptés, validés et
  restitués.
- **Catalogue :** `schemaSim11()` épingle l'axe `abandon` et le sens
  `hausse` ; `validerReglesSim11()` impose la hausse stricte et la somme ≤ 1 ;
  `sim11()` publie le contrat (`analyse_nouvelle`, dépendance
  `SOCLE-EFFECTIFS`).
- **Tests :** suite `SimulationTemporelleSim11Test` avec les trois cas
  ci-dessus, plus la neutralité structurelle et les refus.

### Option 2 — Créer une clé dédiée `taux_abandon_hausse`

- **Rejet :** dupliquerait le contrat générique v1.8, créerait une seconde
  surface d'entrée et contredirait la décision SIM-09 (descripteur commun,
  alias strictement réservés à SIM-07/08).

### Option 3 — Réutiliser `taux_degrade` ou SIM-10 pour approximer

- **Rejet :** l'axe serait faux. Une hausse de l'abandon financée par le
  passage↓ n'est pas une baisse du redoublement ; le champ produit doit
  nommer l'objet étudié (`hausse_abandon`) et la validation de direction doit
  porter sur `abandon`.

### Option 4 — Incrémenter le moteur en v1.9

- **Rejet à ce stade :** aucun comportement moteur nouveau. Un incrément
  sans changement de comportement ferait diverger les hashs existants sans
  justification. Toute découverte contraire en Phase D déclencherait une
  décision explicite.

---

## E. Reclassement de compatibilité

| Avant | Après | Justification |
|---|---|---|
| `configuration` | **`analyse_nouvelle`** | Même famille que SIM-07/08/09/10 : double trajectoire, restitution des sortants et analyse d'un axe déclaré — non réalisable par simple configuration des paramètres existants. SIM-08 et SIM-10 ont déjà établi ce précédent pour des scénarios symétriques. |

Ce reclassement est **confirmé par l'audit** et sera publié dans `sim11()`.

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM11-A1` | Croire qu'une hausse de l'abandon a un effet par elle-même | HAUTE | Cas B : hausse absorbée par le slack = effet **exactement nul**, asserté `[0,0]` et effet `0`. |
| `SIM11-A2` | Attribuer au seul `abandon` un effet produit par le taux compensateur (passage↓ ou redoublement↓) | HAUTE | Documentation explicite : mouvement **joint déclaré** ; aucune causalité isolée revendiquée. |
| `SIM11-A3` | Le moteur accepte tout axe du vocabulaire ; l'épinglage `abandon/hausse` est porté par le schéma catalogue, non appelé par la création HTTP | MOYEN | Réserve héritée `SIM10-R6` ; validation moteur de la forme et de la direction. |
| `SIM11-A4` | Somme du jeu modifié > 1 ou taux hors [0,1] | MOYEN | Schéma borné + `validerReglesSim11()` + garde moteur. |
| `SIM11-A5` | Combinaison avec gel / choc / cible / creux / alias SIM-07-08 / autres axes | MOYEN | Refus explicites hérités ; exclusion mutuelle par ordre des blocs. |
| `SIM11-A6` | Attendre une sensibilité OAT à l'abandon | MOYEN | `AnalyseurSensibilite` ne modélise pas `abandon` ; test assertant l'absence de `taux_abandon`, réserve `SIM11-R6`, aucune valeur inventée. |
| `SIM11-A7` | Dérive des compteurs catalogue (12/33, index 11) | FAIBLE | Assertions explicites mises à jour et vérifiées. |
| `SIM11-A8` | Duplication apparente avec le cas A de SIM-10 (même mouvement joint) | MOYEN | Contrats distincts par l'axe focal et la clé produite (`hausse_abandon` vs `baisse_redoublement`) ; trajectoire commune documentée, moteur unique. |
| `SIM11-A9` | Effet aval faible ou nul à horizon 1 | FAIBLE | Naturel (pipeline lent) ; documenté, jamais compensé. |

---

## G. Décision et Gate A

- **Compatibilité confirmée :** `analyse_nouvelle`.
- **Incrément moteur :** **aucun** — le moteur reste en `1.8`.
- **Périmètre :**
  - `Support/CatalogueScenarios.php` : `sim11()`, `schemaSim11()`,
    `validerReglesSim11()`, branche `validerParametres`, retrait de
    `planifies()` → **12 disponibles / 33 planifiés** ;
  - `tests/Feature/SimulationTemporelleSim11Test.php` (créé) ;
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (compteurs,
    index 11, assertions de schéma et de règles).
- **Aucune** nouvelle table, route, permission, vue ou service.
- **Cas de référence hors moteur (vérifiés) :**
  - Cas A : ref `{0.6,0.2,0.2}` vs mod `{0.6,0.1,0.3}`, δ=0, H=2 →
    mod `[60,34,26]` sortants `[18,9]` vs ref `[60,40,31]` sortants
    `[18,11]` ; écarts `[-6,-5]` ; effet `-2`.
  - Cas B : ref `{0.6,0.2,0.1}` vs mod `{0.6,0.2,0.2}`, δ=0, H=2 →
    `[60,40,31]` et sortants `[18,11]` **identiques** ; écarts `[0,0]` ;
    effet `0`.
  - Cas C : δ=+10 % → admissions `[11,12]` ; mod `[60,38,32]` sortants
    `[15,8]` vs ref `[60,41,35]` sortants `[18,11]` ; écarts `[-3,-3]` ;
    écart final `-3` ; effet `-6`.
- **Gate A :** validé. Le sous-LOOP passe en Phase B.

**Interdits maintenus :** aucune écriture métier, aucune donnée de
production, aucun commit / push / branche, aucune migration destructive.
