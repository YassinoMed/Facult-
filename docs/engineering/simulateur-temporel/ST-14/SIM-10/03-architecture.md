# SIM-10 — 03 · Architecture et conception

**Date :** 2026-11-05  
**Moteur :** `ServiceSimulationTemporelle` v`1.8` (inchangé)  
**Contrat :** `CatalogueScenarios` — SIM-10 `analyse_nouvelle`

---

## D.1 Principe

SIM-10 est un **contrat de scénario** posé sur une capacité moteur déjà
livrée par SIM-09 : le descripteur mono-axe générique. Aucune nouvelle
branche de calcul, aucun nouveau service, aucune nouvelle table.

```
Entrée SIM-10
  taux (référence) + taux_modifie + axe=redoublement + sens=baisse
        │
        ▼
CatalogueScenarios::schemaSim10()   ← épinglage axe / sens
CatalogueScenarios::validerReglesSim10() ← baisse stricte + somme ≤ 1
        │
        ▼
ServiceSimulationTemporelle (v1.8, inchangé)
  aVariationGenerique() → descripteur commun
  reussiteCle = 'baisse_redoublement'
  reussiteVariation = taux.redoublement − taux_modifie.redoublement
        │
        ▼
Double trajectoire :
  principale = taux_modifie   (snapshots persistés)
  témoin     = taux           (en mémoire)
        │
        ▼
analyserReussite() → 5 champs neutres + baisse_redoublement
```

---

## D.2 Contrat d'entrée

| Clé | Rôle | Validation |
|---|---|---|
| `taux` | Jeu de référence, trajectoire témoin | Trois taux, [0,1], somme ≤ 1 |
| `taux_modifie` | Jeu simulé, trajectoire principale | Trois taux, [0,1], somme ≤ 1 |
| `axe_variation` | Axe focal | Épinglé `redoublement` (catalogue) |
| `sens_variation` | Sens focal | Épinglé `baisse` (catalogue) |

Le moteur, lui, accepte `passage|redoublement|abandon` et
`hausse|baisse` : le contrat SIM-10 est un **sous-ensemble** publié du
vocabulaire générique, pas une duplication.

---

## D.3 Contrat de sortie

| Champ | Origine | Signe |
|---|---|---|
| `baisse_redoublement` | `analyserReussite()` | Toujours ≥ 0 (magnitude) |
| `totaux` | Trajectoire principale | — |
| `totaux_reference_reussite` | Trajectoire témoin | — |
| `ecarts_reussite_par_periode` | Différence | Négatif = effectifs réduits |
| `ecart_reussite_final` | Différence au dernier pas | Négatif possible |
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

`schemaSim10()` :

- `taux` requis ;
- `taux_modifie` requis et complet (trois taux) ;
- `axe_variation` requis, `valeurs = ['redoublement']` ;
- `sens_variation` requis, `valeurs = ['baisse']` ;
- gel, choc, cible, creux, alias passage **absents du schéma** → refus
  structurel « paramètre inconnu ».

### D.5.2 Règles

`validerReglesSim10()` :

1. somme du jeu modifié ≤ 1 (tolérance d'arrondi `1.0001`) ;
2. `taux_modifie.redoublement < taux.redoublement` strictement.

### D.5.3 Définition

`sim10()` : version 1, formules 1.0, statut `disponible`, compatibilité
`analyse_nouvelle`, dépendance `SOCLE-EFFECTIFS`. Retrait de `planifies()` :
**11 disponibles / 34 planifiés**.

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
change ni la formule, ni les hashs des scénarios existants. Cette décision
est réversible si la Phase D avait révélé un écart — ce qui n'a pas été le
cas.

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
| Progression, conservation, sortants | Moteur commun (SOCLE → SIM-09) |
| Comparaison de réussite | `analyserReussite()` (SIM-07 → SIM-10) |
| Validation de forme | Boucles `nombreSaisi` + bornes |
| Exposition des champs | `champsSpecifiques()` (liste unique) |
| Analyses OAT / MC | `criteresAnalyses['taux'] = $reussiteTaux` |
| Tests | Structure de suite réutilisée, **valeurs recalculées** |

Aucun service, aucune table, aucune route, aucune permission nouvelle.

---

## D.9 Impact et compatibilité

| Périmètre | Impact |
|---|---|
| SOCLE / SIM-01 → SIM-08 | Aucun (suites inchangées) |
| SIM-09 | Aucun (même moteur, contrat distinct) |
| SIM-11 → SIM-12 | Le descripteur générique est déjà prêt ; contrats à créer |
| SIM-13 | Multi-axes : hors de ce contrat |
| Exécutions historiques | Lisibles, non réécrites |
| Base de données | Aucune migration |
