# Population — architecture (Phase 2)

```text
Etudiant (personne universitaire)
  → inscriptions_academiques (relation officielle / année)
  → dossier (DossierAcademique + StudentDossier, vues métier)
  → pédagogique (InscriptionPedagogique, Phase 4)
```

Distinction : PERSONNE (Etudiant) ≠ INSCRIPTION (relation/année) ≠
DOSSIER (vue administrative) ≠ PÉDAGOGIQUE (UE/matières). Aucune base
parallèle : notes, documents, décisions restent dans leurs sources.

Moteurs : `AffectationService` (groupes), `ListeEtudiantsService` +
`PopulationDataset` (listes gouvernées), `FicheEtudiantService` (360),
`PopulationStatsService` (12 métriques), `StudentLookupService` (identité),
`AcademicProgressionService` (N+1, Phase 6).
