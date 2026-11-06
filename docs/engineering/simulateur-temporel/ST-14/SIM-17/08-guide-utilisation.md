# SIM-17 — 08 · Guide d'utilisation

**Scénario :** Cohorte exceptionnelle
**Catalogue :** `GET admin/simulation-temporelle/catalogue` (`can:simulation.view`)

## A. Déclaration du scénario

Exemple de requête de définition (une seule cohorte, effectif ≠ référence du niveau d'entrée) :

```json
{
  "code": "SIM-17-EXEMPLE",
  "libelle": "Cohorte exceptionnelle en L1",
  "domaine": "effectifs_cohortes",
  "faculte_id": "<id de la faculté>",
  "criteres": {
    "delta_admissions": 0.0,
    "bornes": {"min": 0.0, "max": 0.0},
    "horizon_annees": 2,
    "taux": {"passage": 0.6, "redoublement": 0.2, "abandon": 0.2},
    "cohortes_identifiees": [
      {"code": "C1", "niveau_entree": "L1", "pas_entree": 1, "effectif": 5}
    ]
  },
  "contraintes": [],
  "objectifs": [{"type": "stabilite", "poids": 1}]
}
```

Aucune valeur par défaut n'est injectée. L'effectif déclaré doit différer de la génération de référence du niveau d'entrée.

## B. Exécution

`POST admin/simulation-temporelle/executer/{id}` avec `annee_universitaire_id`, `graine`, `nb_variantes`.

## C. Lecture des résultats

- `suivi_cohortes` : effectifs identifiés et non identifiés par pas et par niveau.
- `bilan_cohortes` : conservation `effectif_entree = effectif_final + sortants + autres_sorties`.
- `entrees_cohortes_par_periode` / `sortants_cohortes_par_periode`.
- `ecarts_cohortes_par_periode` / `ecart_cohortes_final` : écart à la trajectoire témoin.
- `poids_cohortes_par_periode`, `coexistence_par_periode`, `duree_coexistence`, `pic_coexistence`.

Aucune donnée personnelle n'est affichée.
