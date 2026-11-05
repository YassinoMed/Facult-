# ST-11 — 03 · Vérification de ST-10

## 1. Chiffres annoncés vs réels

| Élément | Annoncé ST-10 | Relevé indépendant (2026-11-01) | Verdict |
|---|---|---|---|
| Tests | 64 | **47** (avant corrections ST-11) | ❌ non reproductible |
| Assertions | 342 | **224** | ❌ non reproductible |
| Suite | `SimulationTemporelleTest` | idem (seule suite du module) | ✅ |
| Verdict « PASS » | annoncé | à réévaluer sur preuves | voir §3 |

Recherche de suites additionnelles (unitaires, autres répertoires) : **aucune** — 47/224 est la totalité mesurable. Les chiffres 64/342 n'ont pas été retrouvés dans aucun état exécutable du dépôt (probable : comptage erroné ou environnement perdu). Conformément au cahier (§A5 : « Ne pas considérer ces chiffres comme une couverture complète »), ils sont **infirmés** et remplacés par la mesure réelle.

## 2. Contenu réel de la suite (47 tests initiaux)

Les noms de tests révèlent une couverture fonctionnelle large et vérifiée à la lecture du code :

| Domaine | Tests existants (échantillon) |
|---|---|
| Création/duplication | `test_scenario_cree_et_code_duplique_refuse`, `test_correction_cree_par_idempotent` |
| Validation critères | `test_criteres_invalides_refuses`, `test_graine_manquante_ou_invalide_rejetee`, `test_contraintes_incompatibles_plancher_superieur_plafond_rejetee` |
| Déterminisme/idempotence | `test_execution_reproductible_et_idempotente`, `test_graines_differentes_resultats_differents` |
| Conservation LMD | `test_st10_lot6_transitions_lmd_deterministes_et_conservation` |
| Lecture seule | `test_preuve_absolue_absence_ecriture_tables_metier`, `test_aucune_ecriture_metier_et_snapshots_sans_pii` |
| Analyses | audit qualité (lot1), comparaison (lot2), explication scores (lot3), versionnement (lot4), capacités (lot5), sensibilité (lot7), exports (lot8), Pareto (lot9), Monte-Carlo (lot10), budget (lot11), IA (lot12), alertes (lot13), gouvernance (lot14) |
| UI/périmètre | écran accessible, sidebar filtrée, options filtrées, groupes hors périmètre |

## 3. Réévaluation du verdict ST-10

- Les **fonctionnalités annoncées existent** (17 classes Support vérifiées, 16 routes, analyses présentes).
- Les **preuves de sécurité étaient incomplètes** : la permission de consultation couvrait toutes les écritures (P0-1 corrigé par ST-11).
- Les **chiffres étaient inexacts** (64/342 annoncés, 47/224 réels).

**Verdict ST-10 révisé : PASS_WITH_RESERVATIONS** (et non PASS) — les réserves P0 ayant été corrigées par ST-11, l'état courant est évalué séparément (voir `18-verdict-ST11.md`).

## 4. Après corrections ST-11

```
php artisan test --filter="SimulationTemporelleTest"
→ 50 tests, 50 passed, 300 assertions, 4.8 s
```

(+3 tests de sécurité ST-11, +6 assertions par l'élargissement de la preuve lecture-seule à 10 tables métier.)
