# Résultats — Correction contrôlée Import / Export / Affectation automatique des groupes

> Fichier de suivi demandé le 10/10/2026. Consolidation des LOOPs exécutés.
> Protocole : Audit → Diagnostic → Autorisation → Correction → Tests → Vérification.
> **Aucune donnée de production modifiée.** Dépôt sans git (pas de `git status`/`git diff` possibles).

**Environnement de référence** : PHP 8.5.11 CLI, Laravel 13.33.0, `openspout/openspout ^5.11`,
`dompdf/dompdf ^3.1`, `sanctum ^4.0`. Tests : sqlite `:memory:`, queue `sync` (`phpunit.xml`).
MySQL serveur 9.2.0 (LOOP 4, base dédiée éphémère `inscription_loop4_test`, supprimée après preuve).

---

## 1. Tableau de synthèse

| Anomalie | Diagnostic | Correctif (fichiers) | Tests | État |
|---|---|---|---|---|
| CIN en clair dans l'export de masse | Confirmé par lecture + test RED (5 échecs/7) | `MassAssignmentService.php`, `AffectationProposalController.php`, `propositions.blade.php` + nouveau test | 91 tests, 0 échec | `CORRIGÉ ET TESTÉ` |
| Entités export-seules → 404 API | Confirmé par lecture + test RED (3 échecs/8) | `DataExchangeApiController.php` + nouveau test | 88 tests, 0 échec | `CORRIGÉ ET TESTÉ` |
| Concurrence / capacité des groupes | Revue + SQLite 86/86 + MySQL 3/3 (2 passes) : aucun défaut observé | Aucun (moteur conforme) + nouveau test de preuve | 86 + 6, 0 échec | `NON REPRODUIT` |
| Colonnes d'export vides (`moyenne/crédits/décision`) | Confirmé (`lignePour():341-343` retourne `''`) | En attente LOOP 5 | — | `À DÉCIDER PAR LE MÉTIER` |
| Message d'erreur ODS incomplet | Confirmé (`IoException:22-24` omet ODS) | En attente LOOP 6 | — | Non traité (LOOP 6) |
| Limite API 10 000 lignes en mémoire | Confirmé (`DataExportService:38`) | En attente LOOP 7 | — | Non traité (LOOP 7) |

---

## 2. LOOP 1 — Audit initial (sans modification)

- **Anomalies confirmées par lecture directe** : les 6 (voir tableau).
- **Corrections de chemins du rapport précédent** : `ExportColumnPolicy` = `app/Services/Io/Exports/ExportColumnPolicy.php`
  (pas `DataExchange/`) ; `ListeEtudiantsService` = `app/Services/Exports/ListeEtudiantsService.php`.
- **Écart documentaire** : le masquage réel est `2 premiers + *** + 3 derniers`
  (`ExportColumnPolicy::masquerCin:104`, ex. `12***678`), pas `BK***001`.
- **Baseline exécutée** : 114 tests (113 passés + 1 skip `FOR UPDATE` MySQL-only), 0 échec.
  - `SensitiveExportTest` + `Security/ImportExportSecurityTest` : 11/11.
  - `DataExchangeTest` + `ExportListesTest` + `ApiExportTest` : 54/54.
  - `AffectationMoteurTest` + `AffectationConcurrenceTest` + `AffectationCockpitTest` + `AffectationMasseLotTest` : 48 + 1 skip.

## 3. LOOP 2 (P0) — CIN dans l'export de masse — `CORRIGÉ ET TESTÉ`

- **Cause racine** : `MassAssignmentService::analyser()` produisait `student_cin` brut (`:426`, `:818`)
  sans consulter `exports.personal` ; zéro occurrence de la politique dans `AffectationController` ;
  route limitée à `can:data.export`. Chemins secondaires : JSON `analyserMasse`, vue `masse-simulation`,
  vue `propositions` (`:184` + `Js::from($prop)` sérialisant CIN/e-mail/téléphone/naissance/adresse).
- **Règle autorisée** : masquer sauf `exports.personal` (permission existante réutilisée, rien créé).
- **Fichiers modifiés** :
  - `app/Services/Affectations/MassAssignmentService.php` — masquage centralisé en fin d'`analyser()`
    (couvre vue + JSON + CSV).
  - `app/Http/Controllers/Admin/AffectationProposalController.php` — branche `wantsJson` masquée en mémoire.
  - `resources/views/admin/affectations/propositions.blade.php` — `:184` via `masquerCin`, `Js::from`
    réduit au strict nécessaire (`id`, `proposed_group_id`, `metadata` / `id` seul).
  - `tests/Feature/AffectationMasseCinProtectionTest.php` — **créé**, 7 tests.
- **Résultats** : RED 5/7 avant → **7/7** après ; voisins 42 + 42 ; **total 91, 0 échec**. `pint` vert.
- **Effet assumé** : recherche plein texte par CIN complète dans la vue ne matche plus (noms/numéros OK).

## 4. LOOP 3 (P1) — Export API export-seul — `CORRIGÉ ET TESTÉ`

- **Cause racine** : `DataExchangeApiController::exporter()` validait via `verifierEntite()` → `import()`.
  `presences`, `notes_epreuves`, `anomalies_qualite`, `factures`, `demandes` → 404 à tort.
  Confusion de registre, pas garde-fou (le web résout via `export()` + garde `finances.view`).
- **Fichiers modifiés** :
  - `app/Http/Controllers/Api/V1/DataExchangeApiController.php` — `exporter()` résout via `export()`
    (inconnues → 404 conservé) + **garde `finances.view` → 403 sur `factures`, miroir du web**
    (sans elle : exfiltration au seul scope `data:exchange`). `preview()`/`confirmer()` inchangés.
  - `tests/Feature/DataExportSeulApiTest.php` — **créé**, 8 tests.
- **Résultats** : RED 3/8 avant → **8/8** après ; voisins 52 + 28 (dont dérive OpenAPI) ;
  **total 88, 0 échec**. `pint` vert.
- **Reste** : aligner `docs/openapi.yaml` sur les entités servies (proposé en LOOP 9).

## 5. LOOP 4 (P1) — Concurrence / MySQL — `NON REPRODUIT` (aucun correctif)

- **Revue** : les 4 chemins d'écriture convergent vers `AffectationService::affecter()` (verrous
  étudiant→groupe + capacité re-vérifiée sous verrou + transaction). Pas de contrainte unique DB
  (vérifié `information_schema`) : les verrous sont la seule garde.
- **SQLite** : 86/86 invariants (L1↛L2, complet refusé, simulation sans écriture, idempotence…).
- **MySQL 9.2.0 / InnoDB / REPEATABLE-READ, base dédiée éphémère** (créée, migrée, **supprimée** ;
  dev vérifiée intacte) : nouveau `tests/Feature/AffectationConcurrenceMysqlTest.php`
  (6 workers `pcntl_fork`, gardes refusant toute base non dédiée), **3/3 verts sur 2 passes** :
  capacité 2 jamais dépassée (2 gagnent / 4 refus métier), même étudiant → 1 seule active,
  L1→L2 refusé sans écriture. Rejouer : créer la base + `migrate`, puis
  `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=inscription_loop4_test
  DB_USERNAME=root DB_PASSWORD=root LOOP4_MYSQL=1 php artisan test tests/Feature/AffectationConcurrenceMysqlTest.php`.
- **Incidents = harness de test uniquement** (transaction `RefreshDatabase` invisible aux forks,
  socket partagé, `tearDown` contaminant sqlite) — corrigés dans le test, **zéro code métier touché**.

## 6. Reste à traiter (autorisation requise par LOOP)

- **LOOP 5** : colonnes `moyenne/crédits/décision` vides — **décision métier requise** (alimenter depuis
  délibérations/notes avec règle à définir, ou retirer les colonnes ; aucune formule inventée).
- **LOOP 6** : message `formatInvalide` sans ODS (+ aides des vues).
- **LOOP 7** : mesures 1k/5k/10k de l'export API (durée/mémoire/requêtes) et justification du plafond.
- **LOOP 8** : validation globale (diff impossible sans git — moyen alternatif à décider).
- **LOOP 9** : mise à jour de `docs/fonctionnement-import-export-affectation-groupes.md` (section corrections).

## 7. Commandes de vérification

```bash
php artisan test --compact tests/Feature/AffectationMasseCinProtectionTest.php
php artisan test --compact tests/Feature/DataExportSeulApiTest.php
php artisan test --compact tests/Feature/AffectationConcurrenceMysqlTest.php tests/Feature/AffectationConcurrenceTest.php
php artisan test --compact tests/Feature/AffectationMoteurTest.php tests/Feature/AssignmentAutomatiqueTest.php
vendor/bin/pint app/Services/Affectations/MassAssignmentService.php app/Http/Controllers/Admin/AffectationProposalController.php app/Http/Controllers/Api/V1/DataExchangeApiController.php
```

**Totaux mission (sans double-compte inter-LOOP)** : ~280 exécutions de tests ciblées, 0 échec durable ;
1 skip documenté pré-existant (`FOR UPDATE` MySQL-only) ; fichiers métier modifiés : 4 ;
tests créés : 3 fichiers (18 tests).
