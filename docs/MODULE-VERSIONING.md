# Module Versioning — Système de versionnage interne APP / DB / DEV / PROD

Ce document spécifie l'architecture, le fonctionnement et les garanties de sécurité du module interne de gestion des versions et releases de l'application universitaire.

---

## 1. Principes fondamentaux & Contrainte locale absolue

Le module Versioning est conçu pour fonctionner **entièrement localement et de manière autonome**.
Il ne dépend d'aucun service SaaS, plateforme externe (GitHub, GitLab, Docker Hub, AWS CodeDeploy, Jira), ni CI/CD externe.

Toutes les informations, métadonnées, manifests et historiques sont stockés exclusivement dans :
1. **La base de données du projet** (tables `releases`, `version_components`, `deployments`, `audit_versioning`) ;
2. **Le système de fichiers local** (`storage/app/versioning/` : manifests JSON, snapshots, backups) ;
3. **Le journal d'audit interne** (`audit_versioning`, append-only, sans secrets).

---

## 2. Modèle de versionnage multidimensionnel

Le système pilote et synchronise des dimensions distinctes, en séparant rigoureusement la version logique de compatibilité et l'état technique du schéma :

| Composant | Rôle & Source | Format |
|---|---|---|
| **APP** (`APP_VERSION`) | Version du code applicatif (`config/versioning.php` & table `version_components`) | SemVer (`X.Y.Z[-pre]`, ex: `1.0.0`) |
| **DB Release** (`DB_RELEASE`) | Version logique de compatibilité entre le code applicatif et la base (`config/versioning.php` & composant DB) | SemVer (`X.Y.Z`, ex: `1.0.0`, indépendante du nombre de migrations) |
| **DB Schema** (`DB_SCHEMA`) | État technique réel du schéma Laravel (compte de migrations, dernière migration, pending, hash registre) | Technique (`94 migrations appliquées`, hash SHA-256) |
| **DEV** (`DEV_VERSION`) | Version de la branche ou de l'itération de développement active | SemVer (`X.Y.Z-dev.N`, ex: `1.0.0-dev.1`) |
| **PROD** (`PROD_VERSION`) | Version certifiée en exploitation sur le serveur de production | SemVer (`X.Y.Z`, ex: `1.0.0`) |
| **Build** (`BUILD_VERSION`) | Numéro de compilation interne horodaté | `YYYY.MM.DD.NNN` (ex: `2026.10.04.001`) |

> **Règle architecturale fondamentale** : Le nombre de migrations (ex. 93 ou 94) ne doit JAMAIS être transformé en version applicative SemVer (`1.0.93`). Une base ayant 93 migrations appliquées est parfaitement compatible avec `APP = 1.0.0` et `DB_RELEASE = 1.0.0`.

---

## 3. Architecture des tables de données

Le module s'appuie sur quatre tables créées via les migrations `2026_10_04_170000_create_versioning_tables.php` et `2026_10_04_180000_decouple_db_release_and_db_schema.php` :

### 3.1 `releases`
Lot versionné cohérent prêt pour déploiement.
- `version` (string 50, unique) : ex. `1.0.0`
- `statut` (string 30) : état dans le cycle de vie
- `environnement` (string 20) : `local`, `recette`, `production`
- `app_version` (string 50) : version applicative SemVer
- `db_version` (string 50) : version DB logique (rétrocompatibilité)
- `db_release` (string 50, nullable) : version logique explicite de compatibilité base (SemVer)
- `db_schema` (json nullable) : métadonnées du schéma technique (`applied`, `pending`, `last`, `hash`, `texte`)
- `build_number` (string 50) : horodatage et numéro incrémental
- `manifest_hash` (string 64) : empreinte SHA-256 du manifest
- `compatibility_hash` (string 64) : empreinte cryptographique de compatibilité
- `previous_release_id` (FK nullable vers `releases`) : chaînage pour rollback
- `notes` (text nullable) : changelog et consignes
- `changements` (json nullable)
- `created_by`, `approved_by`, `deployed_by` (FKs nullable vers `users`)
- `deployed_at`, `rolled_back_at`, `locked_at`, `lock_key` (timestamps & concurrence)

### 3.2 `version_components`
Composants individuels instanciés par environnement.
- Clé unique : `(composant, environnement)`

### 3.3 `deployments`
Journal historique des opérations de déploiement et de rollback.
- `type` : `deploy` ou `rollback`
- `statut` : `en_attente`, `en_cours`, `reussi`, `echoue`
- `started_at`, `finished_at`, `duree_secondes`
- `backup_path` : chemin local du dump de sécurité
- `erreur` : message d'erreur si échec
- `meta` : détails JSON (migrations jouées, manifest généré)

### 3.4 `audit_versioning`
Journal d'audit dédié au versionnage (distinct d'`historique_academique` qui requiert un `etudiant_id`).
- Append-only, acteur, action, entité, diff JSON avant/après, IP, User-Agent.

---

## 4. Cycle de vie & Transitions contrôlées (Matrice formelle)

Une release progresse selon une matrice stricte définie dans `Release::TRANSITIONS` :

```
brouillon ──► preparee ──► testee ──► approuvee ──► en_deploiement ──► deployee
    │              │          │            │               │                │
    ▼              ▼          ▼            ▼               ▼                ▼
archivee        archivee   archivee     archivee        echouee        en_rollback
                                                                            │
                                                                            ▼
                                                                        rollbackee
```

- **Immuabilité** : Dès qu'une release atteint l'état `approuvee`, `deployee` ou `archivee`, toute modification de son contenu (code, notes, versions) est bloquée (`Release::STATUTS_IMMUTABLES`).
- **Verrouillage optimiste** : Lors des opérations sensibles, une clé `lock_key` et un horodatage `locked_at` empêchent les exécutions concurrentes.

---

## 5. Moteur de compatibilité & Détection de dérive (Drift)

### 5.1 Contrôle sémantique (`CompatibilityService`)
- Deux versions de même niveau majeur et mineur sont compatibles.
- Un écart de correctif (patch) est toléré si `delta <= config('versioning.compatibilite.tolerance_patch')`.
- Tout écart majeur ou mineur non comblé déclenche un statut `incompatible`.
- **Grille des 10 points pré-déploiement** :
  1. État de la release cible
  2. État du système actuel
  3. Compatibilité sémantique APP
  4. Cohérence interne release APP/DB
  5. Migrations en attente
  6. Configuration contrôlée
  7. Présence du lockfile des dépendances (`composer.lock`)
  8. Intégrité SHA-256 du manifest
  9. Prérequis d'environnement
  10. Décision formelle (autorisé / bloqué)

### 5.2 Détection de dérive (`DriftDetectionService`)
Compare à tout moment le manifest de la release active avec la réalité du serveur :
- Version APP déclarée vs version active
- Version DB déclarée vs migrations appliquées
- Migrations manquantes ou non exécutées
- Altération de l'empreinte de configuration
- Modification du fichier `composer.lock`
- Calcul d'un **Score d'intégrité (0 à 100%)** avec classification des écarts (Mineur / Critique).

---

## 6. Déploiement et Rollback local

### 6.1 Déploiement local sécurisé (`DeploymentService::deployer`)
1. Verrouillage optimiste de la release cible ;
2. Exécution de la grille des 10 points de conformité ;
3. Bascule du statut vers `en_deploiement` ;
4. Création d'une sauvegarde de secours préalable via la commande cœur `sauvegarde:creer` ;
5. Exécution sécurisée des migrations en attente (`Artisan::call('migrate')`) ;
6. Génération et stockage du manifest cryptographique ;
7. Mise à jour atomique en base des composants et de la release (`deployee`) ;
8. Notification des administrateurs via `NotificationService` ;
9. Enregistrement dans `deployments` et `audit_versioning`.

### 6.2 Rollback applicatif et base de données (`DeploymentService::rollback`)
1. Analyse de la release cible (antérieure) ;
2. Détection des migrations réversibles et avertissement sur les migrations destructives (`dropTable`, etc.) ;
3. Génération préalable d'un backup local de sécurité ;
4. Option de rollback des migrations DB (`migrate:rollback`) ;
5. Restauration de l'ancienne release comme `deployee` et marquage de l'actuelle comme `rollbackee` ;
6. Notification et audit complets.

---

## 7. Sécurité & RBAC

- **Isolation faculté (§74)** : Le versionnage est une infrastructure système globale. Tout utilisateur lié à une faculté (`$user->faculteRestreinteId() !== null`) se voit refuser l'accès (`ReleasePolicy`).
- **Permissions dédiées** :
  - `versioning.view` : Consultation dashboard, releases, drift, compatibilité, audit.
  - `versioning.create` : Création et préparation de release en brouillon.
  - `versioning.approve` : Validation et approbation (super_admin).
  - `versioning.deploy` : Exécution du déploiement (super_admin).
  - `versioning.rollback` : Exécution du rollback (super_admin).
  - `versioning.manage` : Suppression de brouillons et maintenance.
- **Double confirmation obligatoire (§42)** :
  - Saisie explicite du mot `DEPLOYER` pour lancer un déploiement.
  - Saisie explicite du mot `ROLLBACK` pour lancer un retour arrière.
- **Protection des secrets (§29)** : Aucune variable sensible (`APP_KEY`, mots de passe, tokens) n'est jamais écrite dans les manifests, les logs ou l'audit.

---

## 8. Commandes Artisan CLI

| Commande | Description |
|---|---|
| `php artisan versioning:status [--json]` | Affiche l'état courant complet : APP, DB, DEV, PROD, build, release active, compatibilité et drift. |
| `php artisan versioning:release [--release-version=] [--type=patch\|minor\|major] [--preparer]` | Crée et prépare une release en local avec calcul automatique de version. |
| `php artisan versioning:manifest [version] [--verifier] [--generer] [--afficher]` | Génère ou vérifie l'intégrité SHA-256 du manifest d'une release. |
| `php artisan versioning:drift [--release=] [--strict] [--json]` | Détecte les divergences système et renvoie le score d'intégrité. |
| `php artisan versioning:deploy <version> [--force] [--sans-backup]` | Exécute le déploiement local sécurisé avec vérifications et sauvegarde. |
| `php artisan versioning:rollback [version] [--avec-db] [--force]` | Affiche le plan de rollback et restaure une version antérieure. |

---

## 9. API interne (/api/v1/version)

- `GET /api/v1/version` : Sonde légère de santé publique (sans token, sans secrets).
- `GET /api/v1/version/status` : État complet sous authentification Sanctum (`throttle:api`).
- `GET /api/v1/version/manifest` : Contenu du manifest actif.
- `GET /api/v1/version/compatibility` : Rapport de compatibilité.
- `GET /api/v1/version/drift` : Rapport de dérive temps réel.
