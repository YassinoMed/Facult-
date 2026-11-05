# SIM-01 — 03 · Architecture et intégration

**Scénario :** SIM-01 — Croissance des admissions
**Compatibilité :** `configuration`
**Version moteur :** `1.1`
**Contrat catalogue :** `1.0`

---

## D.1 Vue d'ensemble

```
CatalogueScenarios (métadonnées)
   │  schemaSim01(), validerParametres(), empreinteScenario()
   ▼
Formulaire / API existants
   │  scénario persisté : criteres, contraintes, objectifs
   ▼
ServiceSimulationTemporelle (moteur autoritaire)
   │  validerCriteres → adapter → simulerVariante → snapshots
   │  → classement → comparaison → capacités → alertes → hash
   ▼
Résultat public (JSON)
   │  admissions_par_periode, totaux, total_final, ...
   ▼
Tables techniques : executions_temporelles + snapshots_executions
```

Le catalogue ne calcule **rien**. Le service ne lit **pas** le catalogue. Le
lien est un contrat de définition : le scénario est construit avec les
paramètres validés par le catalogue, puis exécuté par le moteur.

---

## D.2 Contrat de sortie

Chaque entrée de `classement` et de `non_conformes` expose :

```json
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
```

- `classement` ajoute `score` et `contributions`.
- `non_conformes` ajoute `violations`.
- `mesures` (interne) porte les mêmes champs, consommés par l'explicateur,
  le Monte-Carlo et la sensibilité.

---

## D.3 Versionnement

| Élément | Valeur | Raison |
|---|---|---|
| `VERSION_MOTEUR` | `1.1` | Nouveau champ public `admissions_par_periode` |
| `version_formules` | `1.0` | Aucune formule modifiée |
| `version` du scénario | `1` | Première définition SIM-01 |
| `VERSION_CONTRAT` | `1.0` | Contrat catalogue inchangé |

`hash_entrees` inclut `version_moteur`. Conséquence assumée : les mêmes
entrées exécutées avant et après le passage à `1.1` produisent deux
exécutions distinctes. C'est le comportement attendu d'un changement de forme
de résultat.

---

## D.4 Déterminisme et idempotence

1. Les variantes sont générées par `GenerateurVariantes` à partir de la graine
   (`sha256(graine:i)`), sans aléatoire système.
2. `hash_entrees` couvre scénario, version, critères, périmètre, graine et
   version moteur.
3. Le service recherche une exécution existante par `hash_entrees` ; si elle
   existe, il la retourne sans réécrire les snapshots.
4. `hash_resultat` couvre le résultat complet, snapshots inclus.
5. La variante 0 est toujours le delta saisi ; les variantes suivantes sont
   déterministes.

---

## D.5 Gestion des erreurs

| Situation | Réponse |
|---|---|
| Paramètre catalogue manquant / inconnu / hors bornes | `DomainException` |
| Delta négatif pour SIM-01 | `DomainException` explicite (« utiliser SIM-02 ») |
| Taux partiel | `DomainException` (« Paramètre requis absent : parametres.taux.abandon ») |
| Année source inconnue | `DomainException` |
| Exécution HTTP | `422` avec `{"erreur": "..."}` |
| Permission absente | `403` |
| Scénario facultaire hors périmètre | `403` avant calcul |

---

## D.6 Absence de duplication

| Élément | Réutilisation |
|---|---|
| Formule d'admissions | `simulerVariante` uniquement |
| Progression | `simulerVariante` uniquement |
| Capacités | `EstimateurCapacitesGroupes` |
| Écart à la cible | `ComparateurProjections` |
| Alertes | `GestionnaireAlertesSeuils` |
| Déterminisme | `GenerateurVariantes` + hash du service |
| Persistance | 3 tables existantes |

Aucun service, repository, table, migration ou formule n'a été ajouté.

---

## D.7 Point d'extension retenu

L'ajout est **additif** : un champ dans `mesures`, propagé dans `classement`
et `non_conformes`. Les consommateurs existants (explicateur, Monte-Carlo,
sensibilité, export) continuent de fonctionner ; ils ignorent simplement le
nouveau champ ou le relaient sans le transformer.

---

## D.8 Verdict de phase

**Conception : PASS.** Le contrat est unique, versionné, déterministe et sans
duplication. La seule dette est l'anomalie socle R1 (taux absents), contournée
par le schéma SIM-01 sans être corrigée dans le moteur.
