# Inscriptions détaillées (Phase C)

`StudentArchiveService::registrations()` — par année : inscription
(année, formation, type, régime, statut, décision, moyenne, crédits) +
affectation de l'année (groupe, sous-groupe, date) + situations datées de
l'année (transferts, changements de parcours, suspensions — motif, auteur).

## Sources

- `inscriptions_academiques` (+année, programme, niveau) — lecture seule.
- `affectations_groupe` (`annee_universitaire_id`, statut active).
- `situations_etudiant` filtrées par `date_effet` dans l'année universitaire.
- Types inscription = enum existant (`premiere`, `reinscription`,
  `transfert`, `mutation`, `reprise`, `equivalence`, `autre`).

## Permissions

Onglet `inscriptions` : `archives.view` réutilisée (même finalité —
aucune permission créée, §28). Page : `student_archive.view` + scope faculté.
