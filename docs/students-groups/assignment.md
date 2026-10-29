# Affectations (§11-17, §32-35)

## Contexte (§3)

`Etudiant + Année + Inscription + Programme + Niveau + Groupe (+ Sous-groupe)`.
`etudiants.groupe_id` = pointeur courant ; `affectations_groupe` = historique (une seule `active` par étudiant/année).

## Contraintes (`AffectationService::controler`, sans écriture)

`ETUDIANT_SUSPENDU`, `REGISTRATION_NOT_FOUND|INVALID`, `GROUP_INACTIVE`,
`PROGRAM_MISMATCH`, `LEVEL_MISMATCH` (forçable), `GROUP_FULL` (forçable),
`GROUP_ASSIGNMENT_EXISTS` (idempotent), `MULTIPLE_PRIMARY_GROUPS` (transfert),
`INVALID_SUBGROUP`.

## Écriture (`affecter`)

Chaîne : prévisualiser → expliquer → confirmer → `lockForUpdate` capacité →
clôture ancienne → création → `SituationEtudiant` → `PopulationChanged` → notification.
`force=true` exige `groupes.manage` (`[FORCÉ]` dans motif) ; concurrence 29/30 + 2 admins ≠ 31/30.

## Masse (`simuler` + `affecterLot`)

Simulation ≤5000 sans écriture → confirmation `confirmer=accepted` → transaction
+ notification batch. Import `affectations-groupes` (`matricule/cin,code_groupe,annee`)
délègue au même moteur (preview DataExchange obligatoire).

## Auto (`repartitionAutomatique`)

Simulation équilibrée par places restantes, sans écriture ; confirmation via
`appliquerAuto` (un `affecterLot` par groupe, tranches 500 sous le seuil
`affectations.lots.seuil_sync` ; au-delà, lot asynchrone `AffectationMasseLot` + page de progression). Non-affectés motivés.

## Changement/retrait

Transfert = clôture + création (notes/examens conservés) ; `impacts()` =
créneaux planning cible + épreuves à venir + notes conservées. Retrait historisé,
jamais de suppression.
