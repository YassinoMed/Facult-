# API — erreurs (Phase 11 §11.11)

Enveloppe globale : `{message, code, request_id}` (codes
`VALIDATION_ERROR|UNAUTHENTICATED|FORBIDDEN|NOT_FOUND|RATE_LIMITED|
INTERNAL_ERROR|API_ERROR`, 500 générique sans stack). Réponses métier
: `{message}` + charge utile (contrat figé par les tests — ne pas
« uniformiser » sans version majeure). `request_id` propagé
(`X-Request-ID`, `IntegrationLog`, jamais de payload).
