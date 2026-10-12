# API population (Phase 2)

Réutilisée, inchangée : `GET /api/v1/students` (search/faculty/status/niveau,
`per_page` ≤ 100), `GET /{id}`, `/academic-record`, `/grades`, `/documents`
(métadonnées seules). Sanctum + scopes + `throttle:api` + périmètre relu en
base. Pas d'écriture (POST/PATCH/DELETE absents — surface minimale
volontaire). CIN complète jamais exposée inutilement ; rien de médical.
