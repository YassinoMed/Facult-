# RAPPORT DATA MESH — état consolidé de la plateforme universitaire

Date : 16/10/2026 · Périmètre : `app/DataMesh/`, `docs/data-mesh/`, migrations et données liées ·
Méthode : audit lecture seule + vérifications exécutées (preuves ci-dessous).

## 1. Inventaire vérifié

| Élément | État |
|---|---|
| Produits au catalogue (`App\DataMesh\Catalogue`, v1.0) | **6** : `catalogue_academique` 1.0, `inscriptions_validees` **1.1** (pagination), `affectations_groupes` 1.0, `affectations_enseignement` 1.0, `resultats_examens` 1.0, `analytique_faculte` 1.0 |
| Contrat `ProduitDonnees` + `ProduitSocle` (rôle, périmètre facultaire, PII masquée) | En place |
| Outbox transactionnelle (`OutboxInterface`, `OutboxService`, `OutboxMessage`, table `data_mesh_outbox`) | Code + migration OK (voir §3) |
| Flux vertical (`FluxVerticalDataMesh`, 5 étapes référentiel→scolarité→affectation→pilotage→traçabilité) | Opérationnel |
| Contrôles qualité (`ControlesDonnees`, 6 règles) | Opérationnels |
| Docs | `BASELINE`, `ARCHITECTURE`, `CONTRACTS`, `DATA-PRODUCT-CATALOG`, `GOVERNANCE`, `RAPPORT-VALIDATION`, `RAPPORT-VALIDATION-LOT-02` |

## 2. Environnement et données (constaté, non modifié sauf §3)
- `local`, base `inscription_etudiants` (MySQL 9.2), pas de `config:cache`
- 2037 étudiants, 2073 inscriptions, 154 groupes, **0 affectation active**, 25 historique (`creation_dossier`)
- 7 facultés, 1 programme, niveaux L1/L2/L3 ; 3 groupes à capacité `NULL` (= inconnue, réserve)

## 3. Réparation appliquée pendant l'audit
- **Table `data_mesh_outbox` absente** : la migration `2026_10_16_180000` (créée 18:15) n'avait jamais été
  jouée → outbox inopérante. Appliquée via `php artisan migrate --force` (additif, sans wipe).
- Sans cette table, tout appel `OutboxService::enregistrer()` échouait.

## 4. Vérifications exécutées (preuves)
| Contrôle | Résultat exact |
|---|---|
| `DataMeshTest` | **11/11, 326 assertions** |
| Outbox e2e (dev) : déduplication même clé, `publierEnAttente`, statut, `estDejaTraite`, nettoyage | `true`, `1/1`, `published`, `true`, `oui` |
| Flux vertical e2e (année 7) | `SUCCES` en 152 ms : 7 fac / 1 prog / 3 niv, 2000 inscriptions, 0 affectation, 12 indicateurs, flux audité |
| Qualité sur données réelles | 0 bloquant ; 3 capacités inconnues + 14 sans-inscription (avertissements) |
| Pagination v1.1 (2060 lignes) | page 100 → 5 req / 35 ms / 24 Ko (avant ~400 Ko) ; compat v1.0 conservée |

## 5. Trouvailles et risques
1. **Migration orpheline** : un fichier migration peut exister sans jamais être joué (cf. §3) — recommandation :
   contrôle `migrate:status` dans la procédure de mise en service.
2. **Capacités `NULL`** : traitées comme illimitées par le moteur, signalées comme *inconnues* ;
   aucun marqueur « illimitée explicite » en base (décision métier en attente).
3. **Rattrapage des 2000 affectations non réappliqué** (décision explicite requise, hors périmètre de ce rapport).
4. `TeachingAssignments`/`ExamResults` : exposition sous permission stricte, tests dédiés limités.
5. Conservation/suppression : durées d'audit et propositions en attente de validation métier.

## 6. Verdict : `PASS_WITH_RESERVATIONS`
Mesh logique complet, contracté, testé et mesuré ; réserves = §5 (sauvegarde récupérable absente,
rattrapage en attente de décision, marqueur de capacité explicite manquant).

## Annexe — diagnostics d'exécution
- opencode v2.0.30 · Darwin arm64 · Apple_Terminal · /bin/bash · PHP 8.5.11 · MySQL 9.2.0 (Homebrew)
- Commandes : `phpunit --filter=DataMeshTest`, `php artisan migrate --force`, scripts de vérification
  temporaires (supprimés), aucune donnée métier modifiée hors §3.
