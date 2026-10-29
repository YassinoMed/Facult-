# LOOP-SMART-TIMETABLES-PHASE-B — Moteur de génération branché (module Schedule)

**Date :** 19/10/2026 · **Protocole :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Plan validé :** Phases A (contraintes) → B (moteur de génération) → C (exports & qualité) → D (extensions)
**Périmètre de ce LOOP :** Phase B uniquement — B1 (pondérations déclarées + préférences `souhaitee` dans le scoring de génération), B2 (garde-fous brouillon + bornes d'options), B3 (exposition HTTP « brouillon uniquement » + historique).

---

## 0. Périmètre et principe directeur

Le moteur de résolution CSP (`ScheduleGeneratorService`) et son registre de
contraintes existent **déjà** dans le module (travaux 4/5 non commités) mais
n'étaient **reliés à aucun accès HTTP** et **ignoraient les paramètres
déclarés en Phase A** ainsi que les préférences `souhaitee`.

La Phase B **branche** donc l'existant — elle ne reconstruit rien :

1. **B1** — les pondérations Phase A (`planning.generation.*`) et les plages
   `souhaitee` (enseignant, co-enseignants, salle, groupe) entrent dans le
   scoring qualitatif du moteur ; les paramètres réellement appliqués sont
   **tracés** dans chaque génération.
2. **B2** — garde-fous d'écriture (jamais de modification d'un planning
   publié/verrouillé/archivé) et bornes d'options (itérations, timeout).
3. **B3** — exposition HTTP : simulation sans écriture + génération d'un
   **brouillon** soumis à validation humaine, avec historique consultable.

Interdits respectés : aucune table/service dupliqué (registre, modèles,
scénarios et benchmark existants réutilisés), **aucune publication
automatique possible** (garde-fou codé + message explicite), cours et
examens restent séparés (module Evaluation non touché), **aucune migration**
(aucune colonne, aucune table), permissions existantes
(`schedules.view`/`schedules.manage`) réutilisées.

## 1. LAN — Baseline de l'existant (19/10/2026)

Audit mené avant toute modification. Briques réutilisées telles quelles :

| Élément existant (vérifié) | Rôle en Phase B |
|---|---|
| `ScheduleGeneratorService` (DFS + domaines + snapshot + rollback) | **Cœur** — étendu additivement (scoring, garde-fous, bornes) |
| `ScheduleConstraintRegistry` + modèle `TimetableConstraint` | Source unique des poids/params d'axes (maître au défaut) |
| `ScheduleConstraintChecker` (audit indépendant) | Vérification post-génération, jamais modifiée dans ses règles |
| `SeanceDemande` / `TimetableGeneration` | Entrées de génération / **trace immuable** (statuts, paramètres, stats) |
| `TimetableScenario`, `ScheduleOptimizerService`, `ScheduleBenchmarkService` | Comparaison de variantes et bench existants (non modifiés) |
| Système `Parametre` + `ScheduleGenerationSettings` (Phase A) | Pondérations bornées déjà disponibles — aucune UI nouvelle |

**Baseline mesurée avant modification** (le module a grandi en parallèle le
18/10 de 21:50 à 22:14 : phases 6-12 ajoutées par un flux distinct) :

| Commande | Résultat (avant B1) |
|---|---|
| `php artisan test --filter="Schedule" --compact` | **241 tests → 239 OK / 2 skipped / 0 échec** |
| `SchedulePhase4MoteurGenerationCspTest` | 7/7 (34 assertions) — garanties moteur préexistantes |

**Lacunes traitées par la Phase B** (les autres restent planifiées C/D) :

| ID | Lacune | Preuve (avant Phase B) |
|---|---|---|
| **B1** | Le moteur ignore les pondérations Phase A et les préférences `souhaitee` (R2 de la Phase A : « stockées mais inertes ») | les params `planning.generation.*` n'étaient lus par aucun service de génération ; aucun score qualitatif tracé |
| **B2** | Le moteur écrit sans distinction d'état du planning ; aucune borne d'options | aucun garde-fou `publie`/`verrouille`/`archive` ; itérations et timeout non bornés |
| **B3** | Aucun accès HTTP au moteur (aucune route, aucune vue) | `route:list` sans entrée `generation` ; aucun historique consultable |

**Défaut latent découvert et corrigé (chemin de demande du moteur).**
`preparerDemandesDepuisEnseignements()` filtrait les matières par
`matieres.faculte_id` — **colonne inexistante** (`Schema::getColumnListing('matieres')`
= 19 colonnes, aucune `faculte_id` ; la faculté se lit matière → UE → programme).
Conséquence : hors rattachement explicite d'un enseignement au groupe
(pivot `enseignement_groupe`), le moteur ne trouvait **aucun** enseignement
et produisait une génération vide. Corrigé en réutilisant le **scope
canonique** `Matiere::pourFaculte()` (matière transversale sans UE —
décision D-A — ou UE du programme de la faculté), déjà employé par le repli
de la même méthode : zéro duplication de règle, aucune régression possible
(l'ancien filtre ne pouvait rien retourner).

## 2. DEV — livrables

### B1 — Pondérations déclarées + préférences dans le scoring

1. **Contexte qualitatif préchargé** par génération : 1 requête registre de
   contraintes + 1 requête plages `souhaitee` (portée année du planning +
   fenêtre effective), **garde de fraîcheur** clée par signature
   (`max(updated_at)` des plages souhaitées + `max(updated_at)` des
   contraintes + paramètres de pondération + jours autorisés) — aucun cache
   périmé possible, **aucune requête dans la boucle de candidats**.
2. **Scoring additif** `penalitesCandidat()` : trous étudiants et
   enseignants (par tranche de 30 min au-delà du seuil d'enchaînement de
   20 min), séquence continue (plafond `min(duree_max_sequence_minutes,
   max séquence du registre × 60)`), équilibre des jours,
   préférences `souhaitee`, surdimensionnement de salle, transit
   inter-bâtiments, continuité de matière. Le registre de contraintes
   **reste maître** : à sa valeur par défaut, les pénalités sont identiques
   à l'existant ; un axe neutralisé (poids 0) retire exactement son coût.
3. **Pondérations appliquées en facteurs** autour de leurs défauts
   documentés : `poids/5` (trous, équilibre des jours), `poids/3`
   (continuité) — une pondération Phase A ne change jamais l'ordre par
   défaut du registre tant qu'elle vaut son défaut.
4. **Préférences honorées** pour l'enseignant **et ses co-enseignants**,
   la **salle** et le **groupe** : bonus dans une plage souhaitée, pénalité
   hors plage (paramètre `penalite_cours_hors_souhait` du registre).
5. **Traçabilité** : `TimetableGeneration.parametres['ponderations']`
   (valeurs réellement appliquées) + `['preferences_souhaitees']`
   (comptes enseignants/salles/groupes) + `['dry_run']`.
6. **Correctif de qualification** : `estQualifiePour()` attend un **code**
   matière, l'appel transmettait un identifiant — un enseignant
   nouvellement affecté pouvait être écarté à tort.

### B2 — Garde-fous et bornes d'options

1. `STATUTS_NON_MODIFIABLES = ['publie', 'verrouille', 'archive']` +
   `verifierPlanningModifiable()` : toute écriture est refusée
   (`DomainException`) avec un message qui énonce la règle métier
   (« aucune publication automatique » / validation humaine requise).
2. **La simulation (dry-run) reste toujours autorisée**, y compris sur un
   planning publié — le diagnostic et le benchmark existants ne sont pas
   cassés ; le drapeau `dry_run` est tracé dans l'historique.
3. Bornes d'options : itérations plafonnées par `iteration_max`
   (paramètre Phase A, 50 000) et timeout encadré (1 à 60 secondes) ;
   les valeurs aberrantes retombent sur les défauts sûrs.

### B3 — Exposition HTTP (brouillon uniquement) + historique

1. `ScheduleGenerationController` : `index` (page de pilotage + historique
   des `TimetableGeneration`), `simuler` (rapport sans aucune écriture),
   `generer` (écrit un **brouillon** sur le planning).
2. Gardes : périmètre faculté de l'utilisateur (`peutAccederFaculte` →
   404, y compris requête forgée) + `Gate::authorize('view'|'manage')`.
   Erreurs de service mappées sur la clé de session `conflit` (convention
   des autres écritures du module) ; succès → `session('success')`.
3. 3 routes sous segments fixes **avant** `{planning}` (piège de capture
   évité) : `emplois-du-temps.generation.index` (`schedules.view`),
   `.simuler` (`schedules.manage`, `throttle:10,1`),
   `.generer` (`schedules.manage`, `throttle:5,1`) — throttle aligné sur
   les routes d'écriture voisines.
4. Vue `modules/schedule/generation.blade.php` au thème existant :
   pondérations en vigueur affichées, choix des jours, simulation,
   génération, historique (statut, séances placées, score, itérations,
   durée, mode). Entrée « Génération automatique » ajoutée depuis la page
   du planning.
5. **Aucun chemin ne publie** : la génération écrit avec le statut
   `brouillon` du planning inchangé ; la publication reste un acte humain
   séparé (contrôle pré-publication §12.19 existant).

## 3. Risques identifiés

- **Coût du scoring** : préchargé et mémoïsé par instance — mesuré constant
  (voir §8 PERFORMANCE). Aucune requête par candidat ajoutée.
- **Coût préexistant de la vérification dure par candidat** : ≈197 requêtes
  SQL par séance placée (dominées par `estPlacementSansConflit`), soit
  149 ms pour 8 séances — largement sous le timeout.
- **Fraîcheur du cache** : garde de fraîcheur incluse dans la clé → une
  plage ajoutée pendant une génération n'est jamais ignorée silencieusement.
- **Réversibilité** : aucun schéma modifié ; revenir en arrière = retirer
  les 3 routes + la vue + le lien d'entrée.
- **Édition concurrente** : le fichier moteur a été réécrit par un tiers
  pendant la phase — intégrité revérifiée en clôture (voir RAPPORT §6).

## 4. Contraintes respectées

Aucun commit/push/déploiement ; aucune migration (additive ou non) ;
partage strict cours/examens (Evaluation intact) ; aucune PII journalisée ;
permissions existantes réutilisées (aucune clé créée) ; module désactivé →
404 (`module:schedule` préservé) ; écritures de créneaux toujours soumises
aux transactions verrouillées existantes ; **aucune publication
automatique**.

---

## 8. Clôture des étapes (19/10/2026)

| Étape | Résultat |
|---|---|
| **LAN** | Audit avant modification : moteur existant non exposé, baseline filtre `Schedule` **241 tests / 239 OK / 2 skipped** ; défaut latent du chemin de demande identifié (`matieres.faculte_id` inexistante) |
| **DEV** | B1 scoring branché (pondérations + préférences, tracées) · B2 garde-fous brouillon + bornes · B3 3 routes + 1 vue + lien d'entrée · correctif scope `pourFaculte()` |
| **PERFORMANCE** | Mesures sur fixture dédiée (`ScheduleGenerationPerformanceBTest`) : 1 séance **34,2 ms / 86 requêtes** · 8 séances **149,4 ms / 1 575 requêtes** (coût préexistant de la vérification dure) · lectures des plages `souhaitee` **constantes** entre 1 et 10 séances (preuve d'absence de N+1 / de lecture par candidat) |
| **AUDIT** | `ScheduleGenerationMoteurBTest` **21/21** + `ScheduleGenerationPerformanceBTest` **2/2** = **23 tests / 111 assertions / 0 échec** · `SchedulePhase4MoteurGenerationCspTest` **7/7** (34 assertions) · filtre `Schedule` **265 → 263 OK / 2 skipped / 0 échec** (baseline 241/239/2 : delta = 21 tests B + 2 performance + 1 test ajouté par un flux concurrent) · `pint` OK |
| **CORRECTION** | 3 boucles test-debug-patch : amorçage d'un enseignement LMD réel dans le test HTTP (le moteur n'a aucune source de demandes sans enseignement), attente de la sémantique canonique `pourFaculte` pour les matières transversales, preuve de performance reformulée en *comparaison 1 vs 10 séances* (le compteur total dépend de l'exploration, la lecture des plages non) |
| **VALIDATION** | Verdict : `PASS_WITH_RESERVATIONS` — réserves R1-R6 dans le RAPPORT |

**Verdict final : `PASS_WITH_RESERVATIONS`** — voir `RAPPORT-SMART-TIMETABLES-PHASE-B.md`.
