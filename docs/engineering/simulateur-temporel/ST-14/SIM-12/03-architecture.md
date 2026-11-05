# SIM-12 — 03 · Architecture et conception

**Date :** 2026-11-05  
**Moteur :** `ServiceSimulationTemporelle` v`1.8` (inchangé)  
**Contrat :** `CatalogueScenarios` — SIM-12 `analyse_nouvelle`

---

## D.1 Principe

SIM-12 est un **contrat de scénario** posé sur le descripteur mono-axe
générique livré par SIM-09. Aucune nouvelle branche de calcul, aucun nouveau
service, aucune nouvelle table.

```
Entrée SIM-12
  taux (référence) + taux_modifie + axe=abandon + sens=baisse
        │
        ▼
CatalogueScenarios::schemaSim12()        ← épinglage axe / sens
CatalogueScenarios::validerReglesSim12() ← baisse stricte + somme ≤ 1
        │
        ▼
ServiceSimulationTemporelle (v1.8, inchangé)
  aVariationGenerique() → descripteur commun
  reussiteCle = 'baisse_abandon'
  reussiteVariation = taux.abandon − taux_modifie.abandon
        │
        ▼
Double trajectoire :
  principale = taux_modifie   (snapshots persistés)
  témoin     = taux           (en mémoire)
        │
        ▼
analyserReussite() → 5 champs neutres + baisse_abandon
```

Le moteur ne réinjecte que `passage` et `redoublement` : `abandon` est un
**non-réinjecté structurel**. Une variation de `abandon` seule ne modifie
aucune trajectoire ; seuls les taux de flux déclarés produisent les écarts.

---

## D.2 Contrat d'entrée

| Clé | Rôle | Validation |
|---|---|---|
| `taux` | Jeu de référence, trajectoire témoin | Trois taux, [0,1], somme ≤ 1 |
| `taux_modifie` | Jeu simulé, trajectoire principale | Trois taux, [0,1], somme ≤ 1 |
| `axe_variation` | Axe focal | Épinglé `abandon` (catalogue) |
| `sens_variation` | Sens focal | Épinglé `baisse` (catalogue) |

Le moteur, lui, accepte `passage|redoublement|abandon` et
`hausse|baisse` : le contrat SIM-12 est un **sous-ensemble** publié du
vocabulaire générique, pas une duplication.

---

## D.3 Contrat de sortie

| Champ | Origine | Signe |
|---|---|---|
| `baisse_abandon` | `analyserReussite()` | Toujours ≥ 0 (magnitude) |
| `totaux` | Trajectoire principale | — |
| `totaux_reference_reussite` | Trajectoire témoin | — |
| `ecarts_reussite_par_periode` | Différence | Positif = effectifs accrus |
| `ecart_reussite_final` | Différence au dernier pas | Positif possible |
| `sortants_par_periode` | Partants du dernier niveau | Les deux trajectoires |
| `effet_sortants_cumules` | Somme des écarts | **Négatif, nul ou positif** |

Aucun champ n'est ajouté si le descripteur est absent : les exécutions
antérieures restent lisibles et non ambiguës.

---

## D.4 Ordre des gardes (inchangé depuis SIM-09)

| Ordre | Bloc | Rôle |
|---|---|---|
| 1 | SIM-07 (`taux_ameliore`) | Refuse toute autre forme |
| 2 | SIM-08 (`taux_degrade`) | Refuse la forme générique |
| 3 | Générique | Valide trio, forme, bornes, somme, axe, sens, référence, direction, incompatibilités |
| 4 | Commun | Somme du jeu de référence, bornes, périmètre |

**Conséquence :** aucune combinaison de formes ne peut atteindre le calcul.
L'ordre est explicite et testé ; il n'est pas un effet de bord.

---

## D.5 Catalogue

### D.5.1 Schéma

`schemaSim12()` :

- `taux` requis ;
- `taux_modifie` requis et complet (trois taux) ;
- `axe_variation` requis, `valeurs = ['abandon']` ;
- `sens_variation` requis, `valeurs = ['baisse']` ;
- gel, choc, cible, creux, alias passage **absents du schéma** → refus
  structurel « paramètre inconnu ».

### D.5.2 Règles

`validerReglesSim12()` :

1. somme du jeu modifié ≤ 1 (tolérance d'arrondi `1.0001`) ;
2. `taux_modifie.abandon < taux.abandon` strictement.

### D.5.3 Définition

`sim12()` : version 1, formules 1.0, statut `disponible`, compatibilité
`analyse_nouvelle`, dépendance `SOCLE-EFFECTIFS`. Retrait de `planifies()` :
**13 disponibles / 32 planifiés**.

---

## D.6 Déterminisme et versionnement

| Mécanisme | Effet |
|---|---|
| `hash_entrees` | Inclut les deux jeux, l'axe, le sens, la graine, l'année, les variantes et la version moteur |
| `hash_resultat` | SHA-256 du résultat complet |
| Idempotence | Mêmes entrées → exécution existante, aucun doublon |
| Version moteur | **`1.8` inchangée** : aucun comportement moteur nouveau |
| Version scénario | `1`, formules `1.0` |

Aucun incrément de version n'est justifié : ajouter un contrat catalogue ne
change ni la formule, ni les hashs des scénarios existants. La Phase D n'a
révélé aucun écart moteur ; la décision d'audit est conservée.

---

## D.7 Gestion des erreurs

| Situation | Réponse |
|---|---|
| Baisse non stricte | `DomainException` « strictement inférieur » |
| Somme modifiée > 1 | `DomainException` `> 1` |
| Taux hors [0,1] | `DomainException` `hors [0,1]` (moteur) / `hors bornes` (catalogue) |
| Jeu incomplet | `DomainException` « doit fournir passage, redoublement et abandon » |
| Trio incomplet | `DomainException` « indissociables » |
| Axe / sens invalide | `DomainException` nommant la clé |
| Référence absente | `DomainException` « taux de référence requis » |
| Gel / choc / cible / creux | Refus explicites SIM-03 → SIM-06 |
| Alias SIM-07/08 | Refus explicites par ordre des blocs |

Aucun refus silencieux, aucun résultat partiel, aucune valeur par défaut.

---

## D.8 Absence de duplication

| Élément | Réutilisation |
|---|---|
| Progression, conservation, sortants | Moteur commun (SOCLE → SIM-11) |
| Comparaison de réussite | `analyserReussite()` (SIM-07 → SIM-12) |
| Validation de forme | Boucles `nombreSaisi` + bornes |
| Exposition des champs | `champsSpecifiques()` (liste unique) |
| Analyses OAT / MC | `criteresAnalyses['taux'] = $reussiteTaux` |
| Tests | Structure de suite réutilisée, **valeurs recalculées** |

Aucun service, aucune table, aucune route, aucune permission nouvelle.

---

## D.9 Impact et compatibilité

| Périmètre | Impact |
|---|---|
| SOCLE / SIM-01 → SIM-10 | Aucun (suites inchangées) |
| SIM-11 | Aucun (même moteur, contrat distinct : sens `hausse`) |
| SIM-13 | Multi-axes : hors de ce contrat |
| Exécutions historiques | Lisibles, non réécrites |
| Base de données | Aucune migration |

---

## D.10 Limite structurelle assumée

`abandon` n'étant pas réinjecté, SIM-12 mesure **le mouvement joint
déclaré**, pas l'effet causal isolé de l'abandon. Cette limite est une
propriété du modèle, pas un défaut d'implémentation :

- elle est prouvée par le cas B (`écarts [0, 0]`, effet `0`) ;
- elle interdit toute lecture « une baisse de l'abandon fait augmenter les
  effectifs » ;
- elle est documentée dans le guide et rappelée en réserve `SIM12-R2`.

Aucune valeur par défaut ni compensation implicite n'est ajoutée pour
« corriger » ce comportement : ce serait inventer une règle métier.
