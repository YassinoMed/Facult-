# Registre officiel des LOOP (moteur LOOP K)

Ordre et dépendances. Le passage automatique lit ce registre ; un LOOP
absent = arrêt documenté (`STOPPED`, jamais deviné).

| Ordre | LOOP | Dépend de | Statut |
|---|---|---|---|
| 1 | `SETTINGS-A-H` (socle + versions + UI) | — | PASS (12/10/2026) |
| 2 | `CENTRE-ADMIN-A-H` (SMTP, comptes, rôles, MFA, sécurité, audit) | 1 | PASS (12-13/10/2026) |
| 3 | `LOOP-I` (audit maturité) | 2 | PASS_WITH_RESERVATIONS |
| 4 | `LOOP-J` (seeds, SMTP UI, permissions, MFA) | 3 | PASS |
| 5 | `LOOP-K` (présent moteur d'auto-passage) | 4 | IN_PROGRESS |
| 6 | `LOOP-L` (dette Pint, ops, navigateur, backlog) | 5 | PASS_WITH_RESERVATIONS |
| 7 | `LOOP-M` (présent : correction transversale et revalidation) | 6 | IN_PROGRESS |
| 8 | `LOOP-N` (présent : verrouillage secondaire inactivité) | 7 | IN_PROGRESS |

Règles de transition : `docs/engineering/loop-auto-regles-validation.md`.
Journal : `docs/engineering/loop-auto-journal.md`.
État : `docs/engineering/loop-auto-etat.json` (schéma § annexe).
