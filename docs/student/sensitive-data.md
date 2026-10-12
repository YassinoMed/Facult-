# Données sensibles (Phase 8 §7)

`student_sensitive_records` (métadonnées, soft delete, 4 types :
médical/disciplinaire/situation/administratif) + policies PAR TYPE +
onglet `confidentiel` (404 sinon) + timeline `sensible` séparée.
Exclusions par construction : recherche globale/instantanée, API
`students/*`, exports DataExchange et dossier, documents officiels.
Réutilisé tel quel — aucune table/permission créée.
