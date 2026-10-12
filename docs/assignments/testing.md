# Tests

`tests/Feature/AssignmentAutomatiqueTest.php` (17 tests) :
éligibilité (inscription/suspension/verrou), contraintes
(programme/capacité/scope/horaire), simulation sans écriture +
équilibre 90/3, contraints-d'abord + déterminisme (double run),
capacité insuffisante 40/10, cas complexe 90/4 groupes, confirmation
idempotente, obsolescence, modification validée, verrou, IDOR +
concurrence, RBAC, 0-groupe + capacité 0.

Régression : `AffectationMoteurTest` + `PopulationDataset` +
`DataExchange` verts (66 tests cumulés).
