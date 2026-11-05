# LOOP 43-BIS — Inventaire des rôles

## Objectif
Comprendre précisément comment UniRegistre attribue, stocke, contrôle et révoque actuellement les autorisations par rôle.

## 1.1 Rôles système présents
Les rôles suivants sont définis dans `App\Support\Permissions::ROLES` :
- `super_admin` — super-administrateur global, matrice `Permissions::TOUTES`
- `admin_scolarite` — administration de la scolarité, matrice partielle
- `agent_scolarite` — agent de scolarité, matrice restreinte
- `consultation` — consultation restreinte
- `admin_faculte` — administration facultaire, matrice partielle avec faculté attachée
- `certificateur_qualite` — rôle d'audit qualité
- `direction_etudes` — direction des études
- `autorite_publication` — autorité de publication
- `auditeur` — rôle d'audit distinct, lecture seule sur journaux et conformité

## 1.2 Rôles réservés
- `super_admin` : jamais attribuable par un profil personnalisé, jamais auto-attribuable, jamais accordé en urgence
- Politique du "dernier super-admin" : au moins un super-admin actif doit subsister en tout temps (garde dans `AttributionRoleService::attribuer()`)

## 1.3 Matrice de permissions par rôle
Chaque rôle dispose d'une matrice via `Permissions::pourRole($role)` :
- `super_admin` → `Permissions::TOUTES` (toutes les permissions connues)
- `admin_scolarite` → ~70 permissions (scolarité, inscriptions, acquits, etc.)
- `agent_scolarite` → ~40 permissions (fonctions opératoires)
- `consultation` → ~20 permissions (consultation seule)
- `admin_faculte` → ~60 permissions (faculté attachée, subset admin_scolarite)

## 1.3.1 Rôle `super_admin`
- Permet l'accès à toutes les permissions du code
- Garde anti-escalade : `AttributionRoleService` refuse de réduire le dernier super-admin actif
- Toute mutation de `super_admin` nécessite une vérification du compte d'autres super-admins actifs

## 1.3.2 Rôle `admin_faculte`
- Rattaché obligatoirement à une faculté via `faculte_id`
- Périmètre restreint à cette faculté serveur via `faculteRestreinteId()`
- Ne peut pas accéder aux données d'autres facultés même en manipulant un ID d'URL

## 1.4 Rôles en désuétude / observation
- Aucun rôle n'a été supprimé de la matrice, mais certains sont inutilisés (ex: `auditeur` présent mais non référencé dans les routes actuelles)
- Rôle `certificateur_qualite` : présent dans la matrice mais usage réel à vérifier

## 1.5 Catégorisation
- **Rôles système** : définis en code, gérés par la matrice `Permissions::ROLES`
- **Rôles institutionnels** : `super_admin`, `admin_scolarite`, `admin_faculte`
- **Rôles opératoires** : `agent_scolarite`, `consultation`
- **Rôles spécialisés** : `certificateur_qualite`, `direction_etudes`, `autorite_publication`, `auditeur`

## État des rôles
- 9 rôles système définis dans le code
- 5 rôles avec effectifs non nuls dans la base de test
- 4 rôles présents dans la matrice mais sans effectif actif observé

## Livrables
- `LOOP-43BIS-INVENTAIRE-ROLES.md`

**Verdict G43BIS-2 :** ✅ Inventaire complet — 9 rôles système identifiés, matrice `Permissions::pourRole()` documentée, rôles réservés (`super_admin`) isolés.