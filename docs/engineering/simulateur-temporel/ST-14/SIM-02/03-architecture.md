# SIM-02 — 03 · Architecture

**Scénario :** SIM-02 — Baisse des admissions
**Statut :** architecture figée — aucune extension moteur, aucune route, aucune permission, aucune table nouvelle

---

## A. Principe directeur : configuration, pas d'extension

SIM-02 ne modifie **aucune** couche d'exécution du moteur. Son apport se limite à :

1. une entrée de **catalogue** (méta-description + schéma + règles de domaine),
2. une **validation** de la demande,
3. une **exécution** du moteur canonique avec des paramètres autorisés.

Le moteur `ServiceSimulationTemporelle` n'est ni copié, ni dérivé, ni patché. Toute
modification de son comportement est hors périmètre SIM-02 et doit être tracée
séparément (`MTR-*`).

**Corollaire :** le catalogue SIM-02 n'est pas le moteur. Le catalogue décrit ;
`ServiceSimulationTemporelle` exécute. En cas de divergence, l'exécution est
autoritaire.

---

## B. Architecture de données

### 1. Entrée (paramètres)

```
{
  code:             "SIM-02",
  nom:              "Baisse des admissions",
  famille:          "configuration",
  delta_admissions: [-0.5, 0[  (strictement négatif),
  bornes:           { min: -0.5..delta, max: delta..0 },
  horizon_annees:   1..6 (PLAFOND_HORIZON),
  taux:             {
    passage:        0..1,
    redoublement:   0..1,
    abandon:        0..1,
    passage + redoublement + abandon <= 1 (tolérance 1.0001)
  },
  niveau_id?:       int (périmètre restreint),
  groupes?:         int[] (exige niveau_id),
  graine?:          string (facultatif, idempotence)
}
```

Aucun nouveau champ n'est ajouté au payload du moteur : `delta_admissions`,
`bornes`, `horizon_annees`, `taux` existaient déjà (schéma SIM-01).

### 2. Sortie (lecture seule)

Le catalogue ne produit **que** des métadonnées (`schemaSim02()`). La simulation
elle-même est renvoyée par le moteur — les 3 tables techniques du module seules
sont écrites.

Aucune table n'est ajoutée. Aucune migration.

### 3. État intermédiaire

L'état est purement fonctionnel : paramètres → résultats. Pas de persistance
SCÉNARIO par SCÉNARIO (pas de ligne `simulation_scenarios` dédiée), pas de cache
permanent, pas de fichier de registre.

---

## C. Architecture fonctionnelle

```
┌──────────────────────────────────────────────────────────────────────┐
│ Couche catalogue (SIM-02)                                            │
│   • CatalogueScenarios::schemaSim02() — métadonnées                  │
│   • CatalogueScenarios::validerReglesSim02() — validation domaine    │
│   • SIM02_SCENARIO_REGISTRY — signature unique                      │
└─────────────────────────────┬────────────────────────────────────────┘
                              │ paramètres validés
                              ▼
┌──────────────────────────────────────────────────────────────────────┐
│ Couche moteur (ServiceSimulationTemporelle — V1.1, immuable)         │
│   • calculAdmissionsComposées()  — admissions(pas n)                 │
│   • projetterEfectifs()          — passage / redoublement / abandon  │
│   • applyTauxTransition()        — conservation des niveaux          │
│   • VERSION_MOTEUR = 1.1 — inchangé                                 │
└─────────────────────────────┬────────────────────────────────────────┘
                              │ résultats (lecture seule)
                              ▼
┌──────────────────────────────────────────────────────────────────────┐
│ Couche exposition (UI existante)                                      │
│   • Formulaire web : delta ∈ [-0.5, 2.0] (déjà existant)             │
│   • Aucune nouvelle vue / route / permission requise                 │
└──────────────────────────────────────────────────────────────────────┘
```

---

## D. Points d'intégration

| Point | Type | Statut SIM-02 |
|-------|------|---------------|
| `CatalogueScenarios` | classe | ajout entrée + méthode |
| `ServiceSimulationTemporelle` | classe | **lecture seule** |
| Routes web | route | inchangées |
| Routes API v1 | route | inchangées |
| Permissions | Grille | inchangées |
| Tables DB | schéma | aucune modification |
| Migrations | migration | aucune |
| Événements/Logs | logging | inchangés (voir 05-securite.md) |
| UI / Formulaire | vue | inchangée (delta déjà supporté) |

---

## E. Contraintes d'architecture imposées

1. **Aucune copie du moteur** — réutilisation exclusive de `ServiceSimulationTemporelle`.
2. **Aucun bump `VERSION_MOTEUR`** — 1.1 conservé (idemempotence).
3. **Aucune permission nouvelle** — la grille SIM-01 reste valable.
4. **Aucune route nouvelle** — le formulaire existant suffit.
5. **Aucune table/migration nouvelle** — seules les 3 tables techniques existantes.
6. **Formulaire existant** accepte déjà `delta ∈ [-0.5, 2.0]` → SIM-02 est un
   sous-ensemble immédiat de cette plage.

---

## F. Décisions de conception

- **R1 — Catalogue ≠ moteur** : le catalogue est une couche de métadonnées et de
  validation ; l'exécution reste exclusivement moteur.
- **R2 — delta strictement négatif dans le catalogue, négatif accepté par le moteur** :
  le moteur est neutre (accepte `delta < 0`), la règle « baisse uniquement » vit
  dans le catalogue (réserve SIM02-R1).
- **R3 — taux REQUIS pour SIM-02** : sans `passage/redoublement/abandon` le moteur
  remet les cohortes à zéro (anomalie héritée de SIM-01, réserves SIM01-R4/SIM02-R3).
- **R4 — baseL1 = reset($cohortes)** : la base d'admissions est l'effectif du
  premier niveau observé. Ce n'est pas un paramètre : la base suit le
  périmètre simulé (`niveau_id` / `groupes`).
- **R5 — capacité par défaut 35** : héritée de SIM-01, pas de règle institutionnelle.

---

## G. Non-décisions (explicitement hors périmètre)

- Pas de modélisation des **diplômés** en tant qu'entité (sorties déductibles).
- Pas de **coûts** par niveau / par abandon.
- Pas de **besoins de locaux**, de **charge pédagogique** ou d'**emplois du temps**.
- Pas d'**interaction entre scénarios** (chaque exécution est isolée).
- Pas d'**historique des exécutions** (pas de tableau de bord temporel).
- Pas de **sensibilité** à `delta = 0` (SIM-01) ou `delta = +0` (gel SIM-03).

---

## H. Risques architecturaux

| Risque | Probabilité | Impact | Atténuation |
|--------|-------------|--------|-------------|
| Divergence catalogue/moteur | faible | moyen | le catalogue est généré à partir des signatures du moteur ; la validation SIM-02 est un sous-ensemble de celle du moteur |
| Évolution delta moteur hors [-0.5, 2.0] | faible | moyen | la borne `-0.5` est dure dans le moteur ; le catalogue refuse `delta < -0.5` |
| Oubli du paramètre `taux` → cohortes à zéro | moyen | élevé | validation catalogue exige `passage/redoublement/abandon` ; tests assertent cohérence |
| Fuite vers une copie du moteur | faible | élevé | revue de code obligatoire ; périmètre SIM-02 dans le registre |

---

## I. Vérifications d'architecture

- [x] Aucune méthode du moteur n'est appelée hors `ServiceSimulationTemporelle`.
- [x] `VERSION_MOTEUR` reste `1.1` après SIM-02.
- [x] Aucun `Route::`, aucune définition de `permission`, aucune `Schema::create/modify`.
- [x] `validerReglesSim02()` ne contient aucune logique de calcul.
- [x] `schemaSim02()` ne contient aucune logique de calcul.

---

*Document de référence : `docs/engineering/simulateur-temporel/ST-14/SIM-02/03-architecture.md`*
*Version : 1.0 — figé après validation Lot 0 + SIM-01*
