# API — versionnement (Phase 11 §11.2, §11.33)

`/api/v1` stable : jamais de rupture silencieuse (test de dérive
OpenAPI + contrat scopes). Rupture majeure → `/api/v2` (inexistante
à ce jour — aucune exigence). Ressources versionnées côté métier
(règles, maquettes), pas côté transport. Dépréciation : annoncée,
jamais de suppression sans migration documentée.
