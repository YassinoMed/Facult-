# 02 — Architecture cible (cycle de vie des examens)

**LOOP GLOBAL — Lot EX-LIFECYCLE — Phase C**

## C1 — Moteur de transition unique : conservé et durci

`App\Modules\Evaluation\Services\ExamenService::transiter` reste **le point d'entrée métier unique**
des transitions de statut de l'examen. Après ce LOOP :

- le contrôle décisif (garde critique + matrice + audit de conformité publication) est évalué sur
  la ligne **verrouillée** (`lockForUpdate`) à l'intérieur de la transaction ;
- chaque transition effective écrit un **journal append-only** (`evaluation_examen_evenements` :
  avant/après, acteur, motif) dans la même transaction ;
- la signature reste compatible (`?int $acteurId = null, ?string $motif = null`) — zéro rupture
  pour les appelants existants (contrôleur, orchestrateur de publication).

Le contrôleur `ExamenController::transiter` se limite à : authentification (middleware), permission
(`can:evaluations.update` + `Gate::authorize('update')`), résolution + périmètre (Policy), blocage
explicite de la cible `published`, délégation au service, transformation des `DomainException` en
erreurs de formulaire. Aucune logique métier dans le contrôleur ou la vue.

## C2 — Répartition des responsabilités

| Responsabilité | Composant |
|---|---|
| Calcul des transitions autorisées | `Examen::peutTransiterVers` + `Examen::TRANSITIONS` |
| Préconditions de transition | `ExamenService::transiter` (garde + matrice + audit) |
| Vérifications de gouvernance | `ExamGouvernanceValidationService` (non dupliqué) |
| Exécution transactionnelle | `DB::transaction` + `lockForUpdate()` |
| Historisation | `evaluation_examen_evenements` (transitions) + `PlanningEvenement` (gouvernance) |
| Effets secondaires | `EvaluationChanged` (`$afterCommit = true`) |
| Explicabilité / prévisualisation | `transitionsDisponibles()` / `previsualiserTransition()` + DTO `ResultatTransition` |
| Consultation de l'historique | `ExamHistoriqueAuditOrchestratorService` (lecture seule) |

Aucun nouveau moteur, aucun deuxième système d'audit : le journal ajouté suit le patron exact de
`PlanningEvenement` (append-only, acteur, avant/après, motif).

## C3/C4 — Résultat et contrat de transition

`État initial + cible + acteur + contexte + audit de conformité → résultat` — déterministe,
explicable, reproductible. La prévisualisation (`ResultatTransition`) est **informative** :
la décision définitive est recalculée au moment de l'application, sur l'état verrouillé.
Refus = `DomainException` motivée (message dédié par cause — voir `04`), capturée par le contrôleur.

## C5 — Concurrence

- Verrou de ligne dans la transaction ; contrôle décisif après acquisition.
- Réentrance sûre : appelé depuis `ExamPublicationOrchestratorService` (transaction englobante) —
  même connexion, verrou déjà tenu.
- Garantie effective sur MySQL ; no-op sur SQLite `:memory:` en tests (note `00` §5.4, R-07).

## C6 — Idempotence

- Répétition d'une transition : refusée par la matrice, **aucun événement additionnel** (EX-LC-011/047).
- Rejeu après changement d'état : décision recalculée dans la transaction → refus, aucun effet.
- Publication déjà scellée rejouée : `published_at` rafraîchi, statut non rejoué (orchestrateur).
- Aucune clé d'idempotence HTTP introduite : verrou + matrice suffisent au contrat « pas de double transition ».

## C7 — Effets secondaires

| Effet | Mécanisme | Après commit ? |
|---|---|---|
| Journal de transition | `evaluation_examen_evenements` | Non — dans la transaction (rollback intégral avec elle) |
| Événement interne | `EvaluationChanged` | Oui (`$afterCommit`) |
| Log applicatif / relevés | listeners existants | Oui — un échec de notification ne remet jamais en cause la transition |

## Gouvernance de publication — non contournable : 5 chemins analysés

| # | Chemin | Verdict |
|---|---|---|
| 1 | `POST …/transiter/published` (route directe de transition) | **Bloqué** : refus explicite du contrôleur (« circuit de gouvernance ») — test EX-LC-012 + suite gouvernance |
| 2 | Appel direct `ExamenService::transiter(…, 'published')` | **Cantonnée** : possible uniquement depuis `scheduled` avec audit conforme ; la matrice refuse tout autre état (EX-LC-018) ; aucun chemin HTTP ne l'expose ; réservée à l'orchestrateur interne |
| 3 | `ExamPublicationOrchestratorService::publierExamen` (interne) | **Défense en profondeur** : re-vérifie `evaluations.publish` DANS le service + verrou + diagnostic de conformité ; appelée uniquement par la gouvernance ; aucune route directe |
| 4 | `POST …/gouvernance/publier` (route officielle) | **Circuit complet exigé** : version `approuvée`, certificat + double approbation (acteurs tous distincts), empreinte certifiée inchangée, gel/dérogation motivée, escalade résolue — 198 assertions de gouvernance |
| 5 | API v1 / Livewire / Jobs | **Aucun chemin** : API = lecture archives/acquis ; aucun job n'écrit le statut d'examen (vérifié F6 `06`) |

## GATE C — Vérification

| Critère | Statut |
|---|---|
| Point de contrôle métier unique | ✅ `ExamenService::transiter` (appelants légitimes : contrôleur, orchestrateur) |
| Aucune logique dupliquée | ✅ |
| Aucun contournement de la gouvernance | ✅ (5 chemins analysés ci-dessus) |
| Concurrence maîtrisée | ✅ verrou + contrôle décisif en transaction |
| Idempotence démontrée | ✅ EX-LC-011 / EX-LC-045 / EX-LC-047 |
| Écritures directes restantes | ✅ encadrées et consignées (`00` §5.3, annexe `01`) |
