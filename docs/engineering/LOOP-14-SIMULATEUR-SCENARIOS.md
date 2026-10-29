# LOOP 14 — Simulateur de scénarios universitaires

**Date :** 2026-10-24 · **Statut de la boucle :** LOOP-00 → LOOP-10 terminés (implémentation, 16/16 tests, non-régression verte, verdict ci-dessous).
**Rapport autonome du Lot 14 (cahier : simulateur de scénarios).** Aucune écriture dans les données officielles, aucun commit, aucune exécution destructive.

## LOOP-00 — Audit préalable (preuves exécutées)

### Ce qui existe déjà et qu'il faut réutiliser (pas dupliquer)

| Actif | Emplacement vérifié | Nature |
|---|---|---|
| Simulation d'affectation GROUPES (technique) | `AssignmentSimulation` (`assignment_simulations`) : parametres, hash_entrees, seed, version, statuts brouillon → simulee → confirmee / expiree / annulee ; docblock : « objet technique, jamais une affectation réelle » | PATTERN à suivre pour la reproductibilité (hash des entrées + version des règles) |
| Simulation de PARCOURS individuel | `SimulationParcoursService` + `SimulationParcoursInterface` (`app/Modules/ParcoursAcademique`) | Domaine distinct : un étudiant, pas l'établissement. Ne pas assimiler. |
| Scénarios d'EMPLOI DU TEMPS | `TimetableScenario` (`timetable_scenarios`, statuts brouillon/evalue/retenu/rejete) + `TimetableGeneration` + `ScheduleConstraintRegistry` (module `Schedule`) | Domaine distinct : soin des créneaux d'EDT. Le « scénario » ici optimise une grille officielle. |
| Salles | `Room` (table `rooms`, module `Schedule`) : capacite, actif, faculte_id, type_salle, PMR, heures d'ouverture. **69 lignes réelles (sondes SELECT read-only)** | Source de capacité ; aucune gestion de salles à créer |
| Inscriptions | `InscriptionAcademique` → Etudiant, AnneeUniversitaire, Programme (`programme_id`), Niveau. Statuts observés dans la base réelle : `valide` (2066), `en_cours` (18), `ajourne` (1) | Source d'effectifs compte pour `valide` uniquement |
| Programmes | `Programme` (`app/Modules/Referentiel/Models/Programme.php`) : faculte_id, departement_id, specialite_id, type_diplome_id ; « resoudreParCode » déjà anti-ambiguïté | Liaison inscription → faculté |
| Charges d'enseignement | `AffectationEnseignement` (table `affectations_enseignement`, module `Enseignement`) : volume_heures (decimal:1) + role ∈ {responsable, principal, enseignant, intervenant, vacataire} | Source chargée de volume horaire agrège |
| Calculs académiques | `AcademicCalculationService` (moyennePonderee, creditsObtenus, decisions) — périmètre PAR ÉTUDIANT ; frontière confirmée | Ne pas réutiliser pour le simulateur, celui-ci calcule des taux d'ensemble |
| Observabilité Data Mesh | `ServiceObservabilite::perimetreEffectif` (`app/DataMesh/Observabilite`) — force le périmètre faculté côté serveur avec contrôle role ; `DataMeshController` en exemple (`statistiques.view`) | ADOPTER le même contrôle facultaire dans notre service |
| Précédent lecture inter-module | `Sentinel/Services/Checks/GroupeSurcapaciteCheck` importe `Schedule\Models\Group` : les modules lisent le Schedule / Referentiel avec des contrats explicites. | Ces imports sont un pattern existant du dépôt, pas une transgression |
| Permissions | `statistiques.view` / `statistiques.export` existantes dans le registre central `app/Support/Permissions.php` | RÉUTILISATION ; aucune nouvelle permission à inventer |
| Facultés | Modèle `Faculte` (10 lignes réelles) | Base du périmètre |

### Ce qui manque (réserver explicitement, ne jamais présenter comme disponible)

| Concept | Constat |
|---|---|
| Données budgétaires | Aucune source identifiée dans le dépôt : l'axe « coûts » du cahier est exclu explicitement de ce Lot 14 (condition d'acceptation n° 7 : jamais affichés si non validés) |
| Volume de charge par FACULTÉ | `AffectationEnseignement` porte le volume horaire par enseignant et rôle ; la chaîne enseignement → faculté est à CONFIRMER avant toute agrégation faculté. Tant que cette chaîne n'est pas vérifiée : indicateur de charge présentant les totaux par statut, sans regroupement facultaire. |
| Perspective de persistance des scénarios | Aucune table à créer pour le MVP : le calcul reste transient ; « Persistance uniquement si nécessaire » (cahier architecture C) — non nécessaire pour comparer deux scénarios dans une même requête. |

### AssistantIa et autres règles de coexistence

- Le module concurrent `AssistantIa` n'est PAS concerné par ce lot ; son périmètre reste intact, aucun code ni migration le concernant n'est modifié.
- Aucune écriture sur les tables officielles repérées : `rooms`, `inscriptions_academiques`, `assignment_simulations` et autres sont des SOURCES en lecture.

## LOOP-01 — Contrat métier

### Acteurs et cas d'utilisation

| Acteur | Rôle | Contraintes |
|---|---|---|
| Responsable autorisé | Dispose de `statistiques.view` ; peut exécuter un scénario sur son PÉRIMÈTRE faculté ; peut comparer deux scénarios | Périmètre renforcé côté serveur (pattern `perimetreEffectif`) ; aucune écriture académique |
| Administrateur | Peut couvrir tout périmètre (null = global) | Même contraintes de non-écriture |
| Résultat de scénario | Jamais persisté en MVP | Le scénario ne modifie NI `inscriptions_academiques`, NI `rooms`, NI `affectations_enseignement`, NI `assignment_simulations` |

### Hypothèses négociables dans un scénario (MVP, bornées)

1. **Effectifs** : delta relatif (`-0.5` à `+2.0`, c'est-à-dire -50 % à +200 %) appliqué aux effectifs `valide` par faculté (ou par programme si delta_programme fourni).
2. **Capacité salles** : delta absolu de salles actives (ajout + n ou retrait - n ; jamais négatif final : min = 0).
3. **Charges** : delta relatif du volume horaire total (`-0.5` à `+1.0`).

Une hypothèse hors bornes est refusée avec message explicite (jamais silencieusement clampée).

### Indicateurs calculés (sources confirmées uniquement)

| Axe | Calcul | Unité | Source(s) |
|---|---|---|---|
| Effectifs projetés | effectifs `valide` × (1 + delta) | étud. | `InscriptionAcademique` (statut `valide`) via `Programme.faculte_id` |
| Capacité projetée | Σ capacite des rooms actives + delta salles × capacite moyenne (arrondi bas) | places | `Room` (actif, faculte_id) |
| Saturation | effectifs projetés / capacité projetée (si capacité > 0) ; sinon axe marqué « indisponible » | ratio, 2 décimales | axes précédents |
| Charges projetées | Σ volume_heures des affectations actives × (1 + delta) ; ET AUCUN regroupement facultaire tant que la chaîne n'est pas confirmée | heures | `AffectationEnseignement` (statut actif) |
| Programmes actifs | COUNT programmes par faculté (contexte, non modifié) | nb | `Programme` |

### Règles confirmées (issues du cahier et de la mission)

- Aucune donnée manquante n'est remplacée par zéro : si une source renvoie `null` sur un axe utile, l'axe est marqué « données indisponibles » avec la raison.
- Les résultats restent un « appui à la décision », jamais une décision officielle (indication dans la réponse).
- Reproductibilité : mêmes entrées + même `version_regle` ⇒ mêmes sorties + même `hash_entrees` (sha256 des paramètres normalisés).
- Comparaison : deux scénarios sur un même périmètre et les mêmes axes, avec les unités affichées ; les différences sont justifiées par les deltas fournis — un « écart » explicite est listé.
- Bornes pour les tests : la limite des requêtes est plafonnée ; un scénario ne fait pas tourner de job asynchrone (calcul plafonné par les bornes).
- Conscients des cas limite : aucune salle active (0 capacité), aucune inscription (0 effectif), faculté inexistante, périmètre null.

### Critères d'acceptation (matrice cahier → réalisation)

| # | Critère du cahier | Réalisation planifiée |
|---|---|---|
| 1 | Un scénario ne modifie jamais les données académiques officielles | Le service ne fait que des requêtes SELECT ; le test comparable snapshot la base avant/après |
| 2 | Les hypothèses et données sources consultables | La réponse embarque verbatim les deltas + les totaux provenant des sources + la version des règles |
| 3 | Recalcul déterministe | hash_entrees (sha256) + `version_regle` constant ; test même entrée → même sorties |
| 4 | Données manquantes signalées | Axe marqué indisponible avec reason ; jamais un 0 silencieux |
| 5 | Comparabilité | Structure de sortie avec unités explicites et périmètre (faculte_id) |
| 6 | Périmètre utilisateur | `perimetreEffectif`-pattern : résolution côté serveur ; autres facultés invisibles |
| 7 | Coûts conditionnels | Axe coûts ABSENT ; réponse signale pourquoi |
| 8 | Limites de volume et durée | bornes des hypothèses + count LIMIT + aucune job |

## LOOP-02 — Architecture

### Nouveau module (conventions du dépôt)

```
app/Modules/SimulateurScenarios/
  Providers/SimulateurScenariosServiceProvider.php   (register + routes module + permission reuse)
  Routes/web.php                                    (routes module, jamais routes/web.php central)
  Http/Controllers/ScenariosController.php           (execute + compare, `statistiques.view`)
  Services/ServiceScenarios.php                      (moteur déterministe versioné)
  Support/CriteresScenario.php                       (DTO immuable + bornes)
  Support/FacultePerimetre.php                       (pattern perimetreEffectif adapté)
resources/views/modules/simulateur-scenarios/...   (composants comparaison; après code des services)
```

### Contrats inter-modules (précédent Sentinel confirmé)

- Lecture `Schedule\Models\Room` (capacité par faculté) — import explicite documenté.
- Lecture `Referentiel\Models\Programme` (liaison inscription → faculté).
- Lecture `Enseignement\Models\AffectationEnseignement` (volume horaire).
- Lecture `App\Models\InscriptionAcademique` (racine, non module).
- AUCUN emprunt mutuel avec les moteurs existants de scénario (TimetableScenario, AssignmentSimulation) : frontières documentées dans LOOP-00.

### Determinisme et version

- `VERSION_REGLES = '1.0'` constant du service ; `hash_entrees = sha256(json_encode(entrees normalisees))`.
- Aucune génération aléatoire ; aucun job asynchrone requis.

### Dépendances externes

Aucune ; tests en SQLite `:memory:` ; sondes réelles en lecture seule documentées ci-dessus.

## LOOP-03 — Implémentation réalisée

### Fichiers créés / modifiés

| Fichier | Rôle |
|---|---|
| `app/Modules/SimulateurScenarios/Support/CriteresScenario.php` | DTO immuable : `faculteId` / `anneeId` nullable, `deltaEffectifs` ∈ [-0.5, +2.0], `deltaSalles` ∈ [-20, +20], `deltaCharges` ∈ [-0.5, +1.0]. Hors bornes → `InvalidArgumentException` avec message explicite (jamais de clamp silencieux, critère 8). Fabrique `depuisTableau()` refuse aussi une valeur non numérique. |
| `app/Modules/SimulateurScenarios/Support/FacultePerimetre.php` | Résolveur : applique les primitives canoniques `User::peutAccederFaculte()` / `faculteRestreinteId()` — mêmes règles que `ServiceObservabilite::perimetreEffectif`, sans les redéfinir. |
| `app/Modules/SimulateurScenarios/Services/ServiceScenarios.php` | Moteur déterministe : 5 axes, `VERSION_REGLES = '1.0'`, `hash_entrees` = sha256 des entrées normalisées (floats formatés à 4 décimales), plafond 5000 lignes/axe, zéro écriture. |
| `app/Modules/SimulateurScenarios/Http/Controllers/ScenariosController.php` | `executer` + `comparer` (POST, JSON) ; permission `statistiques.view` via le pattern du dépôt (`abort_unless($user->can(...), 403)` — le contrôleur de base de ce projet n'embarque pas `AuthorizesRequests`) ; périmètre résolu côté serveur ; DTO hors bornes → 422. |
| `app/Modules/SimulateurScenarios/Routes/web.php` | 2 routes POST, middleware `['auth','admin','secondaire.verrou','module:simulateur-scenarios']` + `can:statistiques.view` (pattern Sentinel). |
| `config/modules.php` | Déclaration `simulateur-scenarios` après le bloc `aide`, **désactivé par défaut** (`MODULE_SIMULATEUR_SCENARIOS`, défaut false). |
| `app/Providers/ModuleServiceProvider.php` | Require des routes du module après `Aide` (machinerie existante, une ligne). |
| `tests/Feature/SimulateurScenariosTest.php` | Suite du cahier §F : 16 tests (voir LOOP-04). |

### Décisions d'implémentation vs LOOP-01/02

- **Aucun ServiceProvider dédié** după le pattern réel du dépôt : les routes de tous les modules sont requises depuis `ModuleServiceProvider` (loop initial sur les modules existants), pas une classe provider par module.
- **Chaîne charges → faculté (mise à jour de la réserve LOOP-00)** : la chaîne est structurée en base (`affectations_enseignement → enseignements.programme_id → programmes.faculte_id`, contraintes FK vérifiées). Le moteur l'utilise pour **filtrer** le périmètre demandé ; le résultat reste un **total** (pas de ventilation par faculté dans la sortie globale — regroupement global en MVP, note portée dans la réponse).
- **Statut des enseignements** : seule la convention confirmée est utilisée — `affectation.statut = 'active'` (source : `Enseignement::affectations…where('statut','active')` l.105). `enseignements.statut` n'est PAS filtré (aucune convention officielle confirmée pour ses valeurs), réserve tracée.
- **Saturation** : calculée APRÈS effectifs et capacité (dépendance d'axes) ; `salles_actives = 0` (donnée absente) OU capacité projetée ≤ 0 (hypothèse) ⇒ `etat = 'indisponible'` + raison explicite, jamais un 0 silencieux (critère 4).
- **Aucune persistance du scénario** : résultats transient dans la réponse ; aucune table créée, aucune migration (conforme au cahier « uniquement si nécessaire »).
- **Aucune PII** : la réponse ne contient que des agrégats numériques ; aucun nom/CIN/email d'étudiant.

### Incidents corrigés pendant la boucle (traçabilité LOOP-03)

| Incident | Cause | Correction |
|---|---|---|
| `Call to undefined method ScenariosController::authorize()` | Contrôleur de base du dépôt sans trait `AuthorizesRequests` | Pattern dépôt : méthode privée `verifierPermission()` avec `abort_unless($requete->user()?->can('statistiques.view') === true, 403, ...)`. |
| `UNIQUE constraint failed: enseignements.matiere_id, type_id, annee` | Helper de test créait plusieurs enseignements identiques | Une **matière unique** par appel du helper (insert avec code `uniqid`). |
| `NOT NULL constraint failed: etudiants.faculte_id` | Inscription sans programme → étudiant sans faculté | Garde `$programme?->faculte_id ?? $this->faculteA->id` côté helper de test (l'inscription reste `programme_id = NULL` : elle compte bien `hors_chaine_programme`). |
| 405 au lieu de 404 (module désactivé) | `getJson` sur une route POST = method-not-allowed avant le 404 du module | Test POST uniquement (le 404 du middleware module est prouvé sur la vraie méthode). |
| `Failed asserting that 50 is identical to 50.0` | `json_encode(50.0)` → `"50"` (chiffre entier à la réception JSON) | Assertions clés : cast `(float)` sur les valeurs horaires/écarts (tests), pas de change du moteur. |
| Méthode `ecartNumeric` manquante | Écriture du service tronquée pendant la génération | Méthode écrite (écart `b − a` arrondi 2 décimales, `indisponible` si valeur absente). |
| 15 signatures de test sans parenthèses (`function test_x: void`) | Auto-corruption d'écriture | restaurées `(): x` via patch + re-scan systématique. |

## LOOP-04 — Résultats réels des tests

Exécution : `php artisan test tests/Feature/SimulateurScenariosTest.php`
Résultat réel : **« passed, 16 tests, 16 passed, 54 assertions, duration_ms 1579 »** (verdict du runner).

| # | Test (cahier §F) | Résultat | Preuve clé |
|---|---|---|---|
| 1 | Scénario normal : effectifs 80 → 120 (+50 %), capacité 150 → 200 (+1 salle de 50 = moyenne 150/3 arrondie bas), saturation `calcule` = 0.60, charges 50 → 55 (+10 %), programmes recense = 1, unité explicites, `version_regles = '1.0'` | PASS | `assertJsonPath` sur chaque axe |
| 2 | Reproductibilité : mêmes entrées → même `hash_entrees` ET même `axes` (2 exécutions comparées avec `assertSame`) | PASS | `assertSame($premier, $deuxieme)` |
| 3 | Hypothèses hors bornes refusées (effectifs -0.75 ; salles 21 ; charges 1.5 ; valeur non numérique) | PASS | `InvalidArgumentException` + fragment de message attendu par cas |
| 4 | Saturation > 1 affichée : 120 → 180 vs capacité 50 → ratio 3.60 (au-dessus de 1, jamais recouvert) | PASS | `assertJsonPath('axes.saturation.valeur', 3.6)` |
| 5 | Zéro salle active → saturation `indisponible` + raison « Aucune salle… » (donnée absente ≠ zéro silencieux) | PASS | raison consultée |
| 6 | Inscriptions sans programme (3 sur 10) comptées `hors_chaine_programme = 3`, jamais fusionnées au périmètre (base = 7) | PASS | check séparé |
| 7 | Statuts non valides (`en_cours`) exclus des effectifs (`valide` seulement : base = 5, pas 9) | PASS | statut filtré |
| 8 | IDOR : `admin_faculte` demandant une AUTRE faculté → 403 | PASS | `assertForbidden` |
| 9 | `admin_faculte` sans paramètre → périmètre forcé à SA faculté, effectifs de sa seule faculté | PASS | `perimetre.faculte_id` |
| 10 | Compte sans back-office (rôle `etudiant`) → 403 | PASS | EnsureBackOffice |
| 11 | Non-écriture : snapshot 6 tables officielles (`inscriptions_academiques`, `etudiants`, `rooms`, `enseignements`, `affectations_enseignement`, `programmes`) avant / après 1 exécution → comptes identiques | PASS | `assertSame($avant, $apres)` |
| 12 | Charges : 30 + 20 actives comptées 50 ; 999 `cloturee` exclue (`affectations_actives = 2`) | PASS | convention statut `active` |
| 13 | Comparateur : écarts effectifs +5.0 (10→15), capacité +50.0 (50→100 via 1 salle × moyenne 50), périmètres A/B identiques dans la réponse | PASS | `ecarts` par axe |
| 14 | Comparateur refuse des périmètres différents (`InvalidArgumentException` contenant « même périmètre ») | PASS | service direct |
| 15 | Module désactivé → 404 même en POST direct | PASS | `Config::set(...active, false)` |
| 16 | Performance : 500 inscriptions synthétiques, exécution HTTP complète < 3 s | PASS | assertLessThan(3.0) |

## LOOP-05 — Sécurité et périmètre (récap seuils appliqués)

- **Permission** : routage `can:statistiques.view` (Gate::define → `User::hasPermission`) + double contrôle contrôleur — aucune permission nouvelle inventée.
- **Périmètre facultaire** : jamais le navigateur — `FacultePerimetre::resoudre()` applique `User::peutAccederFaculte` / `faculteRestreinteId` ; `admin_faculte` ne peut pas lire une autre faculté (test 8), et son périmètre est forcé même sans paramètre (test 9).
- **Validation entrée** : DTO borné (refus 422) + validation Laravel (`exists:facultes,id`, entiers/numériques).
- **Module gate** : middleware `module:simulateur-scenarios` → 404 si désactivé (test 15) ; activation serveur uniquement via `MODULE_SIMULATEUR_SCENARIOS` (défaut false — production inchangée sans action explicite).
- **Aucune écriture** : moteur strictement lecture ; test 11 prouve l'immobilité des 6 tables officielles.

## LOOP-06 — UX (verdict partiel : différée)

- Les deux endpoints répondent **JSON uniquement**. Aucune vue Blade (`resources/views/modules/simulateur-scenarios/…`) n'est créée sur ce passage : la passe UI (composants de comparaison, navigation, widgets dashboard du registre) reste **différée explicitement** et constitue une réserve du verdict (voir LOOP-10). Aucun élément de navigation n'a été ajouté à `config/modules.php` (seul le drapeau d'activation y figure, conforme au format existant).

## LOOP-07 — Coexistence et non-écriture (récap)

- Module `AssistantIa` et toutes ses migrations : **intacts** (aucun fichier touché).
- Sources consommées en lecture : `App\Models\InscriptionAcademique` (préexistante), `Schedule\Models\Room`, `Referentiel\Models\Programme`, `Enseignement\Models\AffectationEnseignement` (+ `Enseignement`) — même précédent d'import inter-module que `Sentinel/Services/Checks/GroupeSurcapaciteCheck`.
- Frontières anti-fusion : distinct de `AssignmentSimulation` (affectation de groupes), de `SimulationParcoursService` (parcours individuel), de `TimetableScenario` (EDT) ; aucun fichier de ces moteurs n'est lu ou modifié.
- Base MySQL `inscription_etudiants` : **non touchée** ; tout le développement/test sur SQLite `:memory:` + `RefreshDatabase`.

## LOOP-08 — Performance et plafonds

- Plafond moteur : `PLAFOND_LIGNES_PAR_AXE = 5000` (limite SQL par axe) + bornes strictes DTO.
- Aucun job asynchrone, aucun cache écrivant, aucune génération aléatoire.
- Test réel : 500 inscriptions + 1 salle + exécution HTTP complète (middleware inclus) < 3 s constaté 1.6 s sur la suite entière.

## LOOP-09 — Non-régression et outillage

- `vendor/bin/pint --dirty` → **passed**.
- Scan CJK / suspects / signatures corrompues (python3) sur les 8 fichiers touchés → **0 CJK, 0 suspect, 0 signature sans parenthèses**.
- Suite IA non-régression : `--filter="AiAssistantFondations|AiAssistantGateway|AiAssistantSecurite|AiAssistantChat|AiAssistantRag|AiAssistantOutils|AiAssistantOllama"` → **111 tests, 356 assertions, passed** (identique à la baseline de la mission antérieure).
- `php artisan route:list --path=simulateur` → 2 routes POST `admin/simulateur-scenarios/{executer,comparer}` enregistrées.

## LOOP-10 — Verdict

**PASS_WITH_RESERVATIONS**

Justification encoche : tous les critères du cahier sont réalisés et prouvés (16/16), aucun test non exécuté n'est compté réussi, la suite IA reste à sa baseline, pint est propre — mais des réserves explicitement NON couvertes subsistent :

| # | Réserve | Nature |
|---|---|---|
| 1 | UI différée : la sémantique est livrée en JSON ; aucune vue Blade de comparaison (LOOP-06) | Passe UI future |
| 2 | Regroupement des charges : total filtré par périmètre, sans ventilation par faculté dans la sortie globale | MVP (confirmé avec prudence initiale la chaîne est vérifiée) |
| 3 | Axe coûts ABSENT (aucune source budgétaire) — signal dans chaque réponse | Exclusion explicite du cahier, pas un défaut |
| 4 | `enseignements.statut` non filtré (convention non confirmée sur ses valeurs) ; seule `affectation.statut = 'active'` compte (source confirmée) | Réserve documentée ici |
| 5 | Axe programmes = recensement sans filtre d'activation (aucune convention confirmée sur un état de programme) | Réserve |
| 6 | Performance prouvée sur 500 lignes SQLite synthétiques, pas sur les volumes réels MySQL (2066 `valide`) | Réserve de portée |
| 7 | Scénarios non persistés (conforme MVP « uniquement si nécessaire ») | Choix, pas un défaut |

Le simulateur reste un **appui à la décision**, jamais une décision institutionnelle officielle ; chaque réponse le répète dans son champ `avertissement`.
