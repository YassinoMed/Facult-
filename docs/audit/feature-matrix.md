# Matrice des fonctionnalités — vérifiée dans le code (2026-09-30)

Légende : ✓ vérifié, ~ partiel, ✗ manquant. Colonne Action = suite donnée.

| Fonctionnalité | Existe | Complet | Testé | Intégré | Sécurisé | Documentation | Action |
|---|---|---|---|---|---|---|---|
| Référentiel LMD | ✓ | ✓ | ✓ (16) | ✓ | ✓ | MODULE-REFERENTIEL.md | — |
| Étudiants/Personnel | ✓ | ✓ | ✓ | ✓ | ✓ | BACK-OFFICE.md | — |
| Enseignement | ✓ | ✓ | ✓ (18) | ✓ | ✓ | MODULE-ENSEIGNEMENT.md | — |
| Planning | ✓ | ✓ | ✓ (48+20) | ✓ | ✓ | MODULE-SCHEDULE.md | — |
| Examens | ✓ | ✓ | ✓ | ✓ | ✓ | — | doc manquante, P2 |
| Notes | ✓ | ✓ | ✓ | ✓ | ✓ | ARCHIVES.md | — |
| Délibérations | ✓ | ✓ | ✓ | ✓ | ✓ (quorum) | — | doc manquante, P2 |
| Absences | ✓ | ✓ | ✓ | ✓ | ✓ | — | doc manquante, P2 |
| GED | ✓ (fédérée) | ~ (pas de destruction workflow) | ✓ | ✓ | ✓ | — | destruction contrôlée = P3 |
| Demandes | ✓ | ✓ | ✓ (19) | ✓ | ✓ | MODULE-ONLINE-REQUESTS.md | — |
| Communication | ✓ | ~ (pas de digest/préférences) | ✓ | ✓ | ✓ | — | digest = P3 |
| Finance | ✓ | ~ (pas de provider en ligne) | ✓ | ✓ | ✓ | — | doc manquante, P2 |
| Data Quality | ✓ | ✓ | ✓ | ✓ | ✓ | § ci-dessous | — |
| API | ✓ | ~ (pas de portail étudiant API) | ✓ | ✓ | ✓ | API.md + openapi.yaml | portail = P3 |
| Observabilité | ✓ | ~ (pas de backup auto vérifié en prod) | ✓ | ✓ | ✓ | operations/ | backup prod = P1 |
| PWA | ✓ (manifest/SW/offline) | ~ (pas de push) | ✓ | ✓ | ✓ (pas de cache sensible) | — | push = P3 |
| RBAC | ✓ | ✓ | ✓ (44 sec) | ✓ | ✓ | PROFILS-ET-SCOPING.md | matrice §32.5 ci-dessous |
| CI/CD | ✓ (workflow + pint) | ~ (pas de deploy.sh) | ✓ | ✓ | ✓ | GUIDE-DEPLOY (partiel) | annexe = P2 |
| Backup/DR | ✓ (commande + verify) | ~ (non exécuté en prod) | ✓ (dry) | ✓ | ✓ | operations/ | exécution prod = P1 |
| UX/Accessibilité | ✓ (DS, clavier, ARIA) | ✓ | ~ (pas d'E2E navigateur) | ✓ | ✓ | DESIGN-SYSTEM.md | E2E = P3 |
| BI | ✓ | ~ (pas de graphiques) | ✓ | ✓ | ✓ | — | graphiques = P3 |

## Matrice permissions (§32.5, rôles réels)

| Module | super_admin | admin_scolarite | agent_scolarite | consultation | admin_faculte |
|---|---|---|---|---|---|
| Dossier/Archives | CRUD | CRUD | lecture+écriture | lecture | CRUD scopé |
| Notes | CRUD+unlock | CRUD+unlock | saisie | lecture | saisie (pas unlock) |
| Planning | CRUD | CRUD | lecture | lecture | CRUD scopé |
| Examens | CRUD+publish | CRUD+publish | — | lecture | CRUD scopé |
| Finance | CRUD+confirm | CRUD+confirm | — | — | lecture scopée |
| Documents | CRUD | CRUD | CRUD | lecture+download | CRUD scopé |

Détail faisant foi : `app/Support/Permissions.php` (matrice MATRICE).
