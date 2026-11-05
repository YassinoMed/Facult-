# SIM-01 — 01 · Spécification fonctionnelle

**Scénario :** SIM-01 — Croissance des admissions
**Catégorie :** `admissions_demographie`
**Lot :** A
**Version de scénario :** 1
**Version de formules :** 1.0
**Statut :** `disponible`
**Compatibilité confirmée :** `configuration`
**Dépendance :** `SOCLE-EFFECTIFS`

---

## B.1 Objectif

Projeter une **croissance annuelle des nouvelles admissions** et mesurer ses
effets sur :

- les effectifs par période et le total final ;
- les admissions datées (pas 1..horizon) ;
- l'écart à une cible déclarée ;
- les groupes théoriquement requis et les places manquantes ;
- les alertes de variation et de saturation.

Le scénario ne modifie **aucune** inscription, aucun groupe, aucune
affectation : il produit une projection signée et persistée dans les tables
techniques du module.

---

## B.2 Acteurs et permissions

| Action | Permission | Route |
|---|---|---|
| Consulter le catalogue | `simulation.view` | `GET admin/simulation-temporelle/catalogue` |
| Créer un scénario | `simulation.gerer` | formulaire existant |
| Exécuter | `simulation.executer` | `POST .../scenarios/{id}/executer` (throttle 10/min) |

Le scénario est exécutable sur un périmètre **global** ou **facultaire**. Un
utilisateur hors périmètre reçoit `403` avant tout calcul.

---

## B.3 Entrées

Schéma publié par `CatalogueScenarios::schemaSim01()` — identique au socle,
sauf `taux` **requis**.

| Paramètre | Type | Requis | Bornes | Unité |
|---|---|---|---|---|
| `delta_admissions` | number | oui | `[-0.5, 2.0]` + règle SIM-01 `≥ 0` | ratio annuel composé |
| `bornes.min` | number | oui | `[-0.5, 2.0]` + règle SIM-01 `≥ 0` | — |
| `bornes.max` | number | oui | `[-0.5, 2.0]` | — |
| `horizon_annees` | integer | oui | `[1, 6]` | années |
| `taux.passage` | number | **oui** | `[0, 1]` | proportion |
| `taux.redoublement` | number | **oui** | `[0, 1]` | proportion |
| `taux.abandon` | number | **oui** | `[0, 1]` | proportion |
| `niveau_id` | integer | non | `≥ 1` | identifiant |
| `groupes[]` | integer | non | `≥ 1` | identifiants |

### Contraintes

1. `bornes.min ≤ bornes.max`.
2. `delta_admissions ∈ [bornes.min, bornes.max]`.
3. `passage + redoublement + abandon ≤ 1` (tolérance d'arrondi `1e-4`).
4. `groupes` interdit sans `niveau_id`.
5. **Règle SIM-01 :** `delta_admissions ≥ 0` et `bornes.min ≥ 0`.
   Toute baisse relève de SIM-02 ; tout gel explicite relève de SIM-03.
6. Aucune valeur par défaut n'est ajoutée : le catalogue restitue les
   paramètres **à l'identique**.

### Paramètres d'exécution (hors schéma)

| Paramètre | Valeurs | Rôle |
|---|---|---|
| `annee_universitaire_id` | année existante | source des effectifs observés |
| `graine` | chaîne ≤ 64 | déterminisme des variantes et du hash |
| `nb_variantes` | `[1, 20]` | variantes simulées |

---

## B.4 Sorties

| Indicateur | Contenu |
|---|---|
| `admissions_par_periode` | `[{pas, admissions}]` pour `pas = 1..horizon` |
| `totaux` | effectif total pour `pas = 0..horizon` (pas 0 = état initial) |
| `total_final` | dernier total projeté |
| `variations_annuelles` | variation relative entre pas successifs |
| `comparaison.lignes[]` | année, effectif projeté, écart absolu/relatif, écart à la cible |
| `capacites` | groupes requis, déficit, saturation, places manquantes |
| `alertes_seuils` | alertes de variation et de saturation |
| `classement` / `non_conformes` | variantes conformes classées, non conformes séparées |
| `hash_entrees` / `hash_resultat` | traçabilité et idempotence |
| `version_moteur` | `1.1` |

---

## B.5 Cas couverts

- croissance modérée, forte, nulle (`delta = 0`) ;
- horizon minimal (1) et maximal (6) ;
- périmètre facultaire complet ou restreint à un niveau ;
- présence ou absence de groupes réels ;
- cible déclarée ou absente ;
- contraintes `plafond_total` / `plancher_total` (moteur existant).

## B.6 Cas explicitement non applicables

| Cas | Motif |
|---|---|
| Baisse des admissions | SIM-02 |
| Gel explicite des admissions | SIM-03 |
| Taux différenciés par niveau | extension LOT B |
| Capacité datée / perte temporaire | extension LOT C |
| Répartition par groupe | extension LOT C |
| Budget paramétrable | extension LOT D |
| Analyse probabiliste / stress test | LOT F |

---

## B.7 Critères d'acceptation

1. Les admissions par période sont exposées dans le résultat public de chaque
   variante classée ou non conforme.
2. Les valeurs correspondent à un calcul indépendant, documenté dans les
   tests (aucune réutilisation de la formule du moteur dans les assertions).
3. `delta = 0` produit des admissions constantes et conserve les effectifs
   selon la convention du moteur.
4. Les taux sont obligatoires au niveau du catalogue, et un taux partiel est
   refusé.
5. Un delta négatif est refusé par le contrat SIM-01.
6. Deux exécutions avec les mêmes entrées produisent la même exécution
   (idempotence) et le même hash.
7. Aucune table métier n'est modifiée.
8. Un utilisateur sans permission ou hors périmètre est refusé.
9. La suite historique reste verte.
