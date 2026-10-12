# Référentiel LMD — architecture

Source unique de la structure universitaire (`docs/MODULE-REFERENTIEL.md`
pour le détail historique). Hiérarchie : Faculté (= Établissement) →
Département → Domaine → Mention → Spécialité → Programme (= Parcours) →
Niveau (`ordre`) → Semestre LMD (`numero`) → UE → Matière (= ECUE).

Règles : FK `restrict` partout (jamais de cascade destructive), unicités
contextuelles (`faculte+code`, `programme+code`, `semestre+code`),
maquettes versionnées à transisions verrouillées, prérequis anti-cycle
(BFS), `ordre` d'affichage UE/ECUE (Phase 1), niveaux/plafonds validés
(`ordre ≤20`, `numero ≤24`).

Consommateurs : inscriptions, affectations, enseignement, évaluation,
population, archive, DataExchange, API `referentiel:read`.
