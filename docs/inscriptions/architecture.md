# Inscriptions — architecture (Phase 3)

```text
Etudiant (personne universitaire, Phase 2)
  → inscriptions_academiques (relation officielle / année)
      → semestres S1/S2 → notes → décisions
      → inscriptions_pedagogiques (ECUE, Phase 4)
```

Distinction : ÉTUDIANT (qui) ≠ INSCRIPTION (relation officielle pour une
année : étudiant + année + établissement/faculté dérivée + programme +
niveau + statut + type + régime) ≠ PÉDAGOGIQUE (UE/ECUE de la maquette
active). Aucune base parallèle : notes, documents GED, décisions restent
dans leurs sources.

Hiérarchie : RÉFÉRENTIEL (Phase 1) → POPULATION (Phase 2) → INSCRIPTION
(Phase 3) → ENSEIGNEMENT (Phase 4) → ÉVALUATION → DÉLIBÉRATION →
PROGRESSION. Aucune phase ne recrée les données de la précédente.

Tables : `inscriptions_academiques` (UQ etudiant+année, FK année
obligatoire, programme/niveau FK nullable restrict, type/regime enum,
moyenne/crédits/décision dénormalisés) ; faculté dérivée
`etudiant.faculte_id` (pas de `faculte_id` propre — policies via étudiant).
Index : année, (niveau,statut), programme, statut, type.
