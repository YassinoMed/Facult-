# BASELINE-HARDENING (Phase A — lecture seule, 16/10/2026)

## 1. Périmètre vérifié
- `app/DataMesh/` : 15 fichiers (Catalogue, 2 contrats, 6 produits, ProduitSocle, Flux, Outbox×2, Qualite).
- `docs/data-mesh/` : 8 documents (lots 01 + 02 + rapports).
- Migrations : 161 jouées dont `2026_10_16_180000_create_data_mesh_outbox_table` ; table `data_mesh_outbox` présente et vide.
- **Non versionné** : `app/DataMesh/`, `docs/data-mesh/`, migration outbox sont `??` (untracked) — risque P1 : travail non commité.
- Base dev : 2037 étudiants, 2073 inscriptions, 154 groupes, **0 affectation active**, 25 historique ; pas de `config:cache`.
- Tests Data Mesh : **42 tests, 39 verts, 3 échecs connus** (détaillés §3). CI : `pint --test`, `php artisan test --compact`, `composer audit`, `npm audit`, build Vite.

## 2. Six produits (versions, accès, état)
| Produit | Ver | Accès réel | État |
|---|---|---|---|
| `catalogue_academique` | 1.0 | rôle back-office + périmètre | OK |
| `inscriptions_validees` | 1.1 | idem + `EtudiantPolicy::viewAny` ; pagination bornée | OK (contrat) |
| `affectations_groupes` | 1.0 | idem + policy | OK |
| `affectations_enseignement` | 1.0 | rôles restreints ou `enseignements.view` (permission existante §Permissions:130) | OK, tests limités (P1) |
| `resultats_examens` | 1.0 | rôles restreints ou `resultats.view` (permission existante :180) + `publie=true` obligatoire | OK, tests limités (P1) |
| `analytique_faculte` | 1.0 | idem + policy ; dérivé `BIService` versionné/cache 600 s | OK |

## 3. Trois échecs existants (non-régressions du lot 02, causes identifiées)
1. `DataMeshContractsTest:44` — version codée en dur `'1.0'` pour tous ; `inscriptions_validees` est en 1.1 (légitime, CONTRACTS.md §5).
2. `DataMeshPerformanceBenchmarkTest:139` — borne 5 requêtes ; v1.1 ajoute 1 COUNT indexé (total 6, O(1)). Garde N+1 réelle intacte.
3. `DataMeshVerticalFlowTest:132` — même cause que (1).
Aucun ne signale un défaut de comportement.

## 4. Outbox (constats Phase B)
- Schéma sain : `cle_deduplication` UNIQUE, index `(statut, created_at)`, `tentatives`, `publie_le`.
- **P0-1** : `enregistrer()` = SELECT-puis-INSERT non atomique → en concurrence, le 2e appel lève `QueryException` brut au lieu de retourner l'existant (l'UNIQUE sauve l'intégrité, pas l'ergonomie).
- **P1-1** : transitions non gardées (`published` → `failed` possible via `marquerEchec`).
- **P1-2** : aucun publisher planifié (commande/schedule) → les `pending` ne partent jamais seuls.
- **P2-1** : aucune purge des `published` (croissance table) ; pas de test de conservation des `pending/failed`.
- Payload = responsabilité de l'appelant (aucun appelant prod actuel — vérifié par grep, hors tests).

## 5. Capacités (constats Phase C)
- `Group::$fillable` : `capacite` nullable, **aucun marqueur d'illimitation explicite**.
- Moteur (`AffectationEngine:143`, `MassAssignmentService:analyser:169`, `AffectationService::affecter`) : `NULL` = illimité.
- **5 suites de tests utilisent des capacités NULL** (`AffectationMoteurTest`, `ChangementGroupeTest`, `DataMeshTest`, `InscriptionAffectationAcademiqueTest`, `InscriptionAutomationTest`) → tout changement de sémantique NULL casse du vert existant.
- Données : 3 groupes NULL (faculté 1, L1/L2), 151 finies (4530 places).

## 6. Sécurité/qualité/flux (Phases D-F)
- Matrice : rôles réels + `enseignements.view`/`resultats.view` existantes ; tests négatifs partiels (manquent : hors-périmètre sur les 2 produits restreints, paramètres manipulés ciblés).
- `ControlesDonnees` : 8 règles ; `synchroniserVersAnomalies` persiste vers `anomalies_qualite`.
- Flux vertical : écrit 1 audit (`data_mesh.flux_vertical_execute`) ; ne distingue pas étapes ignorées/échouées ; ne signale pas explicitement « 0 affectation active ».

## 7. Sauvegarde/déploiement (Phase H)
- `BackupService` (+`verify`, commandes `ExecuterSauvegardeJob`, `EvaluerSauvegardes`) existe ; `database_backups` = 0 ligne, `storage/app/backups` vide → **aucune sauvegarde récupérable démontrée** (P0-2).
- RPO/RTO : non définis (décision métier requise).

## 8. Risques priorisés
| ID | Risque | Gravité |
|---|---|---|
| P0-1 | Dedup outbox non atomique (501 concurrent → exception brute) | P0 |
| P0-2 | Aucune sauvegarde récupérable démontrée | P0 |
| P1-1/2/3 | Transitions outbox non gardées / pas de publisher planifié / pas de purge | P1 |
| P1-4 | Travail Data Mesh non commité (untracked) | P1 |
| P1-5 | Couverture négative limitée sur `affectations_enseignement` / `resultats_examens` | P1 |
| P2-1 | Triptyque capacités : migration additive + sémantique NULL à décider (5 suites impactées) | P2 |
| P2-2 | Flux vertical : états ignoré/échoué + diagnostic « 0 affectation » manquants | P2 |
| P3-1 | 3 assertions de version/borne périmées (lot 02) | P3 |

## 9. Plan proposé (lots limités, sans wipe, sans réappliquer les 2000)
1. **Correctifs P3** (tests version/borne + garde N+1 renforcée par invariance au volume).
2. **Phase B** : catch violation unique → existant ; garde `published` terminal ; commande `datamesh:outbox-publier` (+ purge `published` > 30 j) ; tests transaction/rollback, concurrence, reprise, idempotence, rétention.
3. **Phase D** : matrice + tests négatifs systématiques (6 produits × cas).
4. **Phase E/F** : états flux explicites + diagnostic « 0 affectation » ; contrats gelés (schema snapshot en test).
5. **Phase C** : migration additive `capacite_illimitee` + `Group::modeCapacite()` + qualité distinguant les 3 états ; sémantique NULL : proposition = conserver le comportement moteur jusqu'à décision métier (documenté BLOCKED) — sauf instruction contraire.
6. **Phase G** : mesures avant/après sur sqlite + dev, protocole documenté.
7. **Phase H** : procédure sauvegarde/restauration documentée + vérification schéma ; RPO/RTO proposés.
8. **Phase I + RAPPORT-HARDENING-FINAL** + verdict honnête.
