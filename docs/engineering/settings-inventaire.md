# SETTINGS — Inventaire canonique des paramètres (LOOP A, lecture seule, 12/10/2026)

Légende portée cible : G = globale, F = facultaire (surcharge), NC = non configurable
depuis l'interface. Sensibilité : PUBLIQUE / INTERNE / SENSIBLE / SECRET.
Statut implémentation : IMPLÉMENTÉ ou PROPOSITION (préfixe `⇒`).

## 1. Socle existant : table `parametres` (IMPLÉMENTÉ)

Modèle `app/Models/Parametre.php` (`valeurTypee()` : texte/entier/booleen/liste) ;
service `app/Services/ParametreService.php` (`get`/`set`/`valeursParCategorie`,
cache `rememberForever` + invalidation, log `parametre.modifie` sans secret) ;
contrôleur `app/Http/Controllers/Admin/ParametreController.php`
(`index`/`update`, validation par type) ; policy `app/Policies/ParametrePolicy.php`
(`settings.view`/`settings.manage`) ; routes `admin.parametres.index/update`
(`routes/web.php:920-923`, throttle 20/1 en écriture) ; vue
`resources/views/admin/parametres/index.blade.php` ; migration
`2026_09_30_000004_create_parametres_table.php` (+ `2026_10_07_110016` sauvegardes).
Rôles : `settings.*` dans `TOUTES` uniquement → **super_admin seul**
(`app/Support/Permissions.php:206-207`, matrice `:281`, `:437` sans settings).

| Clé | Type | Portée actuelle | Cible | Sensibilité | Validation | Tests |
|---|---|---|---|---|---|---|
| `etablissement.nom` | texte | G | G | PUBLIQUE | string max:2000 (contrôleur) | Suites parametres (cf. `grep parametre tests/`) |
| `etablissement.fuseau` | texte | G | G | INTERNE | idem | idem |
| `academique.seuil_validation` | entier | G | G (indicative ; règles Évaluation font foi) | INTERNE | integer | idem |
| `assiduite.seuil_alerte` | entier | G | G | INTERNE | integer | idem |
| `notifications.purge_jours` | entier | G | G | INTERNE | integer | `PurgerNotifications` |
| `interface.pagination` | entier | G | G (indicatif) | PUBLIQUE | integer | idem |
| `sauvegarde.plan.*` (10 clés) | mixte | G | G | INTERNE | `BackupSchedulerService` | `BackupPlanning*Test` |

## 2. Configuration Laravel + env (IMPLÉMENTÉ, source = déploiement)

| Clé / groupe | Fichier | Portée | Cible | Sensibilité | Consommateurs |
|---|---|---|---|---|---|
| `APP_DEBUG`, `APP_KEY`, `APP_URL` | `config/app.php` + env | déploiement | NC | SECRET (KEY) | Framework |
| `SESSION_*`, `AUTH_PASSWORD_TIMEOUT`, `SANCTUM_*` | `config/session.php`, `auth.php`, `sanctum.php` | déploiement | NC | SENSIBLE | Auth, cookies, tokens |
| `DB_*`, `REDIS_*`, `MAIL_*`, `AWS_*`, `QUEUE_*`, `CACHE_*` | `config/database.php` etc. | déploiement | NC | SECRET (mots de passe/clés) | Infra |
| `INTEGRATION_WEBHOOK_SECRET` | `config/integrations.php:12` | déploiement | NC | SECRET | `WebhookVerifier` |
| `INTEGRATION_THROTTLE_*`, `UPLOAD_MAX`, `IMPORT_MAX_ROWS`, `RETENTION_*` | `config/integrations.php:18-47` | G technique | NC (ops) | INTERNE | Throttles, parsers, purge |
| `INS_FUSEAU`, `INS_CONTACT_*`, `INS_RDV_*`, `INS_DOCUMENTS_REQUIS_*` | `config/inscriptions.php:27-76` | G | G (contact/RDV/FAQ affichables ; matrice pièces = E métier) | PUBLIQUE/PUBLIQUE | Wizard, FAQ, `DocumentsRequisService` |
| `IDENTITY_OCR_DRIVER`, timeouts, binaires | `config/identity.php:24-37` | G technique | NC (ops) | INTERNE | Moteurs OCR |
| `MODULE_*` (14) | `config/modules.php:16-87` | G feature-flags | G piloté (jamais DB sans décision) | INTERNE | `EnsureModuleEnabled`, `ModuleRegistry` |
| `affectations.scoring/seuils` | `config/affectations.php:14-28` | G | G (exposés au cockpit `affectations.automatique.parametres`) | INTERNE | `AffectationEngine` |
| `sauvegarde.*`, `SAUVEGARDE_CLE` | `config/sauvegardes.php:16-47` | G/déploiement | NC (clé = SECRET coffre) | SECRET | `Sauvegarder`, `BackupSchedulerService` |
| `documents-officiels` (15 types), `communication`, `online-requests`, `sentinel.checks`, `schedule`, `student-dossiers`, `documents_requis_*` | `config/*.php` | G | G si exposés (catalogues), E si règles métier | INTERNE | Services/modules dédiés |

## 3. Paramètres en base hors `parametres` (IMPLÉMENTÉ, propriétaires métier)

| Clé | Table / config | Portée | Cible | Sensibilité |
|---|---|---|---|---|
| Contraintes surveillances (+ `actif_*`, révisions) | `surveillance_constraint_configs` (migration `2026_10_08_170000` + `2026_10_09_091647`) | F+session+système (résolveur priorité) | F (déjà UI CRUD, `ContraintesSurveillance`) | INTERNE |
| Gabarits notifications versionnés | `notification_templates` (`2026_10_01_130058`) | G | G | INTERNE |
| Préférences notifications | `notification_preferences` + `user_preferences` (`2026_10_01_130059`, `2026_10_01_114732`) | Individuelle | Individuelle (jamais SETTINGS global) | INTERNE |
| Favoris / vues | `user_favoris`, vues sauvegardées (`2026_10_01_114731`) | Individuelle | Individuelle | PUBLIQUE |
| Périodes d'inscription | `registration_periods` | G/portée faculté-programme | G+F (déjà UI `admin.periodes.*`) | PUBLIQUE (bornes affichées) |
| Règles évaluation/délibération | tables évaluation + `TypeEvaluation` | F/programme | E (module Évaluation, versionnées) | INTERNE |
| Décisions ARB-001…009 | `docs/pedagogie/registre-arbitrages-lmd.md` | Institutionnelle | F (décision humaine, jamais valeur devinée) | INTERNE |

## 4. Propositions (PROPOSITION, à valider en LOOP B)

- ⇒ `etablissement.nom_court/logo/coordonnees` (fichiers : privé + MIME + UUID, cf. §F mission).
- ⇒ Surcharge F documentée uniquement si preuve métier (aucun B sans preuve — état actuel : **zéro surcharge F prouvée**, tout est G ou individuel).
- ⇒ Historique versionné `parametre_versions` + restauration créant une version (inexistant : `set()` écrase + log ; `updated_by` seul).
- ⇒ Concurrence : `lockForUpdate` + 409 sur écriture (inexistant côté paramètres).
