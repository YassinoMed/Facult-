# LOOP-MAIL-00-BASELINE — Inventaire et baseline du système de messagerie

**Projet :** UniRegistre — Laravel / Blade / Alpine.js / Vite
**Date :** 14/10/2026 · **Boucle :** LOOP-MAIL-ENTERPRISE-01, phase LAN (aucune implémentation)
**Auditeur :** session OpenCode — constats vérifiés dans le dépôt, aucune hypothèse.

## 1. État Git (baseline)

- `HEAD` : `3653387` (« MFA : codes tolérants aux espaces… »).
- Worktree **sale** au moment de l'audit (fichiers `M` relevant d'autres chantiers en cours :
  `InscriptionController`, `MfaController`, `Etudiant`, `SmtpConfigurationController`, …).
  Conséquence : toute mesure « avant » ci-dessous est prise sur worktree sale et devra être
  rejouée sur worktree propre avant validation finale.
- Aucun commit effectué par cette boucle à ce stade.

## 2. Configuration effective

| Élément | Valeur observée | Source |
|---|---|---|
| `mail.default` | `env('MAIL_MAILER', 'log')` → `log` dans `.env.example`, **`smtp` dans `.env` local** | `config/mail.php:17`, `.env` |
| SMTP local (`.env`, dev) | `smtp.gmail.com:587`, `MAIL_FROM_ADDRESS` renseignée | `.env` (valeurs non recopiées : secret potentiel) |
| Mailers déclarés | `smtp`, `ses`, `postmark`, `resend`, `sendmail`, `log`, `array`, `failover(smtp→log)`, `roundrobin` | `config/mail.php:38-99` |
| `queue.default` | `database` (`.env` et `.env.example`) | `config/queue.php:16` |
| Échecs queue | `database-uuids`, table `failed_jobs` | `config/queue.php:123-127` |
| Table `jobs` | migration `0001_01_01_000002_create_jobs_table.php` présente | `database/migrations/` |
| `after_commit` | `false` partout (comportement par défaut, non durci) | `config/queue.php` |
| Schedule mail/queue | **aucun** : `routes/console.php` ne planifie ni `queue:work`, ni `queue:retry`, ni purge de files | `routes/console.php` |

## 3. Inventaire des composants

### 3.1 Notifications (canal `mail` exclusif, `MailMessage` par défaut)

Aucune classe `Mailable`, aucun template Blade d'e-mail personnalisé
(`resources/views/{emails,mail}` inexistants ; seule `notifications/index.blade.php`
= cloche applicative, pas d'e-mail).

| Notification | File (`ShouldQueue`) | Sujet (littéral statique vérifié) |
|---|---|---|
| `CodeVerificationDossierExistant` | **non** (synchrone documenté) | Votre code de vérification — mise à jour de dossier étudiant |
| `CodeConfirmationChangementEmail` | **non** | Votre code de confirmation — changement d'adresse email |
| `CodeConfirmationNouvelleAdresse` | **non** | Confirmez votre nouvelle adresse email — dossier étudiant |
| `ConfirmationMiseAJourDossierExistant` | oui | Confirmation de mise à jour de votre dossier étudiant |
| `AlerteChangementEmail` | oui | Sécurité : votre adresse email a été modifiée |
| `ConfirmationChangementEmail` | oui | Confirmation de changement de votre adresse email |

- Sujets 100 % littéraux : **aucune surface d'injection d'en-tête**.
- Contenu OTP minimal documenté (pas de CIN, naissance, statut) — `CodeVerificationDossierExistant:11-20`.
- Codes : `random_int(100000, 999999)` (CSPRNG), hash `sha256` en cache, TTL + tentatives
  (`DossierExistantService:202-215`) ; jetons `random_bytes(24)`.

### 3.2 Points d'envoi

- `DossierExistantService` : 7 appels (`$etudiant->notify()` vers l'adresse **enregistrée**,
  `Notification::route('mail', …)` vers adresses **validées serveur**) — envois OTP **synchrones
  dans la requête HTTP** (commentaire d'intention explicite `:215`).
- `NotificationService` : persistance `AppNotification` (base) + `AcheminerNotificationExterneJob::dispatch()`
  sur file `notifications` (`tries=3`, `backoff=[5,30,120]`, statuts `envoye`/`echec`/`echec_definitif`
  tracés dans `data`, `failed()` dédié). Le canal réel est **simulé** (`CanalNotificationExterneSimule` :
  zéro réseau, garde anti-PII par regex, pannes injectables) — constat : **aucun canal SMTP réel
  n'est branché sur ce job**.
- Listeners `NotifierDepotRecu` / `NotifierDecisionDossier` : via `NotificationService`
  (base + job) — pas d'envoi SMTP direct ; idempotence documentée.
- Mot de passe oublié back-office : broker Laravel `users` (`Password::sendResetLink`) — canal mail natif.
- `TesterMessagerie` (`mail:tester`) : `Mail::raw`, pilote restreint à `smtp|sendmail|log|array`,
  erreur expurgée (classe seule), distingue `log/array` (aucun réseau) de l'envoi réel.

### 3.3 Administration SMTP existante (LOOP J, réutilisée telle quelle)

- Modèle `SmtpConfiguration` : mot de passe cast `encrypted` (clé `APP_KEY`), `$hidden`,
  unicité d'activation (`saving`), table `smtp_configurations` (migration `2026_10_13_…`).
- Contrôleur : super_admin via `can:mail.settings.manage`, mot de passe jamais réaffiché
  (vide = conservé), test **connexion seule** (`EsmtpTransport` start/stop, timeout 10 s,
  erreurs expurgées), permission distincte `can:mail.test.send`.
- Vues `admin/smtp/{index,edit}` cohérentes avec le design system.
- Permission `mail.logs.view` **déclarée** (`Support/Permissions.php:226`) mais **aucune UI
  de journaux mail trouvée** (ni route, ni vue) — écart à traiter en LOOP 04/05.

### 3.4 Journalisation et secrets

- `TesterMessagerie:46-49` : log `mail.test_echec` avec `pilote` + classe d'exception uniquement — propre.
- `AuditService::tracer` : stocke `ancienne/nouvelle_valeur` JSON — **appelants à auditer**
  (aucun appel avec OTP/secret constaté à ce stade ; vérification exhaustive reportée à la LOOP 06).
- `AcheminerNotificationExterneJob:56` : stocke `mb_substr($e->getMessage(), 0, 255)` en base —
  **risque potentiel** si un message d'exception contenait un secret (à durcir en LOOP 04).
- Aucun secret codé en dur détecté (`MAIL_PASSWORD` via env ; seul `hello@example.com` par défaut).

### 3.5 Ce qui n'existe pas (constats, pas des défaillances)

- Pas de catalogue de modèles versionnés, pas d'aperçu, pas d'envoi manuel, pas de reprise
  d'échecs mail, pas de journal d'envois, pas de file prioritaire OTP, pas de stratégie
  d'idempotence d'envoi, pas de worker supervisé, pas de doc SPF/DKIM/DMARC.
- `mail.from` = `hello@example.com` par défaut (`.env` local surchargé).
- `MAIL_SCHEME`/`MAIL_URL` à `null` en exemple ; timeout SMTP `null` (défaut Symfony).

## 4. Baseline de tests (exécutée, pas déclarée)

`MessageriePhaseCTest + SmtpConfigurationTest + SmtpUiLoopJTest +
InscriptionNotificationsEcheancesTest + NotificationTest` : **21/21 passés** (14/10/2026).
`SmtpConfigurationTest + MessageriePhaseCTest` rejoués isolément : **5/5**.
Tous les tests utilisent `Notification::fake()` (59 occurrences) — **zéro envoi réel en suite**.

## 5. Baseline de performance (référence)

- OTP critiques : **synchrones dans la réponse HTTP** (latence SMTP subie par l'utilisateur).
- 3 notifications non critiques : queued (file `default` — pas de priorité OTP dédiée).
- Job externe : `tries=3`, backoff 5/30/120 s, file `notifications`, driver `database`.
- Aucune mesure de latence/débit existante — à produire en LOOP 07.

## 6. Classification des réserves

**Défauts prouvés :** aucun bloquant constaté sur les parcours existants (suites vertes).
**Risques potentiels :**
1. `mail.logs.view` sans UI — traçabilité des envois inexistante.
2. Message d'exception stocké en base (`AcheminerNotificationExterneJob:56`) — expurgation à vérifier.
3. OTP synchrones : indisponibilité SMTP = requêtes publiques ralenties/échouées (pas de circuit-breaker).
4. `failover(smtp→log)` configuré mais jamais sélectionné par les envois OTP (`notify()` utilise le mailer `default`).
5. `after_commit=false` global — envoi possible avant commit en cas de `dispatch()` pré-commit futur.
**Configurations absentes :** worker supervisé, priorités de files, rétention, SPF/DKIM/DMARC, domaine expéditeur de production.
**Validations impossibles sans infrastructure :** SMTP réel (Gmail du `.env` local = dev, **aucun envoi réel effectué pendant cet audit**), réception, délivrabilité, comportement des workers en production.
**Améliorations facultatives :** templates Blade responsives, catalogue versionné, envoi manuel admin, dashboard.

## 7. Parcours critiques fonctionnels (non-régression de référence)

Recherche → code OTP → vérification → dossier ; triple preuve e-mail ; autonome ;
confirmation post-commit ; anti-énumération — couverts par `DossierExistant*Test`,
`LoopPopupHomeOnly04Test` (verts au dernier run large : 353/355, 2 N+1 admin hors sujet).
**Interdictionformelle pour la suite : modifier ces parcours sans preuve de non-régression rejouée.**
