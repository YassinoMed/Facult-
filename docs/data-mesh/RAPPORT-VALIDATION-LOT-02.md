# Data Mesh — RAPPORT DE VALIDATION LOT-02 (durcissement, pagination, fiabilité)

Date : 16/10/2026 · Verdict : `PASS_WITH_RESERVATIONS`.

## 1. Audit (phase 1)
Docs lot 1 relues ; code audité : `app/DataMesh/` avait été étendu entre-temps
(outbox `OutboxInterface`/`OutboxService`+migration, `FluxVerticalDataMesh`, produits
`affectations_enseignement`/`resultats_examens`, catalogue à 6 produits) — intégré tel quel,
aucune réécriture. État initial reproductible : env `local`, base `inscription_etudiants`,
seed frais (6 users, 2037 étudiants, 2073 inscriptions, 154 groupes, **0 affectation**,
25 historique), aucun `config:cache`.

## 2. Protection des données (phase 2)
- Environnement/base confirmés ; **aucune sauvegarde récupérable** (`database_backups`=0,
  `storage/app/backups` vide ; `BackupService::verify()` existe mais non exercé ici) → réserve.
- Historiques : 25 `creation_dossier`, 0 trace auto → rien à restaurer, tout est à recalculer.
- **Aucun `migrate:fresh`, `db:wipe` ni traitement massif dans ce lot** (que des lectures + code).

## 3. Pagination (phase 3)
`inscriptions_validees` **v1.0 → v1.1 MINEUR compatible** : clés v1.0 (`total`, `inscriptions`)
inchangées + `page` (défaut 1), `par_page` (défaut 100, 1..1000), `pages` ; filtres
`faculte_id`/`niveau`/`annee`/`programme_id` bornés serveur ; tri whitelisté
(`id`|`created_at`, repli `id`), sens repli `asc`. Paramètre invalide → défauts, jamais d'erreur.
Mesures (2060 lignes) : défaut 100 → **5 req / 35 ms / 24 Ko** (avant : ~400 Ko) ; max 1000 → 5 req / 128 ms.

## 4. Qualité et gouvernance (phase 4)
- Fraîcheur par produit déjà en contrat ; ajout doc : conservation (légal vs métier à valider),
  triptyque capacités **finie / inconnue-NULL / illimitée-explicite** (aucun marqueur base à ce jour :
  tout `NULL` = *inconnu*, décision en attente), propriétaires/consommateurs au sommaire.
- Contrôles sur données réelles : 0 bloquant ; 3 capacités inconnues + 14 sans-inscription (avertissements).
- Isolation, refus, PII : couverts en tests (dont `resultats_examens` restreint aux rôles + `publie`).

## 5. Simulation (phase 5, lecture seule)
`MassAssignmentService::analyser()` année 7 : **2000/2000 propositions, 0 anomalie**,
remplissage max 14/30, 0 dépassement, 0,96 s ; **0 affectation avant comme après**
(preuve de non-mutation). Écarts : 12 `en_cours` non éligibles, 9 M1/M2 hors année 7 (sans groupes).

## 6. Tests (phase 6) — tous verts, sqlite imposé
- `DataMeshTest` **11/11 (326 assertions)** : + pagination/bornes/déterminisme, filtres, compat v1.0.
- Non-régression : suivi 10, accueil 7+4+15, périodes 14+16, affectation-auto 11 → **88/88 au total** (0 skip, 0 échec).

## 7. Livrables
Code : `InscriptionsValidees` v1.1 + 2 tests. Docs : `CONTRACTS.md` (§5 pagination),
`DATA-PRODUCT-CATALOG.md` (v1.1 + sommaire), `GOVERNANCE.md` (conservation, triptyque capacités),
`RAPPORT-VALIDATION-LOT-02.md` (ce fichier).

## 8. Réserves restantes
1. Aucune sauvegarde récupérable (bloquant toute reconstruction — à créer + tester hors lot).
2. Rattrapage 2000 affectations **non réappliqué** (interdit ce lot ; nécessite décision explicite).
3. `TeachingAssignments`/`ExamResults` : exposition sous permission, tests dédiés limités.
4. `NULL` vs illimitée-explicite : marqueur base + décision métier en attente.
