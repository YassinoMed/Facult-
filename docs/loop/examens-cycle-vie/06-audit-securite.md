# LOOP EX-LIFECYCLE — 06 · Audit de sécurité

## F1 · Autorisation (permissions exactes)

| Opération | Permission vérifiée | Couche(s) |
|---|---|---|
| Lister examens | `evaluations.view` | middleware `can:` + `Gate::authorize` implicite |
| Créer | `evaluations.create` | middleware + `StoreExamenRequest::authorize` |
| Transition | `evaluations.update` + `EvaluationPolicy@update` (+ périmètre) | middleware + Gate |
| Prévisualisation | `evaluations.view` + `EvaluationPolicy@view` (+ périmètre 404) | middleware + Gate |
| Soumettre gouvernance | `evaluations.update` | middleware |
| Certifier | `evaluations.certify` | middleware |
| Approuver 1/2, refuser, escalader | `evaluations.approve` | middleware |
| Réviser / résoudre / publier | `evaluations.publish` | middleware + **re-vérification dans `publierExamen`** |

Aucun wildcard `evaluations.*` supposé : chaque route porte sa permission littérale (audit `routes/web.php`).

## F2 · Isolation facultaire

- Listing : `scopePourFaculteId` (transversal visible).
- Transitions/prévisualisation/scénarios/audit : Policy `dansPerimetre` + `abort_unless(peutAccederFaculte(...), 404)`.
- `faculte_id` du client : **jamais utilisé** — le périmètre vient de `faculteRestreinteId()` et des relations serveur (programme→faculté, groupe→faculté).
- Testée : EX-LC-017 (transition hors périmètre → 403, état inchangé), EX-LC-007 (prévisualisation hors périmètre → 403).

## F3 · IDOR/BOLA

- `examen_id` : binding + Policy + périmètre (tests EX-LC-017/007).
- `session_id` : `findOrFail` + garde session verrouillée + cohérence année.
- `validation_id` : `lockForUpdate` + `exigerStatut` + acteurs distincts (suites gouvernance 198 assertions).
- `scenario_id`/`epreuve_id` : hors périmètre de modification de ce LOOP, couverts par leurs suites existantes.
- Un refus HTTP n'exécute **aucune écriture préalable** : le contrôle Gate précède l'appel service ; le service ne touche l'état qu'après ses propres gardes (testé : aucun événement écrit pour un refus).

## F4 · Anti-élévation

- Se déclarer certificateur/approbateur/publicateur : impossible — 4 acteurs obligatoirement distincts + `exigerRoleSepare` (invariants §2.7/§2.8, couverts par `ExamGouvernanceDoubleValidationTest`).
- Réutiliser un identifiant de validation : statuts obligatoires + empreinte + idempotence publication.
- Modifier une donnée approuvée sans workflow : refus par empreinte (« le planning a changé après certification »).

## F5 · MFA

Constat vérifié par `php artisan route:list` (pile réelle) : les routes du module Évaluation
portent `[auth, admin (EnsureBackOffice), secondaire.verrou, module:evaluation, throttle, can:*]`
— **sans** `EnsureMfa`. C'est un schéma **systémique de tous les modules administratifs**
(Schedule, Finances, StudentDossiers, Sentinel…), pas une régression de ce LOOP : les routes
n'ont jamais porté `mfa.obligatoire` (contrairement au groupe admin central de `routes/web.php`).

- **Aucun nouveau mécanisme MFA introduit** ; aucune modification du comportement global.
- Point transverse consigné (ANOM-A9, `00` §13 et annexe `01`) : la décision d'étendre
  `mfa.obligatoire` aux modules relève d'une politique d'architecture hors périmètre EX-LIFECYCLE.

## F6 · Tâches différées

- `PlanifierExamensJob` / `AffectationSurveillanceJob` : n'écrivent pas le statut des examens (vérifié).
- La publication orchestrée re-valide permission + statut + empreinte **à l'exécution** (pas seulement à la mise en file).
- `EvaluationChanged` : listeners existants, aucun acteur implicite fiable requis (log applicatif).

## Synthèse

| Vecteur | Statut | Preuve |
|---|---|---|
| Transition sans permission | bloqué | EX-LC-016 (403) |
| Transition hors périmètre | bloqué | EX-LC-017 (403, état inchangé) |
| Publication hors circuit | bloqué | EX-LC-012 + suites gouvernance |
| Falsification du journal | bloquée | EX-LC-055 (`DomainException`) |
| État imposé par le client | bloqué | EX-LC-005 (statut ignoré) |
| Rejeu ancienne requête | refusé, sans trace parasite | EX-LC-047 |
| Module `evaluation` désactivé | routes refusées par le mécanisme module existant (`EnsureModuleEnabled`), inchangé | couvert par la convention module (non régressé, suites module OK) |
