# Imports / exports référentiel (DataExchange, pipeline unique)

Entités : `departements`, `ues`, `ecues`, `matieres` (existantes) +
`programmes`, `niveaux`, `semestres` (Phase 1). Identifiants stables
(code + contexte programme/semestre), `cleDoublon`, modes `creer/upsert`,
`vider()` interdit (rattachements), périmètre faculté par ligne,
preview → confirmer → transaction, `LIGNES_MAX 5000`.
Maquettes exclues de l'import (versionnement protégé).

## LOT 2 — dépendances réelles (`ImportDependencyService::DEPENDANCES`)

Ordre issu des FK et résolutions du code (voir docblock du service) :
`ues → [programmes, semestres]` (semestre FK requis), `ecues → [ues]`
(standalone legacy sans FK accepté mais signalé), `matieres → []`
(legacy, même table que ECUE), `notes → [etudiants, matieres]`
(`matieres` = ECUE, choix architectural : jamais de FK `ecue_id`
dupliquée), `deliberations → [sessions-examen, programmes]`,
`decisions-jury → [deliberations, etudiants]`,
`referentiel-mens → []` (source externe indépendante),
`progressions → [etudiants, niveaux, annees-universitaires]`
(dérivé calculé mais importable via pipeline complet),
`documents-academiques → [etudiants]`.

## LOT 2 — gardes ajoutées

- Année active : garde DB `active_unique` ('Y' ssi active) en plus de
  `definirCourante()` verrouillée ; assainissement déterministe à la
  migration (plus récente active conservée).
- Programmes : `statut` (`brouillon/actif/inactif/archive`, `actif`
  booléen synchronisé par le modèle), `capacite` indicative (les
  groupes seuls font foi à l'affectation). Archive terminale (sortie
  refusée). Imports UE/ECUE/inscriptions refusent un programme
  archivé (`PROGRAMME_ARCHIVE`).
- Types de diplôme : `cycle` (`licence/master/doctorat`), 
...[truncated 911 chars]
