# Populations & Groupes — README (§1-2, §44-45)

Moteur central unique, sans duplication :

```text
Étudiants → Inscriptions → Programme/Parcours/Niveau/Semestre → Groupes → Affectations → Listes/Recherche/Stats → Export/PDF
```

## Services réutilisés (ne jamais dupliquer)

- `AffectationService` — contraintes, `lockForUpdate`, simulation, bulk, répartition auto, impacts, retrait historisé.
- `ListeEtudiantsService::requete` — UNE query scopée faculté, 12 filtres combinés, UNE population pour preview/CSV/XLSX/PDF/print.
- `ExportColumnPolicy::catalogue('students')` — colonnes + `exports.personal` (CIN masquée, email/téléphone retirés).
- `DataExchange` — `groupes` (import+export), `affectations-groupes` (import+export).
- `PopulationStatsService` — stats + anomalies (source unique : affectations actives).
- `AuditService` + `SituationEtudiant` — historique ; `NotificationService` — batch.

## Pages

- `admin.etudiants.index` — registre (moteur central, programme/groupe/sans-groupe,
  compteur résultats, quick stats, export-actions, vues sauvegardées).
- `admin.etudiants.sans-groupe` — orphelins + affectation page courante.
- `admin.groupes.index` — filtres année/statut/occupation/capacité/effectif/places + stats capacité.
- `admin.groupes.show` — tableau de bord (effectif/capacité, H/F, alertes, onglets ;
  actions Liste/Étudiants/Imprimer/Masse/Importer).
- `admin.groupes.etudiants` — étudiants d'un groupe (recherche, sous-groupe, export,
  PDF par sous-groupe, impression).
- `admin.affectations.masse|simuler|appliquer` — bulk avec simulation.
- `admin.affectations.auto|auto.simuler|auto.appliquer` — répartition équilibrée simulée.
- `admin.listes.*` — index (titre/regroupement/orientation/émargement/signature),
  prévisualisation (quick stats + profils), export CSV/XLSX/PDF (sync/async),
  impression (sections), `exports` (historique différés + téléchargement).

## Performance & données (Phase 2 §2.17-2.19 + Phase 3)

- `chunk(1000)` + eager loading (jamais `cursor()` + `with` : N+1 avéré et corrigé) ;
  permission `exports.personal` calculée une fois par jeu.
- Index composites (`2026_09_30_185905_add_index_listes_population`) :
  `etudiants(faculte,statut)`, `(faculte,programme,niveau)`, `(groupe,statut)` ;
  `affectations(annee,statut)`, `(sous_groupe,statut)` ; `groups(faculte,programme)`.
- Garde-fous : `PopulationPerformanceTest` (bornes requêtes/temps, 300 lignes),
  `ResponsiveListTest`, `SensitiveExportTest`, `PopulationMySQLPerformanceTest`
  (MySQL uniquement, skip SQLite).
- Benchmark MySQL : `benchmark-mysql.md` (3000 lignes : 0,48 s / 13 req / 10 Mo ;
  EXPLAIN : `idx_etudiants_faculte_statut` utilisé).

## Moteur partagé (Phase 3)

- `PopulationDataset` (10 datasets, §18-19) + `PopulationService` (funnel) :
  `population-datasets.md`.
- Modules intégrés sans duplication : ECUE/UE (page → preview), enseignant
  (page → `students_by_teacher`), enseignement (page → ECUE), épreuves
  (`genererCandidats` + émargement via layout partagé + CSV), délibérations
  (`etudiantsConcernes` + lien population), présences (filtres + feuille PDF),
  notes (workflow intact + exports `notes`/`notes_epreuves` scopés).
- Bug latent corrigé : `InscriptionPedagogique::inscriptionAcademique()`
  inexistante (relation = `inscription`) — `genererCandidats` n'était jamais
  couvert ; désormais testé.

Voir : `assignment.md`, `groups.md`, `student-lists.md`, `exports.md`, `pdf.md`, `security.md`.
