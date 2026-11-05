# SIM-03 — 00 · Audit

**Date :** 2026-11-02  
**Scénario :** SIM-03 — Gel des admissions  
**Famille :** 1 — Démographie et admissions  
**Lot :** A (Admissions et démographie)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées (SOCLE-EFFECTIFS, SIM-01 et SIM-02 disponibles ; SIM-03 planifié). |
| **Moteur** | `ServiceSimulationTemporelle` — version `1.1` ; formule actuelle : `admissions = round(baseL1 × (1 + delta)^pas)`. |
| **Profil temporel** | Absent du moteur 1.1 : le delta est purement composé et uniforme sur chaque période ; aucune interruption temporelle modélisée. |
| **Compatibilité** | `extension_temporelle` déclarée dans le catalogue (nécessite des paramètres variables dans le temps). |
| **Adaptateur** | `AdaptateurEffectifsCohortes::etatInitial` — lecture seule sans PII. |
| **Contrôleur** | `SimulationTemporelleController` — 17 routes, middleware `secondaire.verrou`. |
| **Permissions** | 7 permissions `simulation.*` (isolation stricte). |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. |
| **Tests existants** | 79 tests / 731 assertions à 100 % au vert avant ouverture de SIM-03. |

---

## B. État initial de SIM-03 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version | `null` |
| Version formules | `null` |
| Schéma | `null` |
| Disponibilité | `false` (`CatalogueScenarios::estDisponible('SIM-03') === false`) |
| Compatibilité | `extension_temporelle` |
| Dépendances | `['SOCLE-EFFECTIFS']` |

---

## C. Analyse du besoin fonctionnel et écarts du moteur

### C.1 Définition métier du « Gel des admissions »
Dans la gestion universitaire, le gel des admissions est une mesure exceptionnelle d'interruption temporaire des nouvelles admissions (première cohorte entrante, ex: L1) pour une durée déterminée ($D$ années), motivée par :
- une restructuration pédagogique d'un département ;
- des travaux majeurs d'infrastructures ou indisponibilité de locaux ;
- un réajustement budgétaire ou un moratoire d'accréditation ;
- une résorption d'engorgement préalable.

### C.2 Écart technique identifié
- Le moteur `ServiceSimulationTemporelle` applique actuellement une formule de croissance/décroissance uniforme :
  $$admissions_p = \text{round}(baseL1 \times (1 + \delta)^p)$$
- Pour modéliser le gel, le moteur doit être étendu pour supporter un paramètre d'interruption temporelle :
  $$p \le D \implies admissions_p = 0$$
  $$p > D \implies admissions_p = \text{round}(baseL1 \times (1 + \delta)^{p - D})$$
- Cette extension constitue l'activation de la classe de compatibilité `extension_temporelle`.

---

## D. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM03-A1` | Régression sur les scénarios existants (SOCLE, SIM-01, SIM-02) lors de l'ajout du paramètre temporel | HAUTE | Rendre le paramètre `duree_gel_annees` strictement optionnel dans le moteur par défaut (`null`), et ne modifier la formule que s'il est présent. |
| `SIM03-A2` | Incohérence temporelle ($D > H$) | MOYEN | Valider strictement côté catalogue et moteur que $1 \le D \le H$. |
| `SIM03-A3` | Confusion entre gel partiel et gel total | FAIBLE | Documenter formellement : pendant le gel, les admissions sont rigoureusement nulles ($0$). |
| `SIM03-A4` | Vidage artificiel des cohortes sans taux | MOYEN | Imposer les taux de flux (`passage`, `redoublement`, `abandon`) comme obligatoires dans le schéma `SIM-03`. |

---

## E. Décision de compatibilité et Gate A

- **Compatibilité confirmée :** `extension_temporelle`.
- **Incrément moteur :** passage du moteur à la version `1.2` (`VERSION_MOTEUR = '1.2'`).
- **Gate A :** Validé. Le besoin fonctionnel, les formules d'interruption et de reprise, et les garanties de non-régression sont maîtrisés. Le sous-LOOP passe en Phase B (Spécification).

