# LOOP M — Registre des réserves (13/10/2026)

| ID | Réserve | Gravité | État |
|---|---|---|---|
| M-R1 | C7/backlog B/C/D/E sans source (DH-14) | BLOQUANTE (périmètre) | Ouverte |
| M-R2 | Production NOT_VERIFIED (DH-20) | BLOQUANTE (exploitation) | Ouverte |
| M-R3 | MFA implémentée sans trancher DH-18 (approbation session LOOP J, registre non mis à jour) | MAJEURE (traçabilité) | À consigner : DH-18 → implémentée sous approbation du 13/10 |
| M-R4 | DH-13 Git : dépôt initialisé + push effectués en session, protections de branche inconnues | MAJEURE | Partielle : vérifier required checks |
| M-R5 | DH-11 Pint : résorbée de fait (vert ce jour) | MINEURE | À clore formellement |
| M-R6 | Navigateur/E2E : aucun binaire, aucune suite | MODÉRÉE | NOT_EXECUTED maintenu |
| M-R7 | Panne Redis réelle, race sans listener, `attendu` obligatoire UI seule | MODÉRÉE | Documentées, non bloquantes |
