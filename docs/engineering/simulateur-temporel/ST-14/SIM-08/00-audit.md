# SIM-08 — 00 · Audit

**Date :** 2026-11-04  
**Scénario :** SIM-08 — Dégradation de la réussite  
**Famille :** 2 — Réussite, redoublement et abandon  
**Lot :** A (Admissions, démographie et réussite)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS et SIM-01 à SIM-07 disponibles ; SIM-08 à CR-06 planifiés (8 disponibles / 37 planifiés). |
| **Moteur** | `ServiceSimulationTemporelle` — version `1.6` ; SIM-07 fournit déjà : `taux_ameliore` déclaré, trajectoire de référence parallèle, `analyserReussite()`, `sortants` restitués, analyses OAT/MC recentrées sur le jeu simulé. |
| **Symétrie** | SIM-08 est le **miroir** de SIM-07 : passage déclaré en **baisse stricte** au lieu d'une hausse. La machinerie de comparaison existe ; il reste à la généraliser proprement (nom du champ de variation, exclusion mutuelle). |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune nouvelle table nécessaire. |
| **Tests existants** | **120 tests / 1180 assertions** à 100 % au vert (vérifié avant ouverture). |

---

## B. État initial de SIM-08 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version / formules / schéma | `null` |
| Disponibilité | `false` |
| Compatibilité déclarée | `configuration` |
| Dépendances | `[]` |
| Objectif déclaré | « Mesurer l'effet d'une baisse du taux de passage. » |

---

## C. Analyse du besoin fonctionnel

### C.1 Définition métier

La « dégradation de la réussite » mesure l'effet d'une **baisse du taux de
passage** (échec accru, sélectivité renforcée, crise pédagogique) sur :

1. les **effectifs** : accumulation d'étudiants bloqués dans les niveaux ;
2. les **sortants** (dernier niveau du périmètre) : diplômés en moins ;
3. la **baisse réellement déclarée** du passage.

### C.2 Écart par rapport au moteur v1.6 (acquise par SIM-07)

| Besoin SIM-08 | Capacité existante (v1.6) | Écart |
|---|---|---|
| Deux jeux de taux déclarés | `taux` + `taux_ameliore` | Miroir `taux_degrade` |
| Trajectoire témoin parallèle | Oui (non persistée) | Réutilisée telle quelle |
| Restitutions d'écarts et sortants | Oui (champs neutres) | Réutilisées telles quelles |
| Variation nommée par direction | `hausse_passage` (signé positif) | Ajouter `baisse_passage` (magnitude positive) |
| Exclusion mutuelle hausse / baisse | Non | SIM-07 + SIM-08 simultanés = refus |

---

## D. Options d'implémentation examinées

### Option 1 — Réutiliser `taux_ameliore` avec une valeur inférieure

- **Rejet :** le nom mentirait (un « amélioré » plus faible) ; les messages
  de validation (strictement supérieur) deviendraient faux ; la lecture de
  l'exécution serait trompeuse.

### Option 2 — Contrat générique `taux_simule` + variation signée

- **Rejet :** casserait le contrat **publié** de SIM-07 (v1.6 documenté,
  testé, verdict signé) ; les clés `hausse_passage`/`taux_ameliore` sont
  déjà opposables.

### Option 3 — Miroir `taux_degrade` + génération du champ de variation (retenue)

- `taux_degrade` : jeu complet déclaré, `passage` **strictement inférieur**
  au passage de référence.
- `analyserReussite()` généralisé : la clé de variation (`hausse_passage`
  ou `baisse_passage`) et la magnitude positive sont des paramètres ;
  les cinq champs neutres (référence, écarts, sortants, effet) sont
  partagés.
- Exclusion mutuelle : `taux_ameliore` + `taux_degrade` simultanés → refus
  (validation + garde d'exécution).
- Aucune table, aucun service, aucune duplication de la comparaison.

---

## E. Reclassement de compatibilité

| Avant | Après | Justification |
|---|---|---|
| `configuration` | **`analyse_nouvelle`** | Même justification que SIM-07 (symétrie assumée et documentée dans `SIM-07/00-audit.md`) : la double trajectoire et la restitution des sortants ne sont pas réalisables par configuration des paramètres existants. |

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM08-A1` | Régression SOCLE / SIM-01→07 si le mode dégradé s'active par défaut | HAUTE | `taux_degrade` strictement optionnel (`null` par défaut) ; branche existante inchangée. |
| `SIM08-A2` | Origine de la baisse inventée (compensation automatique) | HAUTE | **Aucune compensation implicite** : les trois taux dégradés sont déclarés ; le moteur ne redistribue rien. |
| `SIM08-A3` | Somme dégradée > 1 (populations créées) | MOYEN | Validation stricte des deux jeux : chaque taux ∈ [0,1], somme ≤ 1 (catalogue + moteur). |
| `SIM08-A4` | Passage dégradé ≥ passage de référence (pas une dégradation) | MOYEN | Règle stricte `taux_degrade.passage < taux.passage`. |
| `SIM08-A5` | Combinaison hausse + baisse (SIM-07 + SIM-08 simultanés) | MOYEN | Exclusion mutuelle explicite (validation + garde d'exécution). |
| `SIM08-A6` | Comparaison faussée par gel / choc / cible / creux | MOYEN | Refus explicites (SIM-03, SIM-04, SIM-05, SIM-06). |
| `SIM08-A7` | Analyses OAT / Monte-Carlo basées sur le taux de référence au lieu du taux dégradé | MOYEN | `criteres` passés aux analyses avec `taux` remplacé par `taux_degrade` (mécanique SIM-07 généralisée). |
| `SIM08-A8` | Arrondis : la baisse exacte peut ne pas produire d'écart entier à chaque pas | FAIBLE | Comportement documenté (même convention que SIM-07) ; cas limites choisis hors frontière .5. |

---

## G. Décision et Gate A

- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée depuis
  `configuration`, symétrie SIM-07).
- **Incrément moteur :** passage à la version `1.7` (`VERSION_MOTEUR = '1.7'`).
- **Périmètre :** un paramètre (`taux_degrade` complet), un champ de
  variation (`baisse_passage`) + les cinq champs neutres déjà publiés par
  SIM-07, généralisation de `analyserReussite()`, exclusion mutuelle —
  aucune table.
- **Gate A :** validé. Le besoin, les options, les cas de référence hors
  moteur (rôles inversés du cas A de SIM-07 : simulé `[60,43,36]` +
  sortants `[15,10]` vs référence `[60,37,28]` + sortants `[21,12]`) et la
  non-régression sont maîtrisés. Le sous-LOOP passe en Phase B.
