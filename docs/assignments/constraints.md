# Contraintes

## Dures (jamais violées)

`ETUDIANT_SUSPENDU`, `REGISTRATION_NOT_FOUND/INVALID` (via `controler`),
`GROUP_INACTIVE`, `PROGRAM_MISMATCH`, `LEVEL_MISMATCH` (sauf exception
`groupes.manage`), `GROUP_FULL`→`CAPACITY` sur places simulées,
`FACULTY_SCOPE`, `SCHEDULE` (chevauchement strict, fin == début OK),
`INVALID_SUBGROUP`, affectation `verrouillee` (§26, sauf force).

## Souples (optimisation uniquement)

Équilibre des effectifs, stabilité (préservation de l'existant),
utilisation des groupes. Appliquées après les dures.

## Non applicable documenté

`EXAM` : les groupes ne sont pas rattachés aux sessions d'examen ;
les épreuves à venir restent informatives (`AffectationService::impacts`).
`SEMESTER` : `groups` n'a pas de semestre ; filtré via les liens
`enseignement_groupe` quand une matière/UE est en paramètre.
