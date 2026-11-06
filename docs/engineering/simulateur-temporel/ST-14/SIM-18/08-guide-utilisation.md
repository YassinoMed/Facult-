# SIM-18 — 08 · Guide d'utilisation

**Scénario :** Double génération
**Catalogue :** `GET admin/simulation-temporelle/catalogue` (`can:simulation.view`)

## A. Déclaration du scénario

Exemple de requête de définition (≥ 2 cohortes, même niveau, pas distincts, chaque effectif ≥ référence du niveau) :

```json
{
  "code": "SIM-18-EXEMPLE",
  "libelle": "Double génération en L1",
  "domaine": "effectifs_cohortes",
  "faculte_id": "<id de la faculté>",
  "criteres": {
    "delta_admissions": 0.0,
    "bornes": {"min": 0.0, "max": 0.0},
    "horizon_annees": 2,
    "taux": {"passage": 0.6, "redoublement": 0.2, "abandon": 0.2},
    "cohortes_identifiees": [
      {"code": "C1", "niveau_entree": "L1", "pas_entree": 1, "effectif": 12},
      {"code": "C2", "niveau_entree": "L1", "pas_entree": 2, "effectif": 10}
    ]
  },
  "contraintes": [],
  "objectifs": [{"type": "stabilite", "poids": 1}]
}
```

Aucune valeur par défaut n'est injectée.

## B. Exécution

`POST admin/simulation-temporelle/executer/{id}` avec `annee_universitaire_id`, `graine`, `nb_variantes`.

## C. Lecture des résultats

- `suivi_cohortes` : effectifs identifiés et non identifiés par pas et par niveau.
- `bilan_cohortes` : conservation par cohorte `effectif_entree = effectif_final + sortants + autres_sorties`.
- `coexistence_par_periode` : cohortes simultanées par niveau et par pas.
- `duree_coexistence`, `pic_coexistence` : durée cumulée et pic de simultanéité.
- `ecarts_cohortes_par_periode` / `ecart_cohortes_final` : écart à la trajectoire témoin.

Aucune donnée personnelle n'est affichée.
