# Import population (Phase 2)

Pipeline unique : `StudentsImporter` (règles, normalisation
`NormalisationService`, preview `ImportPreview` sans écriture) →
`DataImportService` (chunk 500) → `confirmer` (transaction atomique ou
partielle) → `IntegrationLog`. Formats CSV/XLSX/JSON/XML via `IoFactory`.

Doublons : CIN (si renseignée) OU email en base + clé interne fichier ;
race guardée (`UniqueConstraintViolationException`) ; rapport d'erreurs
ligne par ligne. Sans CIN : email seul fait foi.
