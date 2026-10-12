# Ajouter un module — procédure (exemple : Attendance)

Pré-requis : lire `docs/ARCHITECTURE-MODULES.md`. Ne jamais modifier
le cœur pour un besoin métier (pas de colonne en table centrale, pas
de route dans `routes/web.php`, pas de logique en Blade).

## Étapes

1. **Déclarer** le module dans `config/modules.php` : flag
   `MODULE_XXX` (défaut `false`), entrées `nav.admin/nav.etudiant`
   (module, label, route, permission) et `widgets.admin/widgets.etudiant`
   (module, view). Ajouter la variable dans `.env.example`.
2. **Migration** : `php artisan make:migration create_xxx_table` —
   table dédiée, FK + UNIQUE + index, aucune colonne centrale touchée.
3. **Modèle** : `app/Modules/Xxx/Models/Xxx.php` (`$fillable` strict,
   relations vers `Etudiant`/`User` si besoin).
4. **Contrat** : interface dans `app/Contracts/` (méthodes métier) —
   les consommateurs dépendent du contrat, pas du service.
5. **Service** : `app/Modules/Xxx/Services/XxxService.php` qui implémente
   le contrat (transactions, events de domaine, aucun appel HTTP bloquant
   sans throttle/job).
6. **Policy** : `app/Modules/Xxx/Policies/` — permission RBAC **et**
   `peutAccederFaculte()` sur chaque action (anti-IDOR/BOLA).
7. **Permissions** : ajouter `xxx.view/create/update/delete/...` dans
   `Permissions::TOUTES` + matrices par rôle (Gates automatiques).
8. **Request** : `StoreXxxRequest` — `authorize()` = permission,
   `rules()` strictes + messages FR.
9. **Contrôleur fin** : validation → Gate/policy → service → redirect.
   Espace étudiant = résolution depuis `session('etudiant_auth_id')`.
10. **Routes** : `app/Modules/Xxx/Routes/web.php` avec middleware
    `module:xxx` (+ `auth`/`admin` ou `etudiant.auth`, `can:*`,
    `throttle`). Enregistrées par `ModuleServiceProvider`.
11. **Câbler** dans `ModuleServiceProvider` : binding contrat,
    `Gate::policy`, listeners d'events, chargement des routes, view
    composers des widgets (données du module uniquement).
12. **Vues** : `resources/views/components/` réutilisés
    (`stat-card`, `status-badge`, `empty-state`) + vues sous
    `resources/views/modules/xxx/`. Aucune requête lourde en Blade.
13. **Events** : dispatcher un event de domaine depuis le service
    (ex. `XxxEnregistre`) + listener d'audit (`audit->tracer`,
    jamais de secrets). Consommer les events du cœur si pertinent
    (`StudentRegistered`).
14. **Jobs** : tout traitement lourd (PDF, OCR, masse) en Job `ShouldQueue`,
    jamais dans la requête HTTP.
15. **API** (si besoin) : `Api/V1/XxxApiController` + Resource dédiée
    (jamais le modèle brut), scope + throttle + filtres whitelistés.
16. **Activer** : `MODULE_XXX=true` (.env local), vérifier
    `php artisan route:list --name=xxx`.
17. **Tester** : `php artisan make:test --phpunit XxxTest` — désactivé =
    404 + menus absents ; activé = CRUD, validation, isolation faculté,
    permissions, audit. Puis suite complète : `php artisan test`.

## Exemple minimal (Attendance, déjà implémenté)

`Presence` + `AttendanceService` (contrat
`EnregistrementPresenceInterface`) + `PresencePolicy` +
`StorePresenceRequest` + `PresenceController` + `Routes/web.php` +
`PresenceEnregistree`/`AuditerPresence` + vues + widgets + 10 tests.
