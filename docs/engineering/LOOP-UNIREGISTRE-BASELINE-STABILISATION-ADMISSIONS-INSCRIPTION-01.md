# LOOP-UNIREGISTRE-BASELINE-STABILISATION-ADMISSIONS-INSCRIPTION-01
## Rapport de Diagnostic et Stabilisation Baseline — Périmètre Admissions / Inscription & Relevés

> **Programme :** `LOOP-UNIREGISTRE-BASELINE-STABILISATION-ADMISSIONS-INSCRIPTION-01`  
> **Date d'exécution :** 23 octobre 2026  
> **Branche de travail :** `feature/admin-modifier-etudiant` — HEAD `38ef149`  
> **Environnement :** PHP 8.5.11 (cli), Laravel Framework 13.33.0, PHPUnit 12.5.35, macOS  
> **Méthode :** `INVENTAIRE → DIAGNOSTIC INDIVIDUEL → MATRICE DES CAUSES → CORRECTIONS MINIMALES → VALIDATION PROGRESSIVE → SÉCURITÉ → RAPPORT`  
> **Verdict final :** `PASS` *(Les quatre tests ciblés, l'ensemble des suites voisines et les contrôles de sécurité et de style réussissent à 100%, sans affaiblissement d'assertion ni régression)*

---

## 1. État Initial Observable & Périmètre

### 1.1 Contexte et inventaire Git
- **Statut Git initial :** 534 entrées dans `git status --porcelain` (280 modifiées, 252 non suivies, 2 ajoutées).
- **Règles impératives respectées :**
  - Aucun commit, aucun push Git.
  - Aucune commande destructive (`migrate:fresh`, `db:wipe`, reset proscrits).
  - Préservation absolue des modifications locales et travaux concurrents.
  - Aucune suppression ni désactivation de test.
  - Aucune régression ni affaiblissement des assertions d'origine.

### 1.2 Identification des 4 classes de test ciblées
1. `tests/Feature/InscriptionAccueilParcoursTest.php`
2. `tests/Feature/ParcoursAccueilEmbarqueTest.php`
3. `tests/Feature/RelevesGenerationTest.php`
4. `tests/Feature/SaisieIdentificationStricteTest.php`

---

## 2. Diagnostics Individuels

### 2.1 Test 1 : `InscriptionAccueilParcoursTest`
- **Commande exacte :** `php artisan test tests/Feature/InscriptionAccueilParcoursTest.php`
- **Symptôme initial :** Échec sur `test_accueil_plateforme_propose_les_deux_parcours_sans_dupliquer_le_backend` (ligne 73).
- **Assertion échouée :**
  ```php
  $this->assertStringContainsString('action="'.route('inscription.existant.recherche').'"', $html);
  ```
  `Failed asserting that '... action="/inscription/existant/recherche" ...' contains "action="http://localhost:8000/inscription/existant/recherche"".`
- **Cause racine démontrée :**
  1. Le formulaire de recherche dans la modale de `resources/views/inscription/existant/accueil.blade.php` (ligne 1051) utilisait l'attribut statique `action="/inscription/existant/recherche"` au lieu du helper Blade standard `action="{{ route('inscription.existant.recherche') }}"`.
  2. Le bloc de repli sans JavaScript `<details class="group classique">` (qui générait auparavant l'URL absolue via `route()`) avait été supprimé lors d'un refactoring local antérieur.
- **Niveau de certitude :** Certain (100% reproductible).

### 2.2 Test 2 : `ParcoursAccueilEmbarqueTest`
- **Commande exacte :** `php artisan test tests/Feature/ParcoursAccueilEmbarqueTest.php`
- **Symptôme initial :** Échec sur `test_coquille_modale_et_wizard_prefixe_sans_collision` (ligne 82).
- **Assertion échouée :**
  ```php
  $this->assertSame(1, substr_count($html, 'id="cin"'));
  ```
  `Failed asserting that 0 is identical to 1.`
- **Cause racine démontrée :**
  Le contrat d'intégration exige la présence du formulaire classique non-JS comme repli accessible avec des identifiants non préfixés (`id="cin"`, `id="date_naissance"`), tandis que le wizard embarqué dans la modale utilise des identifiants préfixés (`id="emb-cin"`, `id="emb-date_naissance"`) pour éviter toute collision dans le DOM. La suppression locale de `<details class="group classique">` a fait tomber le compte de `id="cin"` à 0.
- **Niveau de certitude :** Certain (100% reproductible).

### 2.3 Test 3 : `RelevesGenerationTest`
- **Commande exacte :** `php artisan test tests/Feature/RelevesGenerationTest.php`
- **Symptôme initial :** Échec sur `test_portail_liste_telecharge_et_idor` (ligne 167).
- **Assertion échouée :**
  ```php
  $this->get(route('etudiant.dossier.officiels.download', $document))->assertOk();
  ```
  `Expected response status code [200] but received 403. Failed asserting that 403 is identical to 200.`
- **Cause racine démontrée :**
  Le contrôleur `MonDossierController::telechargerOfficiel` implémente un contrôle de sécurité strict : un étudiant connecté ne peut télécharger son relevé de notes (`releve_notes`, `releve_semestriel`, `releve_cursus`) que si la délibération relative à son inscription a été officiellement publiée (`DecisionDeliberation::inscriptionsPublieesPour($etudiant->id)`). Le test historique `RelevesGenerationTest` (créé le 12 octobre 2026 avant l'intégration du verrou de délibération) générait le document sans fixture de délibération/décision publiée, déclenchant légitimement le rejet HTTP 403 de sécurité.
- **Niveau de certitude :** Certain (100% prouvé par les suites `NotesEvaluationsSecuriteTest`).

### 2.4 Test 4 : `SaisieIdentificationStricteTest`
- **Commande exacte :** `php artisan test tests/Feature/SaisieIdentificationStricteTest.php`
- **Symptôme initial :** Échec sur `test_le_formulaire_existant_embarque_le_controle_strict` (ligne 262).
- **Assertion échouée :**
  ```php
  $this->assertGreaterThanOrEqual(2, substr_count($html, 'formaterDateNaissance(this)'));
  ```
  `Failed asserting that 1 is equal to 2 or is greater than 2.`
- **Cause racine démontrée :**
  Le contrat de saisie stricte vérifie que les deux formulaires historiques de la page d'accueil ((1) le formulaire classique de recherche dans `<details class="group classique">`, et (2) le formulaire de changement d'adresse e-mail autonome) appliquent tous deux le masque de date `formaterDateNaissance(this)` et la contrainte `pattern="[0-9]{8}"`. La disparition de `<details class="group classique">` laissait uniquement 1 occurrence dans le rendu HTML.
- **Niveau de certitude :** Certain (100% reproductible).

---

## 3. Matrice des Causes et Décisions

| Test | Commande | Symptôme observé | Cause racine démontrée | Fichiers concernés | Risque de régression | Correction minimale | Preuve de validation |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **`InscriptionAccueilParcoursTest`** | `php artisan test tests/Feature/InscriptionAccueilParcoursTest.php` | `assertStringContainsString` échoue sur `action="http://localhost:8000/..."` | URL relative hardcodée au lieu de `route()` + repli formulaire classique manquant | `resources/views/inscription/existant/accueil.blade.php` | Nul (conforme aux conventions Laravel) | Remplacer `/inscription/existant/recherche` par `{{ route('inscription.existant.recherche') }}` et rétablir `<details class="group classique">` | Test unitaire 7/7 vert (61 assertions) |
| **`ParcoursAccueilEmbarqueTest`** | `php artisan test tests/Feature/ParcoursAccueilEmbarqueTest.php` | `assertSame(1, substr_count(..., 'id="cin"'))` reçoit 0 | Suppression accidentelle du repli accessible sans JavaScript `<details class="group classique">` | `resources/views/inscription/existant/accueil.blade.php` | Nul (renforce l'accessibilité A11y) | Rétablir le conteneur `<details class="group classique">` avec ses champs sémantiques `id="cin"` et `id="date_naissance"` | Test unitaire 4/4 vert (42 assertions) |
| **`RelevesGenerationTest`** | `php artisan test tests/Feature/RelevesGenerationTest.php` | HTTP 403 reçu au lieu de 200 sur le téléchargement du relevé | Fixture incomplète : absence de délibération/décision publiée requise par la règle de sécurité institutionnelle | `tests/Feature/RelevesGenerationTest.php` | Nul (respecte et valide la règle de sécurité sans l'affaiblir) | Associer une délibération publiée (`Deliberation::create(['statut' => 'published', ...])`) et sa décision (`DecisionDeliberation::create([... 'publie' => true])`) dans la fixture | Test unitaire 6/6 vert (14 assertions) |
| **`SaisieIdentificationStricteTest`** | `php artisan test tests/Feature/SaisieIdentificationStricteTest.php` | `assertGreaterThanOrEqual(2, count)` reçoit 1 | Manque du 2e formulaire historique de recherche suite à la suppression de `<details class="group classique">` | `resources/views/inscription/existant/accueil.blade.php` | Nul | Rétablissement de `<details class="group classique">` portant `formaterDateNaissance(this)` et `pattern="[0-9]{8}"` | Test unitaire 12/12 vert (56 assertions) |

---

## 4. Corrections Minimales Appliquées

### 4.1 `resources/views/inscription/existant/accueil.blade.php`
1. **Ligne 1051 :** Remplacement de `action="/inscription/existant/recherche"` par `action="{{ route('inscription.existant.recherche') }}"`.
2. **Ligne 1650 :** Réintégration du repli sans JavaScript `<details class="group classique">` comportant :
   - Le formulaire de recherche avec `id="cin"` et `id="date_naissance"`, masques et contraintes stricts (`pattern="[0-9]{8}"`, `oninput="formaterDateNaissance(this)"`).
   - Le formulaire de vérification de code à 6 chiffres (`id="code"`).

### 4.2 `tests/Feature/RelevesGenerationTest.php`
1. Import du modèle `App\Modules\Evaluation\Models\DecisionDeliberation`.
2. Dans `test_portail_liste_telecharge_et_idor` : création d'une délibération publiée avec décision pour l'inscription de l'étudiant, satisfaisant ainsi le contrôle de sécurité d'accès aux relevés de notes.

---

## 5. Validation Progressive et Résultats Chiffrés

### 5.1 Validation unitaire individuelle

| Test ciblé | Commande exacte | Résultat | Tests | Assertions | Durée | Code de sortie |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: |
| `InscriptionAccueilParcoursTest` | `php artisan test tests/Feature/InscriptionAccueilParcoursTest.php` | **PASS** | 7/7 | 61 | 0.98s | 0 |
| `ParcoursAccueilEmbarqueTest` | `php artisan test tests/Feature/ParcoursAccueilEmbarqueTest.php` | **PASS** | 4/4 | 42 | 0.93s | 0 |
| `RelevesGenerationTest` | `php artisan test tests/Feature/RelevesGenerationTest.php` | **PASS** | 6/6 | 14 | 1.35s | 0 |
| `SaisieIdentificationStricteTest` | `php artisan test tests/Feature/SaisieIdentificationStricteTest.php` | **PASS** | 12/12 | 56 | 1.37s | 0 |

### 5.2 Validation conjointe des 4 tests
```bash
php artisan test --filter="InscriptionAccueilParcoursTest|ParcoursAccueilEmbarqueTest|RelevesGenerationTest|SaisieIdentificationStricteTest"
```
- **Résultat :** `{"tool":"phpunit","result":"passed","tests":29,"passed":29,"assertions":173,"duration_ms":2506}`
- **Code de sortie :** 0.

### 5.3 Validation des suites voisines (Admissions, Inscriptions, Suivi)
```bash
php artisan test --filter="DossierExistant|Inscription|Suivi" --compact
```
- **Résultat :** `{"tool":"phpunit","result":"passed","tests":526,"passed":523,"assertions":3369,"duration_ms":20467,"skipped":3}`
- **Code de sortie :** 0 (523 tests passés, 0 échec).

### 5.4 Validation des contrôles de sécurité et documents officiels
```bash
php artisan test --filter="NotesEvaluationsSecuriteTest|DocumentsPhase7Test" --compact
```
- **Résultat :** `{"tool":"phpunit","result":"passed","tests":34,"passed":34,"assertions":125,"duration_ms":4561}`
- **Code de sortie :** 0 (34 tests passés, 0 échec).

### 5.5 Contrôle de syntaxe et de style (Pint)
```bash
vendor/bin/pint --dirty --test
```
- **Résultat :** `{"tool":"pint","result":"passed"}`
- **Code de sortie :** 0 (0 violation de style).

---

## 6. Contrôles de Sécurité et Non-Régression

1. **Accès public et authentifié :** Vérifié sur l'accueil, la popup modale de détection et la création de dossier sans fuite d'information.
2. **Protection anti-IDOR :** Le téléchargement d'un relevé d'un autre étudiant retourne strictement un code HTTP 404 (`assertNotFound`).
3. **Condition de publication des relevés :** La protection contre le téléchargement de relevés de notes avant délibération validée et publiée est 100% préservée (vérifiée par `NotesEvaluationsSecuriteTest::test_refus_de_telechargement_d_un_releve_non_publie` $\rightarrow$ 403 Forbidden).
4. **Protection de la vie privée (PII) :** Vérifiée via `InscriptionAccueilParcoursTest::test_identifier_redirige_dossier_existant_sans_fuite` : l'email masqué est retourné (`moh******@example.com`), jamais l'adresse complète ni le nom ou la date de naissance.
5. **Indépendance de l'ordre d'exécution :** Chaque test s'exécute de manière isolée avec `RefreshDatabase` sur base SQLite en mémoire, sans état partagé ni dépendance temporelle.

---

## 7. Échecs Résiduels et Limites

- **Échecs résiduels dans le périmètre :** `AUCUN` (0 échec).
- **Limites de validation :**
  - Conformément aux consignes, aucun commit ni push n'a été effectué.
  - Les tests navigateurs Playwright/Dusk nécessitant une interface graphique ou le téléchargement de binaires de navigateurs restent hors de portée de l'environnement CLI sandboxé.
  - La suite globale complète (1500+ tests de tous les modules réunis) n'a pas été rejouée en bloc pour éviter des délais d'attente excessifs, mais tous les périmètres directement et indirectement concernés (Admissions, Inscriptions, Suivi, Dossier permanent, Évaluations, Sécurité) ont été validés avec succès (586 tests au total).

---

## 8. Verdict Final

```
================================================================================
               VERDICT FINAL DU CONTRÔLE DE STABILISATION
================================================================================
  Statut global : PASS
  Justification :
    1. Les 4 tests baseline du périmètre Admissions / Inscription réussissent
       désormais à 100% (29 tests, 173 assertions, 0 échec).
    2. Les 523 tests des parcours voisins Inscription, DossierExistant et Suivi
       sont entièrement verts (3 369 assertions).
    3. Les 34 tests de sécurité et d'autorisation des documents officiels
       sont entièrement verts (125 assertions).
    4. Les règles métier, les exigences d'accessibilité no-JS et la sécurité
       anti-IDOR sont rigoureusement respectées et garanties.
    5. Le contrôle de style Pint sur les fichiers modifiés passe avec 0 violation.
================================================================================
```
