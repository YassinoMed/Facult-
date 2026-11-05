# SIM-07 — 04 · Implémentation

**Date :** 2026-11-04  
**Moteur :** `ServiceSimulationTemporelle` — version `1.6`  
**Compatibilité :** `analyse_nouvelle` (reclassée depuis `configuration`)

---

## D.1 Fichiers réellement modifiés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | Modifié | Version `1.6` ; capture des `sortants` (3ᵉ retour de `simulerVariante`) ; garde + construction de `$tauxAmeliore` dans `executer` ; trajectoire de référence parallèle ; `analyserReussite()` ; `criteresAnalyses` (taux améliorés) pour OAT/MC ; `champsSpecifiques()` += 6 champs SIM-07 ; validation de définition SIM-07. |
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | `tout()` intègre SIM-07 ; `schemaSim07()` ; `sim07()` ; `validerReglesSim07()` ; branche `validerParametres` ; retrait de SIM-07 de `planifies()` (37 planifiés). |
| `tests/Feature/SimulationTemporelleSim07Test.php` | Créé | 7 tests — références hors moteur, effets opposés selon l'origine déclarée, contrat, analyses, sécurité. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | Compteurs 8 disponibles / 37 planifiés ; SIM-07 disponible (index 7) ; assertions de schéma ; test dédié des règles SIM-07 (18 tests au total). |

**Aucune nouvelle table, aucun nouveau service, aucune nouvelle route,
aucune nouvelle permission.**

---

## D.2 Détail moteur

### D.2.1 Version et restoration des sortants

```php
public const VERSION_MOTEUR = '1.6';

// simulerVariante : nouveau 3ᵉ élément de retour
// @return array{0: mesures, 1: snapshots, 2: array<int, int> sortants}
```

Dans le handler, les `partent` du **dernier niveau** sont désormais
capturés (`$sortants[$pas] = $partent`) au lieu d'être jetés. Les appelants
existants déstructurent deux éléments : l'ajout est **rétrocompatible**
(PHP ignore l'élément supplémentaire).

### D.2.2 Garde et construction de `$tauxAmeliore` (dans `executer`)

| Contrôle | Message |
|---|---|
| `taux_ameliore` non tableau ou incomplet | `doit fournir passage, redoublement et abandon` |
| `taux` de référence absent ou incomplet | `taux de référence requis` |
| Gel / choc / cible / creux présents | `incompatible avec gel, choc, cible ou creux — un seul profil à la fois` |

### D.2.3 Trajectoire principale et trajectoire témoin

- Trajectoire **principale** : `simulerVariante(..., $tauxAmeliore, ...)`,
  snapshots persistés (variante 0).
- Trajectoire **de référence** : `simulerVariante(..., $criteres['taux'],
  ...)` — mêmes admissions, même delta, gel/choc/creux `null` (exclusivité
  validée) ; **jamais persistée** (aucun snapshot supplémentaire).
- Fusion `analyserReussite()` dans les mesures de **chaque variante**.

### D.2.4 Restitutions (`analyserReussite`)

| Champ | Calcul |
|---|---|
| `hausse_passage` | `taux_ameliore.passage − taux.passage` (4 décimales) |
| `totaux_reference_reussite` | Totaux pas 0 → H de la trajectoire témoin |
| `ecarts_reussite_par_periode` | `{pas, total_simule, total_reference, ecart}` |
| `ecart_reussite_final` | `total_final simulé − total_final référence` |
| `sortants_par_periode` | `{pas, sortants, sortants_reference}` |
| `effet_sortants_cumules` | `Σ sortants simulés − Σ sortants référence` |

### D.2.5 Analyses cohérentes (`$criteresAnalyses`)

`AnalyseurSensibilite` et `SimulateurMonteCarlo` lisent `criteres['taux']`
en interne. Pour SIM-07, une copie des critères avec
`taux = taux_ameliore` leur est transmise : l'OAT varie ±5 pts autour du
passage **amélioré** et le Monte-Carlo ±4 pts autour du passage
**amélioré**. Sans cette substitution, les analyses auraient décrit la
trajectoire de référence, contredisant le résultat principal.

### D.2.6 Validation de définition (`validerCriteres`)

- Jeu amélioré complet (3 clés), chaque valeur ∈ [0,1] (`nombreSaisi`),
  somme ≤ 1 (tolérance 1.0001) ;
- `taux` de référence requis ;
- `taux_ameliore.passage > taux.passage` strict ;
- incompatibilités : `duree_gel_annees` → SIM-03, choc → SIM-04, cible →
  SIM-05, trio de récupération → SIM-06.

---

## D.3 Détail catalogue

### D.3.1 Schéma (`schemaSim07`)

Taux requis (référence, via `schemaAvecTauxRequis`) + `taux_ameliore`
(tableau requis de passage/redoublement/abandon, chacun ∈ [0,1]).
Le gel, le choc, la cible et le creux ne figurent pas au schéma : refus
structurel (« inconnu ») avant la règle de domaine.

### D.3.2 Règles (`validerReglesSim07`)

1. somme du jeu amélioré ≤ 1 → message `> 1` ;
2. `taux_ameliore.passage > taux.passage` → message `strictement supérieur`.

### D.3.3 Définition

`sim07()` : version 1, formules 1.0, compatibilité `analyse_nouvelle`,
dépendance `SOCLE-EFFECTIFS`, statut `disponible`. `planifies()` ne contient
plus SIM-07 : **8 disponibles / 37 planifiés**.

---

## D.4 Absence de duplication

| Élément réutilisé | Origine |
|---|---|
| Progression par niveaux (partent / redoublants / conservation) | Moteur — la **même** fonction simule les deux jeux de taux |
| Admissions | Moteur (même delta, aucun recalcul) |
| Idempotence, hash, snapshots | `executer()` inchangé |
| Capacités, budget, alertes | Estimateurs existants |
| Exposition des champs | `champsSpecifiques()` unique (SIM-05 + SIM-06 + SIM-07) |

---

## D.5 Compatibilité et versionnement

- Moteur `1.5 → 1.6` : `hash_entrees` inclut la version ; les exécutions
  antérieures restent lisibles et ne sont jamais réécrites.
- Les six champs SIM-07 sont additifs ; les scénarios existants conservent
  exactement leur forme (test de rétrocompatibilité dédié).
- Le 3ᵉ retour de `simulerVariante` est interne : aucune signature publique
  ni aucun contrat HTTP modifié.
