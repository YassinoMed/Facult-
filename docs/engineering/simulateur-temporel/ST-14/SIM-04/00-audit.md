# SIM-04 — 00 · Audit

**Date :** 2026-11-03  
**Scénario :** SIM-04 — Admissions exceptionnelles  
**Famille :** 1 — Démographie et admissions  
**Lot :** A (Admissions et démographie)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées (SOCLE-EFFECTIFS, SIM-01, SIM-02 et SIM-03 disponibles ; SIM-04 planifié). |
| **Moteur** | `ServiceSimulationTemporelle` — version `1.2` ; formule de fond : `admissions = round(baseL1 × (1 + delta)^pas)`, avec gel optionnel `duree_gel_annees`. |
| **Profil temporel** | Le moteur 1.2 ne connaît que des trajectoires **continues** (croissance/décroissance composée) ou une **interruption** (gel). Aucun choc additif ponctuel n'est modélisable. |
| **Compatibilité** | `extension_temporelle` déclarée dans le catalogue (paramètre agissant sur un pas précis). |
| **Adaptateur** | `AdaptateurEffectifsCohortes::etatInitial` — lecture seule, sans PII, filtre `niveau_id` / `groupes`. |
| **Contrôleur** | `SimulationTemporelleController` — 17 routes, middleware `secondaire.verrou`. |
| **Permissions** | 7 permissions `simulation.*` (isolation stricte). |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. |
| **Tests existants** | **87 tests / 800 assertions** à 100 % au vert (vérifié indépendamment avant ouverture). |

---

## B. État initial de SIM-04 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version | `null` |
| Version formules | `null` |
| Schéma | `null` |
| Disponibilité | `false` (`CatalogueScenarios::estDisponible('SIM-04') === false`) |
| Compatibilité | `extension_temporelle` |
| Dépendances | `[]` (aucune dépendance déclarée) |

---

## C. Analyse du besoin fonctionnel et écarts du moteur

### C.1 Définition métier des « admissions exceptionnelles »

Les admissions exceptionnelles désignent un **afflux ponctuel** d'étudiants
en première année, distinct de la trajectoire de fond :

- transferts autorisés depuis une autre établissement ou une autre filière ;
- contingent rectoral supplémentaire accordé une année donnée ;
- retour d'étudiants suspendus ou en réorientation ;
- convention de partenariat produisant une promotion unique ;
- décision de rattrapage après une année de sous-remplissage.

Le besoin métier est double :
1. mesurer l'effet **immédiat** du choc sur les effectifs et les capacités ;
2. vérifier que la trajectoire des années suivantes **n'est pas durablement
   déformée** (le choc ne se compose pas dans le taux de croissance).

### C.2 Écart technique identifié

Le moteur applique une trajectoire uniforme. Deux options étaient possibles :

- **option rejetée** : majorer `delta_admissions` pour l'année du choc — cela
  modifierait la trajectoire de fond et composerait le choc sur toutes les
  périodes suivantes, contrairement au besoin ;
- **option retenue** : ajouter un terme additif **ponctuel** au pas déclaré,
  sans toucher au calcul de référence :
  $$admissions_p = \text{round}(baseL1 \times (1+\delta)^p) + C \cdot \mathbf{1}[p = p^*]$$

Cette extension active la classe `extension_temporelle` sans nouvelle formule
de progression ni nouvelle structure de données.

---

## D. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM04-A1` | Régression sur SOCLE, SIM-01, SIM-02, SIM-03 si le choc s'applique par défaut | HAUTE | Les deux paramètres sont strictement optionnels (`null` par défaut) ; la formule antérieure reste inchangée en leur absence. |
| `SIM04-A2` | Choc appliqué hors horizon (jamais visible) ou pas 0 | MOYEN | Validation stricte `1 ≤ pas_exceptionnel ≤ horizon` dans le catalogue **et** dans le moteur. |
| `SIM04-A3` | Choc nul ou négatif déguisé en trajectoire de fond | MOYEN | `admissions_exceptionnelles ≥ 1` ; un choc nul est refusé avec message explicite. |
| `SIM04-A4` | Paramètres dissociés (pas fourni sans choc, ou inverse) | MOYEN | Paire indissociable vérifiée dans les deux validateurs. |
| `SIM04-A5` | Choc confondu avec une croissance durable | FAIBLE | Hypothèse explicite « PONCTUEL » dans le schéma, la documentation et les tests. |

---

## E. Décision de compatibilité et Gate A

- **Compatibilité confirmée :** `extension_temporelle`.
- **Incrément moteur :** passage à la version `1.3` (`VERSION_MOTEUR = '1.3'`).
- **Gate A :** validé. Le besoin, la formule additive, les bornes et la
  non-régression sont maîtrisés. Le sous-LOOP passe en Phase B.
