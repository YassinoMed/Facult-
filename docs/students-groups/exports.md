# Exports (§23, §29, §32-33, §38 + Phase 2 §2.7-2.11)

Principe : UNE query → UNE population → plusieurs formats (`ListesController`).

- Preview 25/ligne + total + quick stats (total/actifs/sans-groupe/groupés) ;
  export CSV/XLSX via `IoFactory` (stream) ; PDF ≤1000 lignes (au-delà :
  CSV/XLSX) ; print ≤1000.
- Prévisualisation et export partagent `filtresValides()` + `requete()` :
  même population garantie. `regroupement`/`orientation`/`titre` persistés
  dans les profils `ExportTemplate type=listes`.
- `DataExchange` : `groupes` + `affectations-groupes` import (preview dry-run,
  `cleDoublon`, périmètre faculté) et export CSV/XLSX/JSON/PDF.
- Import affectations : `matricule,code_groupe,annee` (+ `cin` optionnel) ;
  résolution matricule→dossier puis CIN ; idempotent (`déjà affecté`).

## Seuils sync/async (§2.7)

| Format | Sync | Async |
|---|---|---|
| PDF | ≤1000 lignes | jamais (mise en page mémoire) |
| CSV/XLSX | ≤5000 lignes (`SEUIL_ASYNC`) | >5000 lignes |

Choix documenté : moteur en `chunk(1000)` + streaming tient ≤5000 lignes en
HTTP (<30 s, mémoire bornée, vérifié `PopulationPerformanceTest`) ; au-delà :
job. Aligne l'esprit du seuil orchestrateur (`queue_threshold` 500, moteur
legacy) sans le dupliquer — moteur différent, streaming curseur.

## Async (§2.8-2.11)

- Job `GeneratePopulationExport` : paramètres sérialisables uniquement
  (filtres/colonnes/regroupement/format/user — jamais de Builder) ; requête
  reconstruite via `ListeEtudiantsService`.
- Historique `ImportReport` réutilisé (`type=listes.etudiants`,
  `integration=listes`) : `pending → processing → completed|failed|expired`,
  filtres/colonnes/regroupement en `preview` (jamais de PII), compteurs,
  durée, `request_id` via `IntegrationLog`.
- Notification `export.listes` (lien `admin.listes.exports`) à la fin du job.
- Téléchargement : propriétaire ou super-admin, via
  `ExportOrchestrator::telecharger` réutilisé (disque privé + expiration).
- Expiration : `export_retention_hours` (168 h défaut) ; purge à la volée au
  téléchargement (`expired` + fichier supprimé) — même pattern que l'orchestrateur.
- Traçabilité : `IntegrationLog::tracer` (`export.listes`, `request_id`, référence).
