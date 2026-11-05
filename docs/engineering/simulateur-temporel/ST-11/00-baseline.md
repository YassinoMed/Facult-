# ST-11 — 00 · Baseline indépendante

**Date :** 2026-11-01 · Module `app/Modules/SimulationTemporelle/` · Branche `main`, WIP 244 entrées préservé.

## 1. Inventaire réel (réconciliation de l'inventaire annoncé)

| Type | Réel | Annoncé | Écart |
|---|---|---|---|
| Contrôleur | 1 (`SimulationTemporelleController`, 408 lignes, 16 actions) | — | — |
| Modèles | 3 (`ScenarioTemporel`, `ExecutionTemporelle`, `SnapshotExecution`) | — | — |
| Service orchestrateur | 1 (`ServiceSimulationTemporelle`, 533 lignes) | 1 | ✅ |
| Adaptateurs | 2 (`AdaptateurEffectifsCohortes`, `ContratAdaptateur`) | 1 | — |
| Classes Support | 17 (MoteurEvenements, HorlogeSimulee, GenerateurVariantes, EvaluateurScenarios, ClasseurScenarios, ExplicateurScenarios, ComparateurProjections, EstimateurCapacitesGroupes, AnalyseurSensibilite, SimulateurMonteCarlo, OptimiseurPareto, ProjecteurBudgetaire, AuditeurQualiteDonnees, GestionnaireAlertesSeuils, ExplicateurIaSimulation, ExporteurSimulation, **EvenementSimule** (DTO non listé)) | 15 annoncés « services dédiés » | **Le décompte de 15 était incohérent** : 17 classes Support + 1 service = 18 unités de calcul. Tous les services cités au §1.3 existent. |
| Vues | `modules/simulation-temporel/index.blade.php` + `pdf.blade.php` | — | — |
| Routes | 16 (pas 15) | 15 | +1 (`options/groupes/{niveauId}`) |
| Tests | 1 suite (`SimulationTemporelleTest`) | 64 tests / 342 assertions | **47 tests / 224 assertions réels** |

## 2. Baseline de tests (sortie réelle)

```
php artisan test --filter="SimulationTemporelleTest"
→ 47 tests, 47 passed, 224 assertions, 4.5 s  (AVANT corrections ST-11)
```

**Écart ST-10 confirmé** : le chiffre annoncé (64/342) ne correspond à aucun état reproductible du dépôt (voir `03-verification-ST10.md`).

## 3. État des contrôles à l'entrée de campagne

| Contrôle | État constaté |
|---|---|
| Middleware `module:simulation-temporelle` | ✅ présent sur le groupe (404 si désactivé) |
| `auth` + `admin` + `secondaire.verrou` | ✅ |
| Permission routes | ⚠️ **`can:statistiques.view` sur les 16 routes** — consultation réutilisée pour créer/exécuter/décider → **P0-1 (corrigé)** |
| Isolation facultaire | ✅ `FacultePerimetre::resoudre` + `peutAccederFaculte` dans chaque action |
| Throttle familles | ✅ `st-def` 20/m, `st-exec` 10/m, `st-dup` 20/m, `st-ver` 20/m, `st-ia` 20/m, `st-gov` 15/m |
| Gouvernance 4 yeux | ✅ auto-approbation interdite, hash figé, proposition unique |
| Lecture seule métier | ✅ adaptateur 0 écriture ; test avant/après (élargi à 10 tables pendant la campagne) |
| Déterminisme | ✅ `hash_entrees` inclut version moteur ; graine SHA-256 ; idempotence UNIQUE |

## 4. Corrections réalisées pendant la campagne (P0)

1. **P0-1 — permissions dédiées `simulation.*`** (7 permissions : view, gerer, executer, export, explication, gouvernance.proposer, gouvernance.decider) : routes + 16 Gates contrôleur + tests via habilitations IAM réelles + 3 tests de sécurité nouveaux. **Aucun rôle existant élargi** (super_admin = TOUTES uniquement ; attribution = décision institutionnelle).
2. **P0-2 — preuve lecture-seule élargie** de 4 à 10 tables métier (matières, UE, groupes pédagogiques, notes, décisions, inscriptions pédagogiques) + export CSV inclus dans le chemin testé.

## GATE A — ✅ VALIDÉ

Les déclarations historiques sont distinguées des preuves (§1, §2) ; l'inventaire est réconcilié (26 fichiers) ; les écarts ST-10 sont documentés.
