# Audit final — affectation aux groupes (2026-09-30)

```text
Nombre de contraintes : 10 codes (§29 : ETUDIANT_SUSPENDU,
REGISTRATION_NOT_FOUND/INVALID, PROGRAM_MISMATCH, LEVEL_MISMATCH,
GROUP_INACTIVE/FULL/ASSIGNMENT_EXISTS, MULTIPLE_PRIMARY_GROUPS,
INVALID_SUBGROUP)
Nombre de tests : 11 (AffectationMoteurTest) + 4 PopulationTest adaptés
Tests passés : 11/11 (38 assertions)
Tests échoués : 0
Scénarios E2E : 1 (A → B : clôture, pointeur, notes, audit, notif)
Problèmes restants : aucun bloquant
Risques : volumétrie bulk plafonnée à 5000 (transaction unique) ;
  au-delà, découper ou passer par l'import DataExchange (jobs).
```

Régression : 123 tests verts (affectations, population, examens,
demandes, tâches, GED/finances/BI, DataExchange, qualité/API).
Pint propre. Ancienne route morte `affectations` retirée du `whereIn`
(remplacée par `affectations-groupes`).
