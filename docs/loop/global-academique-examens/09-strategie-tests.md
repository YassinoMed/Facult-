# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 09 · Stratégie de tests

**Date :** 2026-11-03  
**Principe :** une preuve = une exécution réelle, datée, avec code de sortie  
**Interdit :** affaiblir une assertion, marquer `skipped` pour verdir, déduire un succès d'une absence d'exécution  

---

## 1. Niveaux de test

| Niveau | Cible | Exemples |
|---|---|---|
| **Unitaire** | Moteurs purs, règles sans I/O | `ConflitEpreuveService`, calculs de crédits, matrice de transitions |
| **Feature** | Routes, policies, services, transactions | `ExamGouvernanceHttpAuthorizationTest`, `EligibiliteExamenTest` |
| **Intégration** | Chaînes de services et lectures/écritures | `Acquis/`, `ExamGroupesVirtuelsLoopGlobalTest` |
| **Sécurité** | Autorisation, isolation, journal | `IamPermissionsEtCycleDeVieTest`, `ExamGouvernanceSecuriteConcurrenceTest` |
| **Non-régression** | Suites historiques du domaine | `tests/Feature/Acquis/` |

---

## 2. Cartographie des suites exécutées

| Lot | Suite ciblée | Fichiers principaux |
|---|---|---|
| 1 — Acquis | 201 tests | `tests/Feature/Acquis/*` (13 fichiers) |
| 2 — Éligibilité | 32 tests | `EligibiliteExamenTest`, `InscriptionEligibilite*` |
| 3 — Planification | 143 tests | `ExamCapacite*`, `ExamensInvariants*`, `ExamGroupe*`, `PlanificationAutomatiqueExamensTest`, `Loop06*` |
| 4 — Gouvernance | 76 tests | `ExamGouvernance*`, `ExamPublicationEnterpriseTest`, `ExamenCycleVieTest` |
| Sécurité / migration | 27 tests | `IamPermissionsEtCycleDeVieTest`, `ExamGouvernanceSecuriteConcurrenceTest`, `MigrationHistoriqueDryRunLoop41Test` |

> **Attention :** ces suites ne sont **pas disjointes**. Un même fichier peut
> appartenir à plusieurs filtres (ex. `MigrationHistoriqueDryRunLoop41Test`
> est aussi dans `tests/Feature/Acquis/`). Aucun total global n'est donc
> calculé par addition. Les chiffres font foi **par exécution**.

---

## 3. Critères d'acceptation

1. Toute règle du registre `03` possède au moins un test exécuté.
2. Toute correction (`ACQ-RED-01`) est couverte par les tests redevenus verts.
3. Toute assertion attendue est calculée indépendamment (pas de recopie de la
   formule du service dans le test).
4. Aucun test ne dépend d'une donnée de production.
5. Les refus (403, `DomainException`, anomalie) sont testés autant que les
   succès.
6. Un test ignoré n'est jamais compté comme réussi.

---

## 4. Limites d'environnement

- SQLite `:memory:` + `RefreshDatabase` pour l'ensemble des suites.
- `pcntl` absent : les scénarios de concurrence multi-processus ne sont pas
  exécutables localement (4 suites documentées).
- `lockForUpdate` est un no-op sur SQLite : les garanties de verrouillage
  MySQL ne sont pas prouvées par ces tests locaux.

Ces limites sont **explicites** et n'invalident pas les tests fonctionnels
exécutés ; elles interdisent seulement de revendiquer une preuve de
concurrence réelle.

---

## 5. Verdict

**PASS_WITH_RESERVATIONS.** La stratégie couvre les règles critiques et les
refus, mais la concurrence multi-processus reste non prouvée localement.
