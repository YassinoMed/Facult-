<?php

/*
|--------------------------------------------------------------------------
| Module Versioning — configuration interne
|--------------------------------------------------------------------------
| Système de versionnage APP / DB / DEV / PROD entièrement local.
| Aucun secret ici ; les chemins, rétentions et seuils sont
| configurables via les variables d'environnement.
*/

return [

    /*
    |----------------------------------------------------------------------
    | Identité du projet
    |----------------------------------------------------------------------
    */
    'nom_projet' => env('VERSIONING_NOM_PROJET', config('app.name', 'Plateforme Universitaire')),

    /*
    |----------------------------------------------------------------------
    | Version applicative courante (source de vérité fichier)
    |----------------------------------------------------------------------
    | Lue au boot depuis ce fichier ; mise à jour par le service de
    | release. NE PAS modifier manuellement en production — utiliser
    | la commande versioning:release ou l'interface admin.
    */
    'app_version' => env('APP_VERSION', '1.0.0'),

    /*
    |----------------------------------------------------------------------
    | Version logique de la base de données (DB Release)
    |----------------------------------------------------------------------
    | Version SemVer représentant la compatibilité logique entre le code
    | applicatif et la base de données. Indépendante du compteur de migrations.
    */
    'db_release' => env('DB_RELEASE', '1.0.0'),

    /*
    |----------------------------------------------------------------------
    | Environnement logique (distinct de APP_ENV)
    |----------------------------------------------------------------------
    */
    'environnement' => env('VERSIONING_ENV', env('APP_ENV', 'local')),

    /*
    |----------------------------------------------------------------------
    | Composants actifs
    |----------------------------------------------------------------------
    | Dimensions versionnées. Extensible sans migration.
    */
    'composants' => ['app', 'db', 'dev', 'prod'],

    /*
    |----------------------------------------------------------------------
    | Stockage local
    |----------------------------------------------------------------------
    */
    'stockage' => [
        'disque' => env('VERSIONING_DISK', 'local'),
        'racine' => 'versioning',
        'releases' => 'versioning/releases',
        'manifests' => 'versioning/manifests',
        'snapshots' => 'versioning/snapshots',
    ],

    /*
    |----------------------------------------------------------------------
    | Rétention
    |----------------------------------------------------------------------
    */
    'retention' => [
        'releases' => (int) env('VERSIONING_RETENTION_RELEASES', 50),
        'manifests' => (int) env('VERSIONING_RETENTION_MANIFESTS', 100),
        'backups' => (int) env('VERSIONING_RETENTION_BACKUPS', 14),
    ],

    /*
    |----------------------------------------------------------------------
    | Compatibilité
    |----------------------------------------------------------------------
    | Tolérance PATCH entre APP et DB : une différence mineure de
    | patch (ex. APP 2.7.1 + DB 2.7.0) est « compatible avec avertissement ».
    | Au-delà du seuil = incompatible.
    */
    'compatibilite' => [
        'tolerance_patch' => (int) env('VERSIONING_COMPAT_TOLERANCE', 1),
    ],

    /*
    |----------------------------------------------------------------------
    | Build
    |----------------------------------------------------------------------
    */
    'build' => [
        'format' => 'Y.m.d',  // préfixe horodaté du build number
    ],
];
