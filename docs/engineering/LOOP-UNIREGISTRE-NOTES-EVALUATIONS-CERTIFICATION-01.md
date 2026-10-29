# LOOP-UNIREGISTRE-NOTES-EVALUATIONS-CERTIFICATION-01
## Rapport de Certification Indépendante du Module Notes & Évaluations

> **Date de certification :** 23 octobre 2026  
> **Auteur :** Antigravity / Agentic Pair Programmer (Mode Certification Indépendante)  
> **Contexte de référence :** Suite directe de `ADVANCED-02` et `ADVANCED-02-POST-AUDIT`  
> **Environnement audité :** PHP 8.5.11 / Laravel 13.33.0 / MySQL 9.2.0 / PHPUnit 12.5.35 / macOS Darwin  
> **Règles impératives respectées :**  
> - Audit en lecture seule initial strictement respecté.  
> - Zéro commit créé, zéro push Git.  
> - Aucune opération destructive sur la base de données (`migrate:fresh`, `db:wipe`, reset de tables interdits et non exécutés).  
> - Préservation intégrale des 277 fichiers modifiés et 245 fichiers non suivis issus des travaux concurrents.  
> - Aucun navigateur headless ni dépendance externe téléchargés ou forcés sans isolation.  
> - Aucune promotion automatique de verdict : toutes les réserves sont tracées sur la base de preuves réelles.  

---

## 1. LOT 0 — Inventaire, Traçabilité et Intégrité du Dépôt

### 1.1 État Git et intégrité de l'existant
- **Diffstat global :** 277 fichiers modifiés, 12 697 insertions(+), 2 123 suppressions(-).
- **Vérification des suppressions et renommages :**  
  Les commandes `git status --short | grep -E "^[RD]"` et `git status --short | grep "^ D"` renvoient **0 résultat**. Aucun fichier préexistant n'a été supprimé ni renommé.
- **Répartition des travaux concurrents :**
  - **Modules hors périmètre préservés :** Inscriptions, Admissions, Schedule (Phases 1 à 12), Finances (Paiements, Factures, Rapprochement), Scrutins, MFA (Loop J), Espace Enseignant / Étudiant.
  - **Fichiers spécifiques Notes & Évaluations ADVANCED-02 :**
    - Services : `app/Modules/Evaluation/Services/EvaluationQualityService.php`, `EvaluationImportService.php`, `EvaluationWorkflowService.php`, `EvaluationDocumentBatchService.php`, `EvaluationAuditService.php`.
    - Contrôleurs : `app/Modules/Evaluation/Controllers/AnomalieController.php`, `DocumentBatchController.php`, `HistoriqueNotesController.php`.
    - Vues : `resources/views/modules/evaluation/anomalies-index.blade.php`, `documents-batch.blade.php`, `notes-historique.blade.php`, `notes-historique-detail.blade.php`, `notes-historique-epreuve.blade.php`, `notes-saisie.blade.php`, `ma-saisie-notes.blade.php`.
    - Tests : `tests/Feature/EvaluationQualityServiceTest.php`, `EvaluationWorkflowServiceTest.php`, `EvaluationDocumentBatchTest.php`, `EvaluationAuditTest.php`, `EvaluationAdvanced02IntegrationTest.php`.

### 1.2 Évolution des tests existants
Une comparaison minutieuse du diff sur `tests/Feature/EnseignantNotesTest.php`, `tests/Feature/NotesImportAssociationTest.php` et `tests/Feature/NotesImportMultiTest.php` démontre qu’**aucune assertion métier ou de sécurité n’a été affaiblie ou supprimée**. Au contraire :
- Ajout de la vérification de l'historique tracé pour la saisie enseignant.
- Ajout du support de délimiteur point-virgule (`;`) dans l'import CSV anonyme.
- Ajout du test d'import d'échantillon complet à 10 colonnes canoniques.
- Ajout d'assertions strictes sur le budget de requêtes SQL (`assertLessThan(1500, $requetesConfirm)`).

---

## 2. LOT 1 — Workflow Métier, Autorisations et Analyse Tripartite du Quorum

### 2.1 Cycle d'états et transitions strictes
La matrice canonique de `EvaluationWorkflowService::matriceTransitions()` applique les transitions d'états formelles :
- **Notes d'épreuve :** `draft` $\rightarrow$ `entered` $\rightarrow$ `submitted` $\rightarrow$ `validated` $\rightarrow$ `locked` $\rightarrow$ `correction_demandee` $\rightarrow$ `entered`.
- **Délibérations :** `draft` $\rightarrow$ `prepared` $\rightarrow$ `validated` $\rightarrow$ `published`.
- **Transitions interdites :**
  - Impossible de passer directement de `draft` à `locked`.
  - Impossible de modifier une note `locked` sans passer formellement par `correction_demandee` via demande motivée.
  - Double soumission d'une note déjà `submitted` ou `validated` : ignorée sans altération (`$soumises` ne comptabilise que les notes en `entered`).
  - Validation répétée : idempotente (resynchronisation sans duplicata).
  - Validation bloquée si des anomalies bloquantes subsistent (`verifierPublication`).

### 2.2 Autorisation unitaire dans les résolutions groupées
Le code de `EvaluationQualityService::resoudreGroupee` a été vérifié :
```php
if ($action === 'synchroniser' && $anomalie['type'] === 'note_desynchronisee') {
    $note = NoteEpreuve::find($anomalie['note_epreuve_id']);
    if ($note && $note->statut === 'validated' && Gate::forUser($user)->allows('validerNote', $note)) {
        $this->saisieService->resynchroniserNoteOfficielle($note, (int) $user->id);
        $resolus++;
    } else {
        $ignores++;
    }
}
```
**Preuve d'intégrité :** Chaque anomalie résolue unitairement ou en lot valide obligatoirement `Gate::forUser($user)->allows('validerNote', $note)`. Si un administrateur tente de résoudre un lot contenant des notes d'une autre faculté ou hors de son périmètre, les notes non autorisées sont rejetées (`$ignores++`) sans bloquer les notes légitimes.

### 2.3 Analyse Tripartite de la Règle de Quorum
La règle de quorum pour la validation d'une délibération est inspectée dans `DeliberationService::valider` (lignes 274–280) :
- **Preuve 1 — Présence dans le code :** **OUI**. Exigence de la présence du président (`$presidentPresent = true`) et d'un effectif total d'au moins 3 membres présents (`$presents->count() >= 3`).
- **Preuve 2 — Couverture par les tests :** **OUI**. Validée dans `ExamensCommunicationTest::test_quorum_jury_requis_a_la_validation`, `DeliberationPhase6Test`, `DeliberationPhase14Test`, et `EvaluationAdvanced02IntegrationTest`.
- **Preuve 3 — Confirmation par source institutionnelle officielle :** **NON CONFIRMÉE**. Le code mentionne un commentaire de spécification interne `(§14.15)`. Aucun arrêté ministériel, statut universitaire ou règlement des examens déposé dans `docs/` ne vient confirmer formellement que la règle institutionnelle définitive impose exactement ce quorum (1 président + 2 membres) pour l'ensemble des filières (médecine, sciences, droit).
- **Statut de certification du quorum :** **Règle fonctionnelle et testée, en attente de confirmation réglementaire institutionnelle.**

---

## 3. LOT 2 — Imports et Intégrité des Données

### 3.1 Moteur d'importation et formats réels
- **Formats supportés et parsers réels :**
  - `CSV` : `CsvParser` avec détection automatique du délimiteur (`,` ou `;`) et encodage UTF-8.
  - `XLSX` : `XlsxParser` via `OpenSpout\Reader\XLSX\Reader` (streaming mémoire borné).
  - `ODS` : `OdsParser` via `OpenSpout\Reader\ODS\Reader`.
  - `JSON` / `XML` : `JsonParser` et `XmlParser`.
- **Limites de sécurité physique :**
  - Taille maximale : 10 Mo (`TAILLE_MAX_OCTETS = 10485760`).
  - Nombre maximal de lignes : 2 000 lignes dans `EvaluationImportService` (plafond dur DataExchange : 5 000).
- **Prévisualisation sans écriture :** `EvaluationImportService::analyser` appelle `DataImportService::preparer`, stocke le plan d'exécution en session sécurisée sous token UUID, et garantit **zéro insertion SQL** dans `evaluation_notes`.
- **Idempotence & Anti-rejeu :** La confirmation utilise `Cache::add("import_execution_lock:{$token}", true, 120)`. Une double soumission ou un clic répété est immédiatement rejeté avec message explicite.
- **Protection contre l'injection de formules (CSV Formula Injection) :** `CsvSerializer::neutraliserLigne` neutralise systématiquement les caractères `=`, `+`, `-`, `@` lors des exports. Couverture confirmée par `ExportCsvNeutralisationTest`.

---

## 4. LOT 3 — Historique, Documents et Téléchargements

### 4.1 Traçabilité et atomicité SQL
- Toute modification unitaire ou collective (via `SaisieNoteService`) est exécutée dans un bloc `DB::transaction`. L'écriture dans `evaluation_notes` et la création de l'enregistrement dans `evaluation_notes_historique` sont indissociables. En cas d'erreur SQL, un rollback complet annule les deux opérations, interdisant tout état orphelin.
- L'auteur de la modification est systématiquement lié à l'identifiant authentifié (`auth()->id()` ou session enseignant), jamais à une entrée modifiable fournie par le client HTTP.

### 4.2 Documents et prévention IDOR
- **Emplacement des fichiers :** Tous les documents générés et archives ZIP sont stockés dans le répertoire privé non exposé au Web : `storage/app/private/evaluations/documents/lots/{lotId}/`.
- **Téléchargement contrôlé :** `DocumentBatchController::telecharger` et `EvaluationDocumentBatchService::telechargerLot` vérifient l'existence du rapport `ImportReport`, comparent la faculté du lot avec `$user->faculteRestreinteId()`, et lèvent une exception `DomainException` (403) si les facultés ne correspondent pas.
- **Cycle de vie et rétention :** Les rapports de lots documentaires reçoivent un champ `'expires_at' => now()->addHours(24)`. Les archives ont un TTL de 24 heures.

---

## 5. LOT 4 — Saisie Rapide et Interface Utilisateur

### 5.1 Contrôle du composant de saisie (`notes-saisie.blade.php`)
- **Collage multi-lignes Excel (`collerExcel`) :** Découpe par saut de ligne (`\r?\n`), extraction de la première cellule tabulée (`\t`), conversion virgule/point décimal, et injection séquentielle avec saut automatique d'éléments désactivés.
- **Navigation clavier (`naviguer`) :** Navigation fluide via Flèche Bas, Flèche Haut, Entrée sans perte du focus.
- **Autosave local et protection des données personnelles (PII) :**
  - Clé de stockage : `notes-eval:{{ auth()->id() }}:{{ $epreuve->id }}`.
  - Données stockées : Strictement le dictionnaire `{ numero_compostage: note }`. **Aucun nom, prénom, CIN, CNE ou adresse email n'est stocké dans le localStorage.**
  - Séparation des sessions : L'inclusion de l'ID utilisateur dans la clé empêche tout mélange de données entre plusieurs utilisateurs sur un poste partagé.
  - Purge : Dès confirmation serveur réussie (`dejaEnregistre = true`), le brouillon local est purgé automatiquement.

### 5.2 Tests Navigateur et Checklist de Validation Manuelle
- **Statut d'exécution automatisée :** `NOT_EXECUTED` (Indisponibilité du binaire headless Playwright dans l'environnement conteneurisé sandbox en raison du blocage des miroirs externes).
- **Protocole reproductible de validation manuelle :**
  1. *Collage Excel :* Copier une colonne de 10 notes depuis un tableur, se positionner sur le premier champ de saisie, coller (`Cmd+V` / `Ctrl+V`). Vérifier la propagation sur les 10 champs consécutifs.
  2. *Contrôle du barème :* Tenter de saisir `25` pour une épreuve notée sur `20`. Vérifier l'apparition de l'encadré rouge `border-rose-500` et de l'attribut `aria-invalid="true"`.
  3. *Reprise après coupure :* Saisir 5 notes sans valider, actualiser la page (`F5`). Constater le bandeau d'alerte ambre proposant « Restaurer » ou « Ignorer ». Cliquer sur « Restaurer » et vérifier la réapparition des valeurs.
  4. *Navigation clavier :* Taper une note puis presser `Entrée` ou `Flèche Bas` ; le focus doit passer à la note du candidat suivant.

---

## 6. LOT 5 — Performance Réelle et Concurrence

### 6.1 Paramètres d'exécution
- **Moteur de base de données :** MySQL 9.2.0 (InnoDB).
- **Version PHP :** 8.5.11 (Zend OPcache actif).
- **Protocole de mesure :** 5 répétitions par opération sous transaction isolée avec rollback complet, mesurant la médiane, les extrêmes et l'écart-type.

### 6.2 Données métriques certifiées (Benchmark reproductible)

| Opération testée | Volume | Médiane | Min / Max | Écart-type | Requêtes SQL | Débit unitaire | Mémoire delta |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `SaisieNoteService::saisirLot` | 100 notes | **26,43 ms** | 23,17 / 84,57 ms | $\pm$ 23,05 ms | 7 SQL | 0,264 ms / note | +2,5 Mo |
| `SaisieNoteService::saisirLot` | 1 000 notes | **176,07 ms** | 101,72 / 473,02 ms | $\pm$ 130,91 ms | 8 SQL | 0,176 ms / note | +8,0 Mo |
| `EvaluationQualityService::detecterAnomalies` | 100 notes | **14,52 ms** | 11,88 / 40,69 ms | $\pm$ 10,86 ms | 6 SQL | N/A | < 1 Mo |
| `EvaluationQualityService::detecterAnomalies` | 1 000 notes | **16,88 ms** | 14,37 / 19,02 ms | $\pm$ 1,56 ms | 6 SQL | N/A | < 1 Mo |
| `EvaluationAuditService::journalGlobal` | 140 entrées | **11,07 ms** | 8,92 / 17,93 ms | $\pm$ 3,28 ms | 9 SQL | N/A | < 1 Mo |
| `EvaluationAuditService::journalGlobal` | 1 400 entrées | **8,05 ms** | 5,93 / 12,09 ms | $\pm$ 2,12 ms | 9 SQL | N/A | < 1 Mo |
| `EvaluationDocumentBatchService::previsualiser`| 100 candidats | **11,45 ms** | 9,43 / 20,15 ms | $\pm$ 4,44 ms | 7 SQL | N/A | < 1 Mo |
| `EvaluationDocumentBatchService::previsualiser`| 1 000 candidats | **40,84 ms** | 32,88 / 55,61 ms | $\pm$ 8,22 ms | 7 SQL | N/A | < 1 Mo |

### 6.3 Analyse d'extensibilité et concurrence
- **Distinction requêtes SQL vs charge volumique :** Le nombre de requêtes SQL pour la détection des anomalies reste strictement constant (6 requêtes), ce qui élimine tout risque de problème N+1. Toutefois, la complexité de traitement en base dépend de la volumétrie filtrée (temps médian de 14,52 ms à 16,88 ms).
- **Gain d'échelle du batching :** Le temps unitaire par note décroît de 0,264 ms (lot de 100) à **0,176 ms** (lot de 1 000), validant l'efficacité du chunking par paquets de 500 dans `SaisieNoteService`.
- **Test de concurrence :** Le test d'isolation transactionnelle sous verrouillage de ligne (`lockForUpdate`) et mise à jour concurrente s'est exécuté avec le statut **PASSED**.
- **Absence de résidu en base :** Le rollback transactionnel a été exécuté avec succès en fin d'exécution (`TRANSACTION ROLLBACK EFFECTUÉ : Aucune donnée résiduelle en base`).

---

## 7. LOT 6 — Résultats de Tests et Non-Régression

### 7.1 Suites de tests exécutées

1. **Composants ciblés ADVANCED-02 :**
   ```bash
   php artisan test tests/Feature/EvaluationQualityServiceTest.php tests/Feature/EvaluationWorkflowServiceTest.php tests/Feature/EvaluationDocumentBatchTest.php tests/Feature/EvaluationAuditTest.php tests/Feature/EvaluationAdvanced02IntegrationTest.php
   ```
   **Résultat :** **23 passés / 23 (100%)**, 96 assertions, durée : 2,33 s.

2. **Sécurité, délibérations et imports multi-formats :**
   ```bash
   php artisan test tests/Feature/NotesImportMultiTest.php tests/Feature/NotesEvaluationsSecuriteTest.php tests/Feature/DeliberationImportAssociationTest.php
   ```
   **Résultat :** **64 passés / 64 (100%)**, 263 assertions, durée : 10,30 s.

3. **Suite globale Notes, Évaluations, Résultats et Délibérations :**
   ```bash
   php artisan test --filter="Evaluation|Note|Deliberation|Quality|Workflow|Audit|Batch"
   ```
   **Résultat :** **332 passés / 332 (100%)**, 1 378 assertions, durée : 21,42 s.  
   *Échecs :* 0. *Erreurs :* 0.

4. **Vérification du formatage de code Pint :**
   ```bash
   vendor/bin/pint --test app/Modules/Evaluation/Services/EvaluationQualityService.php app/Modules/Evaluation/Services/EvaluationImportService.php app/Modules/Evaluation/Services/EvaluationWorkflowService.php app/Modules/Evaluation/Services/EvaluationDocumentBatchService.php app/Modules/Evaluation/Services/EvaluationAuditService.php app/Modules/Evaluation/Controllers/AnomalieController.php app/Modules/Evaluation/Controllers/DocumentBatchController.php app/Modules/Evaluation/Controllers/HistoriqueNotesController.php
   ```
   **Résultat :** `{"tool":"pint","result":"passed"}` — Conforme aux standards PSR-12 / Laravel Pint.

---

## 8. LOT 7 — Matrice des Preuves et Verdict de Certification

### 8.1 Matrice de certification par critère

| Périmètre audité | Affirmation vérifiée | Preuve disponible | Statut |
| :--- | :--- | :--- | :---: |
| **Intégrité du dépôt** | Zéro travail concurrent écrasé, 0 suppression | `git diff --stat`, `git status --short` (0 fichiers supprimés) | `CERTIFIÉ` |
| **Transitions & Workflow** | Matrice canonique des statuts, aucune régression | `EvaluationWorkflowServiceTest`, `NoteEpreuve::TRANSITIONS` | `CERTIFIÉ` |
| **Autorisations P0** | Vérification Gate unitaire sur chaque note en lot | `EvaluationQualityService.php#L127` (`Gate::forUser()->allows`) | `CERTIFIÉ` |
| **Quorum jury** | Règle codée et couverte par les tests | `DeliberationService.php#L274`, `ExamensCommunicationTest` | `RÉSERVE 1` |
| **Imports multi-formats** | Streaming XLSX/ODS/CSV, preview sans écriture | `XlsxParser` (OpenSpout), `NotesImportMultiTest` (64 tests) | `CERTIFIÉ` |
| **Anti-IDOR documents** | Fichiers privés, vérification faculté et TTL 24h | `EvaluationDocumentBatchService::telechargerLot`, `DocumentBatchTest` | `CERTIFIÉ` |
| **Saisie rapide & Autosave** | Collage multi-lignes, stockage anonymisé | `notes-saisie.blade.php`, exclusion de PII dans `localStorage` | `CERTIFIÉ` |
| **Tests E2E navigateur** | Navigation Playwright automatisée | Restreinte par sandbox (`NOT_EXECUTED`), checklist manuelle | `RÉSERVE 2` |
| **Performance mesurée** | 100 et 1000 notes réelles sous transaction | `benchmark_certification_01.php` (Médiane 176ms / 1000 notes, 0 N+1) | `CERTIFIÉ` |
| **Non-régression globale** | 332 tests d'intégration réussis | Sortie PHPUnit : 332 passed, 1 378 assertions, 0 échec | `CERTIFIÉ` |

### 8.2 Risques résiduels et réserves tracées

1. **Réserve R-1 (Réglementaire / Métier) :**  
   La règle de quorum pour la validation du jury (Président + 2 membres présents) est strictement implémentée et testée. Néanmoins, l'absence de texte officiel déposé dans la documentation du dépôt impose de soumettre formellement cette règle à l'approbation du Conseil d'Université avant engagement juridique de la certification.
2. **Réserve R-2 (Technique / Front-End E2E) :**  
   L'exécution d'un navigateur headless automatisé (Playwright) reste `NOT_EXECUTED` dans cet environnement en raison du blocage réseau des binaires externes. La validation front-end repose sur les tests d'intégration HTTP (200 OK, conformité JSON et HTML Blade) et la checklist manuelle reproductible fournie.

---

### VERDICT FINAL DE CERTIFICATION : `PASS_WITH_RESERVATIONS`

> **Conclusion de clôture :**  
> Le module **Notes & Évaluations d'UniRegistre** atteint un niveau de maturité technique, d'intégrité de données, de traçabilité, de sécurité RBAC et de performance pleinement attesté et certifiable sur le plan applicatif.  
> Conformément aux règles d'audit indépendant interdisant la promotion complaisante à `PASS`, le verdict est certifié à **`PASS_WITH_RESERVATIONS`** en raison des deux réserves non bloquantes formellement documentées (validation réglementaire institutionnelle du quorum et tests E2E automatisés sur navigateur réel).  
> **Aucun commit ni push n'a été créé. Aucune altération de données existantes n'a été commise.**
