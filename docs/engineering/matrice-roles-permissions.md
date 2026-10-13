# Matrice rôles / permissions (Phase E, 12/10/2026)

Source : `app/Support/Permissions.php` (`TOUTES`, `MATRICE`, `ROLES`).

| Rôle | Périmètre | Notes |
|---|---|---|
| `super_admin` | `TOUTES` (dont `settings.*`, `configuration.manage`, suppressions) | Garde dernier actif (update/destroy/toggle) |
| `admin_scolarite` | Large hors `settings.*`, `*.delete` critiques, `notes.unlock` | Pas de `finances.manage` (restreint view) |
| `admin_faculte` | Scopé faculté (policies + `peutAccederFaculte`, scope→404) | Sans `configuration.manage`, sans `archives.delete` |
| `agent_scolarite` | Opérationnel restreint | Sans création comptes |
| `consultation` | `archives.view` et lectures | Sans écriture |

## Permissions candidates (proposées, NON CRÉÉES — décision requise)

`settings.history.view`, `settings.restore`, `settings.diagnostics.view`,
`users.view/create/update/suspend`, `users.sessions.revoke`,
`roles.view/manage`, `permissions.view/assign`, `mail.settings.manage`,
`mail.test.send`, `mail.logs.view`, `audit.view`.

Équivalences existantes : `settings.manage` couvre history/restore
(super_admin) ; comptes via `admin.administrateurs.*` (`super_admin`) ;
sessions via `sessions.destroy` (`auth`) ; audit via `admin.audit.*`.
**Ne rien créer sans approbation explicite** (test de verrouillage :
`RolesPhaseETest`).
