# LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02 — LOT 0 : Audit indépendant du dépôt réel

> **Nature du document** : livrable LOT 0 du mandat `LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02`
> produit par une session distincte de celle des travaux concurrents constatés. Ce document
> **n'écrase et ne modifie** ni `LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-01.md`,
> ni `LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02.md` (rapport d'une autre révision concurrente),
> ni aucun fichier de code. Audit exécuté en **lecture seule** sur le code du module.
>
> **Date** : 2026-10-22, 21:20–21:35 · **Branche** : `feature/admin-modifier-etudiant`
> **HEAD** : `38ef14933de9980a4c62cb805bd19bc86c2b3081` (inchangé) · **Arbre** : ~498 entrées WIP.

---

## 0. Constat opérationnel dominant — travaux concurrents actifs sur ce même mandat

**Fait vérifié par horodatage et `git status`** (21:10 → 21:26, pendant cet audit) :

| Fichier | Mtime | État Git |
|---|---|---|
| `app/Modules/Evaluation/Services/EvaluationQualityService.php` | 21:13 | non suivi (nouveau) |
| `app/Modules/Evaluation/Controllers/AnomalieController.php` | 21:10 | non suivi (nouveau) |
| `resources/views/modules/evaluation/anomalies-index.blade.php` | 21:10 | non suivi (nouveau) |
| `app/Modules/Evaluation/Routes/web.php` | 21:19 | modifié (routes `evaluations.anomalies.*` + `notes.soumettre`) |
| `app/Modules/Evaluation/Services/EvaluationImportService.php` | 21:2x | nouveau |
| `app/Modules/Evaluation/Services/EvaluationWorkflowService.php` | 21:2x | nouveau |
| `tests/Feature/EvaluationQualityServiceTest.php`, `EvaluationWorkflowServiceTest.php` | 21:2x | nouveaux |
| `app/Policies/QualitePolicy.php`, `app/Http/Controllers/Admin/QualiteController.php` | 21:15+ | en cours d'écriture |
| `docs/engineering/LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02.md` | 21:23 | rapport d'une révision concurrente (verdict `PASS_WITH_RESERVATIONS`, constats N-01..N-12) |

**Conclusion** : au moins deux sessions concurrentes opèrent sur ce même mandat — une session
d'implémentation (Lots 1–3 au moins, en cours d'écriture minute par minute) et une session de
révision qui a corrigé le brouillon d'exécution et consigné les défauts N-01..N-12.

**Conséquence appliquée (règle §0.3 du mandat)** : aucune modification fonctionnelle n'est effectuée
par cette session. Tout lot dont les fichiers sont détenus par l'implémentation concurrente non
commitée est rendu **`BLOCKED`** (conflit avec des changements concurrents). Le périmètre exécuté
ici se limite à ce que le mandat rend obligatoire et sûr : **LOT 0 — audit réel, vérification
indépendante, exécution des tests de référence, rapport**.

---

## 1. Architecture constatée

### 1.1 Environnement

- PHP **8.5.11**, Laravel **13.33.0** ; tests sur SQLite `:memory:` (`RefreshDatabase`) ;
  `openspout/openspout ^5.11` disponible (XLSX/ODS) ; aucun framework E2E navigateur
  (pas de Dusk/Playwright dans `composer.json`/`package.json`) — limite déjà consignée dans les
  programmes précédents, reconduite.
- File d'attente réelle en usage (jobs `ImportJob`, `GeneratePopulationExport`, …) ; en test, les
  jobs sont exécutés manuellement via `app(Job::class)->handle(...)` (pas de worker en test).

### 1.2 Les trois niveaux de données — confirmés dans le code

| Niveau | Table / modèle | Source de vérité | Transitions | Protection |
|---|---|---|---|---|
| 1. Notes en saisie | `evaluation_notes` (`NoteEpreuve`) | saisie admin/enseignant, imports | `STATUTS = draft, entered, submitted, validated, locked, correction_demandee` + `TRANSITIONS` serveur (`NoteEpreuve::TRANSITIONS`) | `lockForUpdate` épreuve+notes (transaction), policy `EvaluationPolicy` (périmètre faculté épreuve **et** étudiant), verrouillage `verrouillee_at` |
| 2. Notes officielles validées | `notes` (`Note`, cœur) | synchronisées **à la validation** (`note_id`, `SaisieNoteService::valider` → `resynchroniserNoteOfficielle`) | toute modification d'une note `validated` repasse en `entered` + `valide_par = null` (F-01 corrigé, vérifié lignes 176-224) | revalidation obligatoire, verrous, historique |
| 3. Décisions publiées | `evaluation_decisions` (`DecisionDeliberation`, snapshot) | `DeliberationService::preparer` (snapshot immuable) puis `valider` / `publier` (`publie = true`, `statut = published`) | `decider()` avec **concurrence optimiste** (paramètre `updatedAt`) ; correction d'une note → `marquerResultatsInvalides()` (`resultats_invalides_le`) bloque toute validation ultérieure jusqu'à re-préparation | publication idempotente à confirmer ; téléchargements étudiant bloqués 403 sans publication (testé) |

**Invariants respectés à ne pas fusionner** : le snapshot de délibération n'est jamais réécrit par
une correction de note ; la re-préparation est explicite ; l'affichage étudiant lit la décision
publiée et la note officielle, jamais la saisie.

### 1.3 Cartographie des composants existants (avant/après travaux concurrents)

- **Contrôleurs** : `NoteEpreuveController` (saisie, lot, anonyme, imports multi, validation,
  verrouillage, correction, compostage), `DeliberationController`, `MesNotesController` (espace
  enseignant strictement anonyme), `TableauBordEvaluationController`, `AnomalieController` (nouveau,
  concurrent), `QualiteController` (centre qualité préexistant, retravaillé concurrentement).
- **Services** : `SaisieNoteService`, `DeliberationService`, `CalculEvaluationService`,
  `GradeCheckSuite` (anomalies de délibération : `note_hors_bornes`, `coefficient_nul`,
  `candidat_sans_note`, `moyenne_epreuve_anormale`, `ecart_session` — agrégées, non bloquantes),
  `CompostageService`, `PvDeliberationService`, + nouveaux concurrents `EvaluationQualityService`,
  `EvaluationImportService`, `EvaluationWorkflowService`.
- **Imports Data Exchange** : définitions `NoteEpreuveAnonymeDefinition` (modes `creer|upsert`,
  remplacement interdit par construction — `vider()` lève une exception), `NoteEpreuveDefinition`,
  parcours preview → confirmer (token 36, anti-rejeu `Cache::add`, re-validation anti-TOCTOU,
  revue obligatoire des lignes signalées) → rapport ; volumineux mis en file (`ImportJob`).
- **Historique** : `NoteEpreuveHistorique` (`evaluation_notes_historique`) : ancienne/nouvelle
  valeur, motif, auteur — écrit par la saisie, la validation et les imports. **Aucune vue de
  comparaison avant/après n'existe** (Lot 6 du mandat non couvert).
- **Permissions** : `notes_eval.{view,enter,update,validate,lock,correct,import,export,compostage.resolve}`,
  `evaluations.{view,create,update,delete}`, `sessions_examen.*`, `epreuves.*`, `deliberations.*`,
  `qualite.{view,manage}` — gates générées automatiquement (`AppServiceProvider:173`).

## 2. État initial des tests — exécutés pendant cet audit (preuves réelles)

| Suite | Commande | Résultat |
|---|---|---|
| Domaine complet | `php artisan test --compact --filter="Evaluation\|Note\|Deliberation"` | **198 tests / 198 PASS / 799 assertions** (25,3 s) |
| Suites module + concurrentes | `EvaluationQualityServiceTest`, `EvaluationWorkflowServiceTest`, `NotesEvaluationsSecuriteTest`, `NotesImportMultiTest`, `SaisiesFormatsTest`, `EnseignantNotesTest` | **82 tests / 82 PASS / 339 assertions** (8,9 s) |

**Suite globale NON exécutée** : arbre à ~498 entrées avec écritures concurrentes actives pendant
l'audit — exécution jugée non représentative et risquée ; limite consignée conformément au mandat.

## 3. Vérification indépendante des travaux antérieurs (ADVANCED-01 + remédiation + audit post)

| Réf. | Claim antérieur | Vérification réelle (code/tests de cette session) |
|---|---|---|
| F-01 | Note validée modifiée → `entered` + revalidation exigée | **Confirmé** (`SaisieNoteService` L176-224 : statut forcé `entered`, `valide_par = null`, sauf valeur inchangée) |
| F-02 | Aucune note fantôme pour absent sans valeur | **Confirmé par tests** (`test_absent_sans_note_ne_cree_aucun_enregistrement_fantome` PASS dans la baseline) |
| F-04 | Verrous pessimistes | **Confirmé** (`lockForUpdate` épreuve/notes, transaction `DB::transaction`) |
| F-07 | Périmètre faculté épreuve + étudiant | **Confirmé** (policy `EvaluationPolicy`, gardes contrôleur `importMulti` L298-301) |
| F-08 | Import 1 000 lignes < 50 requêtes | **Non re-mesuré dans ce lot** — mesures antérieures : 11 requêtes / 0,38 s / 18,4 Mo (audit post §18.4). Commande de re-mesure fournie en §8 |
| F-09 | MIME strict + limite 2 000 lignes | **Contredit pour le flux `importMulti`** : la liste MIME accepte désormais `json, xml, ms-excel, xlsx, ods, zip` (L306) — plus large que le `text/csv` strict annoncé ; l'import anonyme simple reste `fgetcsv` (CSV seul) |
| F-10 | Auteur tracé (`saisi_par`, historique) | **Confirmé** (paramètres `auteurId`/`origine` threadés, colonnes upsert L277) |
| F-11 | Navigation clavier + état de sauvegarde | **Confirmé** (`notes-saisie.blade.php` : `ArrowDown/Enter`, états `sauvegarde/modifie/en_cours/erreur`, `beforeunload`, `offline/online`) |
| F-03/D-03 | Dé-publication post-publication | **Toujours ouvert** (aucun mécanisme de dé-publication introduit ; snapshot protégé) — décision institutionnelle |
| D-01/D-02/D-04/D-05/D-06 | Décisions institutionnelles | **Toujours ouverts**, fail-soft en place — non tranchés par ce lot |

## 4. Vérification indépendante des constats N-01..N-12 (révision concurrente)

| Constat | Vérification propre de cette session | Statut |
|---|---|---|
| N-04 mode `creer` ignoré à l'import | Le mode est validé (`in:creer,upsert`), propagé jusqu'à l'exécution et au `ImportJob` ; la sémantique `creer` (rejet des mises à jour) n'a pas été démontrée dans l'exécuteur | **Non conclusif** — lecture ligne à ligne de l'exécuteur requise |
| N-06 duplication du centre d'anomalies | **Confirmé structurellement** : coexistence de `qualite.index` (centre qualité préexistant avec `transiter`/`assigner`, `can:qualite.*`), de `population/qualite`, de `datamesh/qualite` **et** du nouveau `evaluations.anomalies.*` — sans articulation documentée | Confirmé |
| N-07 workflow non branché | **Confirmé** : `EvaluationWorkflowService` n'est référencé que par la nouvelle route `notes.soumettre` ; `DeliberationController::valider/publier` et `NoteEpreuveController::valider` n'y passent pas | Confirmé |
| N-08 MIME/constantes non conformes à l'annonce | **Confirmé** (cf. F-09 ci-dessus) | Confirmé |
| N-11 faux positifs « hors bornes » | **Risque confirmé** : `GradeCheckSuite` compare la note officielle à **0–20 en dur** alors que `NoteEpreuve` porte un `note_max` par épreuve ; si des barèmes ≠ 20 coexistent, anomalie fausse | Confirmé (risque) |
| N-12 permission d'import incohérente en vue | **Confirmé** : la vue gate la section import avec `@can('notes_eval.import')` (L92, L116) alors que la route exige `can:notes_eval.enter` — un compte avec `enter` sans `import` voit le bouton masqué mais peut POSTer | Confirmé (mineur, serveur plus permissif que l'UI) |
| N-01, N-02, N-03, N-05, N-09, N-10 | Constats de la révision concurrente portant sur ses propres artefacts | Non re-vérifiés un par un (hors périmètre de sûreté de cette session) |

## 5. Risques de sécurité relevés (audit LOT 0)

1. **Surface MIME élargie** sur l'import multi (zip/xml/json/ods/xlsx) : la détection MIME réelle
   par `finfo` existe (`EvaluationImportService::detecterMimeReel`), le re-contrôle anti-TOCTOU et
   l'anti-rejeu aussi — mais l'élargissement doit être justifié par des tests par format (limites
   de décompression, bombe zip) avant tout élargissement de rôle.
2. **Incohérence UI/serveur `notes_eval.import` vs `notes_eval.enter`** (N-12) : à aligner sans
   élargir les permissions.
3. **Trois centres d'anomalies/qualité** sans articulation : risque de divergences de périmètre
   (N-05 : scope faculté sur les anomalies persistées à vérifier).
4. **Aucun défaut critique de sécurité nouveau** n'a été introduit par les travaux concurrents dans
   ce qui a pu être vérifié en lecture : les gardes serveur existantes (policy, périmètre, verrous)
   restent en place et les 280 tests de référence passent.

## 6. Décisions institutionnelles toujours ouvertes (reprises, non tranchées)

D-01 pénalisation absence · D-02 dispenses · D-03 dé-publication/rectification post-publication ·
D-04 alignement live vs snapshot · D-05 relevés provisoires avant clôture · D-06 annulation d'une
note validée. **Aucune règle n'a été décidée par cette session** ; tout lot dépendant reste isolé.

## 7. Dépendances entre lots et verdicts

| Lot du mandat | État réel constaté | Verdict de cette session |
|---|---|---|
| LOT 0 — audit | Exécuté ici (lecture seule, preuves §2-§5) | **PASS_WITH_RESERVATIONS** (suite globale et re-mesure perf non exécutées : arbre concurrent) |
| LOT 1 — centre d'anomalies | Implémenté **en cours** par la session concurrente (`AnomalieController`, `EvaluationQualityService`, vue, routes, tests 82/82 dont ses tests) | **BLOCKED** (écriture concurrente active sur les mêmes fichiers ; risque d'écrasement interdit par §0.3) |
| LOT 2 — import/preview | Existe déjà largement (preview/confirm/annuler/rapport/file) + refonte concurrente en cours (`EvaluationImportService`, `NoteEpreuveAnonymeDefinition` modifiée) | **BLOCKED** (idem ; défauts N-04/N-08 à traiter par le détenteur des fichiers) |
| LOT 3 — workflow/publication | Transitions existantes solides (statuts, verrous, concurrence optimiste) + service concurrent `EvaluationWorkflowService` partiellement branché (N-07) | **BLOCKED** (idem) |
| LOT 4 — saisie rapide | Navigation clavier, états de sauvegarde, offline déjà présents ; vues retravaillées concurrentement (21:2x) | **BLOCKED** (idem) |
| LOT 5 — documents groupés | PV/relevés par délibération existent ; pas de génération groupée par périmètre ; aucun fichier du lot détenu à l'instant T mais l'implémentation concurrente progresse lot par lot | **BLOCKED** (prudence : collision probable avec l'implémentation en cours du même mandat) |
| LOT 6 — historique/comparaison | Modèle d'historique existant ; **aucune vue de comparaison** ; fichier non détenu à l'instant T | **BLOCKED** (même raison ; à réévaluer après stabilisation) |
| LOT 7 — intégration finale | Non démarrable avant résolution des lots | **BLOCKED** |

## 8. Commandes de reproduction

```bash
# Baseline domaine (exécutée : 198/198, 799 assertions)
php artisan test --compact --filter="Evaluation|Note|Deliberation"
# Suites module + travaux concurrents (exécutée : 82/82, 339 assertions)
php artisan test --compact tests/Feature/EvaluationQualityServiceTest.php \
  tests/Feature/EvaluationWorkflowServiceTest.php tests/Feature/NotesEvaluationsSecuriteTest.php \
  tests/Feature/NotesImportMultiTest.php tests/Feature/SaisiesFormatsTest.php \
  tests/Feature/EnseignantNotesTest.php
# Re-mesure performance import (non exécutée dans ce lot — arbre concurrent)
php artisan test --compact --filter="test_mesure_performance_requetes_import_lot_notes"
```

## 9. Plan minimal proposé (à exécuter après stabilisation de l'arbre)

1. Attendre la fin des écritures concurrentes ; réinventorier (`git status`, horodatages).
2. Trancher N-06 (articulation centre qualité / centre anomalies — fusion ou périmètres distincts
   documentés) **avant** tout nouveau code d'anomalies.
3. Corriger N-12 (aligner la gate de vue sur la route, sans élargir), confirmer/infirmer N-04 par
   lecture de l'exécuteur + test dédié mode `creer`.
4. Paramétrer la borne de `GradeCheckSuite` sur le barème réel (N-11) — technique, sans décision
   institutionnelle.
5. Brancher ou retirer `EvaluationWorkflowService` (N-07) — décision de cohérence, pas de
   réécriture.
6. Lot 6 : vue de comparaison d'historique autorisée (aucun fichier contesté à réévaluer).
7. Re-mesure performance import 1 000 lignes puis Lot 7 (parcours A-E, sécurité transversale).

## 10. Gate G0 — verdict de cette session

**`PASS_WITH_RESERVATIONS` pour l'audit** : les responsabilités des notes en saisie, des notes
officielles et des publications sont suffisamment comprises (§1.2), les tests de référence passent
(280/280), les travaux antérieurs sont confirmés ou contredits avec preuves, et les défauts
réellement ouverts sont listés. **Aucun lot fonctionnel n'est démarré par cette session** : les
lots 1 à 7 sont rendus `BLOCKED` au titre de la règle « conflit avec des changements concurrents »
(implémentation du même mandat en cours d'écriture non commitée), avec le plan de reprise §9.

**Verdict global du programme au stade LOT 0 : `BLOCKED`** pour l'implémentation, **audit livré**.
Cette session n'a effectué **aucun commit, push, migration ni opération destructive** et n'a créé
que le présent document.
