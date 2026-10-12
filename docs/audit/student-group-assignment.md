# Audit — Affectation des étudiants aux groupes (2026-09-30)

## Architecture actuelle

- `AffectationService::affecter()` (`app/Services/AffectationService.php:24`) :
  suspension, capacité (`Group::estComplet`), override `force` (gardé par
  `groupes.manage`), clôture ancienne active, `etudiants.groupe_id` synchronisé,
  `SituationEtudiant(type=groupe)`, event `PopulationChanged`.
- `AffectationGroupe` (`app/Models/AffectationGroupe.php`) : table
  `affectations_groupe` (etudiant, groupe, annee, date_debut/fin, statut
  active/cloturee, motif, auteur). `scopeActives()`.
- `Group` (`app/Modules/Schedule/Models/Group.php`) : code, faculte, niveau,
  programme_id/niveau_id/annee (additifs), `capacite`, `effectifActuel()`
  (affectations actives), `estComplet()`, `etudiants` (via `groupe_id`).
- Contrôleur `AffectationController@store` : Gate `update` étudiant + 403
  faculté (étudiant ET groupe), motif obligatoire, `force` opt-in.
- `SituationController` : suspendre/transferer/rattacher (changement avec historique).
- Tests : `PopulationTest` (clôture, sync pointeur, force, suspension).

## Contraintes existantes

Suspension, capacité, unicité active implicite (clôture auto), override
autorisé+audité (`[FORCÉ]`), isolation faculté (403), motif obligatoire.

## Règles existantes

`etudiants.groupe_id` = pointeur courant ; anciennes affectations conservées
(`cloturee`) ; `SituationEtudiant` = audit ; `PopulationChanged` = event.

## Fonctionnalités existantes

Individuelle (formulaire fiche), transfert, rattachement, suspension.
Import DataExchange : clé `affectations` en `whereIn` routes **mais absente
du registre** (appel = exception → à brancher ou retirer).

## Problèmes détectés

1. Aucune vérification d'inscription (étudiant non inscrit affectable).
2. Aucune vérification programme/niveau (M1 → groupe L3 possible).
3. Non idempotent : ré-affectation même groupe/année = nouvelle ligne + event.
4. Capacité vérifiée hors transaction → course 29/30 + 2 admins = 31/30.
5. Pas d'impacts planning/examens avant changement.
6. Pas de bulk/simulation ; pas de notification étudiant.
7. Pas de retrait propre (que delete cascade via fiche) ; pas de sous-groupe.
8. `whereIn` `affectations` sans définition (route morte).

## Améliorations nécessaires (scope de ce chantier)

Moteur de contraintes structuré (codes §29) + inscription/programme/niveau/
doublon, idempotence, verrouillage capacité en transaction, impacts,
bulk avec simulation + transaction + notification unique, retrait historisé,
sous-groupe (colonne nullable), définition DataExchange
`affectations-groupes` (remplace le `whereIn` mort), permissions réutilisées
(`archives.update`, `groupes.manage`, `data.import/export`), tests
(unitaires + feature + sécurité + concurrence + E2E).
