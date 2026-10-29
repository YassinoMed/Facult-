# LOOP-SMART-TIMETABLES-PHASE-A — Contraintes & disponibilités déclarées (module Schedule)

**Date :** 18/10/2026 · **Protocole :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Plan validé :** Phases A (contraintes) → B (moteur de génération) → C (exports & qualité) → D (extensions)
**Périmètre de ce LOOP :** Phase A uniquement — A1 (indisponibilités par ressource) + A2 (paramètres bornés du futur moteur).

---

## 0. Périmètre et principe directeur

Le chantier Smart Timetables vise un moteur de planification inspiré
fonctionnellement d'aSc TimeTables. La Phase A pose **la couche
contraintes** :

1. des **plages déclarées par ressource** (enseignant, salle, groupe) —
   `indisponible` = contrainte DURE, `souhaitee` = préférence ;
2. des **paramètres de génération bornés** (pondérations + garde-fous)
   utilisables par la Phase B.

Interdits respectés : aucune table/service dupliqué, **jamais** de
publication automatique (la génération produira toujours un brouillon),
cours et examens restent séparés (le module Evaluation n'est pas touché),
aucune migration destructive, comportement sûr par défaut partout où la
règle manquait (décisions ci-dessous, réversibles).

## 1. LAN — Baseline de l'existant (rappel d'audit, 18/10/2026)

Audit complet livré avant implémentation (aucun fichier modifié à ce
stade). Constats directement exploités par cette phase :

| Élément existant (vérifié) | Rôle |
|---|---|
| `ScheduleConflictService::verifier()` — conflits groupe / enseignant / salle / capacité, chevauchement strict | **Point unique de vérité des conflits** — réutilisé, étendu additivement ; tous les flux (création, modification, déplacement, recherche de créneaux, **import**) passent par lui |
| `ScheduleExplorerService` — `disponibilites()`, `sallesLibres()`, `creneauxLibres()` | Explorations recalculées côté serveur — étendues pour refléter les déclarations |
| `ScheduleService::creerCreneau/modifierCreneau` + `sousVerrous()` (verrous ordonnés + re-vérification) | Transactions existantes inchangées — la nouvelle contrainte est contrôlée SOUS verrous comme les autres |
| Système `Parametre` + page admin `/admin/parametres` (catégories, versions, bornes) | Réutilisé pour A2 — **aucune UI de réglages à créer** |
| Pages Emplois du temps (`x-page-header`, onglets, `disponibilites`, `indisponibilites`…) | Réutilisées pour l'entrée et les vues |
| 17 fichiers de tests Schedule (dont race MySQL, verrou concurrence) | Base de non-régression |

Lacunes traitées par la Phase A (les autres restent planifiées B/C/D) :

| ID | Lacune | Preuve |
|---|---|---|
| **A1** | Aucune table de disponibilités/indisponibilités déclarées par ressource | aucune table `schedule_*` de ce type ; l'explorer ne connaît que les occupations |
| **A2** | Aucun paramètre encadrant le futur moteur (pondérations, garde-fous) | aucune clé `planning.*` dans `parametres` |

### Décisions des 3 points ouverts (comportement sûr, réversibles)

| Point ouvert | Décision Phase A | Suite |
|---|---|---|
| Équipements de salles | **Aucun champ créé** (rien d'inventé) | Phase D — extensions |
| Grille de créneaux figée vs heures libres | **Heures libres conservées** (convention historique du module, aucun schéma ajouté) | Aide à la saisie possible en B2 sans migration |
| Périmètre scénarios | N/A en A | Phase B3 — comparaison de variantes simples |

## 2. DEV — Plan minimal additif livré

### A1 — Disponibilités/indisponibilités déclarées

1. **Migration additive** `schedule_availabilities` : 3 FK nullables
   (teacher/room/group, exclusivité contrôlée applicativement), année
   optionnelle (null = toutes), jour + plage horaire, type
   (`indisponible`/`souhaitee`), fenêtre effective optionnelle, motif,
   auteur, index composites. Réversible (`down` = drop).
2. **Modèle** `ScheduleAvailability` : jours référencés depuis
   `ScheduleSlot::JOURS` (zéro duplication), libellés FR, chevauchement
   strict identique aux créneaux, couverture de période **conservatrice**
   (sans période de planning connue, la plage s'applique).
3. **Contrainte dure** dans `ScheduleConflictService` :
   `conflitsDisponibilites()` (année du planning ou plage globale, fenêtre
   effective, ressource concernée) ; `verifier()` accepte désormais
   `?Schedule $schedule` en **dernier paramètre optionnel** — appels
   existants inchangés, les 4 sites chauds passent le planning déjà chargé
   (zéro N+1).
4. **Explorer** : `disponibilites()` marque `indisponible` + libellé de la
   plage (badge orange côté vue), `sallesLibres()` exclut les salles
   déclarées bloquées, `creneauxLibres()` hérite du conflit dur via
   `verifier()`, `version()` de cache étendue (invalidation automatique).
5. **Service** `ScheduleAvailabilityService` : refus de chevauchement
   (même ressource + même type + même portée année), trace
   `IntegrationLog` (`SCHEDULE_AVAILABILITY_CREATED/UPDATED/DELETED`)
   **sans aucune PII**.
6. **CRUD HTTP** : `ScheduleAvailabilityController` + 2 FormRequests
   (validateur « exactement une ressource »), 2 vues au thème existant,
   6 routes sous préfixe fixe **avant** `{planning}` (piège de capture
   évité), entrée depuis l'index Emplois du temps. Périmètre faculté :
   salles/groupes restreints, enseignants transversaux ; garde
   `autoriserRessource()` (requête forgée hors faculté → 404) +
   `autoriserPerimetre()` (édition hors périmètre → 404).

### A2 — Paramètres bornés du futur moteur

1. **Migration additive idempotente** (patron LOOP J) : catégorie
   `planning`, 5 clés — `poids_trous` (5), `poids_equilibre_jours` (5),
   `poids_continuite` (3), `duree_max_sequence_minutes` (240),
   `iteration_max` (50000). Réversible.
2. **Service** `ScheduleGenerationSettings` : accès typé, **bornes de
   sécurité à la lecture** (un paramètre aberrant ne casse pas le moteur ;
   valeur illisible = défaut/0 sûr).
3. **UI** : onglet « Emplois du temps & Génération » généré
   automatiquement par la page Paramètres existante (méta ajoutée).
   Aucune UI nouvelle.
4. **Garde-fou de conception** : aucun paramètre de publication
   automatique n'existe — la Phase B restera soumise à validation humaine.

## 3. Risques identifiés

- **Coût de contrôle** : 1 requête indexée supplémentaire par
  vérification de conflits (uniquement si des plages existent). Les 4
  sites d'appel chauds reçoivent le planning déjà chargé → aucun N+1
  supplémentaire de planning.
- **Concurrence** : lecture seule des plages à l'intérieur des
  transactions verrouillées existantes (`sousVerrous` inchangé).
- **Comportement conservateur** : planning sans période + plage avec
  fenêtre effective → la plage s'applique (jamais d'invention inverse).
- **Réversibilité** : suppression des 2 migrations = retour à l'état
  antérieur exact (aucune colonne existante touchée).
- **Hors périmètre confirmé** : module Evaluation (examens) non
  modifié ; imports/exports existants non modifiés dans leur logique.

## 4. Contraintes respectées

Pas de `migrate:fresh`/`db:wipe` (2 migrations additives appliquées en
dev), aucun commit/push/déploiement, journalisation sans PII, écritures
transactionnelles conservées, permissions existantes réutilisées
(`schedules.view` / `schedules.manage` — aucune clé créée), module
désactivé → 404 (`module:schedule` préservé), throttle conforme aux
routes voisines.

---

## 8. Clôture des étapes (18/10/2026)

| Étape | Résultat |
|---|---|
| **LAN** | Audit rappelé (§1) ; 3 points ouverts tranchés en comportement sûr, réversibles (§1.4) |
| **DEV** | 2 migrations additives appliquées (1 s + 59 ms) · 11 fichiers créés (dont 2 vues + 1 suite de tests) · 8 modifiés (additif) · 6 routes |
| **PERFORMANCE** | Zéro N+1 (planning passé aux 4 sites chauds) ; index composites posés ; bench Schedule OK |
| **AUDIT** | `ScheduleDisponibilitesRessourcesTest` **22/22** (77 assert.) · filtre `Schedule` **154/156** (2 skipped MySQL) · filtre `Explorer\|Parametre\|MonEmploi\|EmploiDuTemps` **31/31** · pint OK · suite complète **3628 → 3589 OK / 14 échecs préexistants** (prouvé par stash sélectif : 13/13 identiques en baseline ; écart unique = édition concurrente Evaluation 19:22:42, hors périmètre) |
| **CORRECTION** | 3 corrections de boucle : opérateur `+` des tests → `array_merge`, erreurs de service mappées sur la clé `conflit` (convention `storeSlot`), nom de route de connexion admin |
| **VALIDATION** | Verdict : `PASS_WITH_RESERVATIONS` — réserves R1-R5 dans le RAPPORT |

**Verdict final : `PASS_WITH_RESERVATIONS`** — voir `RAPPORT-SMART-TIMETABLES-PHASE-A.md`.
