# LOOP INDUSTRIALISATION ÉVALUATION — 07 · Matrice des permissions

## Permissions `notes_eval.*` (canoniques, aucune créée)

| Permission | Usage | Rôles dotés (matrice `Permissions.php`) |
|---|---|---|
| `notes_eval.view` | consultation notes/historique | admin_scolarite, agent_scolarite, super_admin |
| `notes_eval.enter` | saisie (lot/anonyme/import) | admin_scolarite, **agent_scolarite**, super_admin |
| `notes_eval.update` | mise à jour contrôlée | admin_scolarite, super_admin |
| `notes_eval.validate` | validation + verrouillage | **admin_scolarite, super_admin** |
| `notes_eval.lock` | verrouillage | admin_scolarite, super_admin |
| `notes_eval.correct` | autorisation de correction | admin_scolarite, super_admin |
| `notes_eval.import` | import multi | admin_scolarite, super_admin |
| `notes_eval.export` | export | admin_scolarite, super_admin |
| `notes.compostage.generate` / `resolve` | anonymisation / correspondance | selon matrice |
| `deliberations.manage` / `deliberations.view` | délibérations | selon matrice |
| `evaluations.publish` | publication planning (gouvernance) | admin_scolarite, super_admin |

## Policies associées (double défense)

| Policy | Méthode | Objet | Contrôle |
|---|---|---|---|
| `EvaluationPolicy` | `saisirNote` | Epreuve | `notes_eval.enter` + `dansPerimetre` |
| | `validerNote` | NoteEpreuve | `notes_eval.validate` + périmètre |
| | `validerNotesEpreuve` | Epreuve | `notes_eval.validate` + périmètre |
| | `corriger` | NoteEpreuve | `notes_eval.correct` + périmètre |
| | `verrouiller`/`genererCompostage`/`resoudreCompostage` | Epreuve | permissions dédiées + périmètre |
| `NotePolicy` (cœur) | verrou note officielle | Note | rôles supérieurs si verrouillée |

## Portail enseignant (sans compte back-office)

- Saisie via `MesNotesController` : `saisi_par = null`, `origine` préfixée (« Enseignant »), habilitation par affectation d'épreuve + périmètre Policy.
- **Aucune permission `notes_eval.validate/correct` accessible** : le portail saisit, ne valide pas (séparation RBAC démontrée).

## Vérifications négatives (extrait)

- agent_scolarite → validation : **refusé** (permission absente de son rôle).
- Hors périmètre → 403 (Policy) sans écriture préalable.
- Aucun élargissement de droits effectué pendant la campagne ; aucune nouvelle permission créée (réutilisation des canoniques, §1.3).
