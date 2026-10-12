# Reporting (gouvernance, Phase 10 §10.19-10.20)

Planifié existant réutilisé : `exports:purge` (horaire), SLA 07:30,
qualité hebdo, supervision 5 min, `bi:prewarmer` 04:30. Exports
lourds : REQUEST → JOB (`ImportReport` pending→completed/failed) →
stockage privé → notification → download propriétaire. Pas d'envoi
mail/push de rapports (canaux à brancher, documenté). Tout rapport
est re-générable depuis les sources (pas d'historique figé hors
snapshots délibération).
