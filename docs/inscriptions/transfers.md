# Inscriptions — transferts (Phase 3)

Transfert = opération contrôlée entre facultés/programmes, jamais un
simple `faculty_id` modifié. `SituationController::transferer` :
programme cible + motif obligatoires ; `peutAccederFaculte(programme.
faculte_id)` ; transaction : `etudiant[programme_id, niveau_id=null,
faculte_id=programme.faculte_id]` + `Situation type=transfert` (ancien →
nouveau) + audit `modification_inscription` + notif
`inscription.transferee`. Historique conservé (inscriptions existantes non
réécrites).

Réorientation (même faculté, autre programme) = même endpoint (mutation de
programme) ; `type=mutation` reste une valeur d'historique sans workflow
dédié — pas de contrôleur `réorientation` (non-duplication). Rattachement
LMD (`rattacher`) ne touche pas la faculté. Réorientation ≠ progression.
