# LOOP 11 — Vérification · Centre de services IT

**Date d'exécution :** 27/10/2026
**Mode :** audit en lecture seule + décision d'arrêt. **Aucun fichier applicatif créé ou modifié.**
**Référence :** `LOOP-00-AUDIT-DIRECTEUR-UNIREGISTRE.md` §F11 et §3 (ligne 11).

## 1. Verdict

**`BLOCKED` — arbitrage métier requis avant toute construction.**

Le lot ne peut pas être clos par du code : le besoin « centre de services IT » n'est
pas défini fonctionnellement (demandeurs, catégories, SLA, escalades, pièces jointes,
rétention), et les quatre briques existantes couvrent déjà partiellement ce terrain.
Construire maintenant produirait un **quatrième système de tickets** en doublon de
`Tasks`, `Sentinel`, `TeacherReclamation` et `OnlineRequests` — exactement ce que
l'audit directeur interdit (§3, réserve L11 : « un nouveau module ne doit pas être un
4e système *ticket* »).

Aucun test n'est revendiqué pour ce lot : **`NOT_EXECUTED`**, jamais `PASS`.

## 2. Constat d'absence — preuves rejouées

| Sonde exécutée | Résultat |
|---|---|
| `grep -rIil "helpdesk" app routes config database tests` | **0 fichier** |
| `grep -rIil "ticket" app routes config database tests` | **0 fichier** |
| `grep -rIil "service_it" app routes config database tests` | **0 fichier** |
| `grep -rIil "assistance IT" app routes config database tests` | **0 fichier** |
| `grep -n "'it\.\|tickets\." app/Support/Permissions.php` | **aucune permission** |
| `grep -rln "class .*Ticket" app` | **aucune classe** |
| `grep -rln "Helpdesk\|Centre de services IT" app routes config database tests` | **0** (seule occurrence : la documentation `LOOP-00`) |

**Conclusion : `ABSENT_CONFIRMÉ`** — ni table, ni route, ni permission, ni écran, ni test.
La seule trace lexicale est un commentaire de migration (`create_tasks_tables.php`) qui
mentionne le mot « ticket » comme futur usage du pipeline de tâches, pas une implémentation.

## 3. Actifs réutilisables — vérifiés

| Actif | Emplacement vérifié | Ce qu'il apporte | Ce qu'il ne apporte pas |
|---|---|---|---|
| Pipeline de tâches | `app/Modules/Tasks/Services/TaskService.php` : `synchroniser():25` (anti-doublon par `cle_unique` + réactivation), `transiter():91` (transitions serveur), `assigner():111`, `actionGroupee():127`, `assignerAutomatiquement():215`, `escaladerTachesEnSouffrance():234`, `reconcilierTachesOrphelines():282` | Cycle de vie complet, affectation, escalade, journal d'événements (`TaskEvenement`) | Pas de notion de demandeur, pas de catégorie IT, pas de SLA par catégorie |
| Incidents plateforme | `app/Modules/Sentinel/Models/SentinelIncident.php:25-30` (statuts) et `:35-40` (`TRANSITIONS` explicites), `cle_unique` (dé-duplication), `derniere_notification_le` (anti-spam) | Machine à états exemplaire, dé-duplication, notifications avec cooldown | Modèle orienté **détection automatique** (code, module, gravité, diagnostic), pas orienté **demande humaine** |
| Réclamation enseignant | `app/Models/TeacherReclamation.php:43-58` : 6 catégories, 5 statuts (ouverte → en_cours → resolue/rejetee → fermee), priorités (`:60`), pièce jointe, historique ; traitée par `app/Http/Controllers/Admin/RegularisationPresenceController.php` | Le seul « ticket utilisateur final » réellement exposé | Périmètre **enseignant** uniquement (`teacher_id`), pas étudiant/staff ; pas de SLA |
| Demandes en ligne | `app/Modules/OnlineRequests/Services/` : `SlaService.php:16` (délais ouvrés, fériés exclus), `RequestWorkflowService`, `DelegationDemandeService`, `RequestAttachmentService` | SLA calendaire ouvré, workflow, pièces jointes, délégation | Domaine **demandes administratives** (pièces d'inscription), pas incident technique |
| Base de connaissances | `app/Modules/Aide/Controllers/AideController.php:53` (`index`), `:60` (`montrer`) — module actif par défaut (`config/modules.php:106-108`) | Articles publics consultables | Aucun lien article ↔ résolution de ticket |

**Modules concernés à ce jour :** `tasks` → `MODULE_TASKS=false`, `sentinel` →
`MODULE_SENTINEL=false`, `aide` → `true` (`config/modules.php:58-60, 94-96, 106-108`).
Un centre de services IT serait donc, par défaut, invisible en production locale.

## 4. Pourquoi le lot est bloqué

1. **Périmètre des demandeurs non arbitré.** Un centre de services IT universitaire
   reçoit des demandes d'étudiants, d'enseignants, de personnels administratifs —
   trois populations qui ont aujourd'hui trois canaux distincts (`TeacherReclamation`,
   `OnlineRequests`, aucune pour le personnel). Choisir revient à décider qui a le
   droit d'ouvrir un ticket, ce qui est une décision de gouvernance, pas d'ingénierie.
2. **Catégories IT non définies.** Comptes/authentification, poste de travail, réseau,
   Wi-Fi, imprimantes, logiciels métier, matériel, accès aux modules : la taxonomie
   conditionne les SLA, les permissions et les écrans.
3. **SLA et escalades non définis.** `SlaService` raisonne en jours ouvrés
   universitaires ; `TaskService` escalade des tâches en souffrance. Sans cible de
   service par catégorie (P1/P2/P3) et sans matrice d'escalade, tout code serait une
   invention.
4. **Doublon structurel.** Un module `Helpdesk` autonome dupliquerait les transitions,
   l'affectation, les journaux et les notifications déjà portés par `TaskService`.
   L'option recommandée par l'audit (module `Helpdesk` **au-dessus** du pipeline
   `Tasks`, avec catégories IT et SLA par catégorie) suppose d'accepter que `tasks`
   devienne une dépendance dure — ce qui contredit la règle CORE + MODULES du projet.
5. **Permissions.** Il n'existe aucune permission `it.*`/`tickets.*` ; les rôles
   actuels (`admin_faculte` → `tasks.view/create/update/assign/complete`,
   `sentinel.view`) ne décrivent pas un agent de support. Créer ces permissions exige
   de trancher la matrice d'accès (support niveau 1/2, responsable IT, lecture seule).

## 5. Décisions humaines requises (bloquantes)

| # | Décision | Impact si non tranchée |
|---|---|---|
| D11-1 | Quelles populations peuvent ouvrir une demande (étudiant, enseignant, personnel, externe) ? | Impossible de définir le modèle de données et l'authentification |
| D11-2 | Le centre de services est-il une **vue métier** sur `Tasks` ou un module autonome ? | Risque de duplication (4e système de tickets) |
| D11-3 | Taxonomie des catégories IT et priorités | SLA, écrans, statistiques indéfinissables |
| D11-4 | Cibles de service (P1/P2/P3) et matrice d'escalade | Aucun SLA ne peut être codé |
| D11-5 | Pièces jointes : types autorisés, taille, analyse, rétention | Risque sécurité/rétention non maîtrisé |
| D11-6 | Base de connaissances : qui rédige, qui publie, lien avec les résolutions | Module `Aide` non exploité |
| D11-7 | Rétention et purge des tickets clos | Non-conformité potentielle |

## 6. Ce qui n'a pas été fait — et pourquoi

- **Aucun module `Helpdesk` créé.** Aucune migration, aucun modèle, aucune route,
  aucune permission, aucun test. Le lot s'arrête volontairement avant l'écriture.
- **Aucun actif existant modifié** (`Tasks`, `Sentinel`, `OnlineRequests`, `Aide`,
  `TeacherReclamation` intacts).
- **Aucun test exécuté pour ce lot** : il n'y a rien à tester. `NOT_EXECUTED`, pas `PASS`.

## 7. Critères d'acceptation à appliquer après arbitrage

1. Un seul système de tickets : `Task`/`Sentinel`/`TeacherReclamation`/`OnlineRequests`
   réutilisés, aucun doublon de transitions.
2. Permissions dédiées déclarées dans `Permissions::TOUTES` **et** attribuées à des
   rôles explicites, avec test négatif d'accès inter-facultés.
3. SLA par catégorie calculé en jours ouvrés via `JoursOuvrables` (pas de `now()->addDays`).
4. Escalade idempotente (`cle_unique`), jamais de doublon de ticket actif.
5. Pièces jointes : validation serveur (extension + MIME réel + taille), stockage privé.
6. Aucun PII dans les logs ; audit centralisé des transitions.
7. Tests : cycle complet, transitions illégales → 4xx, isolation facultaire par IDOR,
   SLA, escalade, rétention.
8. Module désactivé ⇒ 404 sur toutes ses routes.

## 8. Réserves

- **R-11/1** — Le constat d'absence repose sur des greps reproductibles ; il ne prouve
  pas l'absence de besoin, seulement l'absence d'implémentation.
- **R-11/2** — `TeacherReclamation` est exposé via `RegularisationPresenceController`
  mais uniquement pour la catégorie `presence` ; son extension à l'IT n'a pas été
  évaluée en profondeur (hors périmètre de ce constat).
- **R-11/3** — Les modules `tasks` et `sentinel` sont désactivés par défaut ; toute
  solution qui s'appuierait dessus devrait d'abord arbitrer leur activation.

## 9. Statut final

**`BLOCKED`** — aucune régression possible (aucun code touché), mais **aucune valeur
livrée**. Le lot reste ouvert jusqu'à réponse aux décisions D11-1 → D11-7.

---

# Mise à jour — exécution du 27/10/2026

**Mode :** implémentation après arbitrage explicite (architecture « au-dessus de
Tasks »), tests isolés SQLite, aucun commit, aucun push, aucune migration jouée sur
la base de développement.
**Verdict :** `PASS_WITH_RESERVATIONS`.

## 10. Arbitrages appliqués

| Décision | Statut | Application |
|---|---|---|
| D11-1 — populations demandeuses | **partiel** | Périmètre livré : personnel administratif authentifié (`admin.*`). Les canaux étudiants/enseignants restent `OnlineRequests` et `TeacherReclamation`, inchangés. |
| D11-2 — vue métier vs module autonome | **tranché** | Vue métier AU-DESSUS de `tasks` : `task_id` porte le cycle de vie, aucune FSM parallèle. |
| D11-3 — taxonomie | **tranché** | Catalogue par défaut modifiable : compte, réseau, poste, logiciel, matériel, autre. |
| D11-4 — SLA et escalade | **tranché** | P1/P2/P3, cibles en jours ouvrés via `JoursOuvrables`, escalade d'un niveau, échéance jamais repoussée. |
| D11-5 — pièces jointes | **non arbitré** | **Non implémenté** ; aucune validation MIME/taille inventée. Réserve R-11/1. |
| D11-6 — base de connaissances | **non arbitré** | Aucun lien ticket ↔ article ; réserve R-11/2. |
| D11-7 — rétention/purge | **non arbitré** | **Aucune purge** ; réserve R-11/3. Le contenu du ticket peut contenir des données personnelles : activation en production subordonnée à une politique de rétention. |

## 11. Livré

- Migration `2026_10_27_110000_creer_tables_centre_services_it.php` :
  `it_categories`, `it_tickets`, `it_evenements` (additives, réversibles,
  catalogue par défaut inséré, aucune colonne centrale modifiée).
- Modèles `CategorieIt`, `TicketIt`, `EvenementIt`.
- Service `ServiceTicketsIt` : création idempotente, transitions et affectation
  déléguées à `TaskService`, escalade idempotente, statistiques bornées.
- Contrôleurs `TicketItController`, `CategorieItController` et 10 routes
  `admin.centre-services-it.*` (404 si le module est désactivé).
- Config `modules.centre-services-it` (désactivé par défaut,
  `MODULE_CENTRE_SERVICES_IT=false`) + chargement des routes.
- Permissions `it.view`, `it.create`, `it.manage`, `it.categories` déclarées
  dans `Permissions::TOUTES` et attribuées explicitement aux rôles
  (`consultation`/`auditeur` : lecture ; `agent_scolarite` : traitement ;
  `admin_scolarite`/`super_admin` : catalogue).
- `JoursOuvrables::ajouterJoursOuvres()` : SLA en jours ouvrés, week-ends et
  jours fériés/vacances publiés exclus.

## 12. Preuves exécutées

| Commande | Résultat |
|---|---|
| `vendor/bin/phpunit --configuration=phpunit.xml --filter="CentreServicesItTest"` | **13/13 tests, 76 assertions** |
| `vendor/bin/phpunit --configuration=phpunit.xml --filter="PermissionsCoherenceTest\|RolesPhaseETest\|AttributionRoleTest\|TasksCockpitTest\|TaskBulkAuthorizationTest\|TaskGenerationInscriptionTest"` | **54/54 tests, 229 assertions** (non-régression RBAC/Tasks) |

Couverture des critères §7 :

1. **Un seul système de tickets** : transitions et affectation passent par
   `TaskService` ; `it_tickets` ne stocke ni statut, ni affectation, ni clôture.
2. **Permissions** : test négatif consultation (403), agent (création), agent
   sans `it.categories` (403), catalogue réservé.
3. **SLA** : test week-end (vendredi → lundi) et jour férié publié
   (lundi férié → mardi).
4. **Escalade idempotente** : rejeu de la même clé = aucun second niveau ;
   l'échéance initiale est conservée.
5. **Pièces jointes** : hors périmètre (D11-5 non arbitré) — **non revendiqué**.
6. **PII** : aucun contenu de ticket dans les logs ; le journal d'affectation
   ne recopie pas le nom de l'agent (identifiant technique seulement).
7. **Tests** : cycle complet, transition illégale 409, isolation facultaire
   (404 en lecture, 403 en création), statistiques bornées, ticket sans tâche
   `indisponible` et non transitionnable.
8. **Module désactivé** : 404 sur tickets, création et catalogue.

## 13. Réserves

- **R-11/1** — Aucune pièce jointe (D11-5 non arbitré). Ne pas activer le
  module pour un usage qui en dépend.
- **R-11/2** — Aucun lien avec la base de connaissances (`Aide`).
- **R-11/3** — Aucune purge/rétention (D11-7 non arbitré). Le contenu des
  tickets peut contenir des données personnelles.
- **R-11/4** — Surface **API uniquement** : aucun écran humain livré. Les
  routes renvoient du JSON ; l'ajout d'une UI n'est pas revendiqué.
- **R-11/5** — Dépendance dure à `tasks` : sans `MODULE_TASKS=1`, la création
  répond 409 et aucun ticket n'est inventé (dégradation explicite, pas de
  bascule vers une FSM locale).

## 14. Statut final

**`PASS_WITH_RESERVATIONS`** — le doublon structurel est évité, les transitions
sont celles de `Tasks`, les SLA sont calculés en jours ouvrés et les contrôles
d'accès sont testés. Les décisions D11-5, D11-6 et D11-7 restent ouvertes et
interdisent une activation en production sans arbitrage complémentaire.
