# Compensation, crédits, règles (Phase 6)

Moteur unique : `AcademicCalculationService` (pondérée centièmes,
`notesValideesParMatiere` — rattrapage remplace normale sans écraser
l'historique, crédits ≥ 10) + `CalculEvaluationService` (UE : moyenne,
seuil, note éliminatoire, crédits) + `RegleEvaluation` versionnée
(année [+ programme], UQ année+programme+version, `actif`, arrondi
central `arrondir`). Résolution : spécifique programme prioritaire,
globale sinon, socle 10/8 si aucune règle.

`compensation_semestre` est load-bearing : moyenne au seuil mais
crédits incomplets → `admis_compensation` si autorisée, sinon
`ajourne` (dettes à reprendre). `compensation_ue=false` = motif de
non-validation (la compensation d'UE se joue au niveau semestre).
Capitalisation : crédits obtenus persistés (`credits_obtenus`) ;
session `normale|rattrapage` conservée par note (UQ
semestre+matière+session). CRUD : `evaluations.regles.*` (versions
coexistantes, active non supprimable).
