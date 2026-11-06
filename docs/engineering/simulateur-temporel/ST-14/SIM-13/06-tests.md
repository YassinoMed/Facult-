# SIM-13 — 06 · Tests et preuves

**Scénario :** SIM-13 — Crise académique combinée
**Suite principale :** `tests/Feature/SimulationTemporelleSim13Test.php`
**Résultat :** **8 tests / 107 assertions / 0 échec** (verts au premier passage)

---

## A. Vue d'ensemble

```
tests/Feature/SimulationTemporelleSim13Test.php

  ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓   8 tests / 107 assertions / 0 échec
```

| # | Test | Objet |
|---|------|-------|
| 1 | `test_sim13_crise_combinee_reference_calculee_independamment` | Cas A (focal redoublement↑) — référence manuelle |
| 2 | `test_sim13_focal_abandon_et_annulation_partielle_des_effets` | Cas B (focal abandon↑, 3 strictes) — non-additivité |
| 3 | `test_sim13_admissions_identiques_quelle_que_soit_la_crise` | Cas C (`delta = +0.10`) — admissions communes |
| 4 | `test_sim13_definition_refuse_profils_non_combines_et_invalides` | Refus moteur (descripteur + incompatibilités) |
| 5 | `test_sim13_catalogue_refuse_les_regles_de_domaine_hors_contrat` | Refus catalogue (règles SIM-13) |
| 6 | `test_sim13_idempotence_et_absence_de_cles_sans_variation` | Déterminisme + rétrocompatibilité |
| 7 | `test_sim13_analyses_sensibilite_et_monte_carlo_autour_des_taux_modifies` | OAT/MC recentrés, pas d'abandon inventé |
| 8 | `test_sim13_permissions_isolation_facultaire_et_zero_ecriture_metier` | Sécurité et lecture seule |

---

## B. Tests de référence mathématique (E)

### Cas A — `{0.5, 0.3, 0.2}` focal redoublement↑ (test 1)

Référence manuelle (commentaires du test, hors moteur) :

```
Réf {0.6, 0.2, 0.2} → totaux [60, 40, 31] ; sortants [18, 11]
Mod {0.5, 0.3, 0.2} → totaux [60, 43, 36] ; sortants [15, 10]
Écarts [+3, +5] ; final +5 ; effet sortants −4 ; hausse_redoublement 0.1
```

Assertions : totaux sim/réf, clé focale présente + 5 clés absentes,
écarts par période, effet sortants, admissions, snapshots cohortes
(`p1 [13,11,19]`, `p2 [14,10,12]`).

### Cas B — `{0.4, 0.3, 0.3}` focal abandon↑ (test 2)

```
Réf {0.6, 0.2, 0.2} → [60, 40, 31] ; sortants [18, 11]
Mod {0.4, 0.3, 0.3} → [60, 40, 31] ; sortants [12, 7]
Écarts [0, 0] ; effet sortants −10 ; hausse_abandon 0.1
```

**Démonstration** : les dégradations combinées ne sont pas additives — les
totaux s'annulent partiellement (arrondis) tandis que les sortants
chutent fortement. Aucun effet causal isolé n'est attribué à l'abandon.

### Cas C — `delta = +0.10` (test 3)

```
Admissions [11, 12] dans les deux trajectoires
Réf [60, 41, 35] ; Mod [60, 44, 38] ; écarts [+3, +3] ; effet sortants −4
```

---

## C. Tests de refus (C/F)

### Niveau moteur (test 4)

| Profil | Message attendu |
|--------|-----------------|
| Focal non strict | `strictement supérieur` |
| Somme modifiée > 1 | `> 1` |
| Taux hors [0,1] | `hors [0,1]` |
| Trio partiel | `doit fournir` |
| Axe absent | `indissociables` |
| Axe/sens hors vocabulaire | `axe_variation` / `sens_variation` |
| Référence absente | `taux de référence requis` |
| Gel / choc / cible / creux | `SIM-03` / `SIM-04` / `SIM-05` / `SIM-06` |
| Alias passage | `SIM-07` / `SIM-08` |

Zéro scénario persisté après refus.

### Niveau catalogue (test 5)

| Profil | Message attendu |
|--------|-----------------|
| Paire focale non dégradante | `dégradation` |
| Amélioration du passage | `amélioration` |
| Une seule dégradation stricte | `deux axes` |
| Somme modifiée > 1 | `> 1` |
| Axe/sens hors valeurs | `non autorisée` |
| `taux_modifie` absent | `taux_modifie` |
| Clés interdites (6 cas) | `inconnu` |

Schéma publié vérifié : `taux_modifie` requis ; axe = 3 valeurs ;
sens = 2 valeurs.

---

## D. Déterminisme / rétrocompatibilité (test 6)

- Même graine → même `id`, même `hash_resultat`, aucune duplication.
- Graine différente → nouvel `id`, variante 0 identique.
- Sans variation : aucune clé de réussite, trajectoire unique `[60, 40, 31]`.

## E. Analyses (test 7)

- OAT : passage base = **0.5**, redoublement base = **0.3** (valeurs
  MODIFIÉES, jamais 0.6/0.2).
- Aucune ligne `taux_abandon` (non réinjecté).
- Monte-Carlo : 50 itérations, `taux_absents = false`.

---

## F. Couverture globale du module

```
SimulationTemporelleSim13Test.php      →   8 tests / 107 assertions (NOUVEAU)
SimulationTemporelleCatalogueTest.php  →  23 tests / 598 assertions (métadonnées +8)
Historique SIM-01 → SIM-12             → 160 tests / 1763 assertions inchangés

Total module : 168 tests / 1878 assertions / 0 échec
```

## G. Commandes

```bash
vendor/bin/phpunit --filter="SimulationTemporelleSim13Test"   # 8/107
vendor/bin/phpunit --filter="SimulationTemporelle"            # 168/1878
vendor/bin/pint --test --dirty                                # OK
```
