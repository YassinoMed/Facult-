# LOOP C — Risques · REVALIDATION (13/10/2026)

> Mise à jour de `settings-loop-c-risques.md` (12/10 23:34). Aucune ligne
> supprimée « en silence » : chaque requalification cite sa preuve du 13/10.

## Table des risques — statut au 13/10

| ID | Risque | Statut 13/10 | Preuve |
|---|---|---|---|
| R-C-01 | Cache obsolète post-`forget` intra-transaction | **RÉSOLU (était POTENTIAL_RACE P0)** | Correctif `afterCommit` présent (`ParametreService.php` L65-75) ; `SettingsCacheRaceMysqlTest` 1/1 vert le 13/10 (scénario T2 re-scellement → purge post-commit) |
| R-C-02 | Course d'écriture réelle MySQL non démontrée | **RÉSOLU (était P0 NOT_RUN)** | 2/2 tests gatés MySQL scratch verts le 13/10 (`SETTINGS_CONC_MYSQL=1`, garde anti-dataset-dev) |
| R-C-03 | 13 clés `sauvegarde.*`/`sentinel.*` hors registre `SettingDefinition` | **TOUJOURS OUVERT (P1→P2)** | Registre = 6 clés institutionnelles (`SettingDefinition::catalogue`) ; `sauvegarde.plan.*` (10) + héritées utilisées validées via **UI dédiée** `SauvegardesController` (validation propre, pas le repli type) ; repli par type couvert par `SettingsDefinitionsLoopCTest::test_cle_hors_registre_repli_par_type` (22/22). Risque résiduel = absence de bornes métier centralisées sur ces clés |
| R-C-04 | `sentinel.*` lus jamais seedés (défauts codés seuls) | **RÉSOLU** | Migration `2026_10_12_231817_ajouter_parametres_sentinel.php` (seuils + cooldown) |
| R-C-05 | Collision de nommage `sauvegarde.*` (paramètres vs types de notification) | **ACCEPTÉ (P2, doc-only)** | Clés = configuration, types notif = gabarits — espaces disjoints ; mentionné dans docs sauvegardes |
| R-C-06 | Restauration sans `attendu` (dernière gagnante) | **DÉCISION OUVERTE (P2)** | Inchangé (`restaurer()` → `set(..., null, ...)`) ; version append-only = traçabilité totale conserve l'historique malgré l'écrasement |

## Nouveaux constats de la revalidation (13/10)

| ID | Constat | Classe | Preuve |
|---|---|---|---|
| R-C-07 | 9 clés legacy `sauvegarde.*` **sans usage runtime prouvé** : `active`, `frequence`, `heure`, `jours`, `retention_jours`, `retention_max_backups`, `stockage_destination`, `alerte_succes`, `alerte_echec`, `compression` | POTENTIELLEMENT_ORPHELINES (P2) | ripgrep exhaustif `app/ resources/ config/ scripts/ routes/ database/ docs/` : aucune lecture/écriture hors migrations (les seules lectures `conserver`, `notifier_echec`, `alerte_age_heures` et tout `plan.*` sont documentées, voir tableau C3 ci-dessous) |
| R-C-08 | Catégories `sauvegardes`/`presences` hors `Parametre::CATEGORIES` ; type `chaine` (legacy) hors `Parametre::TYPES` | CONFIRMED (P3, cohérence catalogue) | Base dev : 30 clés dont `presences.*` ×2 et `sauvegarde.*` famille legacy typée `chaine` |
| R-C-09 | Clés du registre sans consommateur prouvé : `etablissement.nom`, `etablissement.fuseau`, `assiduite.seuil_alerte`, `interface.pagination` | ABSENCE_D_USAGE_NON_PROUVÉE (P2) | grep `app/`, `app/Modules/`, `resources/views/` vide (seules `academique.seuil_validation` [indicative assumée] et `notifications.purge_jours` [PurgerNotifications.php:23] ont un consommateur prouvé) |

## C3 — Cartographie des familles de sauvegarde (base dev, 13/10)

| Clé | Migration d'origine | Lecture(s) | Écriture(s) | Conclusion |
|---|---|---|---|---|
| `sauvegarde.plan.actif` | 2026_10_10_102835 | `BackupSchedulerService:63` | `SauvegardesController:190` | UTILISÉE |
| `sauvegarde.plan.frequence` | idem | `BackupSchedulerService:40` | `:191` | UTILISÉE |
| `sauvegarde.plan.jours` | idem | `BackupSchedulerService:48` | `:192` | UTILISÉE |
| `sauvegarde.plan.heure` | idem | `BackupSchedulerService:56` | `:193` | UTILISÉE |
| `sauvegarde.plan.retention_jours` | idem | `BackupSchedulerService:68`, `ExecuterSauvegardeJob:80` | `:194` | UTILISÉE |
| `sauvegarde.plan.destination` | idem | `BackupSchedulerService:85` | `:196` | UTILISÉE |
| `sauvegarde.plan.chiffrer` | idem | `BackupSchedulerService:70` | `:197` | UTILISÉE |
| `sauvegarde.plan.alerter_echec/retard/succes` | idem | `BackupSchedulerService:71-73` (lu dans `plan()` ; aucun effet observé ailleurs) | `:198-200` | UTILISÉE (effet métier faible → à confirmer, signalé) |
| `sauvegarde.conserver` | 2026_10_07_110016 | `ExecuterSauvegardeJob:79`, `SauvegardesController:168` | `:195` | UTILISÉE |
| `sauvegarde.notifier_echec` | idem | `ExecuterSauvegardeJob:166`, `RestaurationSauvegardeService:236`, `TestRestaurationService:73` | (UI sauvegarde) | UTILISÉE |
| `sauvegarde.alerte_age_heures` | idem | `SauvegardeFraicheurCheck:54` | — | UTILISÉE |
| `sauvegarde.active/frequence/heure/jours/retention_jours/retention_max_backups/stockage_destination/alerte_succes/alerte_echec/compression` | 2026_10_10_120000 | **aucune trouvée hors migrations** (preuve : recherches exhaustives ci-dessus) | seed seul | POTENTIELLEMENT_ORPHELINES (jamais supprimées — décision ouverte) |
| `presences.fenetre_pointage_minutes` | 2026_10_09_130500 | `RapprocherPresencesEnseignants:36`, `PresenceController:722` | page paramètres + commande | UTILISÉE |
| `presences.tolerance_retard_minutes` | 2026_10_09_130000 | `RapprocherPresencesEnseignants:33`, `PresenceController:707` | idem | UTILISÉE |
| `sentinel.file_echecs_seuil` / `file_attente_seuil` / `notif_cooldown_minutes` | 2026_10_12_231817 | `SentinelService`/checks | (page paramètres) | UTILISÉE |

Aucune clé n'a été supprimée ni fusionnée (règle mission).
