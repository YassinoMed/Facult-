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

## 8. Mise à jour 17/10/2026 — pilotage SMTP rendu opérant (post-baseline)

Écart corrigé, constaté en exploitation : la table `smtp_configurations` était
**inerte** — rien n'appliquait la ligne active au mailer Laravel, l'écran admin
donnait donc l'illusion d'un réglage sans effet (constat reproduit : `.env`
`MAIL_MAILER=log` ⇒ aucun courriel ne quittait la machine, OTP compris).

- `App\Services\ConfigurationSmtpService` + `App\Providers\ConfigurationSmtpServiceProvider`
  (enregistré dans `bootstrap/providers.php`) : la ligne **active** est la source de
  vérité (HTTP, commandes, workers) ; sans ligne active, `.env` fait foi ; table
  absente/base indisponible ou secret indéchiffrable ⇒ repli silencieux, jamais de
  fuite (seul `configuration_id` est journalisé).
- Durcissements au passage : `ssl` ⇒ `smtps`, `tls` ⇒ STARTTLS **exigé**
  (`require_tls`, aucun repli en clair avec identifiants), `aucun` ⇒ clair assumé
  explicitement ; `verify_peer` jamais désactivé ; `timeout` borné à 10 s
  (**réserve n° 3** : l'attente SMTP d'une requête publique est désormais plafonnée).
- Échecs d'envoi tracés **sans donnée personnelle** (classe seule) :
  `dossier_existant.code_transport_echec`, `code_non_emis` (debug), `code_echec_inattendu`,
  `code_email_transport_echec`, `code_email_nouvelle_adresse_echec`,
  `code_autonome_transport_echec`, `smtp.configuration_indechiffrable`.
  Point technique : `Symfony\…\TransportException` étend `RuntimeException` —
  la discrimination se fait donc par `TransportExceptionInterface`, sinon une panne
  SMTP réelle était classée « refus métier » et restait muette en production.
- Preuves exécutées : `ConfigurationSmtpPilotageTest` (7 tests : primauté sur `.env`,
  cartographie des chiffrements, secret illisible sans fuite, base indisponible,
  provider branché) ; `DossierExistantParcoursTest` 34/34 ; lot messagerie + parcours
  + notifications **137/137**. Envoi réel via une ligne de base (STARTTLS exigé) :
  accepté par le serveur. Table **laissée vide** en dev ⇒ repli `.env` (`smtp`).
- Réserves restantes (inchangées) : worker de file non supervisé (les notifications
  `ShouldQueue` restent en attente sans `queue:work`), `mail.logs.view` sans UI,
  réserve n° 2 (`AcheminerNotificationExterneJob:56`).

## 9. Mise à jour 17/10/2026 (soir) — refus avant envoi rendus traçables

Incident réel diagnostiqué sur le serveur de dev : « je ne reçois pas le code » sur le
parcours **autonome**. Trois causes, toutes distinctes :

- Cause 1 (corrigée en amont) : avant 20:53, `MAIL_MAILER=log` — les courriels étaient
  écrits dans `storage/logs/laravel.log`, jamais transmis (code complet retrouvé dans
  le journal, destinataire compris).
- Cause 2 (par conception) : le code du parcours « dossier existant » part **uniquement**
  vers l'adresse enregistrée du dossier ; pour les dossiers de démonstration
  (`…@demo.test`), cette adresse est **factice** ⇒ tout serveur SMTP la refuse (550) ⇒
  aucun code ne peut arriver, quelle que soit la configuration d'envoi.
- Cause 3 (l'angle mort) : deux soumissions autonomes (21:11, 21:19) avec un couple
  CIN + naissance **introuvable** (empreinte du limiteur ne correspondant à aucun
  dossier) étaient refusées **en silence total** : réponse neutre (anti-oracle, correct)
  mais **aucune trace** — ni envoi, ni journal. Vingt minutes de diagnostic à l'aveugle.
- Correctif : chaque refus **avant envoi** est tracé avec un motif fermé et zéro donnée
  personnelle — `dossier_existant.refus {"flux":"…","motif":"…"}` (flux : `autonome`,
  `changement`, `nouvelle_adresse`, `recherche` ; motifs : `limite_demandes`,
  `dossier_introuvable`, `ancienne_adresse`, `mot_de_passe`,
  `nouvelle_adresse_invalide`, `nouvelle_adresse_identique`, `statut`,
  `periode_fermee`, `adresse_deja_utilisee`, `cooldown_dossier`, `cooldown_candidat`,
  `cooldown`, `adresse_absente`). La réponse au client ne change pas d'un caractère.
- Aide corrigée : l'étape code du parcours autonome invite désormais à vérifier
  **toutes** les informations saisies ; elle ne désignait que « la nouvelle adresse »,
  ce qui orientait vers la mauvaise piste.
- Preuves exécutées : `DossierExistantAutonomeTest` + `DossierExistantParcoursTest`
  **59/59** (3 nouveaux tests : motifs `dossier_introuvable`, `mot_de_passe`, et gardes
  `adresse_deja_utilisee` / `statut`, chacun avec contexte attendu **exact** — l'ajout
  d'une donnée personnelle ferait échouer l'assertion) ; lot popup + strict **66/66**
  (`SaisieIdentificationStricteTest` : 4 échecs préexistants, identiques sur `HEAD`
  propre).
- Vérification d'ensemble (suite complète, provider actif) : **3525 tests, 14 échecs**
  = 12 préexistants prouvés sur `HEAD` + 2 d'autres chantiers en vol (versionnage API,
  supervision qualité) — aucune régression attribuable à cette mise à jour.
- Parcours autonome mené **de bout en bout** en dev : émission, acceptation SMTP par
  Gmail, puis confirmation appliquée en base (l'adresse du dossier passe à l'adresse
  candidate). Réserve de file inchangée : les deux notifications de confirmation
  (`AlerteChangementEmail`, `ConfirmationChangementEmail`) restent en attente dans
  `jobs` tant qu'aucun worker ne tourne.

## 10. Mise à jour 17/10/2026 (nuit) — emails aux moments clés du dossier

Demande produit : « envoie de mail si l'inscription était effectuée ». Trois moments
couverts, plus une condition — le tout **sans worker de file** (constat : 7 jobs
dormants dans `jobs`, dont des emails de confirmation jamais remis).

- **Dépôt d'une nouvelle inscription** (`POST /inscription` → `StudentRegistered`,
  après commit) : nouvel email `ConfirmationDepotInscription` — accusé de réception
  avec référence de dossier, envoyé à l'adresse saisie par l'étudiant. Auparavant :
  notification in-app seulement, aucun email.
- **Décision administrative** (`DossierDecisionPrise`, statuts `valide` / `rejete`) :
  nouvel email `DecisionDossierInscription` — décision, référence, motif saisi par la
  scolarité le cas échéant. Auparavant : notification in-app seulement.
- **Mise à jour « dossier existant »** : l'email
  `ConfirmationMiseAJourDossierExistant` n'est envoyé **que si au moins un champ a
  réellement changé** (`nb_champs_modifies > 0`) ; une demande vide n'engendre aucun
  email et l'écran final le dit explicitement (« aucun email de confirmation n'était
  nécessaire »). L'écran d'échec parle désormais d'un envoi « échoué » — plus de
  « en attente de nouvelle tentative » (aucun retry automatique n'existe).

Règle d'envoi : **synchrone, fail-soft, idempotent, zéro PII**.
- Synchrone (pas de `ShouldQueue`) : la file `notifications` n'étant pas servie en
  permanence, un email « en attente » serait un email perdu ; le contrat « planifié en
  file » décrit par le rapport de consolidation d'inscription est **remplacé** par
  « envoyé immédiatement ». Les notifications de dépôt / décision / confirmation
  partent dans la requête, comme les codes OTP.
- Fail-soft : toute panne de transport est neutralisée — l'inscription ou la décision
  déjà commise n'est jamais remise en cause ; trace neutre sans PII
  (`Email … non envoyé (… conservée)`) ; jamais le message brut du transport (il cite
  l'adresse du destinataire).
- Idempotent : l'email suit la clé de déduplication de la notification in-app
  (`dossier.depot:{id}`, `decision:{id}:{statut}`) — un rejeu d'événement ne double
  jamais l'envoi.
- Contenus sans donnée sensible : référence de dossier, statut, motif scolarité —
  jamais CIN, date de naissance, mot de passe ni code.

Preuves exécutées :
- `InscriptionNotificationsEcheancesTest` **12/12** — 3 nouveaux tests : email de dépôt
  (référence, zéro PII, rejeu sans double émission) ; email de validation (rejeu sans
  double) ; email de rejet (motif) + panne transport fail-soft décision conservée.
- Lot dossier-existant + notifications **125/125** — 2 tests adaptés car ils encodaient
  l'ancien contrat (« planifié en file » → « envoi immédiat sans worker » ; « email
  même sans changement » → « aucun email sans changement » + écran honnête).
- Preuve moteur en environnement dev (config réelle, transport array pour neutraliser
  SMTP) : les **3 emails** sortent immédiatement — sujets et destinataires exacts — et
  **aucune mise en file** (`jobs` : 7 avant = 7 après). Le transport SMTP réel a été
  prouvé en §8 (mail:tester + OTP réellement reçus).
- Réserve assumée (n° 4) : l'envoi synchrone rend la latence proportionnelle au nombre
  de destinataires — une validation en lot (`bulkStatut`, jusqu'à 200 dossiers) envoie
  N emails séquentiellement dans la requête d'administration. Bascule vers la file dès
  qu'un worker supervisé existe ; le comportement actuel reste correct et diagnosticable
  (échec = trace neutre, jamais de blocage).
