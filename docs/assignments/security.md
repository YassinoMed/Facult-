# Sécurité

Permissions existantes réutilisées : `archives.view` (paramètres,
simulation, prévisualisation), `archives.update` (confirmation,
modification, annulation), `data.export` (CSV), `groupes.manage`
(exceptions/forçage). Aucune permission créée.

Scope : `faculteRestreinteId()` sur groupes/étudiants/simulation
(404 hors périmètre), re-vérifié à la confirmation. Tests IDOR par
ressource. Audit `assignment.*` sans PII complète.
