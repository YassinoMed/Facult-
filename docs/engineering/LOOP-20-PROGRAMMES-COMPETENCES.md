# LOOP 20 — Gestion avancée des programmes et compétences

> Cahier : relier les programmes et maquettes LMD aux objectifs pédagogiques
> et compétences attendues ; catalogue versionné ; associations
> programme/UE/ECUE avec niveaux attendus ; service de validation de
> cohérence ; historique des modifications de maquettes ; comparaison de
> versions ; rapports de couverture distinguant absence de données et
> absence de couverture ; activation via le workflow prévu ; imports
> détectant les ambiguïtés au lieu de choisir arbitrairement.
> Interdits : inventer des compétences ou une taxonomie de niveaux ;
> détruire ou réinterpréter silencieusement des données académiques
> historiques.
>
> **Date :** 2026-10-25 · **Dernière actualisation :** 2026-10-27 ·
> **Statut de la boucle :** LOOP-00 → LOOP-11 terminés (24/24 tests,
> 137 assertions, non-régression verte, verdict ci-dessous). Aucun commit,
> aucune écriture sur la base MySQL (`APP_ENV=production` : lecture seule),
> aucune opération destructive.
>
> **Cycle de complément du 2026-10-27 (LOOP-11)** : les réserves 1, 4 et 6
> sont traitées, la réserve 5 est mitigée par des snapshots de rapport
> immuables ; les réserves 2, 3, 7 et 8 restent des décisions métier ou des
> contraintes de mission. Détail et preuves en §LOOP-11.

## LOOP-00 — Audit préalable (preuves exécutées)

Audit directeur préalable : `docs/engineering/LOOP-14-20-AUDIT-DIRECTEUR.md`
(2026-10-24) — Lot 20 « prêt à lancer ». Vérifications complétées au démarrage :

### Ce qui existe déjà et qu'il faut réutiliser (pas dupliquer)

| Actif | Emplacement vérifié | Usage Lot 20 |
|---|---|---|
| Référentiel LMD complet | `app/Modules/Referentiel/Models` : Programme, Niveau, SemestreLmd, UniteEnseignement, Domaine, Mention, Specialite, TypeDiplome ; `Matiere` (racine, EST l'ECUE de son UE, `ue_id`) | cibles canoniques des associations |
| Maquettes versionnées | `Maquette` (statuts draft→review→approved→active→archived, `version_precedente_id`, verrouillage dès approbation, une seule active par programme avec verrous SQL) ; `MaquetteUe`, `MaquetteUeEcue` (contexte annuel) | périmètre des rapports de couverture |
| Cycle de vie des maquettes | `MaquetteService` : `nouvelleVersion` (snapshot UE+ECUE), `definirContenu`/`definirEcues` (brouillon uniquement), `transiter` (transitions verrouillées, conversions 1205/1213 en refus rejouables) | critères 2, 3, 6 et 8 DÉJÀ couverts — aucune duplication |
| Comparaison de versions | `MaquetteComparaisonService` (lecture seule : UE/ECUE ajoutées/retirées/modifiées, prérequis, écarts de totaux) | critère « Comparaison de versions » DÉJÀ couvert |
| Historique des maquettes | `MaquetteTransitionnee` (event) + `AuditMaquette` (listener) + refus journalisés | critère « Historique des modifications » DÉJÀ couvert |
| Activation simulée | `MaquetteActivationSimulationService` + `ReferentielActivationSimulationTest` | frontière respectée (rien de touché) |
| Permissions | `referentiel.view`, `referentiel.manage`, `referentiel.import`, `referentiel.export`, `maquettes.approve` (registre central) | RÉUTILISÉES — AUCUNE clé nouvelle |
| Périmètre | `ReferentielPolicy` (view/update/delete/approuver + `faculteDuModele`) ; `User::peutAccederFaculte` / `faculteRestreinteId` | couverture par maquette via `Gate::authorize('view', $maquette)` |
| Module gate | `module:referentiel` (404 si désactivé) + `config/modules.php` | réutilisé tel quel — le lot vit DANS le module existant |
| Unicités confirmées | `unites_enseignement` UNIQUE (semestre, code) — code UE NON globalement unique ; `matieres.code` UNIQUE global ; `programmes` UNIQUE (faculté, code) | fondement du test d'ambiguïté d'import |

### Absences nettes (créées par ce lot, additivement)

| Concept | Constat avant le lot |
|---|---|
| Modèle `Competence` | Aucun (grep : 0 hit réel) — catalogue créé VIDE et SAISI |
| Associations compétence ↔ programme/UE/ECUE | Aucune table |
| Rapport de couverture pédagogique | Aucun |
| Import d'associations par codes avec détection d'ambiguïté | Aucun pour ce périmètre |

### Décision d'architecture (cahier : « extension du référentiel existant »)

Le lot est implémenté DANS `app/Modules/Referentiel` (nouvelles classes +
un bloc de routes ajouté à son `Routes/web.php`), PAS un nouveau module :
même drapeau d'activation, même policy, mêmes conventions. Le fichier de
routes existant reçoit un bloc additif placé AVANT les routes `{entite}`
génériques (aucune route existante modifiée).

## LOOP-01 — Contrat métier

### Acteurs et cas d'utilisation

| Acteur | Clés | Cas d'usage |
|---|---|---|
| `admin_scolarite` (et rôles à `referentiel.manage` + `maquettes.approve`) | view + manage + approve | saisie catalogue, révisions, approbation, associations, import |
| Rôles à `maquettes.approve` SANS `referentiel.manage` | approve | approbation des compétences (autorité) |
| `admin_faculte` | `referentiel.view` seul | lecture du catalogue et des rapports de SA faculté ; toute écriture 403 |
| `consultation` | `referentiel.view` | lecture seule |

### Flux et règles confirmées

1. **Catalogue SAISI** : compétence créée en brouillon (version 1, code
   unique global, portée faculté nullable = établissement). Aucun seed, aucun
   référentiel de compétences inventé, aucune taxonomie de niveaux imposée
   (`niveau_attendu` est une chaîne SAISIE, max 60).
2. **Révisions versionnées** : un brouillon peut être révisé (version
   incrémentée) ; l'APPROBATION FIGE (statut `approuvee`, `approuve_par/le`)
   ; une compétence figée n'est plus modifiable — l'évolution passe par
   archivage + nouvelle compétence (jamais de réinterprétation rétroactive
   des associations existantes).
3. **Approbation par l'AUTORITÉ des maquettes** : `maquettes.approve` — la
   même clé que l'approbation des maquettes (critère 4 : « définies par une
   autorité autorisée »), testée négativement (admin_faculte = 403).
4. **Associations canoniques** : cible ∈ {programme, ue, ecue} vers une
   entité EXISTANTE (lien mort = 422), compétence APPROUVÉE uniquement,
   cohérence de portée (compétence locale F ↔ cible de F ; compétence
   globale → toute cible), idempotence (UNIQUE compétence+cible :
   re-lier retourne l'existant), niveau attendu optionnel saisi.
5. **Rapport de couverture** (lecture seule, par maquette) — les DEUX
   absences DISTINCTES :
   - par UE/ECUE du plan : `renseigne` | `non_renseigne` = **ABSENCE DE
     DONNÉE** (jamais interprétée comme lacune) ;
   - par compétence du périmètre (approuvées : établissement + faculté du
     programme) : `couverte` (associée au programme, à une UE ou à un ECUE
     du plan) | `non_couverte` = **ABSENCE DE COUVERTURE** (lacune réelle) ;
   - totaux + légende explicite embarqués dans chaque réponse.
6. **Import par codes** : chaque ligne est résolue indépendamment ;
   `competence_code` inconnu, compétence non approuvée ou **AMBIGUÏTÉ**
   (code UE/programme matchant plusieurs entités — les candidats sont
   listés avec leurs ids) → rejet motivé ; `cible_contexte` optionnel
   (code faculté pour un programme, code programme pour une UE, code UE
   pour un ECUE) lève l'ambiguïté sans choix arbitraire ; ligne valide →
   création idempotente ; plafond 500 lignes/import.
7. **Aucune écriture rétroactive** : les associations portent sur les
   entités canoniques ; une nouvelle version de maquette ne modifie ni les
   associations ni le rapport d'une version existante (testé).

### Critères d'acceptation (matrice cahier → réalisation)

| # | Critère du cahier | Réalisation | Test |
|---|---|---|---|
| 1 | Identifiants académiques existants cohérents | aucune table/migration existante modifiée ; FK additive uniquement | non-écriture |
| 2 | Modifications de maquettes versionnées | EXISTANT (`MaquetteService`, snapshot) + test de non-régression `nouvelle_version_ne_modifie_ni_associations_ni_rapport` | 18 |
| 3 | Contexte historique des anciennes inscriptions préservé | EXISTANT (verrouillage, immutabilité) — baseline `MaquetteVersioningTest` repassée | baseline |
| 4 | Compétences définies par une autorité autorisée | approbation `maquettes.approve` (403 testé) ; catalogue jamais seedé | 1, 4, 5 |
| 5 | Rapports distinguent absence de données et absence de couverture | deux axes + légende + totaux distincts | 10 |
| 6 | Nouvelle version sans modification silencieuse de l'historique | associations canoniques ; rapport v1 identique avant/après v2 | 12 |
| 7 | Imports détectent les ambiguïtés (jamais de choix arbitraire) | rejet AMBIGU avec ids des candidats ; exactement l'association non ambiguë créée | 13 |
| 8 | Activation via le workflow prévu | EXISTANT (`transiter`, gates) — non retouché ; couverture lisible sur tout statut sans effet | baseline |

## LOOP-02 — Architecture

```
app/Modules/Referentiel/                             (EXTENSION du module existant)
├── Models/Competence.php                            (catalogue versionné, figeable)
├── Models/CompetenceObjectif.php                    (associations canoniques idempotentes)
├── Models/CompetenceRapportSnapshot.php             (snapshot immuable de rapport — LOOP-11)
├── Services/CouvertureCompetencesService.php        (catalogue, associations, cohérence, couverture, import, snapshots)
├── Controllers/CompetencesController.php            (actions JSON + UI Blade)
└── Routes/web.php                                   (+ bloc 11 routes, avant {entite} génériques)
resources/views/modules/referentiel/competences/   (index, couverture, coherence — LOOP-11)
app/Console/Commands/VerifierCoherenceCompetences.php (contrôle planifié lecture seule — LOOP-11)
database/migrations/2026_10_25_010000_creer_tables_competences.php (2 tables, down() complet)
database/migrations/2026_10_27_130000_creer_snapshots_couverture_competences.php (1 table additive)
tests/Feature/ReferentielCompetencesTest.php         (24 tests, 137 assertions)
```

- Tables additives `competences` (UNIQUE code, statut, version, portée
  faculté nullable, traçabilité approuve_par/le) et `competence_objectifs`
  (UNIQUE compétence+cible → idempotence ; `cible_id` SANS FK polymorphe —
  l'existence est vérifiée par le service et le contrôle de cohérence
  expose les liens morts au lieu de laisser une FK muette).
- Throttle par famille avec préfixes (`pc-cat`, `pc-assoc`, `pc-import`) —
  leçon du Lot 16 : les compteurs ne partagent plus une clé d'empreinte.
- Permissions : `referentiel.view` (lecture), `referentiel.manage`
  (catalogue/associations), `maquettes.approve` (approbation),
  `referentiel.import` (import) — zéro clé nouvelle, zéro bundle modifié.

## LOOP-03 — Implémentation réalisée (incidents corrigés)

| # | Incident | Cause | Correction |
|---|---|---|---|
| 1 | Fatal « Access level to get() must be public » | collision des helpers `get/post/delete` avec la TestCase Laravel | helpers renommés `appelGet/appelPost/appelDelete` (convention Lots 15-16) |
| 2 | 6 échecs « Maquette verrouillée » | scaffold créant la maquette en `approved` AVANT `definirContenu` | contenu saisi en brouillon PUIS `forceFill` du statut (verrouillage respecté) |
| 3 | 500 sur `coherence` | `chunk(200)` sans callback (2 arguments attendus) | `chunkById(200, closure)` |
| 4 | Snapshot non-écriture faux | comptages pris AVANT le scaffold du test | snapshot APRÈS scaffold, avant le flux |
| 5 | Ligne d'assertion corrompue + syntaxe `'niveau_attendu' '=>' 'maitrise'` | auto-corruption d'écriture | corrigées avant la première exécution verte ; scans de garde 0 corruption |
| 6 | 2 écarts pint (`fully_qualified_strict_types`, `unary_operators`) | FQCN inline et `++` postfixé | `vendor/bin/pint` sur les 2 fichiers, re-test PASS |

## LOOP-04 — Résultats réels des tests

Exécution initiale : `php artisan test tests/Feature/ReferentielCompetencesTest.php`
Résultat réel (2026-10-25) : **« Tests: 18 passed (90 assertions), Duration 1.58s »**.
Après le cycle LOOP-11 (2026-10-27) : **24 passed (137 assertions)** — les
6 tests ajoutés couvrent `cible_contexte`, les snapshots, l'UI et la
commande planifiée.

| Thème (cahier §F) | Preuves clés |
|---|---|
| Catalogue jamais seedé, code dupliqué refusé, création 403 sans manage | 3 tests (count 0, 422, 403) |
| Versionnement : révision brouillon version 1→2, figage à l'approbation (révision refusée 422) | test 4 |
| Approbation par l'AUTORITÉ (`maquettes.approve`) : admin_faculte 403, approbation tracée | test 5 |
| Archivage → plus associable | test 6 |
| Associations : compétence non approuvée 422, cible inexistante 422 (jamais de lien mort), cohérence de portée (locale A → cible B refusée ; globale acceptée) | 2 tests |
| Idempotence (re-lier = même id, count 1) et dissociation | test 9 |
| Couverture : `non_renseigne` (ABSENCE DE DONNÉE) vs `non_couverte` (ABSENCE DE COUVERTURE) dans la MÊME réponse, totaux et légende | test 10 |
| IDOR : couverture d'une autre faculté 403 ; association sur cible hors périmètre 403 | test 11 |
| Nouvelle version de maquette : associations inchangées, rapport v1 STRICTEMENT identique avant/après, v2 mêmes totaux | test 12 |
| Import ambigu : 2 UE même code → rejet AMBIGU avec les 2 ids, AUCUNE association arbitraire (count 1 = la ligne saine uniquement) | test 13 |
| Import valide idempotent (2 créées → re-import 2 ignorées) | test 14 |
| Cohérence : cible supprimée → anomalie « lien mort » exposée | test 15 |
| Non-écriture des 6 tables officielles (maquettes, maquette_ue, maquette_ue_ecue, matieres, unites_enseignement, programmes) | test 16 |
| Module désactivé → 404 (GET et POST) | test 17 |
| Performance : 10 UE × ECUE, rapport HTTP complet < 3 s (constaté 0,04 s) | test 18 |

## LOOP-05 — Sécurité et périmètre (récap)

- **Permissions réutilisées** (route `can:` + `Gate::authorize`) ;
  approbation réservée à `maquettes.approve` (testée 403).
- **Périmètre serveur** : catalogue filtré par `faculteRestreinteId` ;
  compétence locale et cible hors périmètre → 403 ; couverture via
  `ReferentielPolicy::view` (faculté du programme).
- **Validation** : codes/libellés bornés, `cible_type` enum serveur,
  plafond 500 lignes d'import, `niveau_attendu` max 60 (chaîne saisie).
- **Aucune écriture officielle** : 6 tables snapshotées immobiles (test 16) ;
  le lot n'écrit que `competences`/`competence_objectifs`.
- **Module gate** : 404 si `module:referentiel` désactivé (test 17).

## LOOP-06 — UX (passe UI réalisée au cycle LOOP-11)

Le socle reste **JSON-first** (contrats stables pour les clients), mais la
passe UI a été réalisée au cycle du 2026-10-27 :

- `competences/index.blade.php` — catalogue, création d'un brouillon,
  approbation (`maquettes.approve`), archivage (`referentiel.manage`),
  accès au contrôle de cohérence et aux couvertures des maquettes du
  périmètre ;
- `competences/couverture.blade.php` — totaux, distinction visuelle
  « absence de donnée » / « absence de couverture », tableaux UE / ECUE /
  compétences, historique des rapports figés ;
- `competences/coherence.blade.php` — anomalies en lecture seule, rappel
  qu'aucune correction automatique n'est appliquée.

Entrée de navigation « Compétences » ajoutée sous le module `referentiel`
(permission `referentiel.view`), donc visible uniquement si le module est
actif et l'utilisateur habilité.

## LOOP-07 — Performance et plafonds

- Rapport : 2 requêtes d'associations (`whereIn` sur ids du plan) +
  agrégation PHP sur relations chargées — aucun N+1 par entité ;
  index `competence_objectifs (cible_type, cible_id)` justifié par le
  `whereIn` du rapport.
- Index catalogue `competences (statut, faculte_id)` pour le périmètre.
- `chunkById(200)` sur le contrôle de cohérence ; index lecture plafonné
  (500) ; import plafonné (500 lignes).
- Mesure réelle : 10 UE/ECUE + rapport complet en 0,04 s (seuil 3 s).

## LOOP-08 — Régression et outillage

- `vendor/bin/pint --test` (fichiers du lot) → **PASS** (`pint` sur
  `app/Modules/Referentiel`, la commande de cohérence et le test).
- Non-régression référentiel (module hôte MODIFIÉ — routes) :
  `MaquetteVersioning`, `MaquetteComparaison`, `MaquettesFlow`,
  `ReferentielSecurite`, `ReferentielTenantIsolation`,
  `ReferentielGouvernance`, `Referentiel`, `MaquetteEditeur`,
  `MaquettePlanEcues`, `ReferentielImportIdempotence` →
  **86 passed (333 assertions)**.
- Lots 14-16 : `SimulateurScenarios` + `PreventionDecrochage` +
  `AssuranceQualite` → **66 passed (322 assertions)**.
- Suite IA : filtre baseline → **111 passed (356 assertions)** (baseline
  identique).
- Cycle LOOP-11 : filtre large `Referentiel|Maquette|Competence` →
  **264 tests, 259 passés, 5 ignorés (1105 assertions)** ;
  `ReferentielCompetencesTest` → **24/24 (137 assertions)**.
- `php artisan route:list` → **11 routes** du lot enregistrées (9
  `competences*` + `maquettes.couverture` + `maquettes.couverture.figer`).
- `php artisan schedule:list` → `competences:coherence` planifiée à
  `05:40`, conditionnée au module actif.
- Scan anti-corruption (cyrillique/CJK) sur les fichiers → **0** ;
  signatures de tests intactes (24/24 `(): void`).

## LOOP-10 — Verdict

**PASS_WITH_RESERVATIONS**

Tous les critères du cahier sont réalisés et prouvés (18/18 puis **24/24**
tests, 137 assertions après le cycle LOOP-11), les quatre critères déjà
couverts par l'existant (versionnage, historique, comparaison, activation)
sont démontrés par les baselines repassées, les interdits sont testés
(catalogue jamais seedé, ambiguïté jamais résolue arbitrairement, aucune
écriture rétroactive), et aucune table existante n'est modifiée.

État des réserves après le cycle LOOP-11 :

| # | Réserve | État 2026-10-27 |
|---|---|---|
| 1 | UI différée : sémantique JSON, aucune vue (LOOP-06) | **TRAITÉE** — 3 vues Blade + entrée de navigation + tests HTTP (`assertSee`) |
| 2 | Catalogue RÉEL vide : aucun référentiel de compétences institutionnel n'a été saisi (l'inventer est interdit) — la couverture ne peut être calculée qu'après saisie par l'administration | **OUVERTE — règle du cahier** (décision métier, pas un défaut de code) |
| 3 | `niveau_attendu` = chaîne libre SAISIE : aucune taxonomie validée institutionnellement (aucune échelle imposée, conformément au cahier) | **OUVERTE — décision métier à confirmer** |
| 4 | L'import résout par CODE : une cible ambiguë exigeait l'usage de l'API d'association par id | **TRAITÉE** — `cible_contexte` (faculté/programme/UE) lève l'ambiguïté sans choix arbitraire ; sans contexte, rejet motivé conservé |
| 5 | Associations canoniques non versionnées par maquette : un rapport re-calculé après dissociation reflète l'état courant | **MITIGÉE** — snapshots `competence_rapport_snapshots` immuables (empreinte SHA-256, aucun update/delete) ; les associations restent canoniques non versionnées (choix MVP documenté) |
| 6 | Contrôle de cohérence à la demande (endpoint) : aucun job planifié automatique | **TRAITÉE** — commande `competences:coherence` planifiée à `05:40`, lecture seule, code de sortie toujours `SUCCESS` |
| 7 | Migration MySQL NON exécutée (`APP_ENV=production`) : tables créées uniquement en SQLite de test — à migrer à l'activation | **OUVERTE — contrainte de mission** (aucune migration sur la base réelle) |
| 8 | Performance prouvée sur volumes synthétiques (10 UE), pas sur les volumes réels MySQL | **OUVERTE — réserve de portée** |

## LOOP-11 — Cycle de complément du 2026-10-27 (preuves exécutées)

### Corrections et extensions livrées

| # | Livrable | Fichier(s) | Preuve |
|---|---|---|---|
| 1 | Désambiguïsation d'import `cible_contexte` (code faculté → programme ; code programme → UE ; code UE → ECUE) | `CouvertureCompetencesService::resoudreCodeUnique/appliquerContexte` ; validation `CompetencesController::importer` | 3 tests dédiés : ambiguïté non résolue rejetée, contexte programme résout vers la bonne UE, contexte inconnu rejeté |
| 2 | Commande planifiée de cohérence, fail-soft, lecture seule | `app/Console/Commands/VerifierCoherenceCompetences.php` ; `routes/console.php` (`05:40`, `when(module actif)`) | `php artisan schedule:list` affiche la tâche ; test artisan : 1 anomalie signalée, ligne intacte, exit 0 |
| 3 | UI catalogue / couverture / cohérence | 3 vues `resources/views/modules/referentiel/competences/` ; `config/modules.php` (nav) | test HTTP : création → approbation → couverture → cohérence, `assertSee` sur les libellés clés |
| 4 | Snapshots de rapport immuables | `CompetenceRapportSnapshot`, migration `2026_10_27_130000`, `figerRapport()`/`historiqueRapports()`, route `maquettes.couverture.figer` | test : 2 figages ⇒ même empreinte ; dissociation ⇒ rapport courant perd la couverture, snapshot inchangé |
| 5 | Badges de statut | `resources/views/components/status-badge.blade.php` (`approuvee`, `archivee`, `renseigne`, `non_renseigne`, `couverte`, `non_couverte`) | rendu des 3 vues |

### Résultats de tests réellement exécutés

| Commande | Résultat |
|---|---|
| `php artisan test --compact tests/Feature/ReferentielCompetencesTest.php` | **24 passed (137 assertions)**, 1,5 s |
| `php artisan test --compact --filter="Referentiel\|Maquette\|Competence"` | **264 tests, 259 passed, 5 skipped, 1105 assertions** |
| `vendor/bin/pint app/Modules/Referentiel app/Console/Commands/VerifierCoherenceCompetences.php tests/Feature/ReferentielCompetencesTest.php` | **PASS** |
| `php artisan competences:coherence` (base MySQL réelle, table absente) | `Table competence_objectifs absente (migration non appliquée) : contrôle ignoré.` — **exit 0** |
| `php artisan schedule:list` | `40 5 * * * php artisan competences:coherence` présent |

### Points d'attention conservés

- Les snapshots sont la **seule** trace figée : les associations restent
  canoniques et non versionnées (choix MVP). Un snapshot ne modifie ni les
  associations ni les maquettes.
- La commande de cohérence **ne corrige jamais** : elle liste et journalise
  (identifiants techniques + codes uniquement, aucune donnée personnelle).
- Le figage de snapshot exige `referentiel.export` en plus de la lecture :
  c'est un acte de publication, pas une simple consultation.

Le référentiel LMD reste la source canonique : ce lot n'ajoute QUE la
couche compétences (catalogue saisi + associations idempotentes + rapports
de lecture + snapshots) — une nouvelle version de maquette ne réinterprète
jamais l'historique, et un code ambigu ne produit jamais d'association
arbitraire.
