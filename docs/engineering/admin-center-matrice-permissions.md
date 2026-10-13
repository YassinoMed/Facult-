# Centre d'administration — Matrice rôles/permissions (13/10/2026)

> Les noms listés par le cahier sont des **PROPOSITIONS, PAS DES AUTORISATIONS**.
> Statut ⛔ = création interdite sans décision explicite approuvée.

## 1. Rôles réels (source : `Permissions.php:11-17`)

| Rôle | Périmètre | Attributs |
|---|---|---|
| `super_admin` | Global | `self::TOUTES` (toutes permissions) ; gestion comptes (groupe dédié) ; système/maintenance |
| `admin_scolarite` | Global métier | ~150 permissions métier (scolarité, évaluation, données) |
| `agent_scolarite` | Global limité | Opérations saisie, sans gestion |
| `admin_faculte` | **Restreint à SA faculté** | Scopé par policies + `faculteRestreinteId` + scope→404 |
| `consultation` | Lecture | `archives.view` etc. |

## 2. Permissions existantes utilisées par le centre (vérifiées)

| Permission | Rôle(s) porteur(s) | Ce qu'elle protège (réel) | Test |
|---|---|---|---|
| `settings.view` | (catalogue — porteurs réels dans `Permissions.php`) | `admin.parametres.index` + affichage historique | Policy + suites B/G |
| `settings.manage` | id. | `update` + `restaurer` (+ Gate contrôleur double) | 22/22 suites settings |
| `history.view` | id. | Centre audit + export CSV (`routes/web.php:896-899`) | `AuditLogTest` |
| `configuration.manage` | id. | Gabarits notifications CRUD, paramètres système transverses | `CommunicationPhase15Test` |
| (groupe `super_admin`) | middleware dédié | Comptes administrateurs, `/admin/system`, maintenance | suites Security |

**Note de risque** : `settings.manage` couvre aujourd'hui update + restauration (granularité unique). Toute scission est un changement de matrice → ⛔ décision.

## 3. Permissions candidates (cahier) — analyse, AUCUNE créée

| Candidat | Existant ? | Analyse | Risque | Décision |
|---|---|---|---|---|
| `settings.view` / `settings.manage` | ✅ existants | Rien à faire | — | — |
| `settings.history.view` | ❌ | Redondant : l'historique est déjà dans `settings.view` (vue index) ; le scinder élargirait le catalogue sans cas d'usage prouvé | Faible (complexité RBAC) | ⛔ DH-AC-02 : refuser par défaut (réouvrir sur besoin réel) |
| `settings.restore` | ❌ | La restauration crée une version comme `update` (même gravité) — scission sans bénéfice prouvé | Faible | ⛔ DH-AC-02 |
| `settings.diagnostics.view` | ❌ | Diagnostics déjà couverts : `super_admin` (système/maintenance) + Sentinel (`sentinel.view`) | Faible | ⛔ DH-AC-02 |
| `users.view/create/update/suspend/sessions.revoke` | ❌ | Gestion de comptes = **middleware `super_admin` dédié** (`routes/web.php:491+`) — la matrice est volontairement fermée ; une permission `users.*` **élargirait** potentiellement l'accès au-delà de super_admin | **ÉLEVÉ (escalade)** | ⛔ DH-AC-03 : refus recommandé — conserver le groupe super_admin |
| `users.sessions.revoke` (kill-switch AUTH-01) | ❌ | Le correctif AUTH-01 sera dans le groupe super_admin existant — AUCUNE nouvelle permission requise | — | Pas de permission nécessaire |
| `roles.view` / `permissions.view` | ❌ | La future matrice lecture seule (S4.1) peut vivre sous `configuration.manage` (convention existante des pages de référence) | Faible | ⛔ DH-AC-04 : choix du porteur (recommandation : `configuration.manage`) |
| `roles.manage` / `permissions.assign` | ❌ | **Contradiction directe avec le design** (catalogue immuable, aucun endpoint de rôle) | **CRITIQUE** | ⛔ DH-AC-03 : refus ferme recommandé |
| `mail.settings.manage` | ❌ | Seuls les paramètres NON-SECRETS iraient au registre (couverts par `settings.manage`) ; les secrets restent env | Faible | ⛔ DH-AC-05 |
| `mail.test.send` | ❌ | Test d'envoi : à désigner parmi `super_admin` (recommandé — envoi réel réseau) | Moyen (coût/abus réseau) | ⛔ DH-AC-05 : recommandation super_admin + throttle 5,1 |
| `mail.logs.view` | ❌ | Suivi des tentatives : `history.view` couvre sémantiquement (journal append-only expurgé) | Faible | ⛔ DH-AC-05 : recommandation `history.view` |
| `audit.view` | ❌ | **Existant sous le nom `history.view`** — créer `audit.view` serait un doublon | Doublon | ⛔ refus (redondance) |

## 4. Menaces instruites (adversarial, avec preuves existantes)

| Menace | État | Preuve |
|---|---|---|
| Escalade de privilèges | ✅ bloquée par construction | Aucun endpoint de rôle ; `definirRole` validé ; catalogue classe finale |
| Auto-attribution | ✅ impossible | Rôle NON mass-assignable (`User.php:18,57-65`) ; injection ignorée (tests Security) |
| Dernier super-admin | ✅ protégé | Garde destroy/reset (`AdministrateurController`, hardening 29/09) |
| Accès inter-facultés | ✅ scope→404 avant Gate | `FaculteScopeEcritureTest` (19), campagne 4/4 |
| 4-eyes sur corrections | ✅ | `dossier_corrections` (`lockForUpdate`+409) |
| Fuite de secrets via RBAC large | ◐ à surveiller | `history.view` ne doit exposer AUCUN secret — extension du test `le_journal_ne_contient_jamais_de_secrets` au mail (plan de tests) |

## 5. Règle de changement de matrice

Toute création/modification : 1) besoin prouvé documenté ; 2) analyse de risque ci-dessus actualisée ; 3) décision explicite horodatée (registre `decisions-humaines.md`) ; 4) migration de la matrice + tests RBAC ajoutés ; 5) jamais de `self::TOUTES` implicite pour un nouveau porteur restreint.
