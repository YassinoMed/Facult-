# API v1 (référence : `docs/API.md`, `docs/openapi.yaml`)

Base `/api/v1/*` (`routes/api.php:22-133`), `auth:sanctum`, scopes
(`students|archives|grades|documents:read`, `imports:create`,
`schedules:import`, `data:exchange`, `exports:create` — AUCUN scope
médical/sensible), throttle `api(60)/imports(5)/exports(10)/webhooks(30)`.
Enveloppe `{message, code, request_id}` + `errors` 422, `X-Request-ID`
corrélé. Resources (8, jamais de modèle brut, jamais de binaire :
documents = métadonnées). Détail : `docs/API.md`.
