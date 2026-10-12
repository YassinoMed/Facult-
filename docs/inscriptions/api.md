# Inscriptions — API (Phase 3)

Lecture seule : `GET /api/v1/registrations` (filtres whitelistés
annee/programme/niveau/statut/etudiant, `per_page` ≤ 100) +
`GET /api/v1/registrations/{id}`. Scope `archives:read`, `throttle:api`,
périmètre faculté relu en base (`whereHas etudiant`, 404 hors périmètre —
anti-BOLA). Pas d'écriture (POST/PATCH/DELETE absents — surface minimale
volontaire, comme `students`). Enveloppe `{message,code,request_id}`,
pagination bornée.

Population (rappel) : `GET /api/v1/students` (+ `/{id}`,
`/academic-record`, `/grades`, `/documents` métadonnées seules), scopes
`students:read|archives:read|grades:read|documents:read`. CIN complète
jamais exposée sans scope token `archives:read` (`cin_masquee` publique,
`cin` conditionnel au scope) ; jamais de binaire, médical, disciplinaire.
