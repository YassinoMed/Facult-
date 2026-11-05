# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 13 · Déploiement

**Date :** 2026-11-03  
**Périmètre :** préconditions, migrations, configuration, permissions, caches, observabilité, rollback, Go/No-Go  
**Statut du déploiement :** **NO-GO sans réserve levée** — voir §8

---

## 1. Préconditions serveur

Les préconditions générales sont celles de `docs/GUIDE-DEPLOY.md` §2 :
PHP 8.4 avec les extensions réellement nécessaires (`gd`, `finfo`, `zip`,
`xml`, `dom`), base relationnelle supportée, utilisateur applicatif dédié,
droits d'écriture limités à `storage/` et `bootstrap/cache/`.

Aucune dépendance nouvelle n'est introduite par le LOOP Académique :

- aucune extension PHP supplémentaire ;
- aucun package Composer ajouté ;
- aucune file d'attente, aucun scheduler ou worker supplémentaire.

---

## 2. Migrations à appliquer

État local mesuré le 2026-11-03 (`php artisan migrate:status`) :
**2 migrations en attente**, toutes deux additives et réversibles.

| Ordre | Migration | Objet | `up()` | `down()` |
|---|---|---|---|---|
| 1 | `2026_10_31_150000_creer_evaluation_examen_evenements` | Journal append-only des transitions d'examen | Création de `evaluation_examen_evenements` (garde `hasTable`) | Drop de la seule table créée |
| 2 | `2026_11_01_000000_creer_campagnes_redoublement` | Persistance des campagnes de redoublement (référence, agrégats, sans PII) | Création de la table de campagnes | Drop de la seule table créée |

### 2.1 Procédure

```bash
# 1. Sauvegarde de la base avant toute écriture de schéma
#    (mysqldump / pg_dump selon le SGBD — hors périmètre code)

# 2. Vérification de l'état
php artisan migrate:status

# 3. Application (jamais interactive en production)
php artisan migrate --force

# 4. Contrôle post-migration
php artisan migrate:status
```

### 2.2 Points de contrôle

- Les deux migrations ne modifient **aucune** table existante : ni colonne
  ajoutée, ni index renommé, ni contrainte modifiée.
- `2026_10_31_150000` est idempotente par garde `Schema::hasTable`.
- Aucune donnée officielle (`etudiants`, `inscriptions_academiques`,
  `notes`, `groups`) n'est touchée.
- En cas d'échec en cours de route, `migrate:rollback --step=1` est sûr
  **tant qu'aucune donnée n'a été écrite** dans la table créée.

### 2.3 Cas particulier des tests

Les suites tournent sur SQLite `:memory:` + `RefreshDatabase` : elles
exécutent les migrations à chaque test. Leur succès **ne prouve pas** que la
base de développement ou de production est à jour. La vérification
`migrate:status` reste obligatoire.

---

## 3. Configuration

### 3.1 Modules

Le registre `config/modules.php` désactive par défaut les modules à fort
impact. Le module Évaluation doit être activé **explicitement** :

```dotenv
MODULE_EVALUATION=true
```

Le module académique de simulation temporelle est indépendant :

```dotenv
MODULE_SIMULATION_TEMPORELLE=true   # optionnel, requis seulement pour ST-14
```

Un module désactivé répond `404` via le middleware `module:*` et ses liens
de navigation ne sont pas rendus : aucune route n'est laissée ouverte par
erreur de configuration.

### 3.2 Paramètres du domaine Évaluation

| Variable | Défaut code | Rôle | Statut |
|---|---|---|---|
| `EXAM_CAPACITE_RATIO` | `0.50` | Capacité utile = `floor(nominal × ratio)` | **Paramètre, non validé institutionnellement** (`ACQ-DEC-04`, `R-02`) |
| `EXAM_CAPACITE_MAX_PROPOSITIONS` | `12` | Nombre maximal de propositions de salles | Paramètre technique |
| `EXAM_CAPACITE_MAX_SALLES` | `3` | Salles maximales par épreuve | Paramètre technique |
| `EXAM_CAPACITE_MAX_EPREUVES` | `60` | Épreuves maximales par analyse | Paramètre de performance |
| `EXAM_CAPACITE_TTL_MINUTES` | `120` | Durée de vie du cache de propositions | Paramètre technique |
| `EXAM_CAPACITE_AUTORISER_MULTI_SALLES` | `true` | Répartition multi-salles | Paramètre fonctionnel |
| `EXAM_SEPARATION_POUVOIRS_PAR_ROLE` | `true` | Séparation des pouvoirs par rôle | **Activé par défaut**, conforme à la matrice livrée |
| `EVALUATION_DATE_GEL` | `null` | Date de gel institutionnelle du planning | **Non fournie** — décision institutionnelle attendue |

Aucune de ces valeurs n'est présentée comme une règle métier validée. Toute
valeur modifiée doit être justifiée et tracée dans la configuration
d'exploitation, pas dans le code.

### 3.3 Infrastructure

| Élément | Valeur constatée (`.env` local) | Exigence de déploiement |
|---|---|---|
| `SESSION_DRIVER` | `database` | Table de sessions migrée |
| `CACHE_STORE` | `database` | Table de cache migrée |
| `QUEUE_CONNECTION` | `database` | Worker `queue:work database` supervisé |

Le worker doit tourner en continu : les campagnes de redoublement, les
exports et les notifications sont asynchrones. Sans worker, les traitements
restent en file et l'interface paraît bloquée.

---

## 4. Permissions et rôles

### 4.1 Permissions du domaine

| Domaine | Permissions |
|---|---|
| Notes | `notes.view`, `notes.create`, `notes.update`, `notes.delete`, `notes.unlock`, `notes.compostage.generate`, `notes.compostage.resolve` |
| Évaluations | `evaluations.view`, `evaluations.create`, `evaluations.update`, `evaluations.delete`, `evaluations.publish`, `evaluations.certify`, `evaluations.approve` |
| Délibérations | `deliberations.view`, `deliberations.prepare`, `deliberations.manage`, `deliberations.validate`, `deliberations.publish`, `deliberations.lock` |
| Acquis | `acquis.campagne.lancer` (action distincte de la simple lecture) |

### 4.2 Rôles de gouvernance

Le circuit 4 yeux exige des **personnes et des rôles distincts** :

- `certificateur_qualite` — certification du planning ;
- `direction_etudes` — première approbation ;
- `autorite_publication` — approbation finale et publication.

Le compte `super_admin` reste une voie de secours, jamais une voie normale.
La commande de vérification de faisabilité est :

```bash
php artisan examens:separation-roles
```

Elle doit être exécutée après l'attribution des rôles et avant la première
publication réelle.

### 4.3 Constat transverse non corrigé

Les routes des modules ne portent pas `mfa.obligatoire` (`ANOM-A9`,
`R-06`). Ce constat est **systémique** (tous les modules, pas seulement
Évaluation) : il n'a pas été corrigé localement pour ne pas créer une
incohérence d'architecture. Il conditionne un déploiement sans réserve en
environnement exposé.

---

## 5. Caches et workers

Après déploiement du code et des migrations :

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
```

- `config:cache` fige `MODULE_*` et les paramètres `EXAM_*` : toute
  modification ultérieure du `.env` exige une nouvelle exécution.
- `route:cache` est compatible : les routes utilisent des permissions
  littérales `can:*`, pas de closure.
- `queue:restart` est indispensable : les workers gardent l'ancien code en
  mémoire et ignoreraient les services corrigés (`ReglementationAcquisService`,
  `ValidationApprobationPlanService`, `PlanRedoublementService`).

---

## 6. Observabilité

### 6.1 Vérifications manuelles

```bash
# Intégrité des acquis, plans et crédits — sortie JSON pour supervision
php artisan acquis:superviser --annee=<ID> --json

# Audit en lecture seule des groupes virtuels d'examen
php artisan examens:auditer-groupes-virtuels
```

`acquis:superviser` retourne un code de sortie non nul si l'audit n'est pas
conforme : il est directement exploitable par un contrôleur de supervision.
Il ne divulgue aucune donnée personnelle dans ses sorties.

### 6.2 Tâches planifiées existantes

| Commande | Fréquence | Condition |
|---|---|---|
| `examens:auditer-groupes-virtuels` | quotidien `05:10` | `config('modules.evaluation.active') === true` |

Cette tâche est en **lecture seule** : verrou anti-concurrence et empreinte
d'idempotence, aucune publication. Si `MODULE_EVALUATION=false`, elle est
silencieusement ignorée.

### 6.3 Journaux à surveiller

| Journal | Signification |
|---|---|
| `evaluation_examen_evenements` | Transitions d'examen (append-only) ; toute correction produit une nouvelle entrée |
| `evaluation_planning_evenements` | Décisions de gouvernance et scellement |
| Audit admin centralisé | Actions administratives sensibles |
| `NoteEpreuveHistorique` | Historique des notes, sans écrasement |

Aucune PII ne doit apparaître dans les logs applicatifs. Les messages
d'autorisation ne divulguent ni effectifs ni identités.

---

## 7. Rollback

### 7.1 Rollback code (sûr)

1. Redéployer la version précédente des trois services corrigés.
2. `php artisan queue:restart`.
3. Vérifier `acquis:superviser --json` et la suite `tests/Feature/Acquis`.

Aucune donnée n'est perdue : les corrections sont des ajouts de clés de
retour et une méthode restaurée.

### 7.2 Rollback schéma (déconseillé après exploitation)

`migrate:rollback` supprimerait les tables de journaux et de campagnes, donc
les preuves de transitions et l'historique des campagnes. **Ne jamais
rollback une migration ayant reçu des données.** La restauration d'un journal
se fait par une nouvelle entrée, jamais par `UPDATE` ou suppression.

### 7.3 Point de non-retour

Le point de non-retour est la **première publication officielle** scellée par
SHA-256 : à partir de là, le planning publié et son empreinte doivent être
conservés, même en cas de rollback code.

---

## 8. Go / No-Go

### 8.1 Checklist

| # | Condition | État | Preuve |
|---|---|---|---|
| 1 | 2 migrations additives appliquées | ⏳ **À faire** | `migrate:status` : 2 `Pending` |
| 2 | `MODULE_EVALUATION=true` | ⏳ **À faire** | `.env.example` : `false` par défaut |
| 3 | Worker `queue:work database` supervisé | ⏳ **À faire** | `QUEUE_CONNECTION=database` |
| 4 | Rôles `certificateur_qualite`, `direction_etudes`, `autorite_publication` attribués | ⏳ **À faire** | `examens:separation-roles` |
| 5 | Permission `acquis.campagne.lancer` attribuée aux seuls acteurs habilités | ⏳ **À faire** | Référentiel IAM |
| 6 | Caches reconstruits + `queue:restart` | ⏳ **À faire** | §5 |
| 7 | Suites vertes (201 Acquis, 32 Éligibilité, 143 Planification, 76 Gouvernance, 27 Sécurité) | ✅ **Fait** | `10-resultats-tests.md` |
| 8 | Pint propre | ✅ **Fait** | Exécutions locales |
| 9 | Décisions D-01…D-05, D-11/D-12 tranchées | ❌ **Non** | `11-risques.md` `R-01`, `R-05` |
| 10 | Ratios de capacité et valeurs budgétaires validés (ST-10) | ❌ **Non** | `R-02`, `ACQ-DEC-04` |
| 11 | `mfa.obligatoire` sur les routes modules | ❌ **Non** | `ANOM-A9`, `R-06` |
| 12 | Concurrence validée sur MySQL réel | ❌ **Non** | `pcntl` absent, `lockForUpdate` no-op SQLite |

### 8.2 Décision

| Périmètre | Décision |
|---|---|
| Déploiement en **recette / préproduction** | ✅ **GO** — sous réserve d'exécuter les points 1 à 6 et de conserver les décisions ouvertes hors contrat |
| Déploiement en **production ouverte** | ⛔ **NO-GO** tant que les points 9 à 12 ne sont pas levés |

Le NO-GO production n'est pas un échec technique : les suites sont vertes.
Il traduit le refus de présenter comme institutionnelles des valeurs qui ne
sont pas validées, et de garantir une concurrence qui n'a pas été prouvée.

---

## 9. Verdict

**PASS_WITH_RESERVATIONS (recette) / NO-GO (production ouverte).**  
Les préconditions techniques sont maîtrisées, les migrations sont additives
et réversibles, le rollback code est sûr. Les réserves bloquantes sont des
décisions métier (`R-01`, `R-02`), un constat d'architecture transverse
(`R-06`) et une limite d'environnement (`R-07`) — jamais des règles
inventées ou des tests affaiblis.
