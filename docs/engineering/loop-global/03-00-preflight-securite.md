# LOOP GLOBAL 03.0 — Porte de sécurité avant exécution des tests

**Date :** 2026-10-25 · **Mode :** lecture seule (aucun test lancé à ce stade).

## 1. État Git de référence

- LOOP-00 : 743 fichiers en travail (342 M / 399 ?? / 2 A), HEAD `38ef149`.
- À l'entrée de LOOP-03 : **747** (343 M / 402 ?? / 2 A). Écart : **+3 ??** =
  les 3 livrables de la mission elle-même (`LOOP-GLOBAL-00-ETAT-INITIAL.md`,
  `LOOP-GLOBAL-INVENTAIRE.md`, `loop-global/02-couverture-fonctionnelle.md`) ;
  **+1 M non attribué** (aucune modification de code effectuée pendant la
  phase lecture seule — à tracer par diff de listes au LOOP-10).
- Liste de référence complète sauvegardée : `loop-global/.reference-git-03.txt`
  (747 lignes) pour comparaison finale.

## 2. Configuration effective du runner (phpunit.xml, lu intégralement)

| Variable | Valeur | Effet |
|---|---|---|
| `APP_ENV` | testing | environnement de test |
| `APP_KEY` | clé de TEST dédiée (commentaire AC-00c : « jamais un secret de prod ») | pas d'exposition |
| `DB_CONNECTION` / `DB_DATABASE` | **sqlite / `:memory:`** | base en mémoire exclusive |
| `DB_URL` | vide | aucune connexion par URL |
| `MAIL_MAILER` | **array** | aucun envoi réel d'e-mail |
| `QUEUE_CONNECTION` | sync | jobs inline, aucune file réelle |
| `CACHE_STORE` / `SESSION_DRIVER` | array | rien de persistant |
| `BROADCAST` / `PULSE` / `TELESCOPE` / `NIGHTWATCH` | null/false | observabilité désactivée |
| `MODULE_*` | 6 modules explicitement actifs pour la couverture | parité local/CI documentée |

Pas de `phpunit.xml.dist`. Bootstrap standard `vendor/autoload.php` ;
suites déclarées : `Unit` (tests/Unit) et `Feature` (tests/Feature).

## 3. Garde anti-catastrophe du runner (`tests/TestCase.php`)

Chaque test hérite d'un contrôle au `setUp()` : **échec immédiat si
`database.default` ≠ sqlite**, sauf exception étroite et volontaire :
opt-in explicite (`CONCURRENCE_MYSQL=1` ou `LOOP4_MYSQL=1`) ET connexion
mysql ET nom de base `inscription_*_test` (jamais `inscription_etudiants`,
jamais prod). Le commentaire cite l'incident du 16/10/2026 (base de dev
vidée) qui a motivé cette garde.

## 4. Tests à effet externe potentiel — inventaire et gardes vérifiées

| Suite | Effet potentiel | Garde constatée (lecture du code) |
|---|---|---|
| `MaquetteConcurrenceMysqlTest`, `MaquetteRaceMysqlTest`, `AffectationConcurrenceMysqlTest`, `CentreTravailConcurrenceMysqlTest`, `SettingsCacheRaceMysqlTest` | MySQL réel (verrous de lignes) | `markTestSkipped` sans opt-in (`MAQUETTE_RACE_MYSQL=1`, `DB_CONNECTION=mysql` + base dédiée nommée) — auto-skip dans la suite standard |
| `BackupRoundtripTest` | `mysqldump`/`mysql` réels | skip sans `BACKUP_ROUNDTRIP=1` + binaires présents ; bases imposées `backup_roundtrip_src/dst` uniquement |
| `ExamensConcurrenceReelleTest` | concurrence réelle SGBD | docblock honnête : exige `EXAM_CONCURRENCY_DB_DRIVER` ; « aucune base dédiée n'a été créée pendant ce LOOP » — NON EXÉCUTÉ par conception |
| `DeliberationImportAssociationTest`, `NotesImportAssociationTest` | `db:seed` ciblé (`NotesDemoSeeder`) | s'exécute sur sqlite `:memory:` (RefreshDatabase) — semantiel de test, non destructif |
| `CourrielModuleTest` | rendu/envoi | `MAIL_MAILER=array` (+ fakes) — aucun transport réel |
| `app/Console/Commands/Sauvegarder.php` (Process) | dump réel | commande NON invoquée par la suite standard (le test roundtrip est gardé ci-dessus) |

Greffes globales : aucun `Http::` sans fake, aucun `curl_exec`/
`file_get_contents('http…')` dans les tests ; aucun `migrate:fresh`,
`db:wipe`, `migrate:reset` dans les tests ; `shell_exec`/`proc_open`
absents des tests (Process uniquement dans le test roundtrip gardé).

## 5. Mécanisme de schéma

508 fichiers de test utilisent `RefreshDatabase` : migrations exécutées sur
la sqlite `:memory:` neuve à chaque test ; 0 usage de
`DatabaseMigrations`/`DatabaseTransactions`. Aucune base secondaire réelle
n'est joignable par la configuration effective.

## 6. Couverture du préflight par rapport aux points demandés

1. `git status` : ✓ (§1, référence sauvegardée).
2. `phpunit.xml` + bootstrap + connexions : ✓ (§2).
3. Connexions explicites/réseau/destructif/effets externes dans les tests : ✓ (§4).
4. sqlite en mémoire pour le processus PHPUnit : ✓ (§2).
5. Aucun test ne bascule vers MySQL/une base réelle sans opt-in gardé : ✓ (§3-4 ; 3 fichiers sondent `getenv('DB_CONNECTION')` uniquement pour auto-skip).
6. Schéma = migrations sur `:memory:` via RefreshDatabase : ✓ (§5).
7. Aucune sauvegarde/restauration/envoi/paiement réel dans la suite standard : ✓ (§4).

## 7. Verdict de la porte

**PASS** — isolation démontrée par trois couches indépendantes :
configuration (`phpunit.xml`), garde runtime (`TestCase`), auto-skip des
suites à effet externe (12 fichiers vérifiés). L'exécution séquentielle
(LOOP-03.2) est autorisée. Aucune configuration modifiée pour contourner
quoi que ce soit ; aucun commit/push/destructif effectué.
