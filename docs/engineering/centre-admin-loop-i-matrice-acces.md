# LOOP I — Matrice d'accès réelle (12/10/2026)

| Action | Anonyme | Restreint | admin_faculte | super_admin |
|---|---|---|---|---|
| `parametres.index` + historique | redirect | 403 | 403 | 200 |
| `parametres.update` | redirect/419 | 403, rien écrit | 403 | 302 + version + audit |
| `parametres.restaurer` | redirect/419 | 403 | 403 | 302 + nouv. version |
| `roles.index` | redirect | 403 | 403 | 200 |
| `administrateurs.*` | redirect | 403 | 403 | selon gardes |
| `audit.index` | redirect | 200 scopé | 200 scopé | 200 |
| `mail:tester` (CLI) | N/A (serveur) | N/A | N/A | N/A |

Écarts : aucun (matrice E confirmée ; journal accessible à tous les rôles
back-office mais scopé — pas de fuite prouvée). Permissions candidates :
toujours des propositions (matrice-roles-permissions.md).
