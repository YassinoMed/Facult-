# RAPPORT — LOOP-ROLE-ASSIGNMENT-UX-01

## Baseline

`docs/BASELINE-ROLE-ASSIGNMENT-UX-01.md`. Tests initiaux
`RolesPhaseETest|ComptesPhaseDTest` : 6/6. Règles existantes unifiées
sans affaiblissement (propre rôle, dernier super-admin, faculté forcée,
audit, `role` non mass-assignable).

## Architecture

Un seul mécanisme : `AttributionRoleService::attribuer()` (acteur,
cible, rôle, faculté, `role_actuel` optimiste, source, IP → résultat +
corrélation UUID). Appelé par `PATCH administrateurs/{id}/role`
(groupe `super_admin`) et `POST roles/attribuer` (`can:roles.manage` —
`roles.view` ne suffit jamais ; aucun privilège inventé, matrice
inchangée). Recherche bornée `GET administrateurs/recherche`
(2 lettres min., 10 résultats, sans secret). Modale Alpine partagée
(`admin/_modal-attribution-role`) : compte/actuel/cible, nb permissions,
diff ajoutées/retirées depuis le registre réel, avertissement
super-admin, faculté conditionnelle, anti-double-envoi, 409/403/422
explicites, focus/Escape/ARIA, mise à jour DOM sans rechargement
(badges, périmètre, effectifs). Repli sans JS = pages edit existantes.

## Fichiers

- Créés : `app/Services/AttributionRoleService.php`,
  `app/Http/Requests/AttribuerRoleRequest.php`,
  `resources/views/admin/_modal-attribution-role.blade.php`,
  `tests/Feature/AttributionRoleTest.php`, `docs/BASELINE-…`, ce rapport.
- Modifiés : `AdministrateurController` (+index data, +attribuerRole,
  +rechercher), `RoleController` (+index data, +attribuer),
  `routes/web.php` (+3 routes), `administrateurs/index` + `roles/index`
  (boutons, ids, bannières, include).

## Sécurité

Serveur seul décide (liste navigateur indicative). Refus journalisés
(`admin.role_refuse` warning + motif — comble la lacune des `abort_if`
muets) ; succès `admin.role_attribue` (acteur, cible, ancien/nouveau,
source, corrélation, IP ; sans secret — testé). Auto-attribution 403,
dernier super-admin 403, `super_admin` attribuable uniquement par
super-admin (politique existante, + avertissement), concurrence → 409,
idempotence sans écriture.

## Tests exécutés

| Suite | Résultat |
|---|---|
| `AttributionRoleTest` (19 : fonctionnels, autorisation, intégrité/audit) | **19/19, 74 assertions** |
| `RolesPhaseETest\|ComptesPhaseDTest` (non-régression) | **6/6** |
| `pint` (PHP ciblés, jamais Blade) | normalisé, 19/19 rejoués OK |
| `route:list` | `roles.attribuer`, `role.attribuer`, `recherche` présentes |

Défaut trouvé et corrigé en boucle : `getStatusCode()` sur
`AuthorizationException` (500 → 403 propres, 2 contrôleurs).

## Non exécutés / limites

- Parcours manuel navigateur de la modale (aucun navigateur dans
  l'environnement) — logique couverte par tests JSON + rendus 200.
- Suite complète du projet (ciblée aux périmètres comptes/rôles ;
  12 échecs connus hors périmètre sur d'autres chantiers, inchangés).
- Révocation de droits entre ouverture et soumission : couverte via le
  test 409 (rôle attendu obsolète) ; révocation du rôle de l'acteur
  lui-même non rejouée (le service revérifie `estSuperAdmin()` à
  l'exécution — même code, autre stimulus).

## Retour arrière

Supprimer les 3 routes + 2 méthodes contrôleur + include modales (les
pages retombent sur l'édition complète) ; le service et les tests sont
additifs. Aucune migration, aucun changement de schéma.

## Verdict : `PASS_WITH_RESERVATIONS` (réserve = validation manuelle
navigateur non effectuée).
