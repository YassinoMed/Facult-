# LOOP INDUSTRIALISATION ÉVALUATION — 14 · Guide technique

## Architecture (monolithe modulaire — préservée)

```
Module Evaluation
├─ Models      : NoteEpreuve (6 états, matrice TRANSITIONS), Epreuve (7 états),
│                Examen (9 états), NoteEpreuveHistorique (append-only),
│                Deliberation, DecisionDeliberation, Candidat, …
├─ Services    : SaisieNoteService (saisie/validation/verrou/correction),
│                DeliberationService (simulation→préparation→validation→publication),
│                EvaluationImportService (FILE+JOB), CompostageService,
│                EvaluationAuditService, EvaluationNotificationService
├─ Controllers : NoteEpreuveController (Gates granulaires), MesNotesController (portail),
│                DeliberationController, HistoriqueNotesController
├─ Requests    : SaisieNotesRequest, SaisieNotesAnonymesRequest (+ règle NoteDansBareme)
├─ Policies    : EvaluationPolicy (permission + dansPerimetre anti-IDOR)
├─ Events      : EvaluationChanged → AuditEvaluation (log technique)
CORE (services/Acquis, ArchiveService, AcademicCalculationService) — source officielle `notes`
```

## Contrats clés

- **Note d'épreuve** ≠ **note officielle** : synchronisation à la validation via `ArchiveService` (verrou respecté).
- **Transactions** : lot = une transaction (épreuve `lockForUpdate` + notes `lockForUpdate`) ; validation = note verrouillée + note officielle verrouillée.
- **Historisation** : `NoteEpreuveHistorique` append-only (modèle) ; corrections = 3 étapes motivées ; `valide_at` + drapeau d'audit de re-modification.
- **Permissions** : 8 canoniques `notes_eval.*` + Policies ; séparation saisie (agent/enseignant) vs validation/correction (admin).

## Tests & exécution

- SQLite `:memory:` obligatoire ; MySQL requis pour les preuves de concurrence multi-processus.
- Suites de référence : `NotesEvaluations*`, `NotesHistoriqueAppendOnlyTest`, `Deliberation*`, `ExamGouvernance*`.
- Qualité : `vendor/bin/pint --dirty` ; `php artisan route:list --path=evaluations`.

## IA

- Module AssistantIa : **lecture/assistance seulement** (filtre sécurité) ; aucune écriture métier, aucune décision autonome.
