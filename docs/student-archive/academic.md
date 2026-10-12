# Archive académique (Phase C)

Projection lecture seule — sources de vérité inchangées.

## Sources

| Affiché | Source | Service |
|---|---|---|
| Synthèse (moyenne, crédits) | `inscriptions` (champs post-recalcul) | `AcademicCalculationService::synthese` |
| Années/semestres | `inscriptions_academiques` + `semestres` (moyenne/crédits/décision stockés) | — (lecture) |
| UE/ECUE | `pedagogiques → matiere → ue` + notes orphelines | `moyennePonderee`, `notesValideesParMatiere`, `creditsObtenus` |
| Notes (+filtres/pagination) | `notes` + `matiere.ue`, `semestre`, `inscription.annee` | `notesQuery()` public (Phase G/H) |
| Décisions jury | `evaluation_decisions` + délibération | — (lecture) |

## Règles

- Moyennes/crédits UE recalculés à l'affichage UNIQUEMENT via
  `AcademicCalculationService` (mêmes fonctions que le recalcul officiel).
- Statuts affichés = stockés (`semestres.decision`, inscription `statut`,
  note `locked_at`) ; AUCUN statut inventé (pas de « UE validée » calculée).
- Sessions = valeurs `notes.session` existantes (`normale`, `rattrapage`).
- Filtres server-side : année, semestre (numero), UE, ECUE, session,
  verrouillée oui/non. Notes paginées (20).
- Timeline `deliberation` : décisions, masquée sans `deliberations.view`.

## Performance

Onglet académique : ≤40 requêtes mesurées (`StudentArchiveAcademicTest`,
1 inscription/1 note — indépendant du volume via eager + pagination).
