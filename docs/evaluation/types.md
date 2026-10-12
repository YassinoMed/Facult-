# Évaluation — types configurables (Phase 5 §26)

Table `evaluation_types_evaluation` (code unique, libellé, coefficient et
durée par défaut, actif) ; graine = 8 codes `Examen::TYPES`. Les épreuves
référencent un code actif (`StoreEpreuveRequest` + garde service
`EpreuveService::creer`, génération auto incluse) — jamais de chaîne
libre côté planification. CRUD global (`evaluations.view/create/update/
delete`, zéro permission créée) ; suppression refusée si des épreuves
l'utilisent (désactivation plutôt que suppression).

`Matiere.type_evaluation` reste libre (suggestion référentiel, qualité
signale les vides) pour ne pas casser les imports ECUE existants.
