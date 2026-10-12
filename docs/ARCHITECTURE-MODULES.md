# Architecture modulaire — CORE + MODULES

Plateforme universitaire extensible : le **cœur reste stable** et les
fonctionnalités métier arrivent par **modules indépendants**.
Référence d'implémentation : module `Attendance` (présences).

## §1. Avant / après

Avant : toute la logique vit dans `app/Http/Controllers/*`,
`app/Models/*`, `app/Services/*`, `routes/web.php` sans frontière
métier — ajouter un domaine = toucher le cœur partout.

Après : monolithe **modulaire** (pas de microservices) — mêmes base,
authentification et déploiement, avec frontières explicites :

```text
CORE (stable, jamais modifié pour un nouveau module)
├── Authentification : guards users + session etudiant_auth_id
├── Autorisation : app/Support/Permissions.php + Gates + Policies
├── Sécurité : CSRF, throttle, uploads, headers (bootstrap/app.php)
├── Audit : AuditService → historique_academique (transverse)
├── Fichiers : stockage privé + DocumentService
├── API : /api/v1/* versionnée + scopes Sanctum
└── Câblage : config/modules.php + ModuleRegistry + ModuleServiceProvider

MODULES (un dossier par domaine sous app/Modules/)
└── Attendance/ (exemple complet et testé)
    ├── Models/Presence.php        (table dédiée `presences`)
    ├── Services/AttendanceService.php (contrat EnregistrementPresenceInterface)
    ├── Policies/PresencePolicy.php     (permission + faculté)
    ├── Requests/StorePresenceRequest.php
    ├── Controllers/PresenceController.php (fin, §18)
    ├── Routes/web.php             (verrou `module:attendance` → 404 si inactif)
    ├── Events/PresenceEnregistree.php + Listeners/AuditerPresence.php
    └── Vues : resources/views/modules/attendance/*
```

Le cœur **annonce** (`App\Events\StudentRegistered` dispatché par
`InscriptionController@store`), les modules **réagissent**
(`JournaliserInscription`, sans PII). Dépendances uniquement vers le
bas : Module → Core/Contracts, jamais Module → Module ni
Core → Module en dur.

## §2. Registre et activation

`config/modules.php` (backé par env, ex. `MODULE_ATTENDANCE`) déclare
activation, navigation et widgets. `ModuleRegistry` filtre sur
l'activation ; Blade filtre ensuite sur la permission (`@can`).
Le middleware `module:nom` (`EnsureModuleEnabled`) répond **404** si
inactif — contrôle serveur, jamais navigateur.

```bash
MODULE_ATTENDANCE=true php artisan route:list --name=presence
```

## §3. Permissions modulaires

Nouvelles permissions dans `Permissions::TOUTES` (Gates automatiques) :
`attendance.view/create/update/delete/export`, ventilées par rôle
(scolarité complète, agent/faculté sans delete, consultation lecture).
Principe maintenu : Rôle → Permissions → Policy → Resource.

## §4. Navigation et dashboards modulaires

Le layout boucle sur `ModuleRegistry::elementsNavigation('admin'|'etudiant')`
(pills + drawers) ; les dashboards bouclent sur `widgets()` et incluent
la vue du module, qui charge **ses propres données** via view composer
(`ModuleServiceProvider`) — le cœur ne charge rien pour les modules.

## §5. Composants réutilisables

`resources/views/components/` : `stat-card`, `status-badge`,
`empty-state` (pastille + libellé, classes Tailwind en littéraux).
Utilisés par le module Attendance et le dashboard scolarité.

## §6. Base de données

Table dédiée par module (`presences` : FK, UNIQUE, index), **aucune
colonne ajoutée** aux tables centrales. Migrations dans
`database/migrations/` (découverte Laravel standard).

## §7. Tests

`tests/Feature/AttendanceTest.php` (10 tests) : module désactivé =
404 + menus absents ; activé = CRUD, idempotence, validation,
isolation faculté, permissions, taux, audit transverse.
`tests/Feature/StudentDossierTest.php`,
`StudentDocumentTest.php`, `StudentDocumentSecurityTest.php`,
`StudentDocumentIsolationTest.php` (27 tests) : même matrice pour le
module StudentDossiers + upload profond, versionnage, SHA-256,
stockage privé, IDOR/BOLA, motif de rejet obligatoire.
`tests/Feature/OnlineRequestTest.php`, `OnlineRequestSecurityTest.php`,
`OnlineRequestPdfTest.php` (19 tests) : machine à états, schéma
dynamique, pièces, délais, PDF réutilisé, isolation, statuts non
injectables.
`tests/Feature/ScheduleTest.php`, `ScheduleSecurityTest.php`
(15 tests) : conflits groupe/enseignant/salle, horaires, CRUD,
annulation/restauration, exception+unicité, version/updates,
isolation groupes/facultés, permissions, invités, module off.
Les tests du cœur passent inchangés avec les modules actifs ou non.

## §8. Performance et sécurité

- Rien n'est chargé pour un module inactif (routes verrouillées,
  widgets/nav filtrés, requêtes limitées au périmètre faculté).
- Un module respecte obligatoirement : auth, RBAC, Policies, CSRF,
  validation serveur, throttle, audit, faculty scope, stockage privé.
