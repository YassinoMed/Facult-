# SIM-05 — 00 · Audit

**Date :** 2026-11-03  
**Scénario :** SIM-05 — Croissance supérieure à la cible  
**Famille :** 1 — Démographie et admissions  
**Lot :** A (Admissions et démographie)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS, SIM-01, SIM-02, SIM-03 et SIM-04 disponibles ; SIM-05 à CR-06 planifiés. |
| **Moteur** | `ServiceSimulationTemporelle` — version `1.3` ; trajectoire de fond `admissions = round(baseL1 × (1 + delta)^pas)`, gel optionnel (SIM-03), choc additif ponctuel (SIM-04). |
| **Comparateur** | `ComparateurProjections::comparer` — compare l'état initial, la variante retenue et la variante 0, avec un écart à une cible **constante** (`cible_total`). |
| **Évaluateur** | `EvaluateurScenarios` — objectifs `cible_total`, `stabilite`, etc. ; la cible est un scalaire unique, pas une trajectoire. |
| **Adaptateur** | `AdaptateurEffectifsCohortes::etatInitial` — lecture seule, sans PII, filtre `niveau_id` / `groupes`. |
| **Contrôleur** | `SimulationTemporelleController` — routes existantes, middleware `secondaire.verrou`, permissions `simulation.*`. |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune nouvelle table nécessaire. |
| **Tests existants** | **95 tests / 876 assertions** à 100 % au vert (vérifié indépendamment avant ouverture). |

---

## B. État initial de SIM-05 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version | `null` |
| Version formules | `null` |
| Schéma | `null` |
| Disponibilité | `false` (`CatalogueScenarios::estDisponible('SIM-05') === false`) |
| Compatibilité déclarée | `configuration` |
| Dépendances | `[]` |
| Objectif déclaré | « Comparer la projection à une cible rectorale et identifier les dépassements. » |

---

## C. Analyse du besoin fonctionnel

### C.1 Définition métier

La « croissance supérieure à la cible » compare une projection d'admissions à
une **trajectoire de croissance visée par l'autorité** (cible rectorale,
objectif de développement, contrat d'établissement). Elle doit répondre à
trois questions :

1. **Où** la projection dépasse-t-elle la cible, année par année ?
2. **De combien** dépasse-t-elle, en volume et en cumul ?
3. Le dépassement est-il **durable** ou limité à une période ?

Une cible unique en fin d'horizon ne répond pas à la question 1 : deux
trajectoires peuvent atteindre le même total final avec des profils
annuels opposés (retard puis rattrapage, ou avance puis plateau).

### C.2 Ce que le moteur existant sait déjà faire

Le moteur v1.3 dispose déjà d'une **cible constante** :

- `objectifs[].type = cible_total` avec `objectifs[].cible = X` ;
- `ComparateurProjections::comparer(..., $cibleMetier)` calcule, pour chaque
  pas, `ecart_cible = total_pas − X` ;
- `EvaluateurScenarios` score la proximité du total final à `X`.

Cette capacité est réelle et testée (ST-10 Lot 2). Elle ne modélise
cependant **qu'un scalaire constant**, pas un taux de croissance appliqué à
une base observée.

### C.3 Écart technique identifié

| Besoin SIM-05 | Capacité existante | Écart |
|---|---|---|
| Cible exprimée en **taux de croissance annuel** | Aucune | Paramètre `taux_cible_croissance` à ajouter |
| Trajectoire cible **par période** | Cible constante unique | Calcul `round(baseL1 × (1 + taux_cible)^pas)` |
| Écart **signé par période** | Écart au total constant | Tableau `ecarts_cible_par_periode` |
| **Cumul** des écarts | Non | `ecart_cible_cumule` |
| **Détection de dépassement** | Non (seuil de score) | Booléen `depassement_cible` |

---

## D. Options d'implémentation examinées

### Option 1 — Réutiliser `cible_total` avec un total pré-calculé par l'appelant

- **Avantage :** zéro modification du moteur.
- **Rejet :** le comparateur compare **chaque pas au même total constant**.
  Il faudrait un total cible par année ; le schéma ne le permet pas. De plus,
  l'appelant devrait calculer lui-même la trajectoire, ce qui déplacerait la
  formule hors du moteur unique et rendrait le résultat non auditable.

### Option 2 — Ajouter une liste de totaux cibles annuels

- **Avantage :** flexible.
- **Rejet :** introduit une structure de données non validée, permet des
  cibles incohérentes (croissance puis chute arbitraire) et ne rattache pas
  la cible à une **règle de croissance** vérifiable. Le scénario revendique
  une croissance, pas une courbe libre.

### Option 3 — Ajouter `taux_cible_croissance` au moteur unique (retenue)

- Paramètre **saisi**, sans valeur par défaut.
- Trajectoire cible calculée par la **même formule de composition** que la
  trajectoire réelle, avec le taux cible.
- Écarts, cumul et dépassement calculés à partir des admissions réellement
  produites par le moteur (aucun recalcul parallèle).
- Aucune nouvelle table, aucun nouveau service, aucune duplication de la
  formule de progression.

---

## E. Reclassement de compatibilité

| Avant | Après | Justification |
|---|---|---|
| `configuration` | **`analyse_nouvelle`** | La cible constante `cible_total` ne permet pas une trajectoire cible par période, ni un écart cumulé, ni une détection de dépassement. Une nouvelle capacité analytique du moteur est nécessaire. |

Le classement `analyse_nouvelle` est celui prévu par le catalogue pour
« nécessite une nouvelle capacité analytique ». Il ne signifie pas « nouveau
moteur » : l'extension reste dans le moteur unique, sous version `1.4`.

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM05-A1` | Régression SOCLE / SIM-01→04 si la cible s'applique par défaut | HAUTE | Paramètre strictement optionnel (`null` par défaut) ; aucune clé ajoutée aux mesures en son absence. |
| `SIM05-A2` | Cible inventée ou silencieusement fixée | HAUTE | `taux_cible_croissance` **requis** dans le schéma SIM-05, aucune valeur par défaut dans le moteur. |
| `SIM05-A3` | Cible ≥ delta : le scénario ne prouve aucun dépassement | MOYEN | Règle `delta_admissions > taux_cible_croissance` (catalogue + moteur). |
| `SIM05-A4` | Cible négative déguisée en « croissance » | MOYEN | Schéma : `taux_cible_croissance ∈ [0 ; 2.0]` ; borne minimale des variantes ≥ 0. |
| `SIM05-A5` | Comparaison faussée par un gel ou un choc | MOYEN | Incompatibilité explicite : `taux_cible_croissance` + `duree_gel_annees` ou `pas_exceptionnel` ⇒ refus. |
| `SIM05-A6` | Arrondis : un écart exact positif peut devenir nul | FAIBLE | Les écarts sont calculés sur les admissions **arrondies** ; `depassement_cible` reste faux si aucun écart numérique n'est positif. Documenté et testé. |
| `SIM05-A7` | Confusion avec `cible_total` (total constant) | FAIBLE | Indicateurs distincts (`ecarts_cible_par_periode` vs `ecart_cible`) ; documentation explicite. |

---

## G. Décision et Gate A

- **Compatibilité confirmée :** `analyse_nouvelle`.
- **Incrément moteur :** passage à la version `1.4` (`VERSION_MOTEUR = '1.4'`).
- **Périmètre :** un paramètre (`taux_cible_croissance`), quatre champs de
  mesures (`admissions_cible_par_periode`, `ecarts_cible_par_periode`,
  `ecart_cible_cumule`, `depassement_cible`), aucune table.
- **Gate A :** validé. Le besoin, l'écart, les options et la non-régression
  sont maîtrisés. Le sous-LOOP passe en Phase B (spécification et formules).
