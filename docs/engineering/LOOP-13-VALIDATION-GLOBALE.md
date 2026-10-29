# LOOP 13 — Validation globale (consolidation)

**Date d'exécution :** 27/10/2026
**Mode :** exécution de la suite complète + contrôles ciblés (MySQL jetable, navigateur, accessibilité, charge). **Aucun commit, aucun push, aucune action de production.**
**Référence :** `LOOP-00-AUDIT-DIRECTEUR-UNIREGISTRE.md` §3, ligne 13 (« tous contrôles obligatoires passent, aucun risque critique ouvert, preuves reproductibles »).

## 1. Verdict

**`PASS_WITH_RESERVATIONS`.**

La validation **technique exécutable** est verte :

| Contrôle | Résultat |
|---|---|
| Suite complète (Unit + Feature) | **5 244 tests · 5 211 réussis · 0 échec · 33 ignorés · 25 757 assertions · 368 s** |
| MySQL réel (bases jetables) | **37 tests · 144 assertions · 0 échec** sur 9 batteries dédiées |
| Navigateur / smoke E2E | **6 pages · 0 erreur JS · 0 erreur console métier** |
| Accessibilité (axe-core) | **0 violation** sur les 6 pages (WCAG 2.0/2.1 A + AA) |
| Charge HTTP | **240 requêtes · 0 échec** (p95 50–78 ms) |
| Scheduler | **21 tâches** planifiées, dont `competences:coherence` à 05:40 ; commande exécutée en lecture seule |

Le verdict n'est **pas `PASS`** au sens strict du LOOP-00, car quatre contrôles restent
`NOT_EXECUTED` et ne peuvent pas être déclarés passants sans preuve : **scan de sécurité
(SAST/dépendances)**, **couverture de code**, **exécution réelle des tâches planifiées
destructives**, et **roundtrip de sauvegarde MySQL**. S'y ajoutent les blocages métier
déjà documentés (LOOP-09, LOOP-17, LOOP-20). `NOT_EXECUTED` n'est jamais `PASS`.

**Les 22 échecs préexistants ne se reproduisent plus** (§4) : la suite complète est
reproductible d'un seul tenant, sans exclusion ni contournement (§3).

## 2. Environnement réel d'exécution

| Élément | Valeur constatée |
|---|---|
| PHP | 8.5.11 (cli), NTS |
| MySQL | 9.2.0 |
| Base de la suite | SQLite `:memory:` (`phpunit.xml`), `APP_ENV=testing` |
| Cache / session / queue / mail | `array` / `array` / `sync` / `array` |
| Migrations présentes | 206 fichiers |
| Fichiers de tests | 573 |
| Branche / HEAD | `feature/admin-modifier-etudiant` / `38ef149` |
| État Git | 887 entrées modifiées/non suivies, **aucun commit** ; les fichiers revendiqués par ce lot sont listés au §8 |
| Portée des écritures | SQLite `:memory:` + bases MySQL **jetables** (`inscription_*_test`, bases `*_scratch`), jamais la base réelle |
| Build front | `npm run build` exécuté ; `public/build` est ignoré par Git |

Garde-fou vérifié : `tests/TestCase.php` refuse toute connexion non-SQLite sans les
variables `CONCURRENCE_MYSQL=1` ou `LOOP4_MYSQL=1` **et** un nom de base `inscription_*_test`.

## 3. Suite complète

### 3.1 Commande reproductible

```bash
vendor/bin/phpunit --configuration=phpunit.xml --display-skipped
```

### 3.2 Résultat définitif

| Mesure | Valeur |
|---|---|
| Tests exécutés | **5 244** |
| Réussis | **5 211** |
| Échecs | **0** |
| Ignorés (`skipped`) | **33** |
| Assertions | **25 757** |
| Durée | **368,3 s** |
| Code de sortie | **0** |

Le run monolithique **aboutit désormais** : la fuite d'état global `set_time_limit(300)`
(R-13/1) est corrigée, donc plus besoin de scinder la suite ni d'exclure
`TesseractEngineTest`. La stratégie par buckets (Unit + 3 buckets Feature + OCR séparé)
reste la méthode de diagnostic utilisée pendant la correction ; elle n'est plus une
condition de reproductibilité.

### 3.3 Qualification des 33 ignorés

Les 33 ignorés sont **nominatifs et qualifiés** (R-13/4 résolue). **29 d'entre eux ont
une preuve d'exécution dédiée sur MySQL jetable** (§5). Les **4 restants** sont :

| Test | Raison | Statut |
|---|---|---|
| `BackupRoundtripTest::test_roundtrip_mysqldump_restauration_parite` | Exige `BACKUP_ROUNDTRIP=1` + dump/restauration réel | `NOT_EXECUTED` (volontaire : aucun dump de base réelle) |
| `ExamensConcurrenceReelleTest::test_serierisation_de_la_meme_salle_entre_deux_processus` | `markTestIncomplete` — scénario non implémenté | `NOT_EXECUTED` par conception |
| `HfVlmOcrEngineTest::test_transcription_reelle_dune_image_lisible` | Venv `scripts/ocr` (`mlx-vlm`) absent | `NOT_EXECUTED` (environnement) |
| `ArchivesVolumetrieBenchTest::test_benchmark_registre` | Exige `ARCHIVES_BENCH=1` + base scratch volumineuse | `NOT_EXECUTED` (benchmark, hors seuil de validation) |

Les 29 autres couvrent : concurrence MySQL d'affectation, centre de travail, unicité
d'inscription, maquette, planning, archives, cache de réglages, performances d'index,
et les deux verrous `FOR UPDATE` (affectation, présences).

## 4. Les 22 échecs préexistants — traitement

Le run initial (10:41) comptait **22 échecs sur 10 classes**. Tous sont verts aujourd'hui.
Le tableau distingue ce qui est **prouvé par A/B** de ce qui est un **alignement de test
obsolète**, et de ce qui **ne se reproduit plus** sans qu'un correctif dédié ait été isolé.

| Classe | Nb | Symptôme initial | Traitement appliqué | Preuve |
|---|---|---|---|---|
| `Tests\Feature\AcademicExportTest` | 7 | `404` au lieu de `200`, statut `failed` au lieu de `expired` | Aucun correctif dédié à cette classe : les échecs ne se reproduisent pas isolément après les corrections de session. Motif le plus probable : fuite d'authentification entre deux clients API successifs (échec dépendant de l'ordre du run). | Classe seule **7/7** et suite complète **0 échec** ; causalité non isolée par A/B |
| `Tests\Feature\ApiExportTest` | 6 | Même famille (statuts d'export faux) | Même constat que ci-dessus | Classe seule **verte** ; suite complète **0 échec** |
| `Tests\Feature\ReferentielPhase1Test` | 1 | `403` au lieu de `200` | `ActAsApiClient::resetAuthGuards()` restaure aussi le guard par défaut (`setDefaultDriver`) après `forgetGuards()` | **A/B exécuté** : en annulant le correctif, le `403` réapparaît (2 échecs reproduits sur le lot de 5 classes) |
| `Tests\Feature\ScheduleAccesTest` | 1 | `403` au lieu de `422` | Même correctif de guard | **A/B exécuté** : même reproduction que ci-dessus |
| `Tests\Feature\SeparationProfilsTest` | 1 | Export `admin_faculte` : 0 étudiant au lieu de 1 | Aucun correctif dédié ; vert en isolation comme en suite complète. Même hypothèse d'état d'authentification partagé. | Classe seule **verte** |
| `Tests\Unit\DesignSystemTest` | 2 | Marqueurs d'accessibilité obsolètes (`✕`, `<strong>`, `aria-label="Fermer"`) | Test aligné sur le contrat réel des composants : icône décorative `aria-hidden`, titre en `<p>`, libellé « Fermer la modale » | Diff du test + composants vérifiés ; **vert** |
| `Tests\Feature\AccessibiliteParcoursTest` | 1 | Service worker : 2 pages en cache au lieu de 1 | Le test ne lisait que `create.blade.php` (formulaire extrait dans `_wizard.blade.php`) et ignorait que le SW a **deux** `cache.put` distincts (shell hors-ligne + asset) | Lecture des deux Blade + assertions séparées ; **vert** |
| `Tests\Feature\InscriptionParcoursCompletBoutEnBoutTest` | 1 | Redirection vers `/etudiant/corrections` au lieu de `/etudiant/suivi` | `attestation_honneur` exigée par le contrôle serveur (`required|accepted`) était absente de la charge utile du test | Charge utile complétée ; **vert** |
| `Tests\Feature\LoopPopupHomeOnly04Test` | 1 | `action="/inscription/existant/recherche"` introuvable | Aligné sur les routes nommées et la sémantique livrée (`422` pour une identité inconnue, scénario Lenis) ; assertions de non-fuite renforcées (email masqué, pas de code pour un dossier inconnu) | **Vert**, 2 tests ajoutés |
| `Tests\Feature\DataMesh\DataMeshContractsTest` | 1 | `snapshot_kpi` ajouté à `analytique_faculte` sans bump de version | Contrat passé en **v1.1**, documenté `docs/data-mesh/CONTRACTS.md` §6 | Le test vérifie la version gelée **1.1** ; **vert** |

**Total : 7 + 6 + 1 + 1 + 1 + 2 + 1 + 1 + 1 + 1 = 22.**

Deux points d'honnêteté :

- **Aucun correctif n'a désactivé un contrôle de sécurité.** Les assertions de non-fuite
  (absence d'email complet, absence de nom, absence de code pour un dossier inconnu) ont
  été **ajoutées** dans `LoopPopupHomeOnly04Test`, pas retirées.
- **Les 13 échecs d'export ne sont pas attribués à une correction précise** : ils ne se
  reproduisent plus, ni en isolation ni en suite complète, et aucun A/B ne les a reliés
  avec certitude au correctif de guard. L'hypothèse d'une pollution d'état entre tests
  est cohérente avec le symptôme (`404` sur une ressource liée à l'utilisateur) mais
  reste une hypothèse, pas une preuve.

`config/integrations.php` a par ailleurs été complété (`sandbox_autorise`,
`plafond_rejeux`, `upload_max` lisant `INTEGRATION_UPLOAD_MAX_OCTETS` avec repli) pour
le hub d'intégration de LOOP-18 ; ce fichier n'est **pas** la cause des échecs d'export
(vérifié : les tests d'export passent aussi avec la version d'origine).

## 5. MySQL réel — preuves sur bases jetables

Toutes les bases sont **créées pour l'occasion** et ne contiennent aucune donnée réelle :
`inscription_loop4_test`, `inscription_uniq_test`, plus les bases `*_scratch` créées par
les tests eux-mêmes.

| Batterie | Base / garde | Résultat |
|---|---|---|
| `AffectationConcurrenceMysqlTest` | `inscription_loop4_test` · `LOOP4_MYSQL=1` | **3/3**, 20 assertions |
| `CentreTravailConcurrenceMysqlTest` + `UniciteInscriptionConcurrenceMysqlTest` | `inscription_uniq_test` · `CONCURRENCE_MYSQL=1` | **10/10**, 34 assertions |
| `MaquetteRaceMysqlTest` + `MaquetteConcurrenceMysqlTest` | `inscription_uniq_test` · `MAQUETTE_RACE_MYSQL=1` · `maquette_race_scratch` | **5/5**, 21 assertions |
| `ScheduleRaceMysqlTest` | `inscription_uniq_test` · `SCHEDULE_RACE_MYSQL=1` · `schedule_race_scratch` | **2/2**, 8 assertions |
| `ArchivesConcurrenceMysqlTest` | `inscription_uniq_test` · `ARCHIVES_CONC_MYSQL=1` | **2/2**, 13 assertions |
| `ArchivesStatsMysqlTest` | `inscription_uniq_test` · `ARCHIVES_STATS_MYSQL=1` | **1/1**, 3 assertions |
| `SettingsCachePanneMysqlTest` + `SettingsCacheRaceMysqlTest` | `inscription_uniq_test` · `SETTINGS_CONC_MYSQL=1` | **2/2**, 8 assertions |
| `PopulationMySQLPerformanceTest` | `inscription_uniq_test` · driver MySQL | **2/2**, 9 assertions |
| `AffectationConcurrenceTest` + `AttendanceRisquesP1Test` | `inscription_loop4_test` · `CONCURRENCE_MYSQL=1` | **10/10**, 28 assertions (dont les 2 verrous `FOR UPDATE` auparavant ignorés) |

**Total : 37 tests, 144 assertions, 0 échec.**

Trois correctifs de **reproductibilité** ont été nécessaires (ils ne font pas partie des 22) :

- **R-13/2 — connexion `maquette_race_2`** : `config(...) + ['database' => …]` conservait la
  base du `.env` (l'opérateur `+` garde la clé de gauche) → le test NOWAIT était un faux
  négatif. Remplacé par `array_merge`.
- **`ScheduleRaceMysqlTest`** : les fils de concurrence commitent ; un run interrompu
  laissait `facultes.code = FR`. Ajout d'une purge **idempotente** limitée aux codes du
  test (`FR`, `G-RACE`, `R1`, `RC1`, `Race`, `2026-2027`).
- **`PopulationMySQLPerformanceTest`** : MySQL choisit légitimement l'index simple
  `etudiants_statut_index` plutôt que le composite. L'assertion ne fige plus le nom de
  l'index : elle exige l'absence de balayage complet **et** prouve le composite via
  `FORCE INDEX (idx_etudiants_faculte_statut)`.

## 6. Navigateur, accessibilité et smoke E2E

Harnais Playwright (Chrome), serveur local **isolé** (`php artisan serve` sur
`127.0.0.1:8899`, SQLite dans `$TMPDIR`), `axe-core 4.10.2` chargé **depuis le disque**
(la CSP de l'application bloque tout CDN — l'audit porte bien sur l'application).

| Page | HTTP | Violations axe | Erreurs JS | Champs sans nom | `h1` / `main` |
|---|---|---|---|---|---|
| `/` | 200 | **0** | **0** | 0 | 1 / 1 |
| `/inscription/nouvelle` | 200 | **0** | **0** | 0 | 1 / 1 |
| `/inscription/existant` | 200 | **0** | **0** | 0 | 1 / 1 |
| `/inscription/faq` | 200 | **0** | **0** | 0 | 1 / 1 |
| `/etudiant/connexion` | 200 | **0** | **0** | 0 | 1 / 1 |
| `/admin/connexion` | 200 | **0** | **0** | 0 | 1 / 1 |

Aucun identifiant dupliqué, aucun lien/bouton sans texte accessible, aucune image sans
alternative. **Anomalie résiduelle unique** : le `rel=preconnect` vers
`fonts.googleapis.com` est bloqué par `connect-src` (une ligne de console par page).
Sans impact visuel : la feuille de style est autorisée par `style-src` et
`document.fonts.check('16px Inter') === true`.

### 6.1 Anomalies a11y/JS corrigées

| Anomalie | Correctif |
|---|---|
| `aria-disabled="&quot;true&quot;"` (valeur ARIA invalide, axe `critical`) sur l'accueil | Attribut rendu littéralement par Blade, plus par une expression échappée |
| Contraste insuffisant sur le compteur de catégories FAQ | `text-slate-400` → `text-slate-500` |
| `ReferenceError: identification is not defined` (bandeaux MENS du wizard embarqué) | État `identification` ajouté à `parcoursAccueil`, alimenté par les réponses `MENS_FOUND` / `MENS_UNAVAILABLE` |
| `401` inutile du popup IA sur les pages publiques | Popup monté uniquement pour un acteur connecté |
| Sources CSP IPv6 invalides (`http://[::1]:5173`) ignorées + erreurs console | Littéraux IPv6 retirés des directives Vite locales |

Deux tests de régression couvrent les deux corrections d'interface :
`InscriptionAccueilParcoursTest` — **9/9, 67 assertions**.

## 7. Charge HTTP

Mesure `ab` sur le serveur local isolé (60 requêtes, concurrence 3, lecture seule) :

| Page | Requêtes | Échecs | p50 | p95 | p99 | Débit |
|---|---|---|---|---|---|---|
| `/` | 60 | **0** | 55 ms | 58 ms | 101 ms | 50,9 req/s |
| `/inscription/nouvelle` | 60 | **0** | 52 ms | 78 ms | 84 ms | 54,3 req/s |
| `/etudiant/connexion` | 60 | **0** | 48 ms | 52 ms | 54 ms | 62,3 req/s |
| `/admin/connexion` | 60 | **0** | 48 ms | 50 ms | 50 ms | 62,6 req/s |

**Réserve explicite** : `php artisan serve` est mono-processus, sans OPcache ni FPM. Ces
chiffres prouvent l'absence d'échec et l'ordre de grandeur, **pas** la capacité de
production. Une mesure représentative exigerait la stack cible (FPM/Octane + OPcache +
base réelle ou jeu de données représentatif).

## 8. Scheduler et fichiers revendiqués

- `php artisan schedule:list` : **21 tâches** planifiées, dont `competences:coherence`
  (05:40) ajoutée par LOOP-20. Aucune tâche destructive n'a été lancée
  (`documents:purger-orphelins --execute`, `maintenance:purger-logs`, `exports:purge`).
- `competences:coherence` exécutée en lecture seule sur la base réelle : réponse
  « Table competence_objectifs absente… contrôle ignoré. », **exit 0** — aucun écrit.
- Fichiers revendiqués par ce lot (correction des échecs, reproductibilité MySQL,
  accessibilité et validation) : `tests/Concerns/ActAsApiClient.php`,
  `tests/Unit/DesignSystemTest.php`, `tests/Feature/AccessibiliteParcoursTest.php`,
  `tests/Feature/InscriptionParcoursCompletBoutEnBoutTest.php`,
  `tests/Feature/LoopPopupHomeOnly04Test.php`,
  `tests/Feature/MaquetteRaceMysqlTest.php`, `tests/Feature/ScheduleRaceMysqlTest.php`,
  `tests/Feature/PopulationMySQLPerformanceTest.php`,
  `resources/views/components/alert.blade.php`, `resources/views/components/modal.blade.php`,
  `app/Services/DisponibiliteWorkerService.php`,
  `app/Http/Middleware/SecurityHeadersMiddleware.php`, `resources/js/app.js`,
  `resources/views/inscription/existant/accueil.blade.php`,
  `resources/views/inscription/faq.blade.php`, `resources/views/layouts/app.blade.php`,
  `tests/Feature/InscriptionAccueilParcoursTest.php`, et le contrat
  `docs/data-mesh/CONTRACTS.md` (§6, `analytique_faculte` v1.1).
  `config/integrations.php` est également complété (clés du hub d'intégration de
  LOOP-18), sans lien de causalité avec les 22 échecs (§4).
  Les autres entrées Git sont **préexistantes** et n'ont pas été touchées par ce lot.

## 9. Réserves et dette ouverte

| Réf. | Réserve | Statut |
|---|---|---|
| R-13/1 | `set_time_limit(300)` fuyait dans le processus PHPUnit | **Corrigée** — l'appel est ignoré sous PHPUnit (`app()->runningUnitTests()`), comportement production inchangé |
| R-13/2 | 22 échecs préexistants | **Corrigée** — 0 échec, §4 |
| R-13/3 | La suite écrit `public/preview-etudiant.html` (≈ 548 Ko) | **Ouverte** — fichier non suivi, à déplacer vers `storage/` |
| R-13/4 | 33 ignorés non qualifiés | **Corrigée** — 29 rejoués avec preuve MySQL, 4 qualifiés (§3.3) |
| R-13/5 | Couverture de code inconnue | **Ouverte** — aucun driver Xdebug/PCOV configuré |
| R-13/6 | Run monolithique non reproductible | **Corrigée** — run complet vert, §3 |
| R-13/7 | `rel=preconnect` Google Fonts bloqué par `connect-src` | **Ouverte, sans impact** — police chargée via `style-src` |
| R-13/8 | Scan de sécurité (SAST, dépendances, secrets) | **`NOT_EXECUTED`** — hors périmètre de ce lot |
| R-13/9 | Charge mesurée sur serveur de développement | **Ouverte** — non représentative de la production |
| R-13/10 | Blocages métier externes | **Ouvertes** — LOOP-09 (migration non appliquée), LOOP-17 (source officielle absente), LOOP-20 (catalogue vide, snapshots non migrés) |

## 10. Conclusion

- **Validation technique : `PASS`** — suite complète verte (5 244 tests, 0 échec),
  MySQL réel vert sur bases jetables (37 tests), 6 pages sans violation d'accessibilité
  ni erreur JS, 240 requêtes de charge sans échec.
- **Validation globale au sens LOOP-00 : `PASS_WITH_RESERVATIONS`** — les contrôles
  `NOT_EXECUTED` (sécurité, couverture, jobs destructifs, roundtrip de sauvegarde) ne
  peuvent pas être déclarés passants, et trois lots restent bloqués par des décisions
  ou des données externes.
- **Aucun commit, aucun push, aucune migration destructive, aucune donnée réelle
  modifiée.** L'état Git préexistant est préservé ; les 887 entrées du worktree ne sont
  ni nettoyées ni commitées.
