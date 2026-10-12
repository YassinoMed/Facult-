# Sécurité & Performance (§28, §36-38 + Phase 2)

## Chaîne

`auth → admin → can → faculteRestreinteId() serveur → validation →
AffectationService → transaction lockForUpdate → SituationEtudiant/Audit →
notification`.

## Garde-fous

- IDOR/BOLA : `verifierPerimetre` + `peutAccederFaculte` sur chaque groupe/étudiant ;
  `faculte_id` client ignoré pour `admin_faculte` (re-scopé) ; `force` exige
  `groupes.manage` (403 sinon). Exports regroupés re-scopés pareil.
- Mass assignment : `Etudiant::definirStatut` exclusif ; `fillable` sans `statut`.
- Colonnes sensibles : `cin/email/telephone` via `exports.personal` uniquement ;
  CIN masquée sinon — plats ET regroupés (permission calculée une fois par jeu) ;
  jamais de notes/finance/documents dans les listes.
- Exports différés : `ImportReport type=listes.etudiants` sans PII (filtres +
  compteurs) ; téléchargement propriétaire ou super-admin ; expiration
  `export_retention_hours` + purge (données personnelles).
- Uploads/imports : validation `GroupDefinition`/`AffectationGroupeDefinition` +
  parsers plafonnés ; preview obligatoire, aucune écriture avant `confirmer`.
- Audit : `SituationEtudiant` (qui/quand/ancienne/nouvelle/motif) + `AuditService`
  (sans PII) ; `IntegrationLog` avec `request_id`.

## Performance

`with()` + `withCount()` + `chunk(1000)` (jamais `cursor()` + `with` : eager
ignoré = N+1 avéré par `PopulationPerformanceTest` puis corrigé), pagination
12/25, streaming exports, `slice 5000` bulk, PDF plafonné 1000, async >5000.
Index composites migration `2026_09_30_185905_add_index_listes_population`.
Note suite complète : limite CLI locale 128M insuffisante pour les rendus PDF
en un seul processus — lancer `php -d memory_limit=512M vendor/bin/phpunit`
(`artisan test` ne propage pas `-d`).

Tests Phase 2 (33) : `PdfGroupingTest`, `PdfStudentListTest`, `PdfGroupListTest`,
`AsyncExportTest`, `SavedViewTest`, `PopulationPerformanceTest`,
`ResponsiveListTest`, `SensitiveExportTest`.
