# Versionnement et ordre d'affichage

Versions : `version_precedente_id`, historique conservé, aucune perte
(`nouvelleVersion`). Ordre : colonnes `ordre` (niveaux, semestres,
UE, ECUE — ajout Phase 1 pour UE/ECUE, index composites),
tri `ordre,code` partout. Drag-drop : `POST referentiel/reordonner`
(`type`, `ordres{id:position}` ≤500, `referentiel.manage`, périmètre
par entité, transaction, event `OrdreModifie` journalisé).
Recherche : sections Programmes + UE (permission + module + scope),
matières existantes.
