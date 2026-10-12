# API référentiel (`/api/v1/referentiel/*`, lecture seule)

Scope `referentiel:read`, throttle `api`, pagination bornée (`per_page_max`
100), filtres whitelistés (`search`, `programme`, `statut`), périmètre
faculté relu en base (anti-BOLA). Endpoints : `programmes`, `niveaux`,
`semestres`, `ues`, `matieres`, `maquettes`. Dérive OpenAPI testée
(`test_openapi_couvre_routes` — toute route documentée dans
`docs/openapi.yaml`).
