# SIM-10 — 00 · Audit

**Date :** 2026-11-05  
**Scénario :** SIM-10 — Réduction du redoublement  
**Famille :** 2 — Réussite, redoublement et abandon  
**Lot :** A (Admissions, démographie et réussite)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS et SIM-01 à SIM-09 disponibles ; SIM-10 à CR-06 planifiés (**10 disponibles / 35 planifiés**). |
| **Moteur** | `ServiceSimulationTemporelle` — version **`1.8`** ; descripteur d'axe **générique** livré par SIM-09 : `taux_modifie` + `axe_variation` + `sens_variation`, double trajectoire en mémoire, `analyserReussite()` neutre, champs `hausse_|baisse_<axe>` déjà exposés. |
| **Direction `baisse`** | Déjà implémentée et validée dans le moteur : `sens_variation = 'baisse'` exige `taux_modifie[axe]` **strictement inférieur** à la référence ; `$reussiteVariation` est une magnitude positive ; la clé produite est `baisse_<axe>`. |
| **Champ de restitution** | `baisse_redoublement` figure déjà dans `champsSpecifiques()` (liste unique), livré avec SIM-09. |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune nouvelle table nécessaire. |
| **Tests existants** | **136 tests / 1397 assertions** à 100 % au vert (vérifié avant ouverture : 136/136, Pint OK). |

**Conclusion de périmètre :** SIM-10 ne devrait nécessiter **aucune modification
du moteur**. Le travail attendu est un **contrat catalogue** (schéma, règles,
définition) et une **suite de tests** propre, avec des valeurs de référence
recalculées pour le sens `baisse`.

---

## B. État initial de SIM-10 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version / formules / schéma | `null` |
| Disponibilité | `false` |
| Compatibilité déclarée | `configuration` |
| Dépendances | `['SIM-09']` |
| Objectif déclaré | « Évaluer l'effet d'une baisse du redoublement. » |

---

## C. Analyse du besoin fonctionnel

### C.1 Définition métier

La « réduction du redoublement » mesure l'effet d'un **redoublement déclaré en
baisse** sur :

1. les **effectifs** : les étudiants qui ne redoublent plus quittent le
   niveau selon les autres taux déclarés — l'accumulation peut donc
   **diminuer** ;
2. les **sortants** : l'effet aval n'a **pas de signe prédéterminé** — il
   dépend de l'origine déclarée de la baisse (passage↑, abandon↑ ou reste) ;
3. la **baisse réellement déclarée** du redoublement.

### C.2 Point critique : aucun signe « favorable » supposé

Une baisse du redoublement **n'est pas mécaniquement une amélioration** :

- si la baisse alimente le **passage**, davantage d'étudiants progressent et
  les sortants peuvent augmenter ;
- si elle alimente l'**abandon** ou le **reste** (somme des taux < 1), les
  effectifs diminuent et les sortants peuvent **baisser** : les étudiants ne
  sont plus dans le système, ils ne sont pas diplômés pour autant.

Le scénario ne tranche pas cette question : il **mesure** le jeu déclaré.
Aucune compensation, aucune orientation, aucun jugement de valeur n'est
introduit par le moteur.

### C.3 Écart par rapport au moteur v1.8

| Besoin SIM-10 | Capacité existante (v1.8) | Écart |
|---|---|---|
| Deux jeux de taux déclarés | Oui | Réutilisés tels quels |
| Trajectoire témoin parallèle | Oui | Réutilisée telle quelle |
| Descripteur mono-axe générique | Oui (`taux_modifie`, `axe_variation`, `sens_variation`) | Réutilisé tel quel |
| Direction `baisse` stricte | Oui (garde générique) | Réutilisée telle quelle |
| Restitution `baisse_redoublement` | Oui (`champsSpecifiques`) | Réutilisée telle quelle |
| Comparaison neutre (référence, écarts, sortants, effet) | Oui (`analyserReussite`) | Réutilisée telle quelle |
| Analyses OAT/MC sur le jeu modifié | Oui (`criteresAnalyses['taux'] = $reussiteTaux`) | Réutilisées telles quelles |
| **Contrat catalogue SIM-10** | Absent (`planifie`) | **À créer** : schéma, règles, définition |
| **Suite de tests SIM-10** | Absente | **À créer** : cas `baisse` recalculés |

### C.4 Cas de référence calculés hors moteur

Périmètre synthétique : L1 = 10, L2 = 20, L3 = 30 (total 60), δ = 0 sauf
mention, arrondi par flux (`round(effectif × taux)`), progression du dernier
niveau vers le premier.

**Cas A — baisse alimentée par l'abandon↑, H = 2**

- Référence `{p 0.6, r 0.2, a 0.2}` → totaux `[60, 40, 31]`, sortants
  `[18, 11]`.
- Modifié `{p 0.6, r 0.1, a 0.3}` → pas 1 : L1 = 11, L2 = 8, L3 = 15
  (total 34) ; pas 2 : L1 = 11, L2 = 8, L3 = 7 (total 26) ; sortants
  `[18, 9]`.
- Écarts `[-6, -5]` ; écart final `-5` ; effet sortants
  `(18+9) − (18+11) = -2` ; `baisse_redoublement = 0.1`.

**Cas B — baisse absorbée par le reste, H = 1**

- Référence `{p 0.6, r 0.2, a 0.1}` (somme 0.9) → total 40, sortants `[18]`.
- Modifié `{p 0.6, r 0.1, a 0.1}` (somme 0.8) → L1 = 11, L2 = 8, L3 = 15
  (total 34), sortants `[18]`.
- Écart `[-6]` ; **effet sortants `0`** (passage inchangé, aval non atteint).

**Cas C — δ = +10 %, H = 2**

- Admissions `[11, 12]` dans les deux trajectoires.
- Référence `{p 0.6, r 0.2, a 0.2}` → `[60, 41, 35]`, sortants `[18, 11]`.
- Modifié `{p 0.6, r 0.1, a 0.3}` → `[60, 35, 28]`, sortants `[18, 9]`.
- Écarts `[-6, -7]` ; effet sortants `-2` ; `baisse_redoublement = 0.1`.

---

## D. Options d'implémentation examinées

### Option 1 — Réutiliser le descripteur générique v1.8, contrat catalogue seul (retenue)

- **Moteur :** aucun changement. `taux_modifie` + `axe_variation =
  'redoublement'` + `sens_variation = 'baisse'` sont déjà acceptés, validés
  et restitués.
- **Catalogue :** `schemaSim10()` épingle l'axe `redoublement` et le sens
  `baisse` ; `validerReglesSim10()` impose la baisse stricte et la somme ≤ 1 ;
  `sim10()` publie le contrat (`analyse_nouvelle`, dépendance
  `SOCLE-EFFECTIFS`).
- **Tests :** suite `SimulationTemporelleSim10Test` avec les trois cas
  ci-dessus, plus rétrocompatibilité et sécurité.

### Option 2 — Créer une clé dédiée `taux_redoublement_baisse`

- **Rejet :** dupliquerait le contrat générique livré en v1.8, créerait une
  seconde surface d'entrée pour le même besoin et contredirait la décision
  SIM-09 (descripteur commun, alias strictement réservés à SIM-07/08).

### Option 3 — Réutiliser `taux_degrade` (baisse du passage) pour approximer

- **Rejet :** l'axe serait faux. Une baisse du redoublement à passage constant
  n'est pas une baisse du passage ; le champ `baisse_passage` mentirait sur
  l'objet étudié et la validation « passage strictement inférieur » serait
  inapplicable.

### Option 4 — Incrémenter le moteur en v1.9

- **Rejet à ce stade :** aucun comportement moteur nouveau n'est nécessaire.
  Un incrément sans changement de comportement ferait diverger les hashs
  d'exécution sans justification et casserait l'idempotence des scénarios
  existants pour un simple ajout de contrat. **Si la Phase D révèle un écart
  réel, l'incrément sera décidé et documenté à ce moment-là** — jamais par
  anticipation.

---

## E. Reclassement de compatibilité

| Avant | Après | Justification |
|---|---|---|
| `configuration` | **`analyse_nouvelle`** | Même famille de justification que SIM-07/08/09 : double trajectoire, restitution des sortants et analyse d'un axe déclaré — non réalisable par simple configuration des paramètres existants. SIM-08 (miroir de SIM-07) a déjà établi ce précédent pour un scénario symétrique. |

Ce reclassement est **confirmé par l'audit** et sera publié dans `sim10()`.

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM10-A1` | Interpréter la baisse comme automatiquement favorable (sortants↑) | HAUTE | Aucun signe supposé ; cas A et C prouvent un effet sortants **négatif**, cas B un effet **nul**. |
| `SIM10-A2` | Le moteur accepte aussi `sens = hausse` ; l'épinglage `baisse` est porté par le schéma catalogue, non appelé par la création HTTP | MOYEN | Réserve héritée `SIM09-R6` ; validation moteur de la forme et de la direction, contrat d'axe au catalogue. Ne pas revendiquer davantage. |
| `SIM10-A3` | Baisse « compensée » implicitement (passage↑ ou abandon↑ inventé) | MOYEN | Jeu modifié **complet et déclaré** ; somme ≤ 1 ; aucune compensation calculée. |
| `SIM10-A4` | Somme du jeu modifié > 1 ou taux hors [0,1] | MOYEN | Schéma borné + `validerReglesSim10()` + garde moteur. |
| `SIM10-A5` | Combinaison avec gel / choc / cible / creux / alias SIM-07-08 | MOYEN | Refus explicites hérités ; exclusion mutuelle par ordre des blocs. |
| `SIM10-A6` | Analyses OAT/MC calculées sur la référence au lieu du jeu modifié | MOYEN | Le moteur normalise `criteresAnalyses['taux']` ; test dédié (base redoublement = valeur modifiée). |
| `SIM10-A7` | Dérive des compteurs catalogue (11/34, index 10) | FAIBLE | Assertions explicites mises à jour et vérifiées. |
| `SIM10-A8` | Duplication de la suite SIM-09 avec des valeurs recopiées | MOYEN | Valeurs **recalculées à la main** pour le sens `baisse` ; aucune assertion de SIM-09 réutilisée sans vérification. |
| `SIM10-A9` | Effet aval faible ou nul à horizon 1 | FAIBLE | Naturel (pipeline lent) ; cas B documenté et asserté à `0`. |

---

## G. Décision et Gate A

- **Compatibilité confirmée :** `analyse_nouvelle`.
- **Incrément moteur :** **aucun prévu** — le moteur reste en `1.8`. Toute
  découverte contraire en Phase D déclenchera une décision explicite.
- **Périmètre :**
  - `Support/CatalogueScenarios.php` : `sim10()`, `schemaSim10()`,
    `validerReglesSim10()`, branche `validerParametres`, retrait de
    `planifies()` → **11 disponibles / 34 planifiés** ;
  - `tests/Feature/SimulationTemporelleSim10Test.php` (créé) ;
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (compteurs,
    index 10, assertions de schéma et de règles).
- **Aucune** nouvelle table, route, permission, vue ou service.
- **Cas de référence hors moteur (vérifiés) :**
  - Cas A : ref `{0.6,0.2,0.2}` vs mod `{0.6,0.1,0.3}`, δ=0, H=2 →
    mod `[60,34,26]` sortants `[18,9]` vs ref `[60,40,31]` sortants
    `[18,11]` ; écarts `[-6,-5]` ; effet sortants `-2`.
  - Cas B : ref `{0.6,0.2,0.1}` vs mod `{0.6,0.1,0.1}`, δ=0, H=1 →
    `[60,34]` vs `[60,40]` ; effet sortants `0`.
  - Cas C : δ=+10 % → admissions `[11,12]` ; mod `[60,35,28]` vs ref
    `[60,41,35]` ; écarts `[-6,-7]` ; effet sortants `-2`.
- **Gate A :** validé. Le sous-LOOP passe en Phase B.

**Interdits maintenus :** aucune écriture métier, aucune donnée de
production, aucun commit / push / branche, aucune migration destructive.
