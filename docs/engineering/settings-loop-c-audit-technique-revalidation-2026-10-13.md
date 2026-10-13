# LOOP C — Audit technique · REVALIDATION (13/10/2026)

> Document de revalidation indépendante. Ne remplace ni n'écrase
> `settings-loop-c-audit-technique.md` (12/10 23:34) ni les rapports LOOP D/E/H
> ultérieurs ; il confronte leurs affirmations aux preuves du **13/10**.

## Périmètre et garanties

- Aucune modification de code applicatif, aucune migration, aucune écriture
  sur la base de dev (lectures SQL en SELECT seul via tinker), aucune clé
  ajoutée/fusionnée, aucun secret affiché.
- Environnement de test vérifié avant exécution : `phpunit.xml` =
  `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`,
  `CACHE_STORE=array`, `QUEUE_CONNECTION=sync` (lignes `<php>` du fichier).
- Exceptions gatées exécutées sur **base scratch dédiée** (`settings_conc_scratch`,
  garde interne du test : jamais le dataset dev) — pattern établi du dépôt
  (`ScheduleRaceMysqlTest`, `BackupRoundtripTest`).

## Fichiers consultés (13/10)

`app/Services/ParametreService.php` (état 13/10 00:15), `app/Models/Parametre.php`,
`app/Models/ParametreVersion.php`, `app/Services/SettingDefinition.php`,
`app/Http/Controllers/Admin/ParametreController.php`, `app/Policies/ParametrePolicy.php`,
`routes/web.php:920-925`, `routes/console.php:50-58`, migrations `2026_09_30_000004`,
`2026_10_07_110016`, `2026_10_10_102835`, `2026_10_10_120000`, `2026_10_12_205213`,
`2026_10_12_231817`, `SauvegardesController.php:168-200`,
`BackupSchedulerService.php:40-85`, `ExecuterSauvegardeJob.php:79-80,166`,
`RestaurationSauvegardeService.php:236`, `TestRestaurationService.php:73`,
`Sentinel/Services/Checks/SauvegardeFraicheurCheck.php:54`,
`Console/Commands/PurgerNotifications.php`, `Console/Commands/EvaluerSauvegardes.php`,
`Console/Commands/RapprocherPresencesEnseignants.php:22-36`,
`Modules/Attendance/Controllers/PresenceController.php:700-722`,
`tests/Feature/Settings*Test.php` (6 suites), `tests/Feature/SettingsCache*MysqlTest.php` (2),
`app/Support/Permissions.php` (TOUTES + MATRICE).

## C1 — Cache et transactions : **R-C-01 RÉSOLU, preuve exécutée le 13/10**

État initial (doc 12/10) : `POTENTIAL_RACE` — `Cache::forget()` intra-transaction,
re-scellement possible dans `rememberForever` (sans TTL).

État **13/10** (`ParametreService.php`, mtime 00:15) :

```
lockForUpdate → contrôle optimiste (409 DomainException) → no-op sans version
→ update → ParametreVersion::create (append-only)
→ Cache::forget (immédiat, comportement historique conservé)
→ DB::afterCommit(try { Cache::forget } catch (Throwable → Log::warning
   'parametre.cache_invalidation_echec'))        ← correctif R-C-01 (LOOP D)
→ Log 'parametre.modifie' → return fresh() (commit en sortie de closure)
```

Scénario de concurrence reconstruit pas à pas :
1. T1 : `forget()` intra-transaction (cache vidé, COMMIT pas encore fait).
2. T2 (autre connexion) : `get()` → miss → lit la valeur **pré-commit** →
   réécriture de l'ancienne valeur dans le cache (fenêtre réelle).
3. T1 : COMMIT, puis callback `afterCommit` → **second `forget()` purge le
   re-scellement** ; toute lecture ultérieure repeuple depuis la DB committée.
4. Si rollback : les callbacks `afterCommit` ne s'exécutent jamais
   (DatabaseTransactionsManager jette les callbacks) ; le cache vide se
   repeuple seul → auto-cicatrisation.

Classification finale : **CONFIRMED_BUG (historique), corrigé et prouvé** —
ce n'est plus un risque ouvert. Résidu assumé (documenté dans
`settings-loop-e-audit-cache.md`) : micro-fenêtre post-commit incompressible
sans écriture cache hors service — impossible par construction ici.

Comportements connexes vérifiés : `restaurer()` passe par `set()` (même double
purge) ; panne cache post-commit = écriture conservée + warning (E1.6) ;
`valeursParCategorie()` ne passe pas par le cache (lecture directe, cohérent).

**Commandes exécutées (13/10)** :
- `SETTINGS_CONC_MYSQL=1 php artisan test --compact tests/Feature/SettingsCacheRaceMysqlTest.php tests/Feature/SettingsCachePanneMysqlTest.php`
  → **2/2 verts, 8 assertions, 13,7 s** (MySQL réel 9.2, scratch).
- `php artisan test --compact` des 6 suites Settings → **22/22, 124 assertions**
  (rejoué APRÈS le correctif du 13/10 00:15).

## C2 — Concurrence d'écriture et restauration

| Point | Test présent | Exécuté 13/10 | Résultat |
|---|---|---|---|
| `lockForUpdate` + `attendu` → 409 | `SettingsVersionsLoopFTest::test_concurrence_attendu_perime_refuse` | Oui | Vert |
| No-op sans version fantôme | `test_sans_changement_ni_version_ni_audit` | Oui | Vert |
| Restauration = nouvelle version | `test_restauration_cree_une_nouvelle_version` | Oui | Vert |
| Version d'un autre paramètre → 404 | `test_restauration_version_autre_parametre_404` | Oui | Vert |
| Append-only (jamais d'UPDATE/DELETE) | couvert par les tests Versions (modèle sans setter ni destroy applicatif) | Oui | Vert |
| Concurrence réelle MySQL (2 connexions) | `SettingsCacheRaceMysqlTest` (gaté `SETTINGS_CONC_MYSQL=1`) | **Oui** | Vert |
| Panne cache post-commit | `SettingsCachePanneMysqlTest` (gaté) | **Oui** | Vert |

Aucun test unitaire n'est présenté comme preuve de course : la preuve de course
est le test deux-connexions MySQL sur base scratch, exécuté le 13/10.

## Séparation exigée par la mission

- Tests présents : voir tableaux C2/C3.
- Tests exécutables dans un environnement sûr : tous ceux listés (phpunit.xml
  vérifié ; gates scratch pour MySQL).
- Tests effectivement exécutés : 6 suites Settings (22/22) + 2 gatés (2/2) + voisins
  Archives de la veille (24/24) — total à date : 48/48 sur le périmètre.
- Concurrence réelle MySQL : **démontrée** le 13/10 (2 tests gatés verts).
