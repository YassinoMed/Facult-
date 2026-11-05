# SIM-02 — 04 · Implémentation

**Scénario :** SIM-02 — Baisse des admissions
**Fichier cible :** `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php`
**Moteur cible :** `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` (lecture seule)

---

## A. Position dans le code

```
app/Modules/SimulationTemporelle/
├── Support/
│   └── CatalogueScenarios.php      ← MODIFIÉ (SIM-02)
├── Services/
│   └── ServiceSimulationTemporelle.php  ← LECTURE SEULE
app/Modules/SimulationTemporelle/Models/
├── ScenarioTemporel.php            ← utilisé (création scénario de test)
├── ExecutionTemporelle.php         ← écrite (table technique)
├── SnapshotExecution.php           ← écrite (table technique)
tests/Feature/
├── SimulationTemporelleSim01Test.php     ← inchangé
├── SimulationTemporelleSim02Test.php     ← NOUVEAU (7 tests / 56 assertions)
├── SimulationTemporelleCatalogueTest.php ← MODIFIÉ (schéma SIM-02)
└── SimulationTemporelleTestCase.php      ← inchangé
```

---

## B. Modifications du catalogue

### B.1. Schéma SIM-02 (`schemaSim02()`, ligne 406)

```php
public static function schemaSim02(): array
{
    return self::schemaAvecTauxRequis('SIM-02');
}
```

**Observation** : `schemaSim02()` ne fait que réutiliser le schéma de base
avec taux requis — il n'y a aucun champ spécifique SIM-02. Le contrat SIM-02
est donc :

```
delta_admissions ∈ [-0.5, 0[     (bornes min = -0.5, max = 0)
bornes => [min: -0.5, max: 0]
horizon_annees ∈ [1, 20]
taux => {passage, redoublement, abandon}  (tous REQUIS, pas de valeur par défaut)
```

C'est l'héritage exact de SIM-01, seule la règle de domaine change
(`validerReglesSim02()`).

### B.2. Règles SIM-02 (`validerReglesSim02()`, ligne 1602)

```php
private static function validerReglesSim02(array $parametres): void
{
    $delta = (float) $parametres['delta_admissions'];
    $max   = (float) $parametres['bornes']['max'];

    if ($delta >= 0) {
        throw new \DomainException(
            'SIM-02 (baisse) : delta_admissions nul ou positif refusé — ' .
            'utiliser SIM-01 pour la croissance/stabilité, SIM-03 pour le gel explicite.'
        );
    }

    if ($max > 0) {
        throw new \DomainException(
            'SIM-02 (baisse) : borne maximale positive refusée — ' .
            'une variante de croissance relève de SIM-01.'
        );
    }
}
```

**Règles implémentées** :

| R-# | Règle | Implémentation |
|-----|-------|----------------|
| SIM02-R1 | `delta_admissions < 0` strictement | `DomainException` si `delta >= 0` |
| SIM02-R2 | `bornes.max <= 0` (aucune croissance) | `DomainException` si `max > 0` |

**Points précis** :

- La validation ne renvoie pas un tableau d'erreurs : elle lève une
  `DomainException` (blocage dur, compatible avec la validation formulaire).
- Les champs s'appellent `delta_admissions` (pas `delta`) et
  `bornes['max']` — noms hérités du schéma de formulaire.
- La borne `-0.5` n'est **pas** réintroduite ici : elle est appliquée par le
  formulaire web (`delta ∈ [-0.5, 2.0]`) et par la validation générique du
  catalogue (`assertRefuse ... '> 1'` sur `passage + redoublement`).
- Les taux REQUIS sont imposés par `schemaAvecTauxRequis('SIM-02')`, pas par
  `validerReglesSim02()`.

---

## C. Exécution : la même voie que SIM-01

L'exécution passe par la route `POST admin/simulation-temporelle/executer`
(route déjà existante) :

```
ScenarioTemporel::create([...]) →
actingAs($admin) → POST /admin/simulation-temporelle/executer/{id} →
ServiceSimulationTemporelle (VERSION 1.1, inchangé) →
ExecutionTemporelle + SnapshotExecution (tables techniques)
```

Aucun code moteur n'est écrit pour SIM-02 : le scénario SIM-02 n'est qu'un jeu
de paramètres + une règle de domaine `delta < 0`.

---

## D. Ce qui n'a PAS été modifié (liste de vérification)

- [x] `ServiceSimulationTemporelle.php` — aucune ligne changée.
- [x] `VERSION_MOTEUR` — reste `1.1`.
- [x] Routes web / API — aucune route nouvelle (`admin.simulation-temporelle.executer` existante).
- [x] Permissions / Grille — aucune nouvelle permission (`simulation.view`, `simulation.gerer`, `simulation.executer` existantes).
- [x] Tables / Migrations — aucune : seules les 3 tables techniques existantes
  (`ExecutionTemporelle`, `SnapshotExecution`, `ScenarioTemporel`).
- [x] Schéma SIM-01 — inchangé (`schemaSim01` intact).
- [x] Tests SIM-01 — inchangés (52 tests / 312 assertions).
- [x] Formulaire UI — inchangé (`delta ∈ [-0.5, 2.0]` déjà supporté).

---

## E. Interface de validation

`validerReglesSim02()` lance une `DomainException`. La voie HTTP (route
`admin.simulation-temporelle.executer`) convertit cette exception en réponse
HTTP 422/400 standard de Laravel — le formulaire existant affiche le message
sans modification.

**Format de réponse** :

```php
// Valide
array $parametres_valides

// Invalide (ex. delta = 0)
DomainException: "SIM-02 (baisse) : delta_admissions nul ou positif refusé —
utiliser SIM-01 pour la croissance/stabilité, SIM-03 pour le gel explicite."
```

---

## F. Couplage au catalogue

La même structure existe pour SIM-01 (`schemaSim01()` / `validerReglesSim01()`)
et SIM-03 (`schemaSim03()` / `validerReglesSim03()`). SIM-02 **ne réécrit pas**
ces méthodes — il ajoute la sienne. Le catalogue est hétérogène :

- SOCLE : taux optionnels,
- SIM-01 / SIM-02 : taux REQUIS (`schemaAvecTauxRequis`),
- SIM-03 : taux REQUIS + `duree_gel_annees` supplémentaire,
- SIM-04..SIM-12 : taux REQUIS + champs spécifiques.

---

## G. Points d'attention observés

1. **`test_sim02_baisse_composee_avec_reference_calculee_independamment`** utilise
   `taux => [0, 1, 0]` (progression gelée), pas `0.5/0.3/0.2`. Le total ne
   croît que par les admissions : `[60, 69, 77, 84]`.
2. **`test_sim02_conservation_avec_taux_explicites`** : le test vérifie aussi
   que chaque snapshot satisfait `total = Σ cohortes` et `cohortes >= 0`.
3. **`test_sim02_capacite_et_absence_de_modification_des_groupes`** utilise le
   paramètre optionnel `niveau_id` → la simulation se restreint au niveau L1
   et calcule des besoins de groupes (capacité réelle du groupe `SIM02-G1` = 15).
4. **`test_sim02_zero_ecriture_metier_et_tables_techniques_seules`** compare
   l'empreinte complète d'un étudiant témoin avant/après (pas seulement le
   comptage) — garantie forte de non-modification.

---

## H. Résumé des changements

| Fichier | Lignes | Nature |
|---------|--------|--------|
| `CatalogueScenarios.php` | ligne 406 `schemaSim02()` | schéma taux requis |
| `CatalogueScenarios.php` | ligne 1602 `validerReglesSim02()` | 2 DomainException |
| `tests/Feature/SimulationTemporelleSim02Test.php` | nouveau | 7 tests / 56 assertions |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | ligne 478 `schemaSim02()['taux']['requis']` | 1 assertion |
| **Moteur** | 0 | lecture seule |

---

*Document de référence : `docs/engineering/simulateur-temporel/ST-14/SIM-02/04-implementation.md`*
*Version : 1.0 — figé après implémentation catalogue*
