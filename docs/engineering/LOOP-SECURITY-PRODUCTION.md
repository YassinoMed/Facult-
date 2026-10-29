# LOOP-SECURITY-REMEDIATION-01 — Exigences de mise en production

> Livrable 8/8 de la boucle : liste VÉRIFIABLE des exigences à satisfaire lors
> de la mise en service. Chaque exigence correspond à un correctif de la
> boucle (voir `docs/engineering/LOOP-SECURITY-REMEDIATION-01.md`) ou à une
> contrainte d'exploitation découverte pendant la remédiation.
> Les valeurs entre `<>` sont des exemples — jamais des secrets réels.

## 1. Environnement & secrets

| Variable | Valeur exigée en production | Pourquoi |
|---|---|---|
| `APP_ENV` | `production` | Coupe les replis de démonstration (seeders, canonical URL) |
| `APP_DEBUG` | `false` | Aucune trace applicative exposée |
| `APP_URL` | URL HTTPS canonique (`https://<domaine>`) | Origine des liens absolus (reset, notifications) |
| `APP_KEY` | Unique par environnement, jamais commitée | Chiffrement des colonnes (`mfa_secret`, `mfa_recovery_codes`) |
| `SESSION_DRIVER` | `database`, `redis` ou `file` (hors `array`) | Sessions persistantes et révocables |
| `SESSION_SECURE_COOKIE` | `true` | Cookie de session uniquement en HTTPS |
| `SESSION_ENCRYPT` | `true` | Contenu de session chiffré |
| `SESSION_SAME_SITE` | `lax` (ou `strict`) | Anti-CSRF en profondeur |
| `TRUSTED_PROXIES` | Réseau(x) du reverse-proxy | IP réelle des clients (allow-list supervision, logs) |
| `MAIL_MAILER` | `smtp` (jamais `log`) ; en dev/staging : `log` ou Mailpit | Un `smtp` réel en dev émet de vrais e-mails (constaté : statut `accepted_by_smtp`) |

**Séquence :** `php artisan config:cache` après modification du `.env` (les
seeders lisent `config('security.*')`, pas `env()` directement), puis
`php artisan migrate` (migration additive `*_ajouter_codes_secours_mfa_users`).

## 2. Hôtes de confiance & origine canonique

- `TRUSTED_HOSTS` : liste stricte des domaines servis (CSV, motifs
  `*.exemple.com` acceptés pour les sous-domaines). L'hôte de `APP_URL` et la
  boucle locale sont toujours acceptés ; **tout autre `Host` reçoit 400**.
- `FORCE_CANONICAL_URL=true` : toutes les URL absolues (réinitialisation de
  mot de passe, liens de notifications, webhooks) utilisent `APP_URL`, même si
  la requête arrive sur un autre hôte de la liste.
- Vérification : `curl -H 'Host: attaquant.example' https://<domaine>/` →
  `HTTP 400` ; `curl -H 'Host: <domaine>' https://<domaine>/` → `200`.

## 3. Transport, cookies & en-têtes

- TLS obligatoire (redirection 80 → 443 au niveau du proxy) ; `HSTS` est émis
  automatiquement sur les requêtes chiffrées (`max-age=31536000;
  includeSubDomains`).
- `expose_php = Off` dans `php.ini` **et** `server_tokens off;` dans nginx :
  double le masquage de `X-Powered-By` déjà fait par le middleware
  `SecurityHeadersMiddleware` (le middleware couvre PHP-FPM/artisan serve ;
  le serveur web reste responsable de son propre en-tête `Server`).
- La CSP est posée par l'application. `'unsafe-eval'`/`'unsafe-inline'` sont
  **requis par la stack actuelle** (Alpine.js embarqué par Livewire 3 +
  styles inline Tailwind). Chemin de durcissement complet : builder Alpine en
  mode CSP (`@alpinejs/csp`) puis retirer `'unsafe-eval'` — suivi hors de
  cette boucle. `upgrade-insecure-requests` n'est ajouté qu'en HTTPS.

## 4. MFA obligatoire & sessions

- `MFA_REQUIRED_ROLES` : rôles à privilèges soumis au double facteur
  (`super_admin,admin_scolarite,admin_faculte` par défaut). Un compte de ces
  rôles sans MFA est forcé à l'enrôlement (middleware `mfa.obligatoire`) ;
  l'enrôlement et la désactivation exigent le mot de passe ; les codes de
  secours sont générés à l'activation (hachés, chiffrés) — `MFA_RECOVERY_CODES_COUNT`.
- **Purge des sessions avant mise en service (procédure obligatoire) :** les
  sessions back-office créées AVANT le déploiement ne portent pas le marqueur
  `bo_auth_password` et ne déclenchent donc pas l'obligation MFA (risque
  résiduel assumé et documenté). Procédure : après déploiement, vider la table
  `sessions` (ou changer `SESSION_DRIVER`/`APP_KEY`), ce qui force une
  reconnexion — donc l'enrôlement — de tous les comptes à privilèges.
  Vérifier ensuite `ParametreService::get('securite.mfa_obligatoire_admin')`
  selon la politique retenue (extension aux agents/consultation).

## 5. Sondes de supervision

- `SUPERVISION_ALLOWED_IPS` : IP (CSV) autorisées à lire le DÉTAIL des sondes
  (`/api/v1/ready`, `/api/v1/version`). Vide par défaut = seul `status` est
  public (suffisant pour un load-balancer).
- Les sondes publiques ne révèlent ni nom de base, ni pilote de cache, ni
  chemin de stockage, ni occupation disque, ni environnement, ni versions.
- Vérification : `curl https://<domaine>/api/v1/ready` → `{"status":...,"timestamp":...}`.

## 6. Comptes initiaux & seeders

- `SEED_DEMO_DATA` : **jamais** `true` en production réelle. Sans lui, les
  seeders de démonstration (étudiants `Etudiant@123`, comptes scolarité,
  `admin@admin.com`) sont ignorés.
- `ADMIN_INITIAL_PASSWORD` (≥ 12 caractères) : exigé par `AdminSeeder` hors
  local/testing. **Retirer la variable de l'environnement après le seeding**
  et changer le mot de passe à la première connexion.
- Contrôle de sortie : `AdminSeeder` ne crée `admin@universite.ac.ma` qu'avec
  ce mot de passe ; le compte de démonstration `admin@admin.com` n'existe
  jamais hors contexte démo.
- Vérifier après déploiement : `php artisan db:show`/requête SQL confirmant
  l'absence de comptes de démo (`admin@admin.com`, comptes `Etudiant@123`).

## 7. Messagerie & files d'attente

- Workers de file actifs (`php artisan queue:work` sous supervision) : les
  notifications post-commit (confirmations, codes) doivent partir sans
  bloquer les requêtes.
- En dev/staging, **utiliser `log` ou Mailpit** — jamais le SMTP de
  production depuis un environnement de test (risque d'envoi réel et de fuite
  d'identifiants SMTP dans un `.env` partagé).
- Rotation des mots de passe applicatifs SMTP ; aucun secret dans le dépôt.

## 8. CSV/formules, exports & erreurs

- Tous les exports ad hoc neutralisent les cellules commençant par
  `=`, `+`, `-`, `@`, tabulation ou retour chariot (`CsvSerializer`). En cas
  d'ajout d'un nouvel export : passer par `neutraliserLigne()` ou/et
  `neutraliserFormule()` (couvert par `tests/Feature/Security/ExportCsvNeutralisationTest.php`).
- Les rapports d'erreurs d'import suivent la même règle.

## 9. Périmètre faculté (campagnes, groupes, destinataires)

- Un `admin_faculte` ne voit et ne cible QUE sa faculté : campagnes marquées
  par `filtres.faculte_id`, groupes par `criteres.faculte_id`, résolution de
  destinataires forcée côté serveur (`ResolutionDestinatairesService`),
  contrôles en profondeur (services + composants Livewire + policies).
- Une campagne sans marqueur faculté est GLOBALE : elle reste invisible pour
  un `admin_faculte`.
- Contrôle : `tests/Feature/Security/CampagnesPerimetreFaculteTest.php`.

## 10. Checklist de mise en service

1. `.env` conforme au §1, `APP_DEBUG=false`, `APP_URL` HTTPS.
2. `php artisan config:cache` puis `php artisan migrate --force`.
3. `TRUSTED_HOSTS` + `FORCE_CANONICAL_URL=true` posés, testés (§2).
4. `expose_php=Off`, `server_tokens off`, TLS actif (§3).
5. `MFA_REQUIRED_ROLES` revus avec le RSSI, sessions purgées (§4).
6. `SUPERVISION_ALLOWED_IPS` renseigné (ou laissé vide), sondes testées (§5).
7. Seeders : `SEED_DEMO_DATA=false`, compte admin initial créé puis mot de
   passe changé, variable retirée (§6).
8. Workers de file et planificateur actifs ; mailer de production testé sur
   une boîte interne (§7).
9. Exports CSV testés sur un fichier Excel/LibreOffice (valeur piégée
   `=1+1` non exécutée) (§8).
10. Politique MFA, codes de secours et procédure de purge des sessions
    consignées dans le registre d'exploitation (§4).
