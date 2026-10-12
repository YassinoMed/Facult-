# Sauvegardes SQL planifiées — rapport de livraison (10/10/2026)

## 1. Audit préalable

Existant réutilisé sans doublon : `Sauvegarder` (mysqldump explicite),
`BackupService::create/verify` (registre `database_backups`, 6 statuts),
`ExecuterSauvegardeJob` (locks, `tries=1`, notif échec),
`TesterRestaurationJob`, `RestaurationSauvegardeService` (5 garde-fous),
`DechiffrerSauvegarde`, routes `system.backups.*` (5), permissions (6,
dont `delete`/`settings` mortes), scheduler quotidien 01:00, DR
(RPO 24 h → **1 h**), 3 suites de tests (22 tests).

## 2. Fichiers créés et modifiés

Créés : `Services/Sauvegardes/BackupSchedulerService.php`,
`Services/Sauvegardes/BackupRetentionService.php`,
`Console/Commands/EvaluerSauvegardes.php`, `Modules/Sentinel/Services/
Checks/Sauvegarde{Fraicheur,Echecs}Check.php`,
`admin/system/sauvegardes/planification.blade.php`,
`tests/Feature/{BackupPlanning,BackupPlanification,BackupSentinel,
BackupRoundtrip}Test.php`, ce rapport.
Modifiés : migration paramètres (+10 clés `sauvegarde.plan.*`),
`Sauvegarder` (option `--conserver-jours`, purge déléguée),
`ExecuterSauvegardeJob` (`$chiffrer`, `--conserver-jours`),
`SauvegardesController` (`store` async + `planification` ×2),
`RestaurationSauvegardeService` (`created_by` pré-restore),
`BackupSchedulerService` (convergence : `stockageDestination()`),
`config/sauvegardes.php` (destinations 2ᵉ main conservées, bloc
obsolète supprimé), `SentinelController` + vue index (carte),
`config/sentinel.php` (+2 contrôles), `routes/{web,console}.php`
(`planification` ×2 avec `settings`, `sauvegarde:evaluer` 15 min
**remplaçant** le créneau fixe), `index.blade.php` (lien Planning),
`.env.example`, `disaster-recovery.md`, `AGENTS.md`.

## 3. Migrations et commandes

`migrate` : `ajouter_parametres_planning_sauvegardes`
(idempotente, réversible). Commandes : `sauvegarde:evaluer`
(verrou + RUNNING + reprise périmés 2 h + dispatch),
`sauvegarde:creer [--conserver-jours]`, `sauvegarde:dechiffrer`.

## 4. Scheduler déploiement

`Schedule::command('sauvegarde:evaluer')->everyFifteenMinutes()`
(cron `schedule:run` standard, GUIDE-DEPLOY §7.3). Défaut : actif,
horaire. Workers `queue:work` files existantes (job `default`).

## 5. Variables d'environnement (sans secrets)

`SAUVEGARDE_CLE` (existant, chiffrement), `SAUVEGARDE_DESTINATION_EXTERNE`,
`BACKUP_DESTINATION_DEFAULT=local_private`, `BACKUP_TIMEOUT_SECONDS`,
`BACKUP_MIN_DISK_SPACE_MB`. Aucune valeur en clair requise.

## 6. Tests exécutés — résultats réels

| Suite | Résultat |
|---|---|
| `BackupPlanningTest` (planning, 8) + `BackupPlanificationTest` (évaluateur/rétention/repli, 8) + `BackupSentinelTest` (settings/checks/cockpit/pré-restore, 7) | 23/23 |
| `BackupRoundtripTest` (gaté `BACKUP_ROUNDTRIP=1`) | 1/1 : mysqldump 9.2 réel, **1438 tables**, 303 Ko, manifest + `--verifier` OK, parité schéma + ligne marqueur en base vierge, bases supprimées |
| `SystemBackupsTest` + `BackupServiceTest` + `SauvegardeChiffrementTest` | 41/41 (1 transitoire pendant une erreur de syntaxe, reverdi ×2) |
| `SentinelTest` + `Metier` + `Convergence` | 38/38 |
| `audit:routes-securite`, `route:list` (7 routes backups), `schedule:list`, `pint` | 0 alerte, verts |

Total bloc : **89 tests, 88 passés + 1 skip (roundtrip par défaut), 0 échec.**

## 7. Test de restauration documenté

`BackupRoundtripTest` (bases `backup_roundtrip_src/dst` créées puis
supprimées, marked `FRT`, files temporaires nettoyés) + `BackupSentinelTest::
test_pre_restore_attribue_a_l_auteur` (vrai flux sqlite : pré-restore
`tracé` à l'auteur). Restauration prod : inchangée (5 garde-fous),
jamais automatique.

## 8. Limites et risques résiduels

- Destinations : `local_private` (même disque que la base) par défaut ;
  externalisation = `externe` à configurer côté hébergeur (DR).
- `system.backups.delete` / statut `DELETED` : toujours morts (hors scope).
- Lock `store()` par expiration 5 s (pré-existant, documenté).
- `down()` sans `--secret` en restauration (pré-existant, à durcir).
- Clé perdue = lots `.enc` irrécupérables (documenté, pas de séquestre).
- Transitoire observé : suite verte perturbée par une erreur de syntaxe
  simultanée — cause prouvée, sans rapport avec le métier.
- Co-édition 2ᵉ main convergée (`destinations`, `dossierBackup(?clé)`,
  `validerStructureSql`, options mysqldump) : aucune régression (41/41).
