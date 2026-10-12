# Workflow + machine à états (Phase 9 §45-47)

`draft → submitted → received → in_review ⇄ waiting_student →
approved|rejected → completed` (+`cancelled`), matrice
`RequestWorkflowService`, garde centrale, statut jamais mass-assigné.
`transitionner` verrouillé (`lockForUpdate` — deux agents, une seule
transition). Brouillon privé jusqu'à soumission. Idempotence :
throttle + référence UNIQUE + retry ; double transition → 422 métier.
