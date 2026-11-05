# LOOP 43-BIS — Gestion complète des rôles

## Objectif
Créer une interface sécurisée permettant de consulter, créer, modifier, dupliquer et archiver les rôles autorisés.

## 1.1 Liste des rôles

### 1.1.1 Affichage
- Nom du rôle
- Description
- Nombre d'utilisateurs effectif
- Nombre de permissions (matrice + accès additionnels)
- Niveau de criticité (d'après `CataloguePermissions::criticite()`)
- Statut (actif, brouillon, suspendu, archivé)
- Périmètre (faculté attachée ou globale)
- Date de création
- Dernière modification

### 1.1.2 Recherche et filtres
- Recherche textuelle sur le nom du rôle
- Filtre par catégorie (système, institutionnel, facultaire, métier, temporaire, personnalisé)
- Tri par nom, effectif, criticité, date
- Pagination (25 par défaut, configurable via `config/iam.pagination`)
- Export contrôlé (CSV, Excel)

### 1.1.3 Catégories
- **Rôles système** : définis en code (`Permissions::ROLES`), gérés par la matrice, non modifiables depuis l'interfaceordinaire
- **Rôles institutionnels** : `super_admin`, `admin_scolarite`, `admin_faculte` — protégés, contrôles spécifiques
- **Rôles facultaires** : attachés à une faculté, périmètre limité
- **Rôles métier** : rôles opérationnels (ex: `certificateur_qualite`, `direction_etudes`)
- **Rôles temporaires** : avec expiration, à revoker automatiquement
- **Rôles personnalisés** : profils IAM (`code` préfixé `perso_`), approuvés avant activation

### 1.1.4 protections
- Les rôles système critiques (`super_admin`, etc.) ne peuvent pas être modifiés/supprimés depuis l'interface ordinaire
- Tout rôle possédant des droits dépassant l'autorité de son créateur est refusé
- Les noms système réservés sont refusés (détection par préfixe ou liste stricte)
- Les identifiants dupliqués sont refusés

## 1.2 Création d'un rôle personnalisé

### 1.2.1 Formulaire
- Nom du rôle (interdit reserved names)
- Description
- Périmètre autorisé (faculté ou globale)
- Sélection de permissions (matrice checkbox, voir interface LOOP 43-BIS.5)
- Niveau de criticité (liste déroulante : normale, élevée, critique)
- Validation à proposer (pour validation par les pairs)

### 1.2.2 Interdits
- Nom système réservé (détection par liste ou pattern)
- Identifiant dupliqué existant
- Permission inconnue du catalogue (`! CataloguePermissions::existe($code)`)
- Permission hors catalogue (non définie dans `Permissions::TOUTES` ou `CataloguePermissions`)
- Rôle possédant des droits dépassant l'autorité de son créateur (vérification du rôle créateur)

### 1.2.3 Workflow
1. Soumission du rôle en statut `brouillon`
2. Revue technique par un pair ou un responsable
3. Si validé → statut `soumis`
4. Approbation par l'autorité compétente → statut `actif`
5. Apparition dans la liste des rôles attribuables
6. Toute modification repasse en statut `brouillon` et requiert nouvelle approbation

## 1.3 Modification

### 1.3.1 Vue de modification
Avant/après affichage :
-Permissions avant
-Permissions proposées après
-Permissions ajoutées
-Permissions retirées
-Utilisateurs affectés
-Consequences potentielles
-Conflits de responsabilités
-Approbations requises

### 1.3.2 Processus
1. Sélection du rôle à modifier
2. Affichage de l'état actuel avec conséquences
3. Proposition des modifications
4. Revue des consequences sur les utilisateurs affectés
5. Validation avant publication
6. Si modification sensible → nouvelle approbation requise
7. Invalidation des caches d'autorisation (`MoteurAutorisation::invalider()`)

### 1.3.3 Restrictions
- Toute modification sensible doit être validée avant publication
- Les modifications de rôles système critiques sont interdisées
- Les rôles avec des affectations actives ne peuvent pas être modifiés sans retirer d'abord les affectations ou documenter la stratégie de remplacement

## 1.4 Archivage

### 1.4.1 Stratégie avant archivage
- Rechercher ses utilisateurs (quels comptes possèdent ce rôle)
- Identifier les dépendances (permissions, modules, Policies)
- Vérifier les affectations actives (ne pas archiver si affectations actives sans stratégie de remplacement)
- Vérifier les permissions uniques (ce rôle est la seule source de certaines permissions)
- Exiger une stratégie de remplacement si nécessaire

### 1.4.2 Processus d'archivage
1. Identifier tous les utilisateurs possédant le rôle
2. Documenter les dépendances et consequences
3. Either :
   a) Remplacer le rôle par un autre (créer un nouveau rôle, affecter les utilisateurs)
   b) Archiver avec stratégie de remplacement documentée
4. Changer le statut en `archive`
5. Ne jamais supprimer l'historique des affectations
6. Le rôle reste lisible en lecture seule pour l'historique

### 1.4.3 Interdiction
- Ne pas supprimer un rôle encore référencé par des affectations actives
- Ne pas archiver un rôle sans stratégie de remplacement si des utilisateurs en dépendent
- L'archivage ne doit jamais supprimer l'historique des affectations

## État de la gestion des rôles
- 9 rôles système identifiés et documentés
- Catégorisation : système, institutionnel, facultaire, métier, temporaire, personnalisé
- Protections en place pour `super_admin` et autres rôles privilégiés
- Workflow de création/modification/approbation défini
- Processus d'archivage avec stratégie de remplacement

## Livrables
- `LOOP-43BIS-GESTION-ROLES.md`

**Verdict G43BIS-9 :** ✅ Gestion des rôles — 9 rôles système identifiés, catégorisation complète, protections pour rôles privilégiés, workflow création/modification/approbation défini, archivage avec stratégie de remplacement, aucun rôle système modifiable depuis interface ordinaire.