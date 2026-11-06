# SIM-13 — 03 · Architecture

**Scénario :** SIM-13 — Crise académique combinée
**Statut :** architecture figée — configuration pure, aucune extension moteur,
route, permission ni table nouvelle

---

## A. Principe directeur

SIM-13 se réalise par **configuration du descripteur générique** livré par
SIM-09 (moteur v1.8). Son apport propre est un **contrat de domaine
catalogue** : la combinaison multi-dégradations est validée dans le
catalogue ; l'exécution reste intégralement moteur.

**Corollaire :** le moteur exécute ; le catalogue contraint. En cas de
divergence, l'exécution (moteur) est autoritaire.

---

## B. Architecture de données

### 1. Entrée (paramètres validés)

```
{
  code:             "SIM-13",
  nom:              "Crise académique combinée",
  famille:          "configuration",
  delta_admissions: nombre,
  bornes:           { min, max },
  horizon_annees:   1..6,
  taux:             { passage, redoublement, abandon },          // référence
  taux_modifie:     { passage', redoublement', abandon' },       // simulé
  axe_variation:    passage | redoublement | abandon,
  sens_variation:   hausse | baisse,                             // apparié
  niveau_id?:       int, groupes?: liste
}
```

Aucun champ nouveau : le trio `taux_modifie/axe_variation/sens_variation`
existe depuis SIM-09.

### 2. Sortie (lecture seule)

Métadonnées (`schemaSim13()`) + résultat moteur standard enrichi des clés de
réussite (`totaux_reference_reussite`, `ecarts_reussite_par_periode`,
`sortants_par_periode`, `effet_sortants_cumules`, clé focale de variation).
Seules les 3 tables techniques du module sont écrites.

### 3. État intermédiaire

Purement fonctionnel : paramètres → résultats. Aucune persistance
supplémentaire, aucun cache, aucune migration.

---

## C. Architecture fonctionnelle

```
┌──────────────────────────────────────────────────────────────────────┐
│ Couche catalogue (SIM-13) — NOUVELLE                                 │
│   • CatalogueScenarios::sim13()            — métadonnées            │
│   • CatalogueScenarios::schemaSim13()      — métadonnées de forme   │
│   • CatalogueScenarios::validerReglesSim13() — domaine :            │
│       paire focale dégradante, aucune amélioration, ≥ 2 strictes    │
└─────────────────────────────┬────────────────────────────────────────┘
                              │ paramètres validés
                              ▼
┌──────────────────────────────────────────────────────────────────────┐
│ Couche moteur (ServiceSimulationTemporelle — v1.8, IMMUABLE)         │
│   • descripteur générique taux_modifie/axe/sens (SIM-09)            │
│   • double trajectoire + sortants (SIM-07)                          │
│   • OAT/MC autour du jeu modifié                                    │
└─────────────────────────────┬────────────────────────────────────────┘
                              │ résultats (lecture seule)
                              ▼
┌──────────────────────────────────────────────────────────────────────┐
│ Couche exposition (UI existante — inchangée)                         │
└──────────────────────────────────────────────────────────────────────┘
```

---

## D. Points d'intégration

| Point | Type | Statut SIM-13 |
|-------|------|---------------|
| `CatalogueScenarios` | classe | ajout `sim13()`, `schemaSim13()`, `validerReglesSim13()`, branche, liste, retrait de `planifies()` |
| `ServiceSimulationTemporelle` | classe | **lecture seule (v1.8 conservée)** |
| Routes / API | route | inchangées |
| Permissions | grille | inchangées |
| Tables / Migrations | schéma | aucune |
| UI / Formulaire | vue | inchangée |

---

## E. Contraintes d'architecture imposées

1. **Aucune copie du moteur** — réutilisation exclusive.
2. **Aucun bump `VERSION_MOTEUR`** — 1.8 conservé (idempotence des scénarios).
3. **Aucune permission / route / table nouvelle.**
4. **Le schéma n'épingle pas de paire focale unique** : toute paire
   dégradante est admise ; les règles de domaine font foi.

---

## F. Décisions de conception

- **R1 — Catalogue ≠ moteur** : les règles « crise combinée » vivent dans le
  catalogue (réserve SIM13-R1).
- **R2 — Focal strict** : le moteur exige l'axe focal strictement dégradé ;
  le catalogue le re-vérifie (défense en profondeur).
- **R3 — Aucune amélioration + ≥ 2 strictes** : identité de la « crise
  combinée », distincte de SIM-08/09/11 (une dégradation isolée).
- **R4 — Abandon non réinjecté** : aucun effet causal isolé n'est attribué à
  l'abandon ; le dénominateur de l'effet est le mouvement joint déclaré.
- **R5 — Schéma multi-valeurs** : `axe_variation ∈ {passage, redoublement,
  abandon}` et `sens_variation ∈ {hausse, baisse}` avec appariement vérifié
  par les règles (dégradation uniquement).

---

## G. Non-décisions (hors périmètre)

- Pas de scénario « crise puis récupération » (SIM-06 / CR-05).
- Pas de combinaison avec admissions (gel/choc/cible/creux) — clés inconnues.
- Pas de pondération d'intensité de crise, pas de seuil institutionnel.
- Pas de persistance d'un « profil de crise ».

---

## H. Risques architecturaux

| Risque | Probabilité | Impact | Atténuation |
|--------|-------------|--------|-------------|
| Divergence catalogue/moteur (règles non branchées HTTP) | moyenne | moyen | réserve SIM13-R1 ; bornes dures du moteur conservées |
| Libellés moteur « SIM-09→SIM-12 » pour SIM-13 | certaine | faible | réserve SIM13-R2 (moteur non modifié) |
| Attente d'additivité des effets | moyenne | moyen | test B démontre l'annulation partielle ; documenté |
| Évolution future du descripteur générique | faible | moyen | version moteur tracée ; tests de non-régression |

---

## I. Vérifications d'architecture

- [x] Aucune méthode du moteur modifiée (`VERSION_MOTEUR` = 1.8).
- [x] Aucun `Route::`, aucune permission, aucune `Schema::` ajouté.
- [x] `validerReglesSim13()` ne contient aucun calcul de projection.
- [x] `schemaSim13()` est purement déclaratif.
- [x] `planifies()` passe de 32 à 31 entrées sans modification des autres.
