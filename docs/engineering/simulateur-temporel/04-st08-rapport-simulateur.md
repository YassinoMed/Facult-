# LOOP ST-08 — Rapport d'implémentation du simulateur temporel multi-domaines

**Projet :** UniRegistre — Laravel  
**Base technique :** ST-01 à ST-06  
**Pilote métier :** Effectifs et cohortes LMD  
**Date :** 2026-10-25  
**Verdict global ST-08 : `PASS_WITH_RESERVATIONS`** (44/44 tests exécutés avec succès, 244 assertions, Pint PASS, zéro régression, zéro écriture métier).

---

## 1. Préservation obligatoire du dépôt et coordination

- **État git contrôlé :** HEAD `38ef149` (branche `feature/admin-modifier-etudiant`).
- **Écritures concurrentes identifiées :** Travail GouvernanceConformite / Lot 19 préservé dans son intégralité. Aucun fichier concurrent écrasé, aucun commit, push, pull, reset ou stash effectué.
- **Périmètre des modifications :** Strictement restreint aux composants du simulateur temporel (`app/Modules/SimulationTemporelle/`, `resources/views/modules/simulation-temporel/`, `tests/Feature/SimulationTemporelleTest.php`).
- **Sécurité et intégrité :** Aucune modification de schéma destructif, aucune suppression de données.

---

## 2. Déroulement par lots et comptes rendus obligatoires

### Lot A — CORR-1 et tests de traçabilité

- **Périmètre réalisé :**
  - Correction de l'assignation de l'auteur (`cree_par`) : l'auteur initial est injecté dès la création atomique de l'exécution dans `ServiceSimulationTemporelle::executer($scenario, $anneeSourceId, $graine, $nbVariantes, ?int $auteurId = null)`.
  - Dans `SimulationTemporelleController::executer`, l'identifiant de l'utilisateur connecté est transmis au service.
  - Lorsqu'une exécution existante est retournée par la voie idempotente (`hash_entrees` + `graine` identiques), l'exécution d'origine est restituée sans aucune modification de son champ `cree_par`, de son `hash_resultat` ou de ses snapshots.
  - Test de non-régression renforcé `test_correction_cree_par_idempotent` : Utilisateur A crée une exécution, Utilisateur B soumet la même charge idempotente, l'exécution est retournée (même ID), l'auteur d'origine User A est conservé sans être écrasé par User B.
- **Fichiers modifiés :**
  - [`app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php)
  - [`app/Modules/SimulationTemporelle/Http/Controllers/SimulationTemporelleController.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/SimulationTemporelle/Http/Controllers/SimulationTemporelleController.php)
  - [`tests/Feature/SimulationTemporelleTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/SimulationTemporelleTest.php)
- **Tests réellement exécutés et sorties :**
  - `php artisan test --filter="test_correction_cree_par_idempotent"`
  - Sortie : `{"tool":"phpunit","result":"passed","tests":1,"passed":1,"assertions":12,"duration_ms":946}`
- **Preuve de non-écriture métier :** Seule la table `executions_temporelles` est écrite lors de la création d'une nouvelle simulation. Aucune table métier (`inscriptions_academiques`, `etudiants`, etc.) n'est impactée.
- **Verdict du Lot A :** `PASS`

---

### Lot B — Interface de configuration et filtres dynamiques Niveau → Groupe

- **Périmètre réalisé :**
  - Interface utilisateur dynamique développée en Blade + Alpine.js conforme au Design System UniRegistre (`resources/views/modules/simulation-temporel/index.blade.php`).
  - Bandeau d'avertissement permanent et explicite : **« Simulation uniquement — aucune décision réelle n'est appliquée. Aucune donnée d'inscription ou d'affectation n'est modifiée. »**
  - Filtre dynamique hiérarchique :
    1. Sélection facultaire (filtrée selon les droits de l'utilisateur via `FacultePerimetre`).
    2. Cascade vers les niveaux LMD (`options/niveaux?faculte_id=...`), avec gestion des états vides et de chargement.
    3. Choix d'un niveau actualisant immédiatement la liste des groupes compatibles (`options/groupes/{niveauId}?faculte_id=...`).
    4. Multi-sélection de groupes avec boutons « Tout » / « Effacer », affichage des capacités, et note d'exclusion explicite des groupes sans rattachement LMD.
    5. Réinitialisation automatique des sélections enfants (groupes) dès que le niveau ou la faculté parent change.
  - Contrôles de saisie conformes aux validations serveur : horizon (1 à 6 ans), delta d'admissions (−50 % à +200 %), nombre de variantes (1 à 20), bouton de génération de graine aléatoire.
  - Formulaire de transition de cohortes avec validation temps réel de la somme des taux (passage + redoublement + abandon ≤ 100 %) et explication de l'hypothèse « cohortes gelées ».
  - Respect strict de la Content Security Policy du projet (suppression des scripts CDN externes incompatibles, utilisation d'actifs locaux).
- **Fichiers modifiés :**
  - [`resources/views/modules/simulation-temporel/index.blade.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/modules/simulation-temporel/index.blade.php)
  - [`app/Modules/SimulationTemporelle/Http/Controllers/SimulationTemporelleController.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/SimulationTemporelle/Http/Controllers/SimulationTemporelleController.php)
- **Tests réellement exécutés et sorties :**
  - `php artisan test --filter="test_ecran_ui_accessible_avec_banniere_simulation_uniquement|test_options_cascade_niveaux_puis_groupes_du_niveau"`
  - Sortie : `{"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":9,"duration_ms":1230}`
- **Preuve de non-écriture métier :** L'action d'affichage et les endpoints d'options sont strictement en lecture seule (`GET`).
- **Verdict du Lot B :** `PASS`

---

### Lot C — Intégration des filtres au périmètre réel de l'adaptateur LMD

- **Périmètre réalisé :**
  - Extension d'`AdaptateurEffectifsCohortes::etatInitial` pour filtrer les inscriptions validées en fonction des groupes sélectionnés :
    - Prise en compte conjointe de `etudiants.groupe_id` ET de la table officielle `affectations_groupe` (`annee_universitaire_id`, `statut = 'active'`).
    - Respect strict du périmètre facultaire et de l'année universitaire active.
    - Refus explicite et documenté si aucune inscription n'est trouvée pour les groupes choisis (jamais de zéro silencieux).
  - Validation serveur hiérarchique hermétique : rejet 422 de groupes d'un autre niveau, groupes inactifs, groupes sans rattachement LMD ou groupes hors périmètre facultaire.
- **Fichiers modifiés :**
  - [`app/Modules/SimulationTemporelle/Adaptateurs/AdaptateurEffectifsCohortes.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/SimulationTemporelle/Adaptateurs/AdaptateurEffectifsCohortes.php)
  - [`tests/Feature/SimulationTemporelleTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/SimulationTemporelleTest.php)
- **Tests réellement exécutés et sorties :**
  - `php artisan test --filter="test_groupes_selectionnes_determinent_les_donnees|test_groupes_du_niveau_selectionne_acceptes|test_groupe_d_un_autre_niveau_ou_inactif_rejete|test_groupe_hors_perimetre_rejete_meme_requete_forgee"`
  - Sortie : `{"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":15,"duration_ms":1890}`
- **Preuve de non-écriture métier :** Les requêtes de l'adaptateur sont des lectures SQL (`SELECT ... COUNT(*)`) sans aucun effet d'écriture.
- **Verdict du Lot C :** `PASS`

---

### Lot D — Résultats comparatifs, avertissements et explications des scores

- **Périmètre réalisé :**
  - Enrichissement du modèle de résultat de `ServiceSimulationTemporelle` : inclusion de la trajectoire pas à pas (`totaux`) pour chaque variante du classement.
  - Cartes KPI synthétiques : effectif initial, effectif de référence, meilleur score, ratio conformes/rejetés.
  - Tableau de comparaison détaillé : rang, variante, delta annuel, effectif projeté, écart calculé vs référence (+/−), score global d'objectif, décomposition explicative des contributions.
  - Isolation visible des variantes non conformes avec affichage explicite des contraintes dures violées (ex: dépassement de plafond).
  - Bloc d'avertissement méthodologique lorsque les taux de transition ne sont pas renseignés (hypothèse « cohortes gelées »).
  - Bloc d'audit et de reproductibilité affichant la graine, la version du moteur, les empreintes SHA-256 (`hash_entrees`, `hash_resultat`) et la durée d'exécution en ms.
- **Fichiers modifiés :**
  - [`app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php)
  - [`resources/views/modules/simulation-temporel/index.blade.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/modules/simulation-temporel/index.blade.php)
  - [`tests/Feature/SimulationTemporelleTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/SimulationTemporelleTest.php)
- **Tests réellement exécutés et sorties :**
  - `php artisan test --filter="test_simulation_persiste_et_retourne_totaux_trajectoire_par_variante|test_contrainte_duré_isole_les_non_conformes|test_taux_absents_avertissement_jamais_zero_silencieux"`
  - Sortie : `{"tool":"phpunit","result":"passed","tests":3,"passed":3,"assertions":9,"duration_ms":1410}`
- **Preuve de non-écriture métier :** L'évaluation et le classement sont purement algorithmiques en mémoire.
- **Verdict du Lot D :** `PASS`

---

### Lot E — Sécurité, tests, performances et non-régression

- **Périmètre réalisé :**
  - Vérification systématique des autorisations : middleware `auth`, `admin`, `secondaire.verrou`, `module:simulation-temporelle`, et permission `can:statistiques.view`.
  - Contrôle anti-IDOR/BOLA : vérification de `peutAccederFaculte` sur chaque action et requête forgée rejetée avec HTTP 403 / 422.
  - Vérification de l'absence totale de PII dans les snapshots persistés (uniquement des compteurs par niveau).
  - Validation de non-écriture dans les tables métier réelles (assertions sur le count invariant des inscriptions).
  - Test de performance : exécution de 20 variantes sur un horizon de 6 ans en moins de 3 secondes.
  - Respect des conventions de style Laravel Pint : 0 violation.
- **Fichiers vérifiés :**
  - Tous les fichiers du module `SimulationTemporelle` et la suite de tests associée.
- **Tests réellement exécutés et sorties :**
  - `vendor/bin/pint --test app/Modules/SimulationTemporelle tests/Feature/SimulationTemporelleTest.php`
    - Sortie : `{"tool":"pint","result":"passed"}`
  - `php artisan test --filter="MoteurTemporelTest|SimulationTemporelleTest|SimulateurScenariosTest"`
    - Sortie : `{"tool":"phpunit","result":"passed","tests":44,"passed":44,"assertions":244,"duration_ms":3275}`
- **Fonctionnalités non exécutées / Réserves :**
  - Tests E2E automatisés via navigateur (Playwright / Cypress) non exécutés (outils non configurés dans l'environnement local).
  - Application de la migration sur environnement MySQL de production différée (actuellement validée et exécutée sur la base de test autorisée).
- **Preuve de non-écriture métier :**
  - Test unitaire dédié `test_aucune_ecriture_metier_et_snapshots_sans_pii` validé (inscriptions initiales count = inscriptions finales count, 0 modification).
- **Verdict du Lot E :** `PASS_WITH_RESERVATIONS` (réserve : validation E2E navigateur différée).

---

## 3. Synthèse des résultats de test

| Suite de tests | Fichier | Tests | Assertions | Résultat |
|---|---|---|---|---|
| Unitaire moteur pur | `tests/Unit/MoteurTemporelTest.php` | 7 | 99 | **PASS** |
| Fonctionnelle & Sécurité ST | `tests/Feature/SimulationTemporelleTest.php` | 21 | 91 | **PASS** |
| Scénarios transversaux | `tests/Feature/SimulateurScenariosTest.php` | 16 | 54 | **PASS** |
| **Total combiné** | **3 suites** | **44** | **244** | **PASS** |

---

## 4. Conclusion

Le simulateur temporel multi-domaines (pilote effectifs et cohortes LMD) est pleinement opérationnel dans le respect des règles d'idempotence, de sécurité de périmètre et de non-écriture métier.
