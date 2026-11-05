# SIM-01 — 07 · Résultats mesurés

**Date d'exécution :** 2026-11-02
**Environnement :** local, base de test `RefreshDatabase`
**Mode :** AUDIT_ONLY / SIMULATION / TESTING

---

## G.1 Résultats bruts

| Commande | Tests | Réussis | Échecs | Assertions | Durée |
|---|---|---|---|---|---|
| `--filter="SimulationTemporelleSim01Test"` | 7 | **7** | 0 | **56** | ~1,2 s |
| `--filter="SimulationTemporelleCatalogueTest"` | 12 | **12** | 0 | **292** | ~1,2 s |
| `--filter="SimulationTemporelle"` | 71 | **71** | 0 | **660** | ~5,5 s |

Décomposition : `52` tests historiques (`312` assertions) + `12` tests
catalogue (`292` assertions) + `7` tests SIM-01 (`56` assertions).

`52 + 12 + 7 = 71` et `312 + 292 + 56 = 660`.

---

## G.2 Détail SIM-01

| Test | Résultat | Assertions |
|---|---|---|
| Croissance composée | PASS | 6 |
| Admissions constantes / conservation | PASS | 14 |
| Horizons 1 et 6 | PASS | 7 |
| Idempotence / graine | PASS | 9 |
| Cible et capacités | PASS | 12 |
| Permissions / isolation | PASS | 3 |
| Zéro écriture métier | PASS | 5 |
| **Total** | **7/7** | **56** |

---

## G.3 Valeurs observées

### Croissance composée (delta 0.1, horizon 3, progression gelée)

```
admissions_par_periode = [
  {pas: 1, admissions: 11},
  {pas: 2, admissions: 12},
  {pas: 3, admissions: 13}
]
totaux      = [60, 71, 83, 96]
total_final = 96
```

### Admissions constantes (delta 0, taux 0.5/0.3/0.2, horizon 2)

```
totaux      = [60, 43, 36]
snapshots   = pas 1 {L1:13, L2:11, L3:19}
              pas 2 {L1:14, L2:10, L3:12}
conservation = 60 + 10 − 15 − 12 = 43
               43 + 10 − 10 − 7  = 36
```

### Horizon 6

```
admissions  = [11, 12, 13, 15, 16, 18]
total_final = 145
```

### Périmètre L1, cible 100, groupe capacité 15

```
effectif_projete          = 46
ecart_cible               = −54
ecart_cible_pourcentage   = −54,0 %
groupes_theoriques_requis = 4
deficit_groupes           = 3
places_manquantes         = 31
capacite_groupe_hypothese = false
capacite_groupe_reference = 15
```

---

## G.4 Formatage

```
vendor/bin/pint --dirty
```

| Étape | Résultat |
|---|---|
| Avant correctifs | 1 fichier (`CatalogueScenarios.php`) — fixers `single_quote`, `unary_operator_spaces`, `not_operator_with_successor_space` |
| Après correctifs | Aucun fichier modifié |
| Re-test après Pint | 71/71, 660 assertions |

---

## G.5 Non-régression

| Périmètre | Avant SIM-01 | Après SIM-01 | État |
|---|---|---|---|
| Suite historique ST-11/ST-12 | 52 / 312 | 52 / 312 | Inchangée |
| Catalogue ST-14.00 | 10 / 277 | 12 / 292 | +2 tests (SIM-01) |
| SIM-01 | — | 7 / 56 | Nouveau |
| Module complet | 62 / 589 | **71 / 660** | Vert |

L'incrément de `VERSION_MOTEUR` à `1.1` n'a cassé aucun test historique : la
fixture `version_moteur => '1.0'` du test de rétro-compatibilité reste une
donnée d'exemple, pas une assertion sur la constante.

---

## G.6 Écritures constatées

| Table | Variation |
|---|---|
| `etudiants` | 0 |
| `inscriptions_academiques` | 0 |
| `groupes` | 0 |
| `facultes` | 0 |
| `niveaux` | 0 |
| `scenarios_temporels` | 0 |
| `executions_temporelles` | +1 (technique) |
| `snapshots_executions` | +3 (technique, horizon 2) |

Aucune donnée métier réelle n'a été touchée : les tests utilisent une base
`RefreshDatabase` et des entités synthétiques.

---

## G.7 Verdict de phase

**Tests : PASS.** Les 7 tests SIM-01 et les 12 tests catalogue passent, la
suite historique est intacte, Pint est propre après correction, et aucune
écriture métier n'est détectée.
