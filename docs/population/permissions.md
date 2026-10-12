# Permissions population (Phase 2)

AUCUNE permission `students.*` créée : `archives.view/create/update/delete`,
`documents.*`, `notes.*`, `history.view`, `data.import/export`,
`exports.personal` couvrent la même finalité (décision §11 documentée).
Scopes API : `students:read`, `archives:read`, `grades:read`,
`documents:read` (GET seuls, métadonnées, jamais de binaire ni de médical).
Scope faculté : `faculteRestreinteId()` + `peutAccederFaculte()` avant toute
opération sensible (IDOR testé).
