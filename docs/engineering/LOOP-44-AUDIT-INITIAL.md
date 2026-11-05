# LOOP 44 — AUDIT INITIAL DU RÉFÉRENTIEL ET DE LA GOUVERNANCE RÉGLEMENTAIRE
## UniRegistre — Identification des sources de vérité, règles codées en dur, duplications et dépendances

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Governance Team  
**Statut :** AUDITÉ ET CONFORME (PHASE 1 - LOOP 44)  
**Référence contractuelle :** LOOP 44 — Phase 1 & 10  

---

## 1. Objectifs de l'Audit Initial

L'objectif de cette phase est de recenser exhaustivement toutes les représentations et copies de règles académiques disséminées dans le code, les configurations et les tables de la base de données, afin de définir une **source canonique unique** sans créer de référentiel concurrent redondant.

---

## 2. Inventaire et Analyse des Composants Existants

### 2.1 Fichiers de Configuration et Services Analysés

| Fichier / Service | Rôle Observé | Source Juridique | Risques & Constats Identifiés |
|---|---|---|---|
| [`config/acquis_reglementation.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/config/acquis_reglementation.php) | Référentiel central statique des régimes (`LMD_LICENCE`, `CYCLE_INGENIEUR`, `MASTER_LMD`), seuils par défaut, matrices de formations. | Décret 2022-631 (JORT 80/2022), Arrêté 07/07/2023 (JORT 70/2023), Arrêté 10/05/2023 (JORT 51/2023), Décret 95-2602. | Fichier statique PHP versionné via Git, mais non modifiable dynamiquement par les autorités académiques sans redéploiement applicatif. |
| [`app/Services/Acquis/ReglementationAcquisService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/ReglementationAcquisService.php) | Résolution de régime, évaluation du seuil de compétence, niveau de couverture réglementaire (`FORMATION`, `REGIME`, `AUCUNE`). | Articles 8, 9, 10, 11, 13 et 16 de l'Arrêté du 7 juillet 2023. | Excellent comportement fail-closed (`DomainException` si inconnu). Consomme actuellement `config/acquis_reglementation.php`. |
| [`app/Services/Acquis/ControleDroitsRedoublementService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/ControleDroitsRedoublementService.php) | Contrôle des plafonds de redoublement (L1 : 1 max ; cursus Licence : 2 max ; dérogations du doyen). | Décret 2022-631 Art. 24 & Arrêté 2023 Art. 10. | Dépendance directe aux régimes. Règles bien découplées. |
| [`app/Services/Acquis/MoteurDecisionAcademiqueService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/MoteurDecisionAcademiqueService.php) | Orchestration des décisions de passage, ajournement, rachat, exclusion. | Arrêté 2023 Art. 9 (Compensation), Art. 16 (Rachat discrétionnaire). | Dépend des seuils calculés et refuse tout rachat automatique sans décision explicite du jury. |
| [`app/Services/Acquis/CalculAcquisPedagogiquesService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/CalculAcquisPedagogiquesService.php) | Calcul des moyennes semestrielles/annuelles, capitalisation UE/ECUE, crédits acquis. | Arrêté 2023 Art. 8 & 9. | Calcul déterministe au centième avec tolérance 0.01. |

### 2.2 Tables et Modèles Existants en Base de Données

| Table | Modèle Eloquent | Lignes en Base Locale | Rôle et Statut de Gouvernance |
|---|---|:---:|---|
| `evaluation_regles` | `RegleEvaluation` | 0 | Stocke les paramètres d'exécution opérationnels (seuil validation 10, rattrapage, compensation). Aucune traçabilité officielle (pas de texte, pas de date d'effet, pas de 4 yeux). |
| `parcours_regles` | `ParcoursRegle` | 0 | Règles de cheminement dans les maquettes. |
| `sentinel_regles` | `SentinelRegle` | 0 | Règles de détection d'anomalies de saisie. |
| `regles_academiques_versions` | `RegleAcademiqueVersion` | 0 | **Registre canonique de gouvernance** : Table additive créée en LOOP 44 avec immuabilité, SHA-256, 4 yeux, dates d'effet et références officielles. |

---

## 3. Typologie des Problèmes Identifiés

1. **Règles codées en dur dans les configurations statiques** :
   Les seuils généraux (10/20 pour validation UE, 6/20 pour seuil de compétence en santé/droit) figurent dans `config/acquis_reglementation.php`. Si un arrêté ministériel modifie un seuil pour une cohorte future, modifier le fichier PHP sans versionnage en base risquerait de réécrire l'évaluation des promotions antérieures.
2. **Absence d'historique des modifications dans les tables opérationnelles** :
   La table `evaluation_regles` ne possède pas de système de versionnement immuable : une mise à jour écrase l'enregistrement précédent sans conserver l'auteur ni le texte légal justificatif.
3. **Risque de Conflit Multi-Niveaux** :
   Une maquette de formation (ex. Cycle Ingénieur ENSIT) peut prévoir des conditions plus restrictives que le décret général n° 95-2602. Sans hiérarchie documentée, le système ne doit pas trancher arbitrairement.

---

## 4. Décision d'Architecture : Convergence sans Dédoublement

> [!IMPORTANT]
> **Pas de Second Référentiel Concurrent :**
> - La table additive [`regles_academiques_versions`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/database/migrations/2026_10_31_100000_creer_regles_academiques_versions.php) devient le **registre officiel de gouvernance** d'UniRegistre.
> - Le service [`GouvernanceReglesService`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/GouvernanceReglesService.php) gère le cycle de vie officiel (4 yeux, immuabilité, dates d'effet, checksum).
> - Le service [`ReglementationAcquisService`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/ReglementationAcquisService.php) consomme ce registre via la méthode `consulterRegleVersionnee()` en priorité, en basculant en `REVUE_HUMAINE_REQUISE` en cas d'absence de règle confirmée ou de conflit.

