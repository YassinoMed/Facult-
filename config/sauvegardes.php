<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sauvegardes — clé de chiffrement au repos (A4, roadmap 05/10/2026)
    |--------------------------------------------------------------------------
    | Clé libsodium (32 octets, base64) pour `sauvegarde:creer --chiffrer`
    | et `sauvegarde:dechiffrer`. GÉRÉE COMME UN SECRET : coffre dédié,
    | jamais versionnée, jamais dans les sauvegardes applicatives.
    | Générer : php -r "echo base64_encode(random_bytes(32));"
    | Sans clé : `--chiffrer` échoue explicitement (jamais de repli
    | silencieux en clair).
    */
    'cle' => env('SAUVEGARDE_CLE'),

    /*
    |--------------------------------------------------------------------------
    | Répertoire externe de sauvegarde par défaut (LOOP BACKUP-01)
    |--------------------------------------------------------------------------
    | Doit être situé HORS du projet ou dans un sous-dossier privé non public.
    | Ne doit jamais être dans public/, storage/app/public/, resources/, app/.
    */
    'dossier' => env('BACKUP_DIRECTORY', env('SAUVEGARDE_DOSSIER', '~/Backups/inscription-etudiants')),

    /*
    |--------------------------------------------------------------------------
    | Destinations de stockage autorisées et explicitement validées
    |--------------------------------------------------------------------------
    | Seules ces destinations prédéfinies peuvent être sélectionnées
    | depuis l'interface ou les tâches automatiques.
    */
    'destinations' => [
        'local_private' => [
            'nom' => 'Stockage privé sécurisé (storage/app/private/backups)',
            'chemin' => storage_path('app/private/backups'),
            'type' => 'local',
        ],
        'externe' => [
            'nom' => 'Répertoire externe dédié (~/Backups/inscription-etudiants)',
            'chemin' => env('BACKUP_DIRECTORY', env('SAUVEGARDE_DOSSIER', '~/Backups/inscription-etudiants')),
            'type' => 'externe',
        ],
    ],

    'destination_par_defaut' => env('BACKUP_DESTINATION_DEFAULT', 'local_private'),

    /*
    |--------------------------------------------------------------------------
    | Options mysqldump & timeout
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('BACKUP_TIMEOUT_SECONDS', 600),

    'options_mysqldump' => [
        '--single-transaction',
        '--quick',
        '--triggers',
        '--routines',
        '--events',
        '--default-character-set=utf8mb4',
    ],

    /*
    |--------------------------------------------------------------------------
    | Seuil d'espace disque minimal requis (en Mo)
    |--------------------------------------------------------------------------
    */
    'seuil_espace_disque_min_mo' => (int) env('BACKUP_MIN_DISK_SPACE_MB', 500),
];
