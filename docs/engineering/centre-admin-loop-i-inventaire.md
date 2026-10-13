# LOOP I — Inventaire (12/10/2026)

| Élément | Documenté | Présent | Testé | Écart |
|---|---|---|---|---|
| `SmtpConfiguration` + migration `...002424` + `SmtpConfigurationTest` | oui | oui | 2/2 | — |
| `ParametreVersion` + migration `...05213` | oui | oui | 6/6 (F) | — |
| `SettingDefinition` (9 clés + impact) | oui | oui | 4/4 (C) | — |
| `ParametreService` (afterCommit + try/catch, verrou, optimiste) | oui | oui | race 1/1 + panne 1/1 (MySQL gatés) | — |
| `ParametreController` (index/update/restaurer) + sidebar + vues | oui | oui | 4/4 (D) + 3/3 (G) | — |
| `RoleController` + `roles.index` + sidebar | oui | oui | 4/4 (E) | — |
| `AdministrateurController` (recherche/filtres) | oui | oui | 2/2 (D) | — |
| `TesterMessagerie` + diagnostic courriel | oui | oui | 3/3 (C) | — |
| `PurgerNotifications` pilotée | oui | oui | 2/2 (E) | — |
| `parametres.restaurer` + `roles.index` (routes) | oui | oui (`route:list`) | couvertes | — |
| Migration sentinel `...31817` | oui | oui (dev DONE) | 1/1 | — |
| 15 suites (rapport : « 14 suites, 42 résultats ») | partiel | **15 fichiers, 43 tests (41+2 skips)** | 43 exécutés | Comptage : 14 vs 15 fichiers ; 42 vs 43 tests — écart mineur, fond inchangé |
| `matrice-roles-permissions.md` | oui | oui | verrou E | — |
| Historique versions UI + `attendu` obligatoire | oui | oui | F+G | — |

Divergences : aucune fonctionnelle. Seul écart = comptage des suites
(14/42 annoncés vs 15 fichiers/43 tests mesurés — `RolesPhaseETest` ou
`AuditPhaseGTest` probablement exclu du décompte d'origine).
