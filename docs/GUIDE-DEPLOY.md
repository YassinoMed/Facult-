# 🚀 Guide de déploiement en production — UniRegistre

> Application Laravel 13 / PHP 8.4, MySQL, Blade + Tailwind v4 + Alpine.js, Sanctum, modules OCR.
> Public cible : administrateur système / devops. Distribution de référence : **Ubuntu 24.04 LTS**.
> Documents liés : `docs/SECURITY.md`, `docs/AUDIT-SECURITE.md`, `docs/IDENTITY-SCAN.md`,
> `docs/API.md`, `docs/ARCHITECTURE-MODULES.md`.

## Table des matières

1. [Vue d'ensemble — architecture cible](#1-vue-densemble--architecture-cible)
2. [Prérequis serveur](#2-prérequis-serveur)
3. [Installation pas à pas](#3-installation-pas-à-pas)
4. [Configuration .env de production](#4-configuration-env-de-production)
5. [Nginx & TLS](#5-nginx--tls)
6. [Optimisations Laravel & script de déploiement](#6-optimisations-laravel--script-de-déploiement)
7. [File d'attente & planificateur](#7-file-dattente--planificateur)
8. [OCR en production](#8-ocr-en-production)
9. [Checklist sécurité production](#9-checklist-sécurité-production)
10. [Sauvegardes & restauration](#10-sauvegardes--restauration)
11. [Mises à jour applicatives](#11-mises-à-jour-applicatives)
12. [Supervision & santé](#12-supervision--santé)
13. [Dépannage production](#13-dépannage-production)
14. [Annexe : .env complet et deploy.sh](#14-annexe--env-complet-et-deploysh)

---

## 1. Vue d'ensemble — architecture cible

```
                    Internet (HTTPS uniquement)
                              │
                        ┌─────▼─────┐
                        │   Nginx   │  TLS 443 (Let's Encrypt), redirection 80 → 443
                        │  (proxy)  │  sert public/, client_max_body_size 12m
                        └─────┬─────┘
                              │ FastCGI (socket unix)
                      ┌───────▼────────┐
                      │  PHP-FPM 8.4   │  user www-data — Laravel 13
                      │ /var/www/…     │  storage/ privé hors webroot
                      └───┬─────┬──────┘
                ┌─────────┘     └──────────┐
        ┌───────▼───────┐        ┌─────────▼──────────┐
        │   MySQL 8     │        │  storage/app/…     │
        │ (utf8mb4)     │        │  private/ :        │
        │ sessions+jobs │        │  archives,         │
        │ (driver db)   │        │  identity-scan/tmp │
        └───────────────┘        │  (temporaires),    │
                                 │  integrations …    │
                                 └────────────────────┘
        ┌─────────────────────────────────────────────┐
        │ Supervisor : worker `queue:work database`   │  imports/exports lourds
        │ Cron : `php artisan schedule:run` chaque    │  (jobs app/Jobs/Io/*)
        │ minute → purge horaire exports/imports (§82)│
        └─────────────────────────────────────────────┘
```

Points structurants :

- **Tout le stockage applicatif est privé** : les documents sensibles (archives académiques,
  dossiers étudiants, fichiers d'import/export, scans d'identité temporaires) vivent sous
  `storage/app/private/` — **jamais** dans `public/`. Les téléchargements passent par des
  contrôleurs autorisés et journalisés.
- **Un seul serveur suffit** au démarrage : Nginx + PHP-FPM + MySQL + worker + cron cohabitent.
- **La base sert aussi de bus** : sessions, cache et files d'attente sont en driver `database`
  par défaut (variables `.env`). Redis est conseillé dès que la charge API monte (voir §4/§7).
- **Le scan d'identité est optionnel et fail-soft** : moteur OCR par défaut `null`
  (repli saisie manuelle) — aucune dépendance Python/OCR n'est requise pour un déploiement
  de base (voir §8).

---

## 2. Prérequis serveur

### 2.1 Système & dimensionnement

| Élément | Minimum | Recommandé |
|---|---|---|
| OS | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS à jour |
| vCPU / RAM | 2 vCPU / 4 Go | 4 vCPU / 8 Go (si OCR ppocr local) |
| Disque | 40 Go SSD | 80 Go SSD + volume dédié sauvegardes |
| Réseau | IPv4 publique + DNS A/AAAA vers le domaine | idem |

### 2.2 Paquets

```bash
sudo apt update && sudo apt -y upgrade

# PHP 8.4 + extensions réellement nécessaires à l'application
# (php-gd : prétraitement multi-variantes du pipeline CIN ; finfo : validation MIME
#  réelle des uploads ; zip/xml : lectures XLSX OpenSpout ; dom : dompdf)
sudo apt -y install php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring \
    php8.4-xml php8.4-gd php8.4-intl php8.4-zip php8.4-curl \
    php8.4-bcmath php8.4-fileinfo php8.4-opcache

# Base, serveur web, supervision, outils
sudo apt -y install mysql-server nginx supervisor certbot python3-certbot-nginx \
    unzip git curl acl

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js 20 LTS (uniquement pour compiler les assets — vite 8 / tailwind 4)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt -y install nodejs
```

> PHP **8.3 minimum** est accepté par `composer.json` (`"php": "^8.3"`) ; PHP 8.4 est la cible
> du projet. MariaDB 10.11+ peut remplacer MySQL 8. Redis, optionnel, sert pour
> cache/queue à fort trafic (`sudo apt install redis-server php8.4-redis`).

---

## 3. Installation pas à pas

### 3.1 Utilisateur et arborescence dédiés

```bash
sudo useradd --system --home /var/www/uniregistre --shell /usr/sbin/nologin uniregistre
sudo mkdir -p /var/www/uniregistre
sudo usermod -aG uniregistre "$(whoami)"   # optionnel : déployer sans root
```

### 3.2 Code source

```bash
sudo -u uniregistre git clone <URL_DU_DEPOT> /var/www/uniregistre
cd /var/www/uniregistre
sudo -u uniregistre git checkout <branche-ou-tag-de-production>
```

### 3.3 Dépendances PHP (production)

```bash
# --no-dev : pas de phpunit/pint/boost en production (plus léger, moins de surface)
sudo -u uniregistre composer install --no-dev --optimize-autoloader
```

### 3.4 Assets front

```bash
sudo -u uniregistre npm ci
sudo -u uniregistre npm run build        # produit public/build/ (manifest Vite)
# node_modules n'est plus nécessaire à l'exécution :
sudo -u uniregistre rm -rf node_modules
```

> En cas d'erreur « Unable to locate file in Vite manifest » en production : le build n'a
> pas été exécuté ou `public/build` n'a pas été déployé.

### 3.5 Permissions (crucial)

```bash
sudo chown -R uniregistre:www-data /var/www/uniregistre
cd /var/www/uniregistre
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;
# PHP-FPM (groupe www-data) doit pouvoir ÉCRIRE dans storage/ et bootstrap/cache :
sudo chmod -R ug=rwX,o-rwx storage bootstrap/cache
sudo chmod 600 .env    # après création du .env (étape 3.7)
```

### 3.6 Base de données dédiée (privilèges limités)

```bash
sudo mysql <<'SQL'
CREATE DATABASE uniregistre_prod
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'uniregistre'@'localhost' IDENTIFIED BY '<MOT_DE_PASSE_FORT>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES
    ON uniregistre_prod.* TO 'uniregistre'@'localhost';
FLUSH PRIVILEGES;
SQL
```

> ⚠️ Les droits DDL (`CREATE/DROP/ALTER`) sont nécessaires aux migrations (`migrate --force`).
> Si l'exploitation n'exécute plus de migrations, ils peuvent être retirés ensuite.
> utf8mb4 est **obligatoire** : prénoms arabes (OCR CIN tunisienne §22, `ArabicCinScanTest`).

### 3.7 Fichier .env, clé et schéma

```bash
sudo -u uniregistre cp .env.example .env
# → éditer .env selon §4 (APP_URL, DB_*, MAIL_*, secrets…)

# Clé applicative — UNE SEULE FOIS en production.
# La régénérer ensuite invalide sessions (SESSION_ENCRYPT=true) et cookies chiffrés !
sudo -u uniregistre php artisan key:generate

# Schéma (46 migrations applicatives)
sudo -u uniregistre php artisan migrate --force
```

> ❌ **Jamais `php artisan db:seed` en production** : `DatabaseSeeder` insère 8 facultés
> fictives, 25 étudiants de démonstration et surtout des **comptes d'administration aux
> mots de passe publics** (README : `Admin@2026`, `Scolarite@2026`… via `ArchiveSeeder`).
>
> Données initiales légitimes :
> - **Facultés** : créer via le back-office `/admin/facultes` (permission
>   `configuration.manage`) ou un seeder maison maîtrisé ;
> - **Premier super-administrateur** : via tinker (le rôle n'est PAS mass-assignable —
>   audit §P3-17 — il passe par l'API dédiée du modèle) :
>   ```bash
>   sudo -u uniregistre php artisan tinker --execute='
>   \App\Models\User::creerCompteBackOffice([
>       "name" => "Super Administrateur",
>       "email" => "admin@exemple.tld",
>       "password" => "MotDePasse-TresFort-2026",
>   ], "super_admin");'
>   ```

### 3.8 Caches de premier démarrage

Voir §6 — `config:cache`, `route:cache`, `view:cache`, `event:cache`.

---

## 4. Configuration .env de production

`.env.example` du dépôt est la référence complète. Voici les réglages **obligatoires** en
production, bloc par bloc. Règles transversales :

- **`.env` n'est JAMAIS commité** et ne contient que des secrets forts
  (`openssl rand -base64 32` pour chaque secret généré) ;
- **tous les mots de passe de démonstration sont remplacés/supprimés** (cf. §9) ;
- après chaque modification du `.env` : `php artisan config:cache` (§6).

### 4.1 Application

```env
APP_NAME=UniRegistre
APP_ENV=production
# APP_KEY : générée par key:generate, ne plus y toucher ensuite
APP_DEBUG=false            # OBLIGATOIRE : true exposerait stack traces et variables .env
APP_URL=https://inscription.exemple.tld
APP_LOCALE=fr
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=file
```

### 4.2 Journalisation

```env
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error            # 'warning' acceptable ; jamais 'debug' en production
```

> Rappel audit (§24-25) : l'application **ne journalise jamais** de PII ni de données OCR
> (type de document, durée, résultat de matching uniquement). Préservez cette discipline :
> pas de middleware de log de bodies ajouté en production.

### 4.3 Base de données

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=uniregistre_prod
DB_USERNAME=uniregistre
DB_PASSWORD=<secret fort>
```

### 4.4 Sessions — durcies (valeurs vérifiées dans `config/session.php`)

```env
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true       # chiffrement des données de session au repos (§P3-23)
SESSION_PATH=/
SESSION_DOMAIN=.exemple.tld   # ou null si domaine unique
SESSION_SECURE_COOKIE=true    # OBLIGATOIRE : application servie en HTTPS uniquement
SESSION_SAME_SITE=lax
```

### 4.5 Cache, queue, filesystem

```env
CACHE_STORE=database           # ou redis à fort trafic
QUEUE_CONNECTION=database      # jobs : imports/exports lourds (seuil 500 lignes)
FILESYSTEM_DISK=local          # disque local = storage/app/private (hors webroot)
```

### 4.6 Messagerie (SMTP réel)

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.exemple.tld
MAIL_PORT=587
MAIL_USERNAME=<compte smtp>
MAIL_PASSWORD=<secret>
MAIL_FROM_ADDRESS=no-reply@exemple.tld
MAIL_FROM_NAME="UniRegistre"
```

### 4.7 Hachage & authentification

```env
BCRYPT_ROUNDS=12
# Expiration des tokens API Sanctum en minutes — 129600 = 90 jours (audit §P0-2).
# Rotation via : php artisan integration:token-create / integration:token-revoke
SANCTUM_TOKEN_EXPIRATION=129600
SANCTUM_TOKEN_PREFIX=uniregistre
# Reconfirmation du mot de passe back-office après 1 h d'inactivité (§P3-23)
AUTH_PASSWORD_TIMEOUT=3600
```

### 4.8 Reverse proxy (audit §P0-5)

```env
# Indispensable derrière un load-balancer / terminateur TLS : sans lui, le rate limiting
# par IP, le journal d'audit et l'anti-replay webhook voient l'IP du proxy, pas du client.
TRUSTED_PROXIES=*            # ou liste CSV d'IPs des proxies : 10.0.0.1,10.0.0.2
```

### 4.9 Intégrations externes (API v1, webhooks, imports/exports)

```env
# Secret HMAC-SHA256 des webhooks entrants (POST /api/v1/webhooks/external-system) —
# openssl rand -base64 32. Sans secret, documenter côté appelant = rejet.
INTEGRATION_WEBHOOK_SECRET=<secret fort>
INTEGRATION_WEBHOOK_TOLERANCE=300     # fenêtre timestamp en secondes (+ anti-replay 24 h)
# Rate limiting API (§49) — ajuster selon les systèmes externes réels
INTEGRATION_THROTTLE_API=60           # req/min par token
INTEGRATION_THROTTLE_IMPORTS=5
INTEGRATION_THROTTLE_EXPORTS=10
INTEGRATION_THROTTLE_WEBHOOKS=30
# Au-delà de 500 lignes, import/export dispatché en job (202 Accepted)
INTEGRATION_QUEUE_THRESHOLD=500
# Rétentions PII purgées par la tâche planifiée `exports:purge` (horaire, §82)
INTEGRATION_EXPORT_RETENTION_HOURS=168     # 7 jours
INTEGRATION_IMPORT_RETENTION_HOURS=168     # fichiers d'import jamais confirmés
INTEGRATION_IMPORT_MAX_ROWS=10000
```

### 4.10 Modules (`config/modules.php` — activation serveur uniquement)

```env
# Un module désactivé : routes 404 (middleware `module:*`), liens et widgets masqués.
MODULE_ATTENDANCE=false          # Présences
MODULE_STUDENT_DOSSIERS=false    # Dossiers étudiants + documents versionnés
MODULE_ONLINE_REQUESTS=false     # Demandes en ligne
MODULE_SCHEDULE=false            # Emplois du temps
MODULE_REFERENTIEL=true          # Référentiel LMD (fondation des autres modules)
```

> ⚠️ `MODULE_REFERENTIEL` vaut **true par défaut** : réglez-le explicitement.
> N'activez que les modules effectivement exploités.

### 4.11 OCR / scan d'identité — voir §8

Par défaut sûr : `IDENTITY_OCR_DRIVER=null` (aucun OCR, saisie manuelle — inscription
jamais bloquée). Ne configurez un moteur qu'après lecture de §8.

---

## 5. Nginx & TLS

### 5.1 VirtualHost

`/etc/nginx/sites-available/uniregistre` :

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name inscription.exemple.tld;
    # Certbot transforme ce bloc en redirection permanente vers HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name inscription.exemple.tld;

    # Certificats Let's Encrypt (gérés par certbot)
    ssl_certificate     /etc/letsencrypt/live/inscription.exemple.tld/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/inscription.exemple.tld/privkey.pem;

    root /var/www/uniregistre/public;
    index index.php;

    # Plafond cohérent avec l'application : imports 10 Mo (INTEGRATION_UPLOAD_MAX),
    # documents 10 Mo, scans d'identité 8 Mo (IDENTITY_UPLOAD_MAX)
    client_max_body_size 12m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120s;
    }

    # Jamais de fichier caché / sensible (.env, .git…) :
    location ~ /\.(?!well-known).* { deny all; }

    # Optionnel : garde-fou anti-rafale au-dessus du rate limiting applicatif
    # limit_req zone=uniregistre burst=30 nodelay;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/uniregistre /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

> **En-têtes de sécurité : NE PAS les dupliquer dans Nginx.** Ils sont émis par
> `SecurityHeadersMiddleware` (CSP, X-Frame-Options, nosniff, Referrer-Policy,
> Permissions-Policy, COOP/CORP). Le **HSTS** (`max-age=31536000; includeSubDomains`)
> est émis par l'application **uniquement lorsque la requête est vue comme sécurisée** —
> d'où l'importance de `TRUSTED_PROXIES` derrière un terminateur TLS.
> Un doublon d'en-têtes (valeurs divergentes) est un faux positif d'audit fréquent.

### 5.2 PHP (`/etc/php/8.4/fpm/php.ini` et `cli/php.ini`)

```ini
; Cohérents entre eux : post_max_size > upload_max_filesize ≥ client_max_body_size
upload_max_filesize = 12M
post_max_size       = 16M
max_file_uploads    = 5
memory_limit        = 256M
max_execution_time  = 60
expose_php          = Off
; OPcache production
opcache.enable=1
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
```

Puis :

```bash
# php -m | grep -E 'gd|fileinfo|intl|mbstring|pdo_mysql|zip'  # vérification
sudo systemctl restart php8.4-fpm
```

### 5.3 Certificat Let's Encrypt

```bash
sudo certbot --nginx -d inscription.exemple.tld --redirect
sudo systemctl status certbot.timer   # renouvellement automatique
sudo certbot renew --dry-run          # test
```

---

## 6. Optimisations Laravel & script de déploiement

### 6.1 Caches de production

```bash
cd /var/www/uniregistre
sudo -u uniregistre php artisan config:cache
sudo -u uniregistre php artisan route:cache
sudo -u uniregistre php artisan view:cache
sudo -u uniregistre php artisan event:cache
```

- L'autoloader optimisé est déjà produit par `composer install --optimize-autoloader`
  (config `optimize-autoloader: true` du projet) — sur une mise à jour des dépendances :
  `composer install --no-dev --optimize-autoloader` suffit.
- `php artisan storage:link` : **non requis** par défaut — aucune vue ne sert de fichier
  depuis le disque `public` ; les documents passent par des contrôles d'accès applicatifs
  depuis `storage/app/private`. Ne le faites que si un module maison en a besoin.

### 6.2 Séquence de déploiement type

Script complet en annexe §14. Enchaînement canonique :

1. `php artisan down --secret="…"` (mode maintenance, bypass par URL signée) ;
2. `git pull --ff-only` ;
3. `composer install --no-dev --optimize-autoloader` ;
4. `npm ci && npm run build` (si assets modifiés) ;
5. `php artisan migrate --force` ;
6. `config:cache` / `route:cache` / `view:cache` / `event:cache` ;
7. `php artisan queue:restart` (les workers rechargent le nouveau code) ;
8. `php artisan up` ; `sudo systemctl reload php8.4-fpm` ;
9. smoke tests (§11.3).

---

## 7. File d'attente & planificateur

### 7.1 Pourquoi un worker est obligatoire

`QUEUE_CONNECTION=database` : les imports/exports dépassant `INTEGRATION_QUEUE_THRESHOLD`
(500 lignes par défaut) sont **dispatchés en jobs** (`app/Jobs/Io` : `ImportStudentsJob`,
`ImportGradesJob`, `ExportStudentsJob`, `ExportAcademicRecordsJob`) et l'API répond
**202 Accepted** avec un rapport consultable. **Sans worker actif, ces traitements ne
s'exécutent jamais** et s'empilent dans la table `jobs`.

### 7.2 Worker sous Supervisor

`/etc/supervisor/conf.d/uniregistre-worker.conf` :

```ini
[program:uniregistre-worker]
process_name=%(program_name)s_%(process_num)02d
; Les FILES nommées doivent être écoutées explicitement (lot D4,
; 05/10/2026) : documents (lots PDF) + verification (vérification
; dossier) AVANT default — priorités d'exécution.
command=php /var/www/uniregistre/artisan queue:work database --queue=documents,notifications,verification,default --sleep=3 --tries=3 --max-time=3600
directory=/var/www/uniregistre
autostart=true
autorestart=true
stopasignals=SIGQUIT
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/uniregistre-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start uniregistre-worker:*
sudo supervisorctl status
```

> Les échecs après `--tries=3` atterrissent dans `failed_jobs`
> (`database-uuids`) — surveillance et rejeu en §12.

### 7.3 Planificateur (cron)

Une seule entrée cron fait tourner le scheduler Laravel :

```cron
# /etc/cron.d/uniregistre
* * * * * www-data cd /var/www/uniregistre && php artisan schedule:run >> /dev/null 2>&1
```

**Environnement de développement** : pas de cron nécessaire — lancer
`php artisan schedule:work` dans un terminal dédié (blocant, relance
les tâches à l'heure exacte). Sans lui, la page
`/admin/maintenance` affiche « Planificateur : en panne — heartbeat
absent » : c'est le comportement attendu, la sonde vérifie le cache
`supervision:scheduler-dernier` posé toutes les 5 minutes par
`supervision-heartbeat` (§ Phase 21).

```bash
# Dev uniquement
php artisan schedule:work
```

**Tâche planifiée réellement définie** (`routes/console.php`, §82) :

| Tâche | Fréquence | Rôle |
|---|---|---|
| `exports:purge` | **horaire** | Purge RGPD : fichiers d'**export** expirés (`INTEGRATION_EXPORT_RETENTION_HOURS`, défaut 168 h) **et** fichiers d'**import** jamais confirmés expirés (`INTEGRATION_IMPORT_RETENTION_HOURS`, défaut 168 h). Purge journalisée (`integration_logs`, sans payload). |

Simulation sans suppression (audit interne) :

```bash
php artisan exports:purge --dry
```

<!-- B:SECTIONS-8-14 -->

