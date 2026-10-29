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
- `AmenagementParcoursService` : 4 workflows `proposer/decider` (`lockForUpdate`, 409 si déjà traité, event `ParcoursDecisionEnregistree` → audit). Transitions décisionnelles formalisées dans la constante `TRANSITIONS` (source unique).

## Machine à états décisionnelle (aménagements)

Source unique : `AmenagementParcoursService::TRANSITIONS` (catégorie →
champ consommé (`statut` ou `decision`) → cible ⇒ états sources autorisés).
Le contrôleur `decider()` valide l'entrée avec cette carte (`Rule::in`),
le service la re-vérifie sous transaction + `lockForUpdate` (422 cible
inconnue, 409 transition illégale). Codes et messages d'erreur inchangés.

| Catégorie | Champ | Transitions fermées |
|---|---|---|
| `equivalences` | `decision` | `accordee` ⇒ `proposee` (statut final `validee`) ; `refusee` ⇒ `proposee` (statut final `rejetee`) ; absence de décision = `refusee` |
| `dispenses` | `statut` | `validee`/`rejetee` ⇒ `proposee` ; `revoquee` ⇒ `validee` |
| `reorientations` | `statut` | `validee`/`rejetee` ⇒ `proposee` |
| `passerelles` | `statut` | `admise`/`refusee` ⇒ `proposee`, `eligible`, `non_eligible` |

États réservés sans chemin d'écriture : `annulee` (réorientations),
`eligible`/`non_eligible` (passerelles : admissibles comme sources,
jamais attribués par le service). Anti-dérive : test
`test_fsm_carte_coherente_avec_constantes_modeles` compare la carte aux
constantes `STATUTS`/`DECISIONS` des modèles à chaque run.

## Versionnement des règles de simulation

`parcours_regles` versionne les règles configurables (§16) : `versionner()`
crée `version = max(version)+1` pour le `code`, jamais de mise à jour
destructive ; les anciennes versions restent en base (décisions rejouables)
et peuvent être désactivées (`actif=false`, option `desactiverAnciennes`).
Résolution (`resoudre()`) : règle spécifique (année+programme) > programme >
globale > socle LMD (`socle()`, jamais caché + averti) ; le plus haut
`actif=true` gagne. Chaque simulation persiste sa référence `code@version`
(colonne `regle_reference`) et liste ses règles dans `resultat.regles` :
les simulations antérieures restent explicables après toute révision.

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
