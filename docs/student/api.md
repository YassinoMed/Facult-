# API dossier (Phase 8 §33)

`students` (search/faculty/status/niveau, ≤100), `/{id}`,
`/academic-record`, `/grades`, `/documents` (métadonnées),
`/results` (moteur + dettes + décision publiée), `/timeline`
(catégorie/limite≤100/offset + meta). Scopes `students:read |
archives:read | grades:read | documents:read`, throttle, périmètre
404 anti-BOLA, openapi resynchronisé. Pas de `requests` (workflow
protégé ; export métadonnées via `data/*`), pas de sensible.
