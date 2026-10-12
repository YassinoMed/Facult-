# Performance BI (Phase 10 §10.21-10.22)

Agrégations SQL (GROUP BY, SUM) + `limit` cohortes + exports bornés
(10 000 lignes, async au-delà de 5 000). Cache : clé
`bi:{version}:a{f}:f{fac}:p{prog}` (scope dans la clé, jamais de
fuite), TTL 600s, version md5 (5 tables), préchauffage quotidien
`bi:prewarmer` 04:30 (année active × global + facultés). Pas de
`Cache::tags`, pas de N+1 (retard SLA = seule boucle bornée métier).
