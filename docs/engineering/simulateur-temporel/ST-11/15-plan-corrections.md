# ST-11 — 15 · Plan des corrections

## Anomalies détectées et traitées

| ID | Anomalie | Gravité | Cause racine | Fichier(s) | Correction | Test de non-régression | Statut |
|---|---|---|---|---|---|---|---|
| ST11-ANO-01 (P0) | Permission de **consultation** (`statistiques.view`, 7 rôles dont `consultation`) autorisait toutes les écritures du module, y compris **décider une gouvernance** | **CRITIQUE** (séparation des responsabilités) | Réutilisation d'une permission de lecture pour tout le module (convention ST-04 héritée) | `Permissions.php`, `Routes/web.php`, `SimulationTemporelleController.php`, `SimulationTemporelleTest.php` | 7 permissions dédiées `simulation.*` (view/gerer/executer/export/explication/gouvernance.proposer/gouvernance.decider) ; 16 routes + 16 Gates synchronisés ; **aucun rôle élargi** ; habilitations IAM pour les acteurs de test | ST11-SEC-011/012/013 (3 nouveaux tests) | ✅ corrigée |
| ST11-ANO-02 (P1) | **Sur-émission d'effectifs aux frontières d'arrondi** : partent + redoublants arrondis peuvent dépasser l'effectif (ex. 11 élèves, 0.5/0.5 → 12 flux) | **ÉLEVÉ** (exactitude) | Arrondis indépendants par flux sans plafond | `ServiceSimulationTemporelle::simulerVariante` | Garde de conservation : `redoublants = max(0, effectif − partent)` | ST11-MATH-014 (61+11−15=57 exact) | ✅ corrigée |
| ST11-ANO-03 (P2) | Preuve lecture-seule limitée à 4 tables métier | MOYEN | Registre C2 incomplet dans le test | `SimulationTemporelleTest::test_preuve_absolue…` | Élargie à **10 tables** (matières, UE, groupes, notes, décisions, inscriptions pédagogiques) + export CSV dans le chemin testé | le test lui-même | ✅ corrigée |
| ST11-ANO-04 (INFORMATION) | Chiffres ST-10 non reproductibles (64/342 vs 47/224) | INFORMATION | Comptage erroné historique | docs ST-10 | Infirmés, remplacés par la mesure réelle | `03-verification-ST10.md` | ✅ documentée |

## Anomalies classées BLOCKED_BUSINESS (aucune correction sans décision)

| ID | Point | Décision attendue |
|---|---|---|
| ST11-BB-01 | Attribution des 7 permissions `simulation.*` aux rôles métier (par défaut : refus, seul super_admin) | Institution (quels rôles créent/exécutent/décident ?) |
| ST11-BB-02 | Amplitude des perturbations Monte-Carlo (±8 % delta, ±4 % passage) — hypothèses codées, à valider par la direction des études si elles doivent devenir réglementaires | Institution |

## Compléments post-verdict (Phases B/C — 2026-11-02)

L'audit approfondi (`04-audit-securite.md`, `05-preuve-lecture-seule.md`) n'a révélé **aucune nouvelle anomalie critique ou élevée**. Il ajoute les constats suivants, qui ne modifient pas le verdict : ils relèvent de la Phase N (durcissement), de la Phase M (test manquant) ou d'une décision institutionnelle.

| ID | Constat | Gravité | Correction proposée | Nature |
|---|---|---|---|---|
| ST11-SEC-R1 | Canal 403/404 sur ressource hors périmètre | FAIBLE | 404 uniforme (convention dépôt) | Phase N |
| ST11-SEC-R2 | Année d'exécution non liée à la faculté/au statut actif | MOYEN | Validation métier (EC-05) | Phase N |
| ST11-SEC-R3 | Sémantique `faculte_id = null` non tranchée | MOYEN | Décision institutionnelle (EC-06) | BLOCKED_BUSINESS |
| ST11-SEC-R4 | Immuabilité snapshots sans contrainte DB | INFO | Trigger/observer si exposition future | Phase N (optionnel) |
| ST11-SEC-R5 | Préfixes de contrôle CSV non neutralisés | FAIBLE | `trim` avant test de formule | Phase N |
| ST11-SEC-R6 | Libellé CSV « RAPPORT OFFICIEL » | FAIBLE | Reformuler | Phase N |
| ST11-SEC-R7 | PDF « certifié conforme » | FAIBLE | « projection non opposable » | Phase N |
| ST11-SEC-R8 | Test de throttle (429) absent | MOYEN | Test 429 + en-têtes + isolation familles (SEC-010) | Phase M |
| ST11-SEC-R9 | Hash non recalculé après gouvernance | INFO | Documenter la limite | Documentation |
| ST11-SEC-R10 | ID proposition basé sur `time()` | INFO | Acceptable | — |
| ST11-RO-R1 | Exécution + snapshots non transactionnels | MOYEN | `DB::transaction` autour des l.334-356 | Phase N |

**Aucune de ces réserves ne touche les données métier, la confidentialité ou l'autorisation.** Les corrections Phase N restent additives et sans migration destructive.

> **Correspondance de libellés** : `ST11-SEC-R8` est référencé `ST11-R8` dans le rapport final consolidé. Les deux désignent la même réserve (test de dépassement du throttle, statut 429).

## Non-corrections justifiées

- **Conventions quantiles** (index plancher, médiane supérieure) : convention cohérente et documentée — changer interpolerait sans nécessité métier.
- **50 itérations Monte-Carlo** : pas une erreur — limite de coût documentée ; ne prétend pas à la convergence.
- **Vues** : refonte = lot ST-12 (pas une correction de sécurité).
