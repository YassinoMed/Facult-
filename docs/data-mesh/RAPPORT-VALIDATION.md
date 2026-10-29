# Data Mesh — RAPPORT DE VALIDATION (lot 1)

Date : 16/10/2026 · Verdict : `PASS_WITH_RESERVATIONS`.

## 1. Fichiers créés / modifiés
Créés : `app/DataMesh/Contrats/ProduitDonnees.php`, `app/DataMesh/Catalogue.php`,
`app/DataMesh/Produits/{ProduitSocle,CatalogueAcademique,InscriptionsValidees,AffectationsGroupes,AnalytiqueFaculte}.php`,
`app/DataMesh/Qualite/ControlesDonnees.php`, `tests/Feature/DataMeshTest.php`,
`docs/data-mesh/{BASELINE,ARCHITECTURE,CONTRACTS,DATA-PRODUCT-CATALOG,GOVERNANCE}.md` (+ ce rapport).
Modifiés (hors lot, réparations constatées) : migration `2026_10_16_162742` (`SET NULL` sur colonne `NOT NULL` → `nullable()->nullOnDelete()`),
`tests/TestCase.php` (garde sqlite), `AppServiceProvider` (`prohibitDestructiveCommands` en prod).

## 2. Produits et contrats opérationnels
4/4 au catalogue v1.0 (`Catalogue::codes/liste/produit` vérifiés en test) : AcademicCatalog,
StudentEnrollment, StudentGroupAssignment, FacultyAnalytics (consommateur `BIService`).
Flux vertical §10 fonctionnel en lecture : référentiel → inscriptions validées (PII masquée) →
état d'affectation (décisions via services existants) → agrégats versionnés.

## 3. Tests exécutés (résultats exacts)
- `DataMeshTest` : **9/9, 193 assertions** (catalogue, conformité contrats, refus sans consommateur/rôle,
  isolation facultaire, PII minimisée + idempotence, occupation + garde ≤15 requêtes,
  6 contrôles sur anomalies réelles, agrégats sans ligne individuelle).
- Non-régression : `InscriptionSuiviDossierTest` 10/10, `AffectationAutomatiqueApresValidationTest` 11/11,
  `InscriptionAccueilParcoursTest` 7/7, `ParcoursAccueilEmbarqueTest` 4/4, `LoopPopupHomeOnly04Test` 15/15,
  `PeriodeInscriptionTest` 14/14, `InscriptionPagePeriodeTest` 16/16, masse/métriques 37 (36 + 1 skip préexistant).

## 4. Mesures reproductibles (dev local, ~2000 lignes, MySQL)
| Produit | Requêtes | Durée | Volume |
|---|---|---|---|
| catalogue_academique | 5 | 30 ms | 0,8 Ko |
| inscriptions_validees | 4 | 167 ms | ~400 Ko (2037 lignes — pagination recommandée lot 2) |
| affectations_groupes | 1 | 7 ms | 45 o (0 affectation, post-seed) |
| analytique_faculte | 22 | 40 ms | 2 Ko (puis cache 600 s) |
| contrôles qualité (6 règles) | ~8 | 84 ms | — |
Contrôles sur données réelles : 0 bloquant ; 3 capacités inconnues + 14 sans-inscription (avertissements).

## 5. Réserves et décisions en attente
1. `TeachingAssignments` / `ExamResults` non exposés (notes confidentielles — décision métier requise).
2. `inscriptions_validees` volumineux sans pagination (lot 2).
3. Conservation/suppression des données : règle métier à documenter.
4. Incidents de session : wipe dev par `config:cache` périmé + migration `SET NULL` invalide (réparés, gardes ajoutés) ; rattrapage 2000 affectations à rejouer sur décision.
