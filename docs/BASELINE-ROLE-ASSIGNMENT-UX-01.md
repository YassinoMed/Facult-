# BASELINE — LOOP-ROLE-ASSIGNMENT-UX-01 (inspection, zéro modification)

## Architecture découverte

- Comptes : `AdministrateurController` (groupe `super_admin`), vues
  `admin/administrateurs/{index,create,edit}`. Mutation rôle existante :
  `update()` (PUT formulaire complet) — gardes : propre rôle intouchable,
  dernier super-admin protégé (désactivation/rétrogradation/suppression),
  `faculte_id` forcé côté serveur, audit `admin.permission_changed`.
- Rôles page : `RoleController@index` (lecture, `can:roles.view` — seul
  super_admin la détient ; `roles.manage` existe, détenue par super_admin
  via TOUTES uniquement). 5 cartes, effectifs, détail dépliable.
- Registre : `Permissions::ROLES` (5) + `pourRole()` ; Gates auto
  (`AppServiceProvider:172`). `User::definirRole()` valide + persiste ;
  `role` non mass-assignable.
- Audit : `Log::channel(...)->notice('admin.*')`, sans secret. Refus :
  `abort_if` 403 SANS journalisation (lacune à combler : politique de
  journalisation des refus).
- Frontend : Blade + Tailwind v4 + Alpine global (Livewire présent dans le
  bundle mais inutilisé sur ces écrans — pas de Livewire ajouté). Fetch
  JSON conventionnée (`X-CSRF-TOKEN`, 409 conflit, cf. studio/editer).
- Différence écrans : comptes = mutation via edit complet ; rôles page =
  aucune mutation. Règles serveur à unifier dans un seul service.

## Règles d'attribution actuelles (à préserver)

1. Acteur super_admin uniquement. 2. Jamais son propre rôle. 3. Jamais
   rétrograder/désactiver/supprimer le dernier super-admin actif.
4. `super_admin` attribuable par un super-admin (politique existante —
   conservée + avertissement explicite, pas d'élargissement).
5. `admin_faculte` ⇒ `faculte_id` active obligatoire ; sinon NULL forcé.
6. Audit succès `admin.permission_changed` ; refus non journalisés.

## Tests initiaux : `RolesPhaseETest|ComptesPhaseDTest` 6/6 (46 assertions).
