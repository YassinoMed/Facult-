# Enseignement — architecture Phase 4

```text
Référentiel permanent (Phase 1 : programmes, UE, ECUE, maquettes versionnées)
  → Exécution annuelle : Enseignement (ECUE × type × année, statuts
     draft→…→archived, volumes CM/TD/TP) + AffectationEnseignement
     (rôle, volume, active/cloturée, historisée) + rattachements groupes
     (enseignement_groupe, sous-groupes)
  → InscriptionPedagogique (inscription académique × ECUE, UQ, maquette
     ACTIVE exigée) → Évaluation (Phase 5)
```

Distinction : RÉFÉRENTIEL (décrit la formation) ≠ EXÉCUTION (ce qui est
ouvert/affecté une année). Une maquette modifiée ne réécrit jamais
l'historique (versionnage référentiel).

Zéro duplication : enseignants = Teacher, groupes = Group (+sous-groupes),
planning = Schedule (slots→matiere), présences = Attendance, documents =
GED, calculs = services (`EnseignementService`, `ServiceEnseignantService`,
`QualitePedagogiqueService`), import/export = DataExchange
(`enseignements`, `inscriptions-pedagogiques`, `affectations-groupes`).
