# SIM-06 — 04 · Implémentation

**Date :** 2026-11-03  
**Moteur :** `ServiceSimulationTemporelle` — version `1.5`  
**Compatibilité :** `extension_temporelle` (confirmée)

---

## D.1 Fichiers réellement modifiés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | Modifié | Version `1.5` ; `facteurRecuperation()` ; `analyserRecuperation()` ; branche creux dans `simulerVariante` + snapshots enrichis (référence, facteur, phase) ; propagation aux closures sensibilité / Monte-Carlo ; garde d'exécution du trio ; validation de définition ; helpers `nombreSaisi()` / `entierSaisi()` ; renommage `champsCibleCroissance()` → `champsSpecifiques()` (SIM-05 + SIM-06). |
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | `tout()` intègre SIM-06 ; `schemaSim06()` ; `sim06()` ; `validerReglesSim06()` ; branche `validerParametres` ; retrait de SIM-06 de `planifies()` (38 planifiés). |
| `tests/Feature/SimulationTemporelleSim06Test.php` | Créé | 8 tests — références hors moteur, contrat, arrondis, idempotence, sécurité, lecture seule. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | Compteurs 7 disponibles / 38 planifiés ; SIM-06 disponible (index 6) ; assertions de schéma ; test dédié des règles SIM-06 (17 tests au total). |

**Aucune nouvelle table, aucun nouveau service, aucune nouvelle route,
aucune nouvelle permission.**

---

## D.2 Détail moteur

### D.2.1 Version

```php
public const VERSION_MOTEUR = '1.5';
```

### D.2.2 Facteur et phases (`facteurRecuperation`)

```php
private static function facteurRecuperation(int $pas, int $dureeBaisse, int $dureeRecuperation, float $profondeur): array
```

- `p ≤ d` → `[1 − profondeur × p/d, 'baisse']`
- `d < p ≤ d + r` → `[(1 − profondeur) + profondeur × (p − d)/r, 'recuperation']`
- sinon → `[1.0, 'reference']`

Fonction pure, statique, sans état : le creux et le retour sont testables
indépendamment du moteur d'événements.

### D.2.3 Branche creux dans `simulerVariante`

Nouveau paramètre `?array $recuperation = null` (8ᵉ position, après le
choc). Quand il est fourni :

1. `$reference = round(baseL1 × (1 + delta)^pas)` ;
2. `$admissions = round($reference × $facteur)` ;
3. le snapshot du pas reçoit `admissions_reference`,
   `facteur_recuperation` (arrondi 6 décimales) et `phase_recuperation`.

La branche gel / fond historique est **inchangée** : elle n'est atteinte
que si le trio est absent (exclusivité garantie par validation).

### D.2.4 Restitutions (`analyserRecuperation`)

Construites depuis les snapshots (aucun recalcul de formule) :

- `admissions_reference_par_periode` ;
- `ecarts_recuperation_par_periode` (admissions, référence, écart signé,
  phase, facteur) ;
- `ecart_recuperation_cumule` (somme des écarts) ;
- `creux_recuperation` (écart minimal, 0 si aucun négatif).

### D.2.5 Double barrière

| Niveau | Contrôle |
|---|---|
| **Définition** (`validerCriteres`) | Trio indissociable, types, profondeur ∈ ]0 ; 1], durées ≥ 1, `d + r ≤ horizon`, incompatibilités gel / choc / cible |
| **Exécution** (garde dans `executer`) | Mêmes bornes de forme + `d + r ≤ horizon` : une ligne insérée hors service ne peut jamais provoquer de division par zéro ni un creux non interprétable |

### D.2.6 Propagation sensibilité / Monte-Carlo

La closure `$simulateurFn` propage désormais `$recuperation` (comme le gel
et le choc en v1.3) : les analyses OAT et Monte-Carlo recalculent la même
trajectoire creusée, jamais une trajectoire divergente.

### D.2.7 Renommage

`champsCibleCroissance()` → **`champsSpecifiques()`** : le helper expose
désormais les quatre champs SIM-05 **et** les quatre champs SIM-06, toujours
uniquement lorsqu'ils sont présents. `SIM-05/04-implementation.md` porte une
note de renommage ; aucune clé ni aucun comportement SIM-05 modifié.

---

## D.3 Détail catalogue

### D.3.1 Schéma (`schemaSim06`)

Taux requis (comme SIM-01→05) + trio :

| Clé | Type | Requis | Bornes |
|---|---|---|---|
| `profondeur_baisse` | number | oui | `[0 ; 1]` (règle : > 0) |
| `duree_baisse_annees` | integer | oui | `[1 ; 6]` |
| `duree_recuperation_annees` | integer | oui | `[1 ; 6]` |

Le gel, le choc et la cible ne figurent pas au schéma : refus structurel
(« inconnu ») avant la règle de domaine.

### D.3.2 Règles (`validerReglesSim06`)

1. `profondeur_baisse > 0` → message « strictement positive » ;
2. `duree_baisse + duree_recuperation ≤ horizon` → message « dépassent
   l'horizon ».

### D.3.3 Définition

`sim06()` : version 1, formules 1.0, compatibilité `extension_temporelle`,
dépendance `SOCLE-EFFECTIFS`, statut `disponible`. `planifies()` ne contient
plus SIM-06 : **7 disponibles / 38 planifiés**.

---

## D.4 Absence de duplication

| Élément réutilisé | Origine |
|---|---|
| Trajectoire de fond `(1 + delta)^pas` | Moteur (même expression) |
| `baseL1` | État initial (même lecture que SIM-01→05) |
| Snapshots, idempotence, hash | `executer()` inchangé |
| Capacités, budget, alertes | Estimateurs existants |
| Exposition des champs | `champsSpecifiques()` unique (SIM-05 + SIM-06) |

Aucun service parallèle, aucune formule recopiée, aucune structure
nouvelle.

---

## D.5 Compatibilité et versionnement

- Moteur `1.4 → 1.5` : `hash_entrees` inclut la version ; les exécutions
  v1.4 et antérieures restent lisibles et ne sont jamais réécrites.
- Les quatre champs SIM-06 sont additifs ; les scénarios existants
  conservent exactement leur forme (test de rétrocompatibilité dédié).
