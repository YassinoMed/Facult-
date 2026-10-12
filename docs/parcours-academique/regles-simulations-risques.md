# Parcours académique — règles, simulations, risques

## Règles (§16)

Socle LMD si rien configuré (`ParcoursRegleService::socle`) :
`PASSAGE_L1_L2`/`PASSAGE_L2_L3` (60 crédits, dettes ≤ 2),
`ADMISSION_MASTER` (180 crédits + décision admis* + admission externe),
`PASSAGE_M1_M2` (60 crédits), `RISQUE` (seuils 0/3/6/9).
Seuils de notes = `evaluation_regles` (jamais dupliqués).
Chaque résultat cite `code@vX` ou `@socle`.

## Simulations (§2-5, §15, §17)

Scénarios : passage, redoublement, conditionnel, conservation_dettes,
reorientation, changement_parcours, admission_formation,
passerelle_master, validation_semestre, compensation,
validation_matiere, equivalence, dispense.
Persistance : une ligne `parcours_simulations` (hypothèses + résultat).
L3 → M1 : `non_progression_sans_admission` tant qu'aucune admission ;
`passerelle_master` exige crédits + admis* + commission.
Compensation : `CalculEvaluationService` + `compensation_semestre`
(avant/après, UE concernées, décision possible) — hypothèse seule.

## Risques (§12)

Dettes (≥5 = +4), moyenne < 8 (+3) / < 10 (+2), ≥2 semestres non
validés (+2), lenteur (+2), restants > 120 (+1). Niveaux 0/3/6/9.
`parcours_alertes_risque` = trace informative, jamais de sanction auto.

## Historique / comparaison (§13-14)

Chronologie triée (inscriptions, semestres, décisions publiées,
situations, 4 aménagements, simulations). Comparaison 2-4 étudiants
scopés faculté, ou scénarios entre eux.
