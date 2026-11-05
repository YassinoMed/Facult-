# SIM-01 — 08 · Guide d'utilisation

**Scénario :** SIM-01 — Croissance des admissions
**Public :** administrateur scolarité, admin faculté, analyste
**Interface :** catalogue JSON + formulaire d'exécution existant (aucun écran
dédié SIM-01)

---

## H.1 Prérequis

| Élément | Valeur |
|---|---|
| Module actif | `modules.simulation-temporelle.active = true` |
| Permissions | `simulation.view`, `simulation.gerer`, `simulation.executer` |
| Année source | une `annee_universitaire` avec inscriptions validées |
| Périmètre | global ou faculté autorisée |

Sans permission, l'API renvoie `403`. Un scénario facultaire hors périmètre est
refusé avant calcul.

---

## H.2 Étape 1 — Vérifier le contrat disponible

```http
GET /admin/simulation-temporelle/catalogue
```

Réponse (extrait) :

```json
{
  "contrat_version": "1.0",
  "version_moteur": "1.1",
  "scenarios": [
    {"code": "SOCLE-EFFECTIFS", "statut": "disponible"},
    {
      "code": "SIM-01",
      "statut": "disponible",
      "version": 1,
      "version_formules": "1.0",
      "compatibilite": "configuration",
      "schema": {
        "taux": {"requis": true}
      }
    }
  ]
}
```

`SIM-01` est le seul scénario de croissance disponible. Les 43 autres restent
`planifie` et ne sont pas exécutables.

---

## H.3 Étape 2 — Créer le scénario

```http
POST /admin/simulation-temporelle/scenarios
```

```json
{
  "code": "CROISSANCE-2027",
  "libelle": "Croissance des admissions 2027",
  "faculte_id": 1,
  "criteres": {
    "delta_admissions": 0.1,
    "bornes": {"min": 0.0, "max": 1.0},
    "horizon_annees": 3,
    "taux": {
      "passage": 0.7,
      "redoublement": 0.2,
      "abandon": 0.05
    }
  },
  "contraintes": [
    {"type": "plafond_total", "valeur": 5000}
  ],
  "objectifs": [
    {"type": "cible_total", "cible": 1500, "poids": 1.0}
  ]
}
```

### Règles à respecter

- `delta_admissions ≥ 0` et `bornes.min ≥ 0` : une baisse se traite avec
  SIM-02, un gel avec SIM-03.
- Les trois taux sont **obligatoires**. Un taux partiel est refusé.
- `passage + redoublement + abandon ≤ 1`.
- `horizon_annees ∈ [1, 6]`.
- `niveau_id` est optionnel ; `groupes` n'est autorisé qu'avec `niveau_id`.

---

## H.4 Étape 3 — Exécuter

```http
POST /admin/simulation-temporelle/scenarios/{id}/executer
```

```json
{
  "annee_universitaire_id": 1,
  "graine": "campagne-2027",
  "nb_variantes": 3
}
```

Réponse (extrait) :

```json
{
  "execution": {
    "id": 12,
    "version_moteur": "1.1",
    "resultat": {
      "etat_initial": {"cohortes": {"L1": 10, "L2": 20, "L3": 30}, "total_initial": 60},
      "classement": [
        {
          "index": 0,
          "delta": 0.1,
          "total_final": 96,
          "totaux": [60, 71, 83, 96],
          "admissions_par_periode": [
            {"pas": 1, "admissions": 11},
            {"pas": 2, "admissions": 12},
            {"pas": 3, "admissions": 13}
          ]
        }
      ]
    }
  },
  "idempotente": "nouvelle"
}
```

---

## H.5 Lire les résultats

| Champ | Lecture |
|---|---|
| `admissions_par_periode` | flux d'entrées simulé, année par année |
| `totaux` | effectif total, pas 0 = état initial |
| `total_final` | dernier total projeté |
| `variations_annuelles` | vitesse de croissance entre deux pas |
| `comparaison.lignes[].ecart_cible` | écart signé à la cible déclarée |
| `capacites.groupes_theoriques_requis_total` | groupes nécessaires |
| `capacites.deficit_groupes` | groupes manquants |
| `capacites.places_manquantes` | places manquantes |
| `capacites.capacite_groupe_hypothese` | `true` = capacité par défaut 35, non mesurée |
| `alertes_seuils` | variation > 15 % ou saturation > 95 % (seuils moteur) |

### Précautions de lecture

1. `admissions_par_periode` ne contient pas le pas 0.
2. La capacité unitaire est issue des groupes réels du périmètre ; à défaut,
   elle vaut `35` et l'hypothèse est signalée.
3. Les taux sont globaux : aucune différenciation L1/L2/L3 dans SIM-01.
4. Les seuils d'alerte sont des valeurs du moteur, **non institutionnelles**.
5. Une exécution identique (mêmes entrées, même graine) renvoie l'exécution
   existante : le résultat est figé, pas recalculé.

---

## H.6 Erreurs fréquentes

| Message | Cause | Correction |
|---|---|---|
| `Paramètre requis absent : parametres.taux` | taux non fournis | fournir les trois taux |
| `SIM-01 (croissance) : delta_admissions négatif refusé` | delta < 0 | utiliser SIM-02 |
| `SIM-01 (croissance) : borne minimale négative refusée` | `bornes.min < 0` | mettre `min ≥ 0` |
| `passage + redoublement + abandon > 1` | taux incohérents | réduire la somme à ≤ 1 |
| `Valeur hors bornes ... horizon` | horizon > 6 | réduire l'horizon |
| `403` | permission ou périmètre | demander l'habilitation |

---

## H.7 Bonnes pratiques

- Fixer la graine pour toute comparaison de campagnes.
- Utiliser un `niveau_id` et des groupes réels lorsque la question porte sur
  une capacité précise.
- Ne pas présenter les alertes 15 % / 95 % comme des normes institutionnelles.
- Pour une baisse ou un gel, ne pas forcer SIM-01 : utiliser le scénario dédié.
- Conserver `hash_entrees` et `hash_resultat` dans tout rapport.
