# LOOP ST-07 — Phases 0 et 1 : audit de stabilisation du simulateur temporel

**Date :** 2026-10-25 12:18 · **Mode :** lecture seule — AUCUNE modification
effectuée. Arrêt programmé après cet audit, attente d'approbation.

## Phase 0 — Préservation et coordination

- HEAD `38ef149` inchangé ; **776 fichiers en travail** (353 M / 421 ?? / 2 A)
  — tout préservé, rien supprimé/déplacé/restauré ; aucun commit/stash/reset.
- **Écritures concurrentes ACTIVES** : `config/integrations.php` modifié il y
  a moins de 15 minutes ; rapport `LOOP-19` toujours absent ; l'arbre a crû
  de 412→421 ?? et 345→353 M depuis 10:11 (travail GouvernanceConformite).
- **Application du §6 : les tests de stabilité (empreintes/observation) sont
  ARRÊTÉS et la ré-exécution globale reste `BLOCKED_ENVIRONMENT`** tant que
  la session concurrente écrit. L'audit Phase 1, purement lecture, est mené.

## Phase 1 — Audit ST-01→ST-06 par preuves (fichiers relus, aucune exécution)

| # | Contrôle | Preuve (emplacement lu) | Classement |
|---|---|---|---|
| 1 | Couverture du hash d'entrées et clé d'idempotence | `ServiceSimulationTemporelle` : hash sha256 sur [id, **version**, criteres, contraintes, objectifs] + annee_source + faculté + graine + nb_variantes + version_moteur ; migration l.49 `UNIQUE(scenario_temporel_id, hash_entrees, graine)` ; service l.87-93 retourne l'exécution existante terminée | **CONFIRMÉ** (test réel du jour : re-run → même id, count=1, même hash_resultat) |
| 2 | Comportement après modification d'une définition | `Routes/web.php` : 0 route update/patch/put ; contrôleur sans action de révision → une définition est IMMUABLE ; toute évolution = nouveau scénario (nouveau code) → nouvel hash | **CONFIRMÉ** (immuabilité par conception — documentée ici) |
| 3 | Reproductibilité à paramètres/versions identiques | `GenerateurVariantes::pseudoAleatoire` = sha256(graine:i) (aucun mt_rand) ; horloge/moteur sans now() ; test du jour : graines égales → hash identiques, graines différentes → hash différents | **CONFIRMÉ** |
| 4 | Migration, index, contraintes, relations | `2026_10_25_040000` : 3 tables additives, `restrictOnDelete` (scénario, exécution) / `cascadeOnDelete` (snapshots), UNIQUE idempotence, index (execution_id, pas), down() complet | **CONFIRMÉ** (SQLite test) · **NON VÉRIFIÉ** sur MySQL isolé (Phase 3, base autorisée requise) |
| 5 | Cohérence agrégats LMD et périmètre facultaire | Adaptateur : jointure inscriptions→niveaux (code, ordre) filtrée par programme→facultés et statut `valide` ; périmètre vide refusé (DomainException) ; contrôleurs : `peutAccederFaculte` sur scénario ET exécution | **CONFIRMÉ** (test IDOR 403 du jour) |
| 6 | Permissions, middleware module, périmètre | Routes : `auth, admin, secondaire.verrou, module:simulation-temporelle` + `can:statistiques.view` (réutilisée) ; drapeau défaut false ; tests 403 (hors back-office, autre faculté) et 404 (module off) | **CONFIRMÉ** |
| 7 | Classement conformes / non conformes | `ClasseurScenarios` : conformes triés score desc (égalité → index), non conformes isolés avec violations ; test du jour : plancher impossible → classement vide + 3 non_conformes | **CONFIRMÉ** |
| 8 | Absence d'écritures métier réelles | Service : seuls `ScenarioTemporel::create`, `ExecutionTemporelle::create`, `SnapshotExecution::create` (l.54/140/156) ; test du jour : inscriptions immobiles, snapshots sans PII | **CONFIRMÉ** avec 1 réserve ↓ |
| 8bis | **Défaut identifié — `cree_par` non idempotent** | Contrôleur l.84 : `forceFill(['cree_par'…])->save()` s'exécute AUSSI sur l'exécution EXISTANTE retournée par la voie idempotente → l'attribut `cree_par` du premier exécutant est ÉCRASÉ par le re-demandeur (aucune corruption de données métier ; traçabilité d'auteur dégradée) | **À CORRIGER (P3)** — corriger : n'affecter `cree_par` que si `wasRecentlyCreated` |
| 9 | Tests et rapports existants | Exécutés RÉELLEMENT ce jour (pas réexécutés dans ST-07) : Unit 7/7 (99 assertions), Feature 10/10 (42), pint PASS 17 fichiers, non-régression Lot 14+ST 33/33 (195) et IA 111/111 (356), scan 0 corruption. Rapport `01-rapport-implementation.md` | **CONFIRMÉ** (daté du jour) — ré-exécution postérieure NON faite (concurrence) |

## Verdicts distincts (périmètre réellement testé uniquement)

| Composant | Verdict | Fondement |
|---|---|---|
| Moteur (ST-01) | **PASS** | 7/7 unitaires exécutés (horloge, ordre total, générateur, évaluateur, classeur, plafond) |
| API (ST-04) | **PASS** | 10/10 feature (permissions, périmètre, plafonds, idempotence, non-écriture, perf) |
| Persistance (ST-03) | **PASS_WITH_RESERVATIONS** | défaut P3 `cree_par` (8bis) ; snapshots = variante référence seule ; migration NON vérifiée sur MySQL isolé |
| Interface (Phase 2) | **NOT_EXECUTED** | conception à approuver (plan HG-01→05 déjà soumis + écrans Phase 2) |
| Non-régression globale | **BLOCKED_ENVIRONMENT** | suites ciblées vertes ce jour ; suite complète empêchée par les écritures concurrentes (protocole 03.6-03.8) |

## Plan minimal (après approbation uniquement)

1. **CORR-1 (P3)** : `cree_par` affecté seulement si `wasRecentlyCreated`
   (1 ligne contrôleur + test : re-run ne modifie plus l'auteur initial).
2. **CORR-2 (optionnel)** : persister les snapshots de la variante meilleure
   du classement en plus de la référence (si besoin métier confirmé).
3. Phase 2 (interface) : appliquer le plan `02-audit-niveau-groupe.md`
   (HG-01→05, déjà validé techniquement par audit) + maquette JSON/Blade des
   écrans (horizon, critères, cascade niveau→groupe, multi-sélection,
   classement explicable, avertissements, bandeau « aucune décision réelle »).
4. Phase 3 : après stabilisation constatée — tests ciblés, migration sur
   MySQL **isolé autorisé**, concurrence/unicité, non-régression puis suite
   globale ; toute commande documentée avec sorties réelles.

**ARRÊT ICI — aucune des modifications ci-dessus n'est engagée. Sont attendues :
l'approbation du plan minimal (dont CORR-1) et la fenêtre de stabilité pour la
Phase 3.**

---

## ADDENDUM — Implémentation approuvée (CORR-1 + HG-01/02) : RÉALISÉE

**Verdict mis à jour : `PASS_WITH_RESERVATIONS`** (23/23 tests simulateur,
39/39 avec Lot 14, pint PASS, 7 routes).

| Item | Livré | Preuves réelles |
|---|---|---|
| CORR-1 | `cree_par` affecté seulement si `wasRecentlyCreated` (contrôleur) | `test_correction_cree_par_idempotent` : re-run par un autre agent → même exécution, auteur initial préservé |
| HG-01 | Validation serveur hiérarchique dans `validerCriteres(criteres, faculte)` : niveau inexistant 422 ; groupes sans niveau 422 (incompatible cascade) ; groupe autre niveau / inactif / **sans rattachement LMD** / **hors périmètre** → 422, jamais réaffecté ; critères tracés dans `hash_entrees` (reproductibilité inchangée) | 4 tests : acceptés / autres-niveau+inactif+sans-rattachement rejetés (0 scénario créé) / sans-niveau+niveau-inconnu / **requête forgée inter-facultés 422** |
| HG-02 | `GET options/niveaux` (périmètre via `FacultePerimetre` + `Niveau→programme.faculte`) et `GET options/groupes/{niveauId}` (groupes ACTIFS du niveau, `groups.niveau_id` EXISTANT, note d'exclusion des sans-rattachement, niveau hors périmètre 403, niveau absent 404) | tests cascade : L1 listé ; seuls G-OK1/G-OK2 (actifs du niveau) retournés ; 404 niveau inexistant ; 403 inter-facultés |
| Adaptateur | `etatInitial(..., ?niveauId)` : état initial filtré par niveau sélectionné (cohérence simulation/critères) | exécuté dans le test « groupes du niveau acceptés » (200) |

Suites : Unit 7 + Feature 16 = **23 passed (163 assertions)** ; avec Lot 14 :
**39 passed (217)**. Réserves inchangées (UI Blade différée — contrat JSON
livré ; MySQL isolé et suite globale : Phase 3, bloquée par la concurrence).

---

## ST-08 — Compte rendu d'implémentation (Lots A→E)

**Verdict ST-08 : `PASS_WITH_RESERVATIONS`** — 25/25 simulateur (173
assertions), 41/41 avec Lot 14 (227), pint PASS, 8 routes, scan 0 corruption
(sélecteur d'emoji 0xFE0F retiré), zéro écriture métier.

| Lot | Périmètre réalisé | Verdict |
|---|---|---|
| A — CORR-1 | Vérifié puis renforcé : test étendu (A crée, B idempotent → même exécution, auteur initial, **hash_resultat et snapshots inchangés**) | PASS |
| B — Interface | `index()` + route UI + vue Blade/Alpine `modules/simulation-temporel/index.blade.php` : explication, bannière « Simulation uniquement — aucune décision réelle », horizon ≤ 6 / variantes ≤ 20 / delta / graine / taux (vides = « cohortes gelées » expliquées), cascade niveau→groupes (fetch `options/*`, **réinitialisation des enfants au changement de parent**), multi-sélection, états chargement/erreur/vide, classement (scores + contributions + écart vs référence), non-conformes avec violations, rappel | PASS_WITH_RESERVATIONS (E2E navigateur NON_EXECUTED — pas d'outil installé) |
| C — Filtres réels | `AdaptateurEffectifsCohortes::etatInitial(..., ?array $groupesIds)` : les groupes sélectionnés DÉTERMINENT les données (inscriptions des étudiants `groupe_id ∈ groupes`, via `Etudiant` existant) ; contrat étendu de façon compatible ; test dédié : 5 étudiants du groupe → `total_initial == 5` ; groupes vides = donnée absente explicite | PASS |
| D — Résultats | Classement explicable (contributions par objectif), écart référence/alternatives, avertissements taux manquants, violations isolées | PASS |
| E — Sécurité/tests | Permissions réutilisées + périmètre à chaque action, module off 404, requêtes forgées 422 (tests), plafonds serveur, snapshots sans PII (testé), pint, non-régression Lot 14 | PASS_WITH_RESERVATIONS |

**Fichiers modifiés** (aucun conflit concurrent : module propriétaire + 1
vue nouvelle) : adaptateur/contrat/service (passe groupes), contrôleur
(action index + année active), routes (+UI), vue, tests (+4).
**Non exécuté / réserves** : E2E navigateur (aucun outil installé) ;
migration MySQL isolée et suite globale — `BLOCKED_ENVIRONMENT` (session
concurrente Lot 19 toujours sans rapport à 12:47) ; entrée de navigation
non ajoutée (config/modules.php partagé — accès par URL directe
`admin/simulation-temporelle`).
**Preuve de non-écriture métier** : test dédié (inscriptions immobiles) +
adaptateur lecture seule + snapshots compteurs sans PII.
## ST-08 addendum : entrée sidebar 'Simulateur temporel' ajoutée à config/modules.php (nav admin, permission statistiques.view, filtrée par module actif — test dédié : présente si actif, absente si désactivé). Suites : 42/42 (229 assertions).
