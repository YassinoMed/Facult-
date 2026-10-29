# LOOP-01 — WORKFLOW DE VALIDATION CONFIGURABLE

> **Verdict : PASS** (conditions d'application et réserves en fin de rapport)
> Date : 25/10/2026 — protocole audit-first, réutilisation maximale, 0 commit/push/déploiement.
> HEAD `38ef149` **inchangé** pendant tout le lot. Exécution dans le cadre du
> programme LOOP MASTER (auto-pass enchaîné L05 → L02 → L03 → L04 → L07 → **L01** → L06…).

---

## 1. Périmètre (entrée audit §F1, L37-44)

**Statut d'entrée : EXISTANT_PARTIEL (moteur complet mais figé et désactivé
par défaut).** Manques nommés par l'audit pour le lot :

1. **définitions de workflow versionnées** — la FSM du module vivait en
   `const` de classe, sans version ni conservation par instance ;
2. **quatre-yeux sur approbation** — aucune séparation de fonctions sur
   `in_review → approved` ;
3. **délégations** — aucune infrastructure pour déléguer le pouvoir
   de validation.

Existant validé conservé tel quel : SLA (`SlaService` + scheduler
07:30, `processing_days` par type), fil de messages, pièces jointes,
policy périmètre faculté, 15 permissions `demandes.*`, tests
`OnlineRequestTest` (9) + `OnlineRequestSecurityTest` (7).

## 2. État des lieux (lecture amont)

Découverte structurante : le repo contient **déjà** un moteur de
workflows générique (`app/Services/Workflow/`) — `MoteurWorkflowService`
(définitions versionnées résolues par registre, quatre yeux natif,
isolation faculté, `lockForUpdate`, audit), `RegistreWorkflow`,
`WorkflowDefinitionInterface`, et une définition `WorkflowDemandeEtudianteDefinition`
transcrite à l'identique de la FSM du module. **Mais** :

- le moteur n'est consommé par **aucun code de production** (seule sa
  suite de test l'exerce — fichier non suivi, co-flux) ;
- la définition demandes référençait une permission **fictive**
  (`demandes.manage`, inexistante dans la matrice) et `demandes.view`
  pour des transitions qui exigent `demandes.process` en production —
  un câblage direct aurait rendu toute approbation impossible ;
- la topologie FSM était **dupliquée** (`RequestWorkflowService::TRANSITIONS`
  ≡ définition) — violation du zéro-doublon ;
- aucune transition ne connaissait son acteur : `assigned_to`
  (responsable) était le seul marqueur d'agent sur la ligne ;
- zéro infrastructure de délégation (grep exhaustif).

## 3. Parti pris minimal (réutilisation maximale)

**Câbler, ne pas construire** : le moteur existant devient la source
unique du domaine `demande`. Le quatre-yeux s'appuie sur la donnée
existante (`assigned_to` = l'agent qui a reçu/examine la demande) —
zéro colonne nouvelle pour lui. Les délégations réutilisent la
permission existante `demandes.assign` comme garde de gestion (zéro
permission créée) et le canal d'audit existant `tracerReferentiel`
(actions administratives sans étudiant). Une seule migration
additive (`request_delegations` + colonne `version_workflow`),
rollback documenté dans l'en-tête du fichier. La résolution de
l'autorité de validation est **un point unique** (Gate) — routes,
policies, FormRequests et service convergent sans duplication.

Choix documenté : les définitions restent **en code** (git-versionnées,
typées, testées) et non « en base » — des transitions modifiables par
données ouvriraient une voie d'escalade de privilèges par édition de
rangée ; la conservation exigée par l'audit est livrée par le **pinning
par instance** (`version_workflow` épinglée à la création, résolution
par le moteur à chaque transition).

## 4. Réalisations

### 4.1 Source unique et définition corrigée

- `app/Services/Workflow/Definitions/WorkflowDemandeEtudianteDefinition.php`
  — `permissionRequise()` alignée sur la matrice réelle :
  `approved` → `demandes.approve`, `rejected` → `demandes.reject`,
  `received/in_review/waiting_student/completed` → `demandes.process`,
  actions étudiant (`submitted`, `cancelled`) libres. La permission
  fictive `demandes.manage` disparaît.
- `app/Modules/OnlineRequests/Services/RequestWorkflowService.php`
  — la façade statique délègue désormais à la définition
  (`autorisee()` → `transitionAutorisee()`), la constante
  `TRANSITIONS` dupliquée est supprimée ; `libelles()` conservé
  (2 vues Blade).

### 4.2 Moteur : quatre-yeux étendu à l'agent assigné

- `app/Services/Workflow/MoteurWorkflowService.php`
  — `verifierSeparationFonctions()` prend `assigned_to` en dernier
  recours de la chaîne d'initiateur (`propose_par` → `created_by` →
  `auteur_id` → **`assigned_to`**) : celui qui examine ne peut jamais
  approuver. Emprise vérifiée : `assigned_to` n'existe que sur
  `online_requests` (grep migrations) — les domaines `inscription` et
  `correction` (suite co-flux 9/9 verte) sont inchangés.
  Les prédicats `verifierSeparationFonctions` /
  `verifierIsolationFacultaire` deviennent publics : réutilisation par
  les modules consommateurs sans duplication de logique.

### 4.3 Câblage du service des demandes

- `OnlineRequestService` — `transitionner()` résout la définition
  **épinglée** (`resoudreDefinition('demande', …)` lit `version_workflow`)
  et applique la cascade sous verrou : topologie (message historique
  « Transition « X » → « Y » interdite. » préservé à l'identique), puis
  — quand un acteur est fourni (voie administration) — permission via
  `Gate::forUser` (donc RBAC **ou délégation**), quatre-yeux
  (message dédié), isolation (défense en profondeur ; la policy reste
  la barrière HTTP). `creer()` épingle la version courante du registre.
  Les 6 méthodes d'action gagnent un `?User $acteur = null` optionnel —
  les voies étudiant (soumission, annulation) restent purement
  topologiques, signatures rétro-compatibles.
- `OnlineRequestController` — les 7 actions de transition passent
  `$request->user()` ; + `deleguer()` / `revoquerDelegation()`.

### 4.4 Délégations de l'autorité de validation

- `database/migrations/2026_10_25_140000_lot01_…php` — **additive** :
  table `request_delegations` (déligant, délégataire, faculté snapshot,
  fenêtre bornée, motif, `created_by`, `revoked_at`, index) + colonne
  `online_requests.version_workflow` (défaut 1 = backfill). Rollback :
  drop table + drop colonne. **Non appliquée en local** (APP_ENV
  production) ; exécutée par sqlite en test.
- `Models/RequestDelegation` — casts, relations, `estActive()`.
- `Services/DelegationDemandeService` — `deleguer()` (garde : le
  délégant détient `demandes.approve` — jamais une délégation, donc
  chaîne impossible ; jamais d'auto-délégation ; même faculté ou
  délégant central ; fenêtre future ≤ 90 jours), `revoquer()`
  (délégant ou titulaire `demandes.assign`, sous verrou, idempotent),
  `peutValider()` (RBAC direct, sinon délégations actives dont le
  délégant détient **toujours** le pouvoir — une délégation s'éteint
  avec lui). Audit `delegation_validation.creee/revoquee` par le canal
  référentiel.
- `Providers/AppServiceProvider` — les Gates `demandes.approve` et
  `demandes.reject` sont redéfinies après la boucle RBAC générique :
  `peutValider()` devient l'unique résolveur (routes `can:`, policies,
  FormRequests, service).
- `Policies/OnlineRequestPolicy` (approve/reject) et
  `Requests/RejectOnlineRequestRequest` — passent par `can()` (Gate),
  isolation inchangée.
- `Routes/web.php` (module) — `POST admin/demandes/delegations` et
  `DELETE admin/demandes/delegations/{delegation}`, `can:demandes.assign`,
  throttle 10/min. Zéro permission nouvelle.

### 4.5 Tests

- **Nouvelle suite** `tests/Feature/WorkflowDemandesValidationTest.php`
  (11 tests, 141 assertions) : permissions réelles par le moteur
  (approbation vs traitement), quatre-yeux `assigned_to` (assigné
  bloqué, second porteur passe, sans assignation libre), version
  épinglée à la création, **cohérence anti-dérive** (états ≡
  `OnlineRequest::STATUTS` ≡ libellés ≡ façade sur les 81 paires),
  délégation active → le délégataire approuve par la route (avec
  audit), révocation → 403 immédiat, expiration → 403, refus
  auto-délégation / fenêtre 91 jours / re-délégation (chaîne),
  quatre-yeux appliqué au délégataire, périmètre exact de la
  délégation (rien au-delà d'approuver/rejeter), et défense en
  profondeur service (permission + quatre-yeux sous verrou, messages
  exacts).
- **Adaptation renforcée** `OnlineRequestTest::test_workflow_complet_cote_admin` —
  le cycle complet intègre désormais la séparation : l'admin assigné
  voit son auto-approbation refusée (erreur session `status`), un
  second porteur du pouvoir approuve, le PDF est généré, le cycle
  termine. Test renforcé, jamais affaibli.
- Suites existantes non touchées par ailleurs : `OnlineRequestSecurityTest`
  (7) passe à l'identique (IDOR, statut non injectable, 403/404
  inchangés), suite co-flux `WorkflowValidationConfigurableTest` (9)
  inchangée et verte.

### 4.6 Documentation

- `docs/MODULE-ONLINE-REQUESTS.md` — §1 réécrite (source unique,
  version épinglée) + **§8 « Validation configurable, quatre yeux et
  délégations »** (câblage moteur, sémantique `assigned_to`,
  règles des délégations, points de résolution, endpoints, audit).
- `docs/architecture/modules.md` — ligne OnlineRequests : table
  `request_delegations` ajoutée.
- `docs/engineering/LOOP-00-AUDIT-DIRECTEUR-UNIREGISTRE.md` — hygiène :
  contamination « sicher » (L39) corrigée en « sécurisées », rien
  d'autre touché dans le doc directeur.

## 5. Co-édition (concurrence) — frontière et incidents tracés

- `tests/Feature/WorkflowValidationConfigurableTest.php` (suite du
  moteur, **non suivie**) : détectée comme travail en vol du co-flux —
  **non touchée** ; mes tests moteur du domaine `demande` vivent dans
  ma propre suite.
- **Blocage trunk transitoire** : `tests/Unit/MoteurTemporelTest.php`
  (co-flux, jamais touché par moi) est passé en erreur de parse
  (espace dans un nom de méthode), empoisonnant la découverte de
  TOUTE suite PHPUnit ; ma tentative de réparation minimale a échoué
  sur relecture — **le co-flux a auto-réparé son fichier** dans
  l'intervalle. Signalé ici comme exigé par le programme.
- `pint --dirty` a reformaté au passage des fichiers co-flux en vol
  (SimulationTemporelle, GouvernanceConformite) — style uniquement,
  aucun de mes edits ne les concernait.
- Co-flux très actif aujourd'hui (migrations 2026_10_25 01-03h,
  modules Competences/HubIntegration/GouvernanceConformite) — ma
  migration prend le créneau 14h, re-vérifié avant création.

## 6. Preuves

| Vérification | Résultat |
|---|---|
| `php -l` sur les 13 fichiers touchés | Aucune erreur |
| `vendor/bin/pint` (zone du lot) | PASSED, 0 fixer résiduel |
| Nouvelle suite `WorkflowDemandesValidationTest` | **11/11 PASSED, 141 assertions, ~1,4 s** |
| Suites existantes + co-flux (`OnlineRequestTest`, `OnlineRequestSecurityTest`, `WorkflowValidationConfigurableTest`) | **25/25 PASSED, 87 assertions** |
| **RUN CANONIQUE LOT 01** (10 suites : WorkflowDemandesValidationTest, OnlineRequestTest, OnlineRequestSecurityTest, OnlineRequestPdfTest, InscriptionDemandeComplementTest, WorkflowValidationConfigurableTest, MensDivergencesWorkflowTest, EvaluationWorkflowServiceTest, DocumentWorkflowTest, WorkflowDossierPlanningTest) | **77/77 PASSED, 393 assertions, ~5,7 s** |
| HEAD / commits | `38ef149` inchangé, 0 commit, 0 push |
| Migrations | **1 additive** (délegations + version_workflow), rollback dans l'en-tête, non appliquée en local, exécutée par sqlite en test |
| Tests ignorés | 0 |

## 7. Verdict

**PASS.** Les trois manques nommés par l'audit sont traités par câblage
réutilisatif : définition versionnée unique (conservation par instance
épinglée), quatre-yeux réel sur l'approbation (l'agent assigné ne
s'auto-approuve jamais, délégataire compris), délégations bornées,
révocables, auditées, à point de résolution unique. La permission
fictive qui aurait cassé tout câblage naïf est éliminée ; la
duplication de FSM disparaît ; les messages et contrats historiques
sont préservés (seule adaptation : le cycle complet admin intègre la
séparation des fonctions — renforcement).

## 8. Conditions d'application

1. **Déployer la migration** `2026_10_25_140000` (additive ; rollback =
   drop table + drop colonne, aucune donnée métier perdue).
2. **Gouvernance** : le quatre-yeux rend l'approbation à deux porteurs
   obligatoire — une équipe mono-agent ne peut plus approuver seule.
   C'est l'exigence de l'audit, à assumer opérationnellement (au
   besoin via une délégation croisée, qui reste soumise à la
   séparation).

## 9. Réserves

- **R-01/1** — UI de gestion des délégations absente : endpoints
  prêts (administration assistée, tests), formulaires à venir.
- **R-01/2** — `delaiMaxHeures` de la définition (24/48/72 h) est
  informationnel et non consommé : le SLA réel reste
  `processing_days`/`SlaService`. Double source documentée — arbitrer
  si le moteur doit devenir porteur des délais.
- **R-01/3** — Définitions en code (choix de sécurité documenté §3) :
  toute évocation future de « définitions en base » devra rouvrir
  l'arbitrage escalade-de-privilèges vs configurabilité.
- **R-01/4** — `peutValider()` = requête + délégant par délégation
  active : volumétrie attendue très faible, à reprendre en cache si
  les délégations devenaient massives.
- **R-01/5** — La chaîne de détection du « maker »
  (`propose_par`/`created_by`/`auteur_id`/`assigned_to`) est un ordre
  engageant : tout nouveau champ d'initiateur doit l'étendre
  consciemment (test de non-régression à prévoir).
- **R-01/6** — Les voies étudiant restent topologie-seule au service
  (l'appartenance est au contrôleur) — contrat d'origine préservé,
  documenté ici pour le lecteur du moteur.
- Héritage toujours ouvert : R-03/1…5, R-04/1…6, R-07/1…7, R1-R4
  de LOOP-00.

## 10. Notes pour les lots suivants

- Le quatre-yeux `assigned_to` profite à tout domaine câblé au moteur
  (toute entité portant `assigned_to` hérite de la séparation).
- **LOT 10** (émargement) : les QR réels hérités du LOT 07 sont en
  place ; vérifier le rendu 60 px et l'expérience scan.
- Les délégations illustrent le modèle « service module + Gate
  redéfinie » pour tout pouvoir transitoire futur (remplacements
  LOT 09, approbations LOT 11…) — sans dupliquer la mécanique.
