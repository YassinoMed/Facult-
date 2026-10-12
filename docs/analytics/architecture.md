# Analytics — architecture (Phase 10)

```text
BASE MÉTIER (tables transactionnelles)
  → SERVICES MÉTIER (PopulationStatsService, CalculEvaluationService, …)
    → BIService::indicateurs (projections + agrégations SQL)
      → Cache versionné (clé année×faculté×programme)
        → Dashboards / exports CSV / prewarm scheduler
```

Aucune base concurrente : pas de Data Warehouse (volumes compatibles
MySQL + index, §10.2 — décision documentée, réévaluable). BI en
lecture seule : aucun dashboard ne modifie une donnée académique.
Qualité : `AnomalieQualite` + `AgregateurQualite` (détecteurs existants,
`faculte_id` persisté au balayage, NULL = global).
