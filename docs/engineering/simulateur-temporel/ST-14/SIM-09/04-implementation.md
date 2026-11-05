# SIM-09 — 04 · Implémentation

**Date :** 2026-11-05  
**Moteur :** `ServiceSimulationTemporelle` — version `1.8`  
**Compatibilité :** `analyse_nouvelle` (reclassée depuis `configuration`)

---

## D.1 Fichiers réellement modifiés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | Modifié | Version `1.8` ; helper `aVariationGenerique()` ; gardes d'exécution génériques ; descripteur commun `$reussiteTaux` / `$reussiteCle` / `$reussiteVariation` ; validation de définition générique ; `champsSpecifiques()` += 4 clés ; OAT/MC recentrés. |
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | `sim09()`, `schemaSim09()`, `validerReglesSim09()`, branche `validerParametres` ; retrait de SIM-09 de `planifies()` (35 planifiés). |
| `tests/Feature/SimulationTemporelleSim09Test.php` | Créé | 7 tests — références hors moteur, descripteur générique, exclusion mutuelle, analyses, sécurité. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | **10 disponibles / 35 planifiés** ; SIM-09 disponible (index 9) ; assertions de schéma ; test des règles SIM-09 (20 tests au total). |

**Aucune nouvelle table, aucun nouveau service, aucune nouvelle route,
aucune nouvelle permission.**

---

## D.2 Détail moteur

### D.2.1 Version et descripteur commun

```php
public const VERSION_MOTEUR = '1.8';

// Descripteur interne commun aux trois formes :
$reussiteTaux       // jeu simulé (alias passage ou taux_modifie)
$reussiteCle        // 'hausse_passage' | 'baisse_passage' | sens_axe
$reussiteVariation  // magnitude POSITIVE
```

`analyserReussite($totauxSimules, $totauxRef, $sortantsSimules,
$sortantsRef, $cleVariation, $variation)` est **inchangé** depuis SIM-08 :
la forme générique réutilise exactement la même machinerie.

### D.2.2 Formes d'entrée et normalisation

| Forme | Clés | Axe | Clé produite |
|---|---|---|---|
| SIM-07 (alias) | `taux_ameliore` | passage | `hausse_passage` |
| SIM-08 (alias) | `taux_degrade` | passage | `baisse_passage` |
| SIM-09→12 (générique) | `taux_modifie` + `axe_variation` + `sens_variation` | déclaré | `sens_axe` |

Les alias conservent **strictement** leurs contrats publiés (messages,
champs, schémas) : la normalisation est interne au moteur.

### D.2.3 Gardes d'exécution

| Bloc | Contrôles |
|---|---|
| SIM-07 (premier) | Forme, référence, incompatibilités gel/choc/cible/creux, **+ `taux_degrade` + forme générique** |
| SIM-08 | Forme, référence, incompatibilités, **+ forme générique** |
| Générique | Trio indissociable, forme du jeu modifié, bornes [0,1], somme ≤ 1, axe/sens ∈ vocabulaire, référence complète, incompatibilités gel/choc/cible/creux |

L'ordre chronologique rend tout contrôle inverse inutile : aucune
combinaison de formes ne peut atteindre le moteur.

### D.2.4 Validation de définition (`validerCriteres`)

| Contrôle | Message |
|---|---|
| Trio incomplet | « taux_modifie, axe_variation et sens_variation sont indissociables » |
| Jeu incomplet | « taux_modifie doit fournir passage, redoublement et abandon » |
| Taux hors [0,1] | « taux_modifie.{clé} hors [0,1] » |
| Somme > 1 | « (modifié) > 1 » |
| Axe invalide | « axe_variation doit être passage, redoublement ou abandon » |
| Sens invalide | « sens_variation doit être hausse ou baisse » |
| Référence absente | « taux de référence requis » |
| Direction non stricte | « strictement supérieur / inférieur » |
| Gel / choc / cible / creux | SIM-03 / SIM-04 / SIM-05 / SIM-06 |
| Alias simultané | SIM-07 / SIM-08 (blocs exécutés en premier) |

### D.2.5 Analyses cohérentes

`criteresAnalyses['taux'] = $reussiteTaux` : OAT et Monte-Carlo analysent
la trajectoire **principale** (jeu modifié), jamais la référence.

---

## D.3 Détail catalogue

### D.3.1 Schéma (`schemaSim09`)

`taux` requis (référence) + `taux_modifie` requis et complet +
`axe_variation` épinglé à `['redoublement']` + `sens_variation` épinglé à
`['hausse']`. Gel, choc, cible, creux et les alias passage ne figurent pas
au schéma : refus structurel (« inconnu »).

### D.3.2 Règles (`validerReglesSim09`)

1. somme du jeu modifié ≤ 1 → message `> 1` ;
2. `taux_modifie.redoublement > taux.redoublement` → message
   `strictement supérieur`.

### D.3.3 Définition

`sim09()` : version 1, formules 1.0, compatibilité `analyse_nouvelle`,
dépendance `SOCLE-EFFECTIFS`, statut `disponible`. `planifies()` ne contient
plus SIM-09 : **10 disponibles / 35 planifiés**.

---

## D.4 Absence de duplication

| Élément réutilisé | Origine |
|---|---|
| Progression, conservation, sortants | Moteur (même fonction que SOCLE/SIM-01→08) |
| Machinerie de comparaison | `analyserReussite()` (SIM-07→09) |
| Validation de forme des taux | Boucles `nombreSaisi` + bornes, motif SIM-07/08 |
| Exposition des champs | `champsSpecifiques()` (une seule liste) |

---

## D.5 Compatibilité et versionnement

- Moteur `1.7 → 1.8` : `hash_entrees` inclut la version ; exécutions
  antérieures lisibles, jamais réécrites.
- SIM-07/08 strictement inchangés : leurs suites passent sans modification.
- `hausse_redoublement` / `baisse_redoublement` / `hausse_abandon` /
  `baisse_abandon` sont additifs (présents uniquement avec le descripteur
  générique).
