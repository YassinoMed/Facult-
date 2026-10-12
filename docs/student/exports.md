# Exports dossier (Phase 8 §25-26)

Collectifs : `ListeEtudiantsService` + `GeneratePopulationExport`
(datasets, queue, ImportReport). Individuel unifié :
`etudiants.exportDossier` (JSON : synthèse, parcours, documents
métadonnées, demandes, timeline ≤100 — sensibles exclus, audité,
throttle). Parcours CSV : `archives.export`. Jamais de PII inutile
(médical/disciplinaire/sensible exclus par construction).
