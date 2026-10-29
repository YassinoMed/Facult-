# RAPPORT-SMART-TIMETABLES-PHASE-A — Contraintes & disponibilités déclarées (module Schedule)

**Date :** 18/10/2026 · **Protocole :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Verdict : PASS_WITH_RESERVATIONS**

---

## 1. Résumé exécutif

Le plan Smart Timetables (A → B → C → D) étant validé, la Phase A livre
la **fondation contraintes** : des plages déclarées par ressource
(enseignant, salle, groupe) — `indisponible` opposée à TOUS les flux de
planification via le point unique `ScheduleConflictService` (création,
modification, déplacement, recherche de créneaux, **import**), et des
paramètres bornés du futur moteur (Phase B). Aucune table/service
dupliqué, **aucune publication automatique possible par conception**,
module Evaluation (examens) non touché, tout est additif et réversible.

## 2. Réalisation vs plan validé (Phase A)

| Livrable du plan | Statut |
|---|---|
| A1 — table indisponibilités par ressource + CRUD + intégration `ScheduleConflictService` (contrainte dure si déclarée) | ✅ livré — contrainte dure contrôlée sous les verrous existants sur les 5 chemins d'écriture/vérification ; 6 routes CRUD ; validateur « exactement une ressource » ; chevauchement même ressource refusé |
| A1 — explorer cohérent | ✅ livré — `disponibilites()` badge « Indisponible » + libellé de la plage, `sallesLibres()` exclut les salles bloquées, `creneauxLibres()` hérite du conflit dur, cache invalidé |
| A2 — règles configurables (dures/préférences pondérées) + UI + défauts sûrs | ✅ livré — 5 paramètres `planning.generation.*` bornés et par défaut sûrs, service typé `ScheduleGenerationSettings`, onglet « Emplois du temps & Génération » dans la page Paramètres existante (zéro UI nouvelle) |
| « Ne jamais publier un planning automatiquement » | ✅ par conception — aucun chemin de publication n'existe en A ; garde-fou maintenu pour la Phase B (brouillon + validation humaine) |

## 3. Fichiers

**Créés (11 code + 2 docs) :**
- `database/migrations/2026_10_18_140000_creer_disponibilites_ressources_schedule.php` (additive, réversible)
- `database/migrations/2026_10_18_140100_parametres_generation_planning.php` (idempotente, réversible)
- `app/Modules/Schedule/Models/ScheduleAvailability.php`
- `app/Modules/Schedule/Services/ScheduleAvailabilityService.php` (chevauchement + traces)
- `app/Modules/Schedule/Services/ScheduleGenerationSettings.php` (paramètres bornés)
- `app/Modules/Schedule/Controllers/ScheduleAvailabilityController.php`
- `app/Modules/Schedule/Requests/StoreScheduleAvailabilityRequest.php` + `UpdateScheduleAvailabilityRequest.php`
- `resources/views/modules/schedule/indisponibilites/index.blade.php` + `form.blade.php`
- `tests/Feature/ScheduleDisponibilitesRessourcesTest.php` (22 tests)
- `docs/engineering/LOOP-SMART-TIMETABLES-PHASE-A.md` + ce rapport

**Modifiés (additif strict) :**
- `app/Modules/Schedule/Services/ScheduleConflictService.php` — `conflitsDisponibilites()` + paramètre optionnel `?Schedule` sur `verifier()` (rétrocompatible)
- `app/Modules/Schedule/Services/ScheduleService.php` — les 3 appels passent le planning déjà chargé (zéro N+1)
- `app/Modules/Schedule/Services/ScheduleExplorerService.php` — marquage/exclusion des plages + version de cache étendue
- `app/Modules/Schedule/Routes/web.php` — 6 routes `admin.emplois-du-temps.indisponibilites.*` (segments fixes AVANT `{planning}`)
- `app/Models/Parametre.php` — catégorie `planning`
- `app/Http/Controllers/Admin/ParametreController.php` — méta d'onglet `planning`
- `resources/views/modules/schedule/index.blade.php` — entrée de navigation
- `resources/views/modules/schedule/disponibilites.blade.php` — badges « Indisponible »

## 4. Preuves d'exécution

| Commande | Résultat |
|---|---|
| `php artisan migrate --force` | 2 migrations additives appliquées (1 s + 58,9 ms) |
| `php artisan route:list` (grep `indisponibilites`) | 6 routes enregistrées (index, create, store, edit, update, destroy) |
| `php artisan test --filter=ScheduleDisponibilitesRessourcesTest` | **22/22, 77 assertions** |
| `php artisan test --filter=Schedule` | **154/156, 620 assertions, 0 échec** (2 skipped : tests MySQL race/verrou) |
| `php artisan test --filter="Explorer\|Parametre\|MonEmploi\|EmploiDuTemps"` | **31/31, 131 assertions** |
| `php artisan test` (suite complète, avec Phase A) | **3628 tests → 3589 OK · 14 échecs · 25 skipped · 17 372 assertions** — les 14 échecs sont **préexistants** (prouvé par stash sélectif, §6 R5) ; les 22 nouveaux tests passent dans la suite complète |
| `vendor/bin/pint` (fichiers de la phase) | OK — parse error préexistant signalé hors périmètre (voir R3) |

**Couverture test livrée :** module désactivé 404 · invité redirect
connexion admin · `consultation` (view seule) lecture 200 + création 403 ·
création HTTP + trace `IntegrationLog` · exactement une ressource (0 et 2
refusées) · fin ≤ début refusée · chevauchement même ressource refusé /
adjacence autorisée · modification + suppression via HTTP · blocage de
création de créneau par indisponibilité enseignant / salle / groupe
(messages vérifiés) · plage `souhaitee` non bloquante · portée année
(autre année = aucun blocage) · fenêtre effective (hors période OK /
recouvrante bloquante) · `creneauxLibres` exclut les plages déclarées ·
explorer marque « indisponible » avec libellé · vérification de
déplacement (JSON) signale le conflit · suppression lève la contrainte ·
périmètre faculté (index filtré, édition 404, requête forgée 404,
transversalité enseignant) · paramètres par défaut + bornes appliquées.

## 5. PERFORMANCE & INTÉGRITÉ

- **Zéro N+1** : `verifier()` reçoit le planning **déjà chargé** sur ses 4
  sites d'appel (paramètre optionnel en dernière position — appels
  historiques intacts) ; le chemin d'import (boucle par ligne) passe aussi
  par `creerCreneau` → aucun code d'import modifié, contrainte héritée.
- **Index** : 4 index composites posés sur `schedule_availabilities`
  (teacher/room/group × jour, type × jour).
- **Bench Schedule** (BENCH-SCHEDULE de la suite) : seuils respectés —
  `disponibilites` p50 ≈ 147 ms / 10 requêtes, aucun dépassement.
- **Intégrité** : aucune table existante altérée, écritures
  transactionnelles conservées, plages lues dans les mêmes verrous
  ordonnés (`sousVerrous` inchangé), traces sans PII (identifiants
  techniques seuls).

## 6. Réserves (justifient PASS_WITH_RESERVATIONS)

1. **R1 — Décisions reportées (comportement sûr)** : équipements de
   salles → Phase D ; grille de créneaux figée → non créée (heures libres
   conservées, aide possible en B2 sans migration) ; périmètre scénarios
   → Phase B3.
2. **R2 — Préférences `souhaitee` inertes** : stockées et gérables, mais
   non encore exploitées (leur pondération arrive avec la Phase B, dont
   les paramètres bornés existent déjà). Aucun comportement bloquant
   introduit par elles.
3. **R3 — Parse error préexistant hors périmètre** :
   `app/Http/Livewire/Admin/Scrutins/Builder.php` (WIP scrutins du
   16/10/2026, **non suivi par git**, concaténation `.` orpheline
   ligne 172). Non touché — à corriger ou retirer par son auteur. Détecté
   aussi par `pint --dirty` ; n'affecte aucun test de la phase.
4. **R4 — Comportement conservateur documenté** : planning sans période
   (`starts_at`/`ends_at` null) + plage à fenêtre effective → la plage
   s'applique (sinon le contrôle serait impossible) ; avec période,
   seule la recouvrance bloque.
5. **R5 — Suite complète : non-régression prouvée par stash sélectif.**
   **Avec Phase A** : 3628 tests → 3589 OK, 14 échecs, 25 skipped
   (17 372 assertions) ; **baseline Phase A retirée** (stash sélectif des
   8 fichiers modifiés + migrations et suite de tests écartées) :
   3606 tests → 3568 OK, 13 échecs. Comparaison ensembliste : **les 13
   échecs sont strictement identiques dans les deux runs → préexistants**
   (WIP inscriptions/archives/évaluation : comptages de requêtes N+1,
   messages de validation attendus, sensibilités d'ordre inter-tests).
   L'unique écart
   (`ContraintesSurveillanceTest::test_page_contraintes_rend_avec_configurations_existantes`,
   relation `session` manquante sur un modèle Evaluation) est attribué à
   une **édition concurrente hors périmètre** :
   `app/Modules/Evaluation/Models/SurveillanceConstraintConfig.php`,
   modifié à **19:22:42 — entre les deux runs** — par le travail WIP
   Evaluation en cours (fichier jamais ouvert par la Phase A). Une fois
   cette édition en place et les changements Phase A restaurés, le test
   passe (classe entière 16/16 vérifiée). **Aucun échec introduit par la
   Phase A.**

## 7. Contraintes respectées

Aucun commit/push/déploiement ; migrations additives appliquées en dev
uniquement ; aucune donnée existante modifiée ; aucune PII journalisée ;
permissions existantes réutilisées (`schedules.view`/`schedules.manage`) ;
aucune clé de permission créée ; aucun doublon de table/service
(paramètres réutilisés via le système `Parametre` existant) ; module
désactivé → 404 préservé ; cours et examens séparés (Evaluation intact).
