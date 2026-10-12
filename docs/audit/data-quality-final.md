# Qualité des données — état final (2026-09-30)

Balayage réel (`qualite:auditer`, base de développement seedée) :
**20 anomalies** — 19 population, 1 référentiel, 0 pédagogie ;
11 avertissements, 9 infos, **0 erreur, 0 critique**.

## Règles actives (non dupliquées, sources uniques)

| Source | Règles | Fichier |
|---|---|---|
| population | validé sans inscription, sans groupe, sureffectif, sans programme, sans département | `PopulationStatsService.php` |
| referentiel | programme sans maquette, UE sans ECUE, crédits incohérents | `ReferentielStatsService.php` |
| pedagogie | UE sans ECUE, sans enseignant/groupe, volume nul | `QualitePedagogiqueService.php` |

## Workflow

Détection → persistance `anomalies_qualite` (clé sha1, idempotente) →
`admin/qualite` (acquitter/assigner/résoudre) → `--sync-tasks` (tâche
`qualite:`) → résolution → auto-résolution au balayage suivant.
Doublons : détection exacte (import) + fuzzy (fiche), **fusion manuelle
uniquement**. Normalisation : email/tél/CIN/noms (`NormalisationService`).
Lineage : `source_systeme` (web/import/api/systeme) sur audit + situations.

## Données orphelines / temporelles

Aucune détectée par les règles actuelles sur le jeu de démonstration.
Règles non couvertes (backlog P3) : inscriptions hors période, examens
hors semestre, documents expirés non traités (couverts par
`documents:marquer-expires` côté GED).
