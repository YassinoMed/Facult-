# SIM-02 — 00 · Audit

**Date :** 2026-11-02
**Scénario :** SIM-02 — Baisse des admissions
**Famille :** 1 — Démographie et admissions
**Mode :** audit préalable, aucune modification fonctionnelle avant ce document

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| Catalogue | `CatalogueScenarios` — 45 entrées avant ouverture |
| Moteur | `ServiceSimulationTemporelle` — `VERSION_MOTEUR = 1.1` |
| Génération de variantes | `GenerateurVariantes::deltasAdmissions` |
| Adaptateur | `AdaptateurEffectifsCohortes::etatInitial` |
| Contrôleur | `SimulationTemporelleController` |
| Routes | 17 routes `admin/simulation-temporelle/*` |
| Permissions | 7 permissions `simulation.*` |
| Tables techniques | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions` |
| Vue | `resources/views/modules/simulation-temporel/index.blade.php` |
| Tests | 71 tests / 660 assertions au vert avant modification |

---

## B. État initial de SIM-02

| Point | Valeur |
|---|---|
| Statut catalogue | `planifie` |
| Version | `null` |
| Version des formules | `null` |
| Schéma | `null` |
| Disponibilité | `false` |
| Règle de domaine | absente |
| Dépendances | aucune |
| Compatibilité préliminaire | `configuration` |

Le test `SimulationTemporelleCatalogueTest` vérifiait explicitement que
SIM-02 était indisponible. Cette assertion est une **caractéristique du
contrat ST-14.00**, pas une anomalie : elle doit évoluer avec l'ouverture
du scénario.

---

## C. Capacités du moteur réutilisables

### C.1. Delta négatif déjà supporté

`ServiceSimulationTemporelle::BORNE_DELTA_MIN = -0.5` et
`validerCriteres()` acceptent un delta négatif dans les bornes. Aucune
modification du moteur n'est nécessaire pour SIM-02.

### C.2. Formule existante

```text
admissions(pas n) = round(baseL1 × (1 + delta)^n)
```

Avec `delta < 0`, la trajectoire est décroissante. Le moteur utilise déjà
`round`, donc les admissions restent entières.

### C.3. Non-négativité

`baseL1 ≥ 0` (effectif observé) et `1 + delta ≥ 0.5` (borne moteur
`−0.5`), donc `admissions ≥ 0` par construction. Une valeur nulle reste
possible par arrondi sur les petits effectifs.

### C.4. Progression et conservation

Le moteur applique les taux `passage`, `redoublement`, `abandon` et
plafonne les redoublants pour éviter de créer une population. La
conservation est vérifiable sur les snapshots.

---

## D. Écarts et risques identifiés

| ID | Constat | Gravité | Traitement |
|---|---|---|---|
| `SIM02-A1` | Le moteur accepte aussi un delta positif : la règle « baisse uniquement » ne peut vivre que dans le catalogue | MOYEN | Documenter comme réserve `SIM02-R1` ; le runtime reste borné par le moteur |
| `SIM02-A2` | `baseL1 = reset($cohortes)` : si le premier niveau est vide, la baisse ne produit aucune admission | MOYEN | Réserve `SIM02-R3` (héritée SIM01-R3) |
| `SIM02-A3` | Taux globaux identiques pour tous les niveaux | MOYEN | Réserve `SIM02-R4` |
| `SIM02-A4` | Capacité par défaut 35 si aucun groupe réel | MOYEN | Réserve `SIM02-R5` |
| `SIM02-A5` | Aucun écran dédié SIM-02 : le formulaire existant accepte déjà `delta ∈ [-0.5, 2.0]` | FAIBLE | Réserve `SIM02-R6` |
| `SIM02-A6` | Une baisse extrême peut produire 0 admission par arrondi, sans être un gel explicite | FAIBLE | Documenté dans les hypothèses |
| `SIM02-A7` | La règle catalogue n'est pas branchée sur la création HTTP | MOYEN | Réserve `SIM02-R1` (héritée L0-R1 / SIM01-R6) |

---

## E. Décision de compatibilité

**`configuration` confirmée.**

- Aucune formule nouvelle.
- Aucun paramètre nouveau.
- Aucune table nouvelle.
- Aucune route nouvelle.
- Aucune permission nouvelle.
- `VERSION_MOTEUR` reste `1.1` (pas de bump, idempotence préservée).

---

## F. Gate A

Le périmètre réel est compris, les dépendances sont nulles et le moteur
n'a pas à être étendu. Le sous-LOOP peut passer en spécification.
