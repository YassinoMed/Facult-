# LOOP-04 — PASSEPORT ACADÉMIQUE ET SIMULATION LMD

> **Verdict : PASS** (conditions d'application en fin de rapport)
> Date : 24/10/2026 — protocole audit-first, réutilisation maximale, 0 commit/push/déploiement.
> HEAD `38ef149` **inchangé** pendant tout le lot. Exécution dans le cadre du
> programme LOOP MASTER (auto-pass enchaîné L05 → L02 → L03 → **L04** → L07…).

---

## 1. Périmètre (entrée audit §F4)

Source : `docs/engineering/LOOP-00-AUDIT-DIRECTEUR-UNIREGISTRE.md` L69-76 —
statut « EXISTANT, DÉSACTIVÉ (simulation complète, testée, append-only) ».
Manques nommés par l'audit :

1. **FSM décisionnel dispense/écriture** — statuts sans transition contrôlée
   (les gardes existaient au niveau service, mais n'étaient ni centralisées
   ni exposées au contrôleur : `decider()` acceptait un `statut` chaîne
   libre, la vérification 422/409 revenant entièrement au service) ;
2. **versionnement des règles de simulation explicité à la doc** —
   `ParcoursRegleService::versionner()` correct en code, mais le cycle de
   vie (versionner → résolution → références code@version) inexistant
   dans la documentation du module.

Deux inconnues de l'audit à lever : rôle exact de `ParcoursApiController`
et des policies annexes `StudentArchive`/`DocumentAcademique`.

## 2. État des lieux (lecture amont)

Module `ParcoursAcademique` audité ligne à ligne :

- **`SimulationParcoursService`** : contrat anti-écriture intact
  (docblock §2-5, avertissement injecté dans chaque résultat,
  append-only `parcours_simulations`, audit tracé, 13 scénarios
  `ParcoursSimulation::SCENARIOS` — l'énumération exacte vérifiée = 13,
  convergente avec le registre PACA-08 ; l'audit initial mentionnait 11).
  Dispatch `simuler()` : 422 sur scénario inconnu, 404 si source
  pour un autre étudiant ; les 6 simulateurs restent lecture seule
  (moyennes via `AcademicCalculationService`, délibération via
  `CalculEvaluationService`, progression via `AcademicProgressionService`).
- **`AmenagementParcoursService`** : gardes FSM solides (422 cible
  inconnue, 409 transition illégale, `lockForUpdate` + transaction
  sur les 4 workflows), mais listes de transitions écrites en ligne
  et le contrôleur `decider()` validait `statut` en chaîne libre.
- **Tests existants** : `ParcoursAcademiqueTest` = 51 préexistants
  (workflows positifs, BOLA, concurrence 409 équivalence, anti-écriture,
  scénarios comparés, règles versionnées) ; aucune transition illégale
  dispense/réorientation/passerelle testée, aucune garde contrôleur.
- **Inconnues levées** :
  - `ParcoursApiController` : API **lecture seule** (§28), routes
    `students/{student}/parcours{\,-credits,-risques,-simulations}`
    sous `scope:archives:read` + `throttle:api`, BOLA-404 ;
  - `ParcoursPolicy` : RBAC + isolation faculté (périmètre vérifié avant
    permission) ; les capacités non modélisées (`parcours.comparer`,
    `parcours.regles`) résolues via le `Gate::define` générique
    (`AppServiceProvider:175`, matrice `Support/Permissions.php`) ;
    rien à ajouter ;
  - `StudentArchivePolicy` et `DocumentAcademiquePolicy` : même pattern
    RBAC + `peutAccederFaculte` — conformes, aucune anomalie.

## 3. Parti pris minimal (réutilisation maximale)

Une **seule source de vérité** pour la machine à états : la constante
`TRANSITIONS` sur le service qui possède déjà les workflows. Zéro
migration, zéro route, zéro nouveau service/modèle/permission. Les
messages et codes d'erreur (422/409) conservés à l'identique :
comportement observable inchangé, seule la surface de validation du
contrôleur se resserre (erreurs de validation conviviales au lieu d'un abort
brut pour une saisie hors liste).

## 4. Modifications réalisées

### 4.1 Code (2 fichiers)

- `app/Modules/ParcoursAcademique/Services/AmenagementParcoursService.php`
  - **AJOUT** — `public const TRANSITIONS` : carte catégorie → `champ`
    (`statut` ou `decision`) → `entrees` = cible ⇒ états sources
    autorisés. Documenté comme source unique (« tout ajout de statut
    passe ici, jamais par une liste en ligne »).
  - **REFACTO (sémantique inchangée)** — les 4 méthodes `decider*`
    consomment la carte : 422 sur cible inconnue (inclus la cible
    non ouverte), 409 sur état source illégal via `in_array($verrouillee->statut, $depuis)`.
    `ParcoursEquivalence::DECISIONS` reste la référence modèle ; la
    dérivation `accordee→validee` / `refusee→rejetee` est inchangée.
- `app/Modules/ParcoursAcademique/Controllers/AmenagementParcoursController.php`
  - `decider()` : validation d'entrée dérivée de la carte (`Rule::in`
    sur les clés `entrees`). Pour `equivalences` : champ `decision`
    (`accordee|refusee`), `statut` ignoré (nullable, toléré pour
    compat données d'affichage) ; défaut « sans décision = refusee »
    conservé. Pour dispenses/réorientations/passerelles : `statut`
    requis et borné par la carte. Concurrence toujours résolue côté
    service (409 sous `lockForUpdate`).

### 4.2 Tests (1 fichier, +6 tests)

`tests/Feature/ParcoursAcademiqueTest.php` (51 → 57) :

1. `test_fsm_dispense_transitions_controlees` — proposee→revoquee 409
   (« Seule une dispense validée peut être révoquée. »), statut inconnu
   422 sans écriture, chemin légal proposee→validee→revoquee, puis
   revoquee→validee 409 ;
2. `test_fsm_reorientation_annulee_reservee_et_double_409` —
   `annulee` (état réservé du modèle) refusé en 422, `proposee`
   préservé, double décision 409 (« déjà traitée ») ;
3. `test_fsm_passerelle_refusee_possible_et_double_409` — transition
   refusee ouverte depuis `proposee` (chemin positif absent des
   suites), refusee→admise 409 (« déjà décidée ») ;
4. `test_fsm_carte_coherente_avec_constantes_modeles` — **anti-dérive** :
   chaque catégorie de la carte référencée, `champ` valide, cibles et
   sources ∈ constantes `STATUTS` des modèles, `annulee` jamais
   ouverte, entrées équivalences ≡ `ParcoursEquivalence::DECISIONS`,
   sources dispenses dédupliquées = `['proposee','validee']` ;
5. `test_fsm_controleur_rejete_statut_et_decision_externes` — HTTP
   : `statut_bidon` (dispenses) et `accordee_bis` (équivalences) →
   erreurs de validation en session (`TestResponse::assertSessionHasErrors`),
   aucune écriture ;
6. `test_fsm_equivalence_sans_decision_defaut_refusee` — verrou du
   comportement conservé : POST sans `decision` = `refusee`/`rejetee`.

### 4.3 Documentation (2 fichiers)

- `docs/parcours-academique/architecture.md` — **2 sections ajoutées**
  (« Machine à états décisionnelle » : table des transitions fermées +
  états réservés + anti-dérive testé ; « Versionnement des règles de
  simulation » : cycle `versionner()` = max(version)+1 sans écrasement,
  désactivation optionnelle `actif=false`, résolution
  spécifique>programme>globale>socle (socle jamais caché), références
  `code@version` persistées dans `regle_reference` et
  `resultat.regles`, simulations antérieures rejouables) — couvre le
  deuxième manque nommé par l'audit ;
  bullet `AmenagementParcoursService` mis à jour (constante
  `TRANSITIONS` source unique).
- `docs/parcours-academique/tests-securite.md` — compteur rafraîchi
  22 → 57 tests (il était périmé) + ligne FSM détaillée.

## 5. Co-édition (concurrence) — frontière respectée

Le co-flux était actif en parallèle sur le module (mtimes et `git status`) :
**MaSimulationParcours** côté étudiant (contrôleur, Request, 3 vues
`ma-simulation-*.blade.php`, suite `PortailSimulationParcoursTest` +
`MaSimulationParcoursTest`), module `PreventionDecrochage`, KPI cockpit
(LOT 03), schedulers, etc. Constat : **aucune collision** — mon périmètre
s'arrêtait aux fichiers `AmenagementParcours*` (admin) ; les suites du
co-flux ont été incluses dans le run canonique et passent intégralement ;
`ParcoursSimulation.php` était déjà modifié par le co-flux à l'entrée,
je n'y ai pas touché.

## 6. Preuves

| Vérification | Résultat |
|---|---|
| `vendor/bin/pint --dirty` (x2) | PASSED, 0 auto-fix |
| Suite module `ParcoursAcademiqueTest` seul | **57/57 PASSED, 238 assertions, ~2,9 s** |
| **RUN CANONIQUE LOT 04** (6 suites : ParcoursAcademiqueTest, PortailSimulationParcoursTest, MaSimulationParcoursTest, AcademicProgressionTest, DeliberationPhase6Test, NavigationPhase7Test) | **122/122 PASSED, 587 assertions, ~5,2 s** |
| HEAD / commits | `38ef149` inchangé, 0 commit, 0 push |
| Migrations | **0** (aucune table ni colonne touchée) |
| Tests ignorés | 0 (aucun skipped dans les runs) |

## 7. Verdict

**PASS.** Les deux manques §F4 sont traités : FSM décisionnelle
centralisée, testée et documentée ; versionnement des règles explicité à la
doc. Comportement de production inchangé (codes/messages identiques) ;
surfaces d'entrée validées ; anti-dérive carte↔modèles exécutée à chaque
run de la suite module.

## 8. Conditions d'application

Aucune : pas de migration, pas de configuration, pas de nouvelle
dépendance. Le livrable est actif dès le déploiement du code.

## 9. Réserves

- **R-04/1** — Les conflits de concurrence restent des réponses HTTP
  brutes (409 avec message explicite) côté web, comportement
  préexistant conservé ; une ergonomie de session (message convivial)
  serait un développement UI futur, hors périmètre minimal.
- **R-04/2** — Réorientations : l'état `annulee` du modèle reste
  **réservé** sans chemin d'écriture (`decider()` le refuse par 422).
  Toute ouverture future passe par `TRANSITIONS` + test anti-dérive ;
  une suppression de l'état exigerait une migration de données.
- **R-04/3** — Passerelles : `eligible`/`non_eligible` ne sont attribués
  par aucun flux actuellement (admissibles comme états sources de
  décision seulement) ; comportement documenté, non supprimable sans
  audit de données historiques.
- **R-04/4** — `SimulationParcoursService::simulerMatiere()` : après
  `toArray()`, l'accès `?->id` sur un tableau est un chemin latent si
  `matiere_id` est null (préexistant, non touché ; avis warning PHP,
  aucun impact sur les suites vertes ; à inspecter si une donnée
  historique nomme `matiere_id` null).
- **R-04/5** — Identifiant accentué `$transférables`
  (`SimulationParcoursService`, préexistant, PHP valide) : cosmétique,
  hors périmètre minimal.
- **R-04/6** — Compteurs historiques (« 22+29 ») du registre PACA-08 non
  remis à jour (tâche flux doc-sync) ; la doc module est exacte (57).
- Rappels transverses toujours ouverts (hérités) : R-03/1…5 (volatilité
  registre KPI, snapshot catalogue cockpit manquant, fusion futures des
  couvertures widgets) et R1-R4 de LOOP-00.
