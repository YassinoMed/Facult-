# Archive étudiant — architecture (Phase B)

Module `student-archive` (`MODULE_STUDENT_ARCHIVE=false` par défaut) : couche
d'agrégation, ZÉRO table métier, ZÉRO donnée recopiée.

## Funnel

```text
admin.etudiants.archive (can:student_archive.view + scope faculté 404)
→ StudentArchiveController@show (fin)
→ StudentArchiveService (overview/timeline/statistics)
→ FicheEtudiantService (resume/completude/anomalies) + ArchiveTimelineRegistry
→ providers (contrat App\Contracts\StudentArchiveTimelineProvider)
→ Audit via ArchiveConsultee → AuditerArchive → AuditService (archive_view)
```

## Providers cœur (sources existantes)

| Catégorie | Source |
|---|---|
| `inscription` | `inscriptionsAcademiques` + année |
| `groupe` | `affectations` historisées + groupe/année |
| `situation` | `situations` append-only (motif, auteur) |
| `document` | `documentsAcademiques` (métadonnées seules) |

Timeline : fusion triée desc + `limite` (1-100, défaut 20) + `offset`
(« charger plus », §39). Filtres : `annee_universitaire_id`, `categorie`.

## Sécurité

Permissions `student_archive.view|timeline.view|academic.view|
documents.view|documents.download|export|pdf|audit.view` (TOUTES + MATRICE :
super_admin/admin_scolarite larges, agent sans export/pdf/audit,
consultation view/download, admin_faculte sans audit).
Policy `StudentArchivePolicy::voir/voirAudit` (permission + `peutAccederFaculte`).
Contrôleur : scope faculté AVANT permission (404 anti-énumération).
Audit `archive_view` (étudiant, onglet, IP/UA) — sans PII métier.

## Vues

`modules/student-archive/show.blade.php` : `x-page-header`, 4 `x-stat-card`,
parcours longitudinal (inscriptions triées), `x-timeline` + filtres année/
catégorie + « charger plus », `x-alert` anomalies. Lien retour fiche 360 ;
fiche 360 → bouton « Archive historique » (module actif + permission).

## Non-duplication (§55)

Aucune table `archive_*`. Diplômes/certificats = projection (décisions +
documents, Phase D). Confidentiel/médical/disciplinaire = Phase F
(colonne confidentialité + 1 table sensible justifiée, à valider).
Export PDF/API = Phases G/H via layout `pdf/liste` et scopes existants.

## Phase C/D (onglets)

`?onglet=` : `general` (overview), `academique` (`academic()` +
`notesQuery()` paginé + décisions), `inscriptions` (`registrations()`),
`documents` (`documents()` + `diplomas()` + `certificates()`),
`timeline` (registry + 3 providers : délibérations, demandes, absences),
`audit` (`HistoriqueAcademique` paginé, `voirAudit`).
Gates par onglet : `academic.view`, `archives.view`, `documents.view`,
`timeline.view` réutilisées — AUCUNE permission créée en C/D.
Données Phase G : méthodes publiques `academic/registrations/documents/
diplomas/certificates/notesQuery` (voir `academic.md`, `registrations.md`,
`documents.md`).
