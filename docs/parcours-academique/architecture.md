# Parcours académique avancé LMD — architecture

Module `ParcoursAcademique` (slug `parcours-academique`, `MODULE_PARCOURS_ACADEMIQUE=false`).
Principe : **aucun doublon**. Toute moyenne/UE/décision vient des moteurs
existants (`AcademicCalculationService`, `CalculEvaluationService`,
`RegleEvaluationService`, `AcademicProgressionService`). Le module n'écrit
jamais dans `notes`, `inscriptions_academiques`, `niveaux` ni
`evaluation_decisions` : simulations append-only, décisions via workflows
verrouillés + audit.

## Tables dédiées (migration `2026_10_04_000001`)

`parcours_regles` (code+version, critères JSON), `parcours_plans`,
`parcours_simulations` (hypothèses+résultat JSON, règle de référence),
`parcours_equivalences`, `parcours_dispenses`, `parcours_reorientations`,
`parcours_passerelles`, `parcours_alertes_risque`. Aucune colonne centrale touchée.

## Services

- `ParcoursRegleService` : résolution spécifique > programme > globale > socle, `versionner()` conserve l'historique.
- `CreditParcoursService` : ECTS depuis inscriptions + synthèses UE (délégation, pas de second calcul). `dettes_credits = total − obtenus`.
- `DetteAcademiqueService` : dérivé des notes validées par matière (rattrapage remplace normale) ; dispenses/équivalences validées = couverture affichée, dette d'origine conservée.
- `PlanParcoursService` (`PlanificationParcoursInterface`) : analyse + `proposerPlan()` (brouillon réversible).
- `SimulationParcoursService` (`SimulationParcoursInterface`) : 13 scénarios, résultat explicable (conditions, crédits, règles, décision officielle de référence, avertissement). Zéro écriture métier.
- `RisqueAcademiqueService` : score + motifs (faible/moyen/élevé/critique), informatif uniquement.
- `HistoriqueParcoursService` : chronologie lecture seule (inscriptions, semestres, délibérations, situations, aménagements, simulations).
- `ComparaisonParcoursService` : étudiants (2-4, scopés) ou scénarios.
- `AmenagementParcoursService` : 4 workflows `proposer/decider` (`lockForUpdate`, 409 si déjà traité, event `ParcoursDecisionEnregistree` → audit).

## Sécurité

Permissions `parcours.view/simuler/gerer/comparer/regles` (matrice).
Contrôleurs : scope faculté → 404 avant gate 403. Concurrence : verrou
pessimiste + 409. Audit : `parcours.simulation/plan_propose/risque_calcule`
+ décisions via listener (sans PII).

## Routes (`admin.parcours.*`, 13)

Tableau de bord, fiche étudiant, simulations (index/store/show),
aménagements `{equivalences,dispenses,reorientations,passerelles,risques}`,
plans, risque, règles. Vues `resources/views/modules/parcours-academique/`
avec composants existants (`page-header`, `stat-card`, `card`, `alert`,
`empty-state`, `status-badge` étendu aux statuts parcours).
