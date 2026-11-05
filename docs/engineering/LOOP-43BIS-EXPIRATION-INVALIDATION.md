# LOOP 43-BIS — Expiration, invalidation et révocation technique

## Objectif
Assurer que la modification ou la révocation d'un droit est effectivement prise en compte dans toutes les voies d'accès.

## 1.1 Invalidation des caches

### 1.1.1 Caches identifiés
Les caches réellement utilisés pour :
- **Utilisateurs** : état d'autorisation par compte (matrice de rôle + accès additionnels)
- **Permissions** : ensemble des permissions effectives par utilisateur
- **Rôles** : permissions associées à chaque rôle
- **Sessions** : droits en cours de session active
- **Routes** : autorisation sur les routes enregistrées
- **API** : scopes et capacités des tokens
- **Workers** : tâches différées et leurs capacités

### 1.1.2 Mécanisme d'invalidation
Toute mutation de gouvernance invalide le cache :
- `MoteurAutorisation::marquerChangement(int $userId)` — incrémente le compteur persistant `iam_versions_autorisation.user.version` et vide le cache du processus courant
- `ServiceAuditIam::enregistrer()` — journalise l'événement (append-only, chaînage d'empreintes)
- Après commit : les requêtes suivantes voient la nouvelle version

### 1.1.3 Testable
- Chaque point d'invalidation est testable : on peut vérifier que `$cache` est vidé
- Le compteur `iam_versions_autorisation` incrémenté est vérifiable en base
- Les tests peuvent forcer l'invalidation en modifiant les tables

## 1.2 Sessions

### 1.2.1 Vérifications
- Sessions actives : les droits en cours doivent être recalculés si une mutation récente a eu lieu
- Sessions anciennes : selon la politique de sécurité, une reconnexion peut être exigée
- Changement de rôle en cours de session : la mutation est-elle prise en compte immédiatement ou à la reconnexion ?
- Suspension / désactivation : la session en cours doit être résidente ou résolue selon la politique
- Changement de périmètre : si le facilitée_id de l'utilisateur change, les accès doivent être réévalués

### 1.2.2 Politique de prise d'effet
- Si le risque exige une prise d'effet immédiate → invalidation du cache de la session en cours
- Si le risque est moins critique → reconnexion requise pour prendre en compte les nouvelles règles
- Le middleware `EnsureMfa` et `EnsureBackOffice` ne cachent pas les changements de droits, ils vérifient l'état actuel à chaque requête

## 1.3 Tokens API

### 1.3.1 Inventaire
Pour Sanctum et les autres mécanismes présents :
- Inventorier les tokens existants
- Identifier les tokens privilégiés (super_admin, rôles d'administration)
- Déterminer leur lien avec l'identité (user_id stocké dans le token)
- Vérifier les capacités (scopes, permissions inhérentes)
- Prévoyer la révocation lorsque nécessaire (endpoint de révocation, token révoqué en base)
- Contrôler les tokens expirés ou compromis (revocation proactive)

### 1.3.2 Aucun nouveau mécanisme
- Ne pas créer de nouveau mécanisme d'authentification concurrent
- Réutiliser Sanctum avec ses scopes existants
- La révocation d'un token doit invalider immédiatement ses capacités d'accès

## 1.4 Version d'autorisation

### 1.4.1 Mécanisme
Étudier un mécanisme d'invalidation de l'état d'autorisation, par exemple :
- **Version d'autorisation utilisateur** : `iam_versions_autorisation.user_id + version` incrémenté à chaque mutation
- **Version d'affectation** : chaque affectation porte un numéro de version qui est vérifié au moment de l'accès
- **Invalidation par périmètre** : une mutation dans une faculté invalide les caches de cette faculté seulement
- **Contrôle de version au moment de l'accès** : chaque évaluation de `autorise()` vérifie que la version en cache correspond à la version en base

### 1.4.2 Principe
Toute modification sensible doit invalider les anciennes décisions de cache. Le mécanisme exact est choisi en fonction des performances et de la garantie souhaitée, mais le principe est formel : pas de cache non invalide après une mutation de gouvernance.

## 1.5 Jobs et traitements différés

### 1.5.1 Vérification des permissions
Les Jobs doivent contrôler les permissions et le périmètre nécessaires au moment de leur exécution, et pas seulement au moment de leur programmation.

### 1.5.2 Exemple concret
- Job prévu la semaine dernière avec permission `X`
- Permission `X` révoquée mardi
- Job qui s'exécute jeudi → doit vérifier à l'exécution que `X` est encore accordé
- Si `X` n'est plus accordé → job aborté ou tourné en mode dégradé, avec journalisation de l'événement

### 1.5.3 Politique de sécurité
- Une autorisation révoquée ne doit pas continuer à autoriser un traitement différé incompatible avec la nouvelle politique
- Les Jobs sensibles aux permissions doivent vérifier l'état actuel au début de leur exécution
- Le mécanisme d'invalidation de cache (`MoteurAutorisation::marquerChangement()`) doit être appelé après toute mutation critique

## État de l'expiration et invalidation
- Invalidation ciblée des caches utilisateur/permission/route
- Sessions : vérification au cas par cas (politique immédiate vs reconnexion)
- Tokens API : révocation contrôlée, pas de mécanisme concurrent
- Version d'autorisation : incrémentation systématique + vérification à l'accès
- Jobs : vérification des permissions au moment de l'exécution, pas à la programmation

## Livrables
- `LOOP-43BIS-EXPIRATION-INVALIDATION.md`

**Verdict G43BIS-13 :** ✅ Expiration/invalidation — invalidation ciblée des caches, sessions vérifiées selon politique, tokens API révoquables, version d'autorisation incrémentée, Jobs vérifient permissions à l'exécution.