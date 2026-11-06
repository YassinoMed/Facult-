# SIM-14 — 08 · Guide d'utilisation

**Scénario :** Blocage en L1
**Catalogue :** `GET admin/simulation-temporelle/catalogue` (`can:simulation.view`)

---

## A. Déclaration du scénario

Exemple de requête de définition (via l'interface admin ou l'API) :

```json
{
  "code": "SIM-14-EXEMPLE",
  "libelle": "Blocage en L1 — redoublement accru",
  "domaine": "effectifs_cohortes",
  "faculte_id": "<id de la faculté>",
  "criteres": {
    "delta_admissions": 0.0,
    "bornes": {"min": 0.0, "max": 0.0},
    "horizon_annees": 2,
    "taux": {"passage": 0.6, "redoublement": 0.2, "abandon": 0.2},
    "taux_par_niveau": {
      "L1": {"passage": 0.6, "redoublement": 0.5, "abandon": 0.2}
    },
    "niveau_focal": "L1"
  },
  "contraintes": [],
  "objectifs": [{"type": "stabilite", "poids": 1}]
}
```

Aucune valeur par défaut n'est injectée : chaque taux de la surcharge L1 doit être saisi.

---

## B. Exécution

`POST admin/simulation-temporelle/executer/{id}` avec `annee_universitaire_id`, `graine`, `nb_variantes`.

---

## C. Lecture des résultats

- `totaux` et `totaux_reference_reussite` : trajectoire principale vs témoin.
- `ecarts_par_niveau` : effet de la surcharge par niveau et par pas.
- `niveau_focal` / `ecart_niveau_focal_final` : effet sur le niveau focal.
- `sortants_par_periode` / `effet_sortants_cumules` : impact sur les sortants du périmètre.

Aucune donnée personnelle n'est affichée.
