# Gouvernance des données (Phase 10)

| Donnée | Source | Sensibilité | Accès |
|---|---|---|---|
| Matricule | Population | administrative | périmètre faculté |
| CIN | Population | restreinte | masquée (`cin_masquee`, scope `archives:read`) |
| Notes | Évaluation | académique | `grades:read` + périmètre |
| Décisions jury | Délibérations | officielle | publiée seule en API |
| Médical/disciplinaire | Sensible | isolée | permissions par type, hors BI/exports |
| KPI | Agrégats | publique interne | `statistiques.view` + périmètre |

Qualité : détecteurs (population/référentiel/pédagogie) →
`AgregateurQualite` (déduplication `cle_unique`, `faculte_id`) →
centre `admin.qualite` (transitions) → tâches (`cle_unique`).
Rétention : purges ciblées, audit jamais purgé (voir
`docs/student/retention.md`). Linéage : chaque KPI expose sa source.
