# LOOP EX-LIFECYCLE — 03 · Implémentation

## Réalisations réelles (fichiers)

### Créés
| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_31_150000_creer_evaluation_examen_evenements.php` | table additive append-only `evaluation_examen_evenements` (examen_id FK cascade, action, acteur_id FK nullOnDelete, statut_avant/après, motif, métadonnées JSON, created_at sans updated_at, 2 index) — `down()` réversible |
| `app/Modules/Evaluation/Models/ExamenEvenement.php` | journal append-only : `updating()`/`deleting()` lèvent `DomainException` (impossible à falsifier via Eloquent) ; actions `transition_effectuee`, `creation` |
| `app/Modules/Evaluation/Services/ResultatTransition.php` | DTO immuable de prévisualisation (C3) : acceptable, conditions satisfaites, bloquages, message |
| `tests/Feature/ExamenCycleVieTest.php` | 19 scénarios EX-LC ciblés (60 assertions) |

### Modifiés (diffs ciblés, WIP 223 fichiers préservé)
| Fichier | Changement |
|---|---|
| `app/Modules/Evaluation/Services/ExamenService.php` | ① `lockForUpdate()` + relecture dans la transaction (contrôle décisif à l'application) ; ② écriture `ExamenEvenement` dans la transaction ; ③ paramètres optionnels `acteurId`/`motif` (compatibilité des appelants conservée) ; ④ `historiserCreation()` ; ⑤ `transitionsDisponibles()` ; ⑥ `previsualiserTransition()` ; ⑦ `bloquagesDe()` répliquant l'ordre d'évaluation exact du moteur |
| `app/Modules/Evaluation/Controllers/ExamenController.php` | `transiter()` transmet acteur + motif optionnel ; `store()` historise la création ; nouvelle méthode `transitions()` (lecture, Gate `view` + périmètre facultaire 404) |
| `app/Modules/Evaluation/Routes/web.php` | route GET `evaluations/examens/{examen}/transitions` (`can:evaluations.view`, nom `evaluations.examens.transitions`) |

### Non modifiés (preuves de non-régression)
- `Examen` (matrice, garde critique, scope facultaire) — inchangé.
- `ExamGouvernanceValidationService`, `ExamPublicationOrchestratorService` — inchangés (leur appel `transiter()` reste compatible).
- `EvaluationPolicy`, `StoreExamenRequest`, vues — inchangés.

## Exécution d'une transition (D3, états réels)

1. Contrôleur : `Gate::authorize('update')` + blocage `published` + périmètre.
2. Service : `DB::transaction` → `whereKey()->lockForUpdate()->firstOrFail()`.
3. Garde `estCritique()` puis matrice `peutTransiterVers()` (contrôle décisif après verrou).
4. Si `published` : audit contraintes dures (via gouvernance uniquement en pratique).
5. `update(statut, published_at)` + écriture événement append-only.
6. `EvaluationChanged::dispatch` (effets secondaires, convention existante).
7. Retour `Examen::fresh()`.

## Prévisualisation (D7/G3)

`GET admin/evaluations/examens/{examen}/transitions?cible=X` retourne :
- `statut_courant`, `disponibles[]` (cible, acceptable, bloquages),
- `previsualisation` (conditions satisfaites, bloquages, message).

Lecture pure ; l'application recalcule tout — une prévisualisation `acceptable` ne garantit pas l'application (concurrence/évolution de l'état entre-temps).

## Ce qui n'a PAS été fait (réserves assumées)

- Motif obligatoire des transitions sensibles (D-02 ouvert) — colonne prête, exigence non inventée.
- Notifications dédiées aux transitions simples (D-03 ouvert).
- Propagation d'annulation aux épreuves (D-04 ouvert — statu quo, audit existant détecte les incohérences).
- Préconditions de complétude sur `→completed` (D-05 ouvert).
- Réouverture post-publication (D-01 — non prise en charge explicitement).
