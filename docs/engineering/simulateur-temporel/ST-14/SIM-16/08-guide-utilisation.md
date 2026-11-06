# SIM-16 — 08 · Guide d'utilisation

**Scénario :** Flux déséquilibrés entre niveaux
**Catalogue :** `GET admin/simulation-temporelle/catalogue` (`can:simulation.view`)

## A. Déclaration du scénario

Exemple de requête de définition (≥ 2 niveaux, ≥ 2 triplets distincts) :

```json
{
  "code": "SIM-16-EXEMPLE",
  "libelle": "Déséquilibre multi-niveaux",
  "domaine": "effectifs_cohortes",
  "faculte_id": "<id de la faculté>",
  "criteres": {
    "delta_admissions": 0.0,
    "bornes": {"min": 0.0, "max": 0.0},
    "horizon_annees": 2,
    "taux": {"passage": 0.6, "redoublement": 0.2, "abandon": 0.2},
    "taux_par_niveau": {
      "L1": {"passage": 0.5, "redoublement": 0.3, "abandon": 0.2},
      "L2": {"passage": 0.7, "redoublement": 0.1, "abandon": 0.2}
    }
  },
  "contraintes": [],
  "objectifs": [{"type": "stabilite", "poids": 1}]
}
```

Aucune valeur par défaut n'est injectée.

## B. Exécution

`POST admin/simulation-temporelle/executer/{id}` avec `annee_universitaire_id`, `graine`, `nb_variantes`.

## C. Lecture des résultats

- `totaux` et `totaux_reference_reussite` : trajectoire principale vs témoin.
- `ecarts_par_niveau` : effet de chaque surcharge par niveau et par pas.
- `niveau_focal` / `ecart_niveau_focal_final` (si déclaré).
- `sortants_par_periode` / `effet_sortants_cumules` : impact sur les sortants.

Aucune donnée personnelle n'est affichée.
