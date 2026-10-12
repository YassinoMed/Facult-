# États (Phase 9 §46)

Transitions autorisées : voir `RequestWorkflowService::TRANSITIONS`.
Interdits structurels (ex. `rejected → completed`) levés en
`ValidationException` (422, jamais 500). Chaque transition : validée,
verrouillée, auditée (`online_request.*` + avant/après), notifiée
(`demande.*`). Corrections : `waiting_student → in_review` au dépôt
de pièce ; pas de réouverture silencieuse.
