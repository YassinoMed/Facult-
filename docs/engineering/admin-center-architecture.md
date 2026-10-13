# Centre d'administration — Architecture cible et sources de vérité (13/10/2026)

> Principe directeur : **aucune refonte** (aucun 2e moteur, aucune 2e table là où une
> existe), extension verticale des mécanismes vérifiés par les LOOP B→E.

## 1. Sources de vérité (une par domaine — jamais deux)

| Domaine | Source de vérité | Interdits |
|---|---|---|
| Valeurs de configuration métier | Table `parametres` + registre `SettingDefinition` (types, règles, défauts, sensibilité, propriétaire) | Valeurs métier codées en dur ; paramètres de modules propriétaires déplacés au registre générique (D-C-03 : `sauvegarde.*`, `sentinel.*` restent hors registre) |
| Historique des paramètres | `parametre_versions` **append-only** (restauration = nouvelle version) | UPDATE/DELETE applicatif (convention testée) |
| Secrets (SMTP, clés) | **Environnement uniquement** (`.env` / coffre) — jamais `parametres`, jamais versions, jamais logs, jamais réaffichage UI | Stockage en base, hash "réversible", affichage partiel |
| Rôles/permissions | `App\Support\Permissions` (classe finale immuable) — UI générée, jamais éditante | Table de rôles, endpoint d'édition, migration du catalogue sans décision |
| Comptes back-office | `users` + garde dernier super-admin + `creerCompteBackOffice` | Suppression automatique, soft-delete silencieux |
| Sessions | Table `sessions` (driver database) — révocation = DELETE scopé | Cookie côté client comme source |
| Modèles de messages | `notification_templates` versionnés (CRUD `configuration.manage`) | 2e système de gabarits ; gabarits Blade embarquant des secrets |
| Envoi externe | `CanalNotificationExterneInterface` (drivers : Simule par défaut, Smtp à créer) + job file `notifications` | `Mail::` en direct dans les contrôleurs ; envoi sans preuve de résultat |
| Suivi d'envoi | `mail_tentatives` (NOUVELLE, append-only, destinataire haché, erreur expurgée) | Corps de message, PII, secrets |
| Audit | `AuditLog` (IP hachée, avant/après, zéro secret) + centre `history.view` | Journal alternatif par domaine ; PII |
| Diagnostics | `MaintenanceController` + `SystemeController` + Sentinel (registre `sentinel.php`) | 4e moteur de supervision ; duplication des contrôles |

## 2. Flux normalisés (réutilisés, non recréés)

- **Écriture d'un paramètre** : `ParametreService::set()` (transaction + verrou + `attendu` + version + double invalidation cache + audit log) — tout ajout de clé passe par `SettingDefinition` + migration de graine (valeurs = défauts codés, cf. critère E point 3 appliqué à `sentinel.*`).
- **Action sensible UI** : Gate double (route `can:` + `Gate::authorize`) + throttle + confirmation JS + flash résultat.
- **Échec d'envoi** : job `tries` bornés, statut append-only, retry manuel — jamais d'exception vers l'utilisateur (pattern fail-soft du canal simule).
- **Événement sécurité** : traçage audit (acteur, cible, résultat) — contenu sans PII.

## 3. Découpage technique cible

- **Zéro nouvelle permission par défaut** : les complétions S1.1/S3.2/S4.1/S7.1/S8.1/S9.1 réutilisent `settings.view`, `history.view`, `configuration.manage`, `super_admin` (matrice dédiée pour les candidats ⛔).
- **1 migration additive** seulement (`mail_tentatives`, réversible, aucun toucher aux tables centrales) + éventuelles graines de clés `mail.*` non-secrètes (après décision).
- **1 nouveau service** (`CanalNotificationExterneSmtp`) + 1 contrôleur fin (`MailDiagnosticsController`) — le reste = étendre les contrôleurs existants.
- **Navigation** : une vue d'accueil + entrée nav unique — aucun déplacement de route (URLs stables).

## 4. Invariants à préserver (non négociables)

1. Secrets hors base/hors versions/hors logs/hors UI (tests `le_journal_ne_contient_jamais_de_secrets` à étendre au mail).
2. `parametre_versions` append-only ; restauration chaînée ; jamais d'effacement.
3. Scope→404 avant Gate (convention projet) sur toute nouvelle route paramétrée.
4. Double invalidation cache settings (immédiate + post-commit) — ne jamais la contourner.
5. Dernier super-admin indestructible ; aucun self-role-edit.
6. Aucune affirmation d'envoi sans preuve ; échec = erreur explicite.
7. CSRF sur tout POST/PATCH/DELETE web ; throttles sur toutes les mutations.
8. Tests obligatoires avant toute déclaration de complétude (plan dédié).
