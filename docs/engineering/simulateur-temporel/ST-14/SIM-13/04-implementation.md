# SIM-13 — 04 · Implémentation

**Scénario :** SIM-13 — Crise académique combinée
**Fichier cible :** `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php`
**Moteur :** `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` (lecture seule, v1.8)

---

## A. Position dans le code

```
app/Modules/SimulationTemporelle/Support/
└── CatalogueScenarios.php      ← MODIFIÉ (SIM-13)
app/Modules/SimulationTemporelle/Services/
└── ServiceSimulationTemporelle.php  ← LECTURE SEULE (v1.8)
tests/Feature/
├── SimulationTemporelleSim13Test.php      ← NOUVEAU (8 tests / 107 assertions)
└── SimulationTemporelleCatalogueTest.php  ← MODIFIÉ (métadonnées + compteurs)
```

---

## B. Modifications du catalogue

### B.1. Liste des définitions (`tout()`)

```php
$definitions = [
    ..., 'SIM-12' => self::sim12(), 'SIM-13' => self::sim13(),
];
```

### B.2. Retrait de `planifies()`

- L'entrée `'SIM-13' => [...]` est retirée ; la docblock passe de
  « 32 scénarios planifiés » à « 31 scénarios planifiés ».
- Bilan : **14 disponibles / 31 planifiés / 45 au total**.

### B.3. Branche de validation (`validerParametres()`)

```php
if ($code === 'SIM-13') {
    self::validerReglesCommunes($parametres);
    self::validerReglesSim13($parametres);
}
```

### B.4. `schemaSim13()` — métadonnées de forme (aucun calcul)

- `taux` REQUIS (`schemaAvecTauxRequis`) ;
- `taux_modifie` REQUIS, complet (3 taux, `[0,1]`) ;
- `axe_variation` REQUIS, `valeurs: ['passage', 'redoublement', 'abandon']` ;
- `sens_variation` REQUIS, `valeurs: ['hausse', 'baisse']`.

Le schéma n'épingle **pas** de paire focale unique : les règles de domaine
vérifient l'appariement dégradant et l'absence d'amélioration.

### B.5. `validerReglesSim13()` — règles de domaine

Ordre de vérification (premier refus gagne) :

1. **Somme du jeu modifié ≤ 1.0001** ;
2. **Paire focale dégradante** : `passage/baisse`, `redoublement/hausse`,
   `abandon/hausse` ;
3. **Aucune amélioration** : `passage_mod ≤ passage_ref`,
   `redoublement_mod ≥ redoublement_ref`, `abandon_mod ≥ abandon_ref` ;
4. **Focal strictement dégradé** (contrat du descripteur) ;
5. **Au moins deux dégradations strictes** au total.

Messages (extraits) :

- `"SIM-13 (crise combinée) : la paire focale abandon/baisse n'est pas une dégradation — attendu passage-baisse, redoublement-hausse ou abandon-hausse."`
- `"SIM-13 (crise combinée) : passage_modifie supérieur au passage de référence — aucune amélioration admise."`
- `"SIM-13 (crise combinée) : au moins deux axes doivent se dégrader strictement — une dégradation isolée relève de SIM-08, SIM-09 ou SIM-11."`

### B.6. `sim13()` — métadonnées de définition

| champ | valeur |
|---|---|
| code / nom | `SIM-13` / « Crise académique combinée » |
| catégorie / lot | `reussite_flux` / A |
| compatibilité | `configuration` (confirmée par l'audit) |
| dépendances | `['SOCLE-EFFECTIFS']` |
| version / formules | `1` / `1.0` |
| périmètre | `faculte_ou_global` |

---

## C. Exécution : aucune ligne moteur ajoutée

```
ScenarioTemporel (trio générique) → POST admin/simulation-temporelle/executer
  → descripteur générique v1.8 (SIM-09) → double trajectoire (SIM-07)
  → ExecutionTemporelle + SnapshotExecution (3 tables techniques)
```

---

## D. Ce qui n'a PAS été modifié (liste de vérification)

- [x] `ServiceSimulationTemporelle.php` — 0 ligne (v1.8).
- [x] Routes / permissions / tables / migrations — aucun ajout.
- [x] Schémas SIM-01 → SIM-12 — inchangés.
- [x] Tests SIM-01 → SIM-12 — inchangés (non-régression 160/1763).
- [x] Formulaire UI — inchangé.

---

## E. Interface de validation

- `validerReglesSim13()` lève une `DomainException` (blocage dur).
- La voie HTTP existante affiche le message sans modification.
- Niveau moteur : le descripteur générique conserve ses propres refus
  (focal strict, somme, exclusions) — défense en profondeur.

---

## F. Résumé des changements

| Fichier | Nature |
|---------|--------|
| `CatalogueScenarios.php` | + `sim13()`, `schemaSim13()`, `validerReglesSim13()`, branche, liste ; − entrée `planifies()` |
| `SimulationTemporelleSim13Test.php` | nouveau — 8 tests / 107 assertions |
| `SimulationTemporelleCatalogueTest.php` | compteurs 14/31 + bloc métadonnées SIM-13 + disponibilité |
| **Moteur** | **0 ligne** |

---

*Document de référence : `ST-14/SIM-13/04-implementation.md` — figé après Pint
(1 correction automatique : quotes/espacements).*
