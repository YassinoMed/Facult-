<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Intégrations externes — configuration (§47 : secrets via .env uniquement)
    |--------------------------------------------------------------------------
    */

    // Secret HMAC-SHA256 partagé avec les systèmes externes pour les webhooks (§53-54)
    'webhook_secret' => env('INTEGRATION_WEBHOOK_SECRET'),

    // Fenêtre de validité d'un timestamp de signature (secondes)
    'webhook_tolerance' => (int) env('INTEGRATION_WEBHOOK_TOLERANCE', 300),

    // Rate limiting API (§49) — configurable
    'throttle' => [
        'api' => (int) env('INTEGRATION_THROTTLE_API', 60),          // req/min par token/IP
        'imports' => (int) env('INTEGRATION_THROTTLE_IMPORTS', 5),   // imports/min
        'exports' => (int) env('INTEGRATION_THROTTLE_EXPORTS', 10), // exports/min
        'webhooks' => (int) env('INTEGRATION_THROTTLE_WEBHOOKS', 30), // webhooks/min
    ],

    /*
    | Lot 18 — Hub d'intégration : séparation des environnements
    |--------------------------------------------------------------------------
    | Un contrat de connecteur d'ENVIRONNEMENT `sandbox` n'est activable QUE si
    | l'établissement a explicitement autorisé le bac à sable ici (défaut :
    | false — la production n'accepte jamais un contrat de test par accident).
    | Aucune valeur n'est inventée : c'est une décision institutionnelle.
    */
    'sandbox_autorise' => (bool) env('INTEGRATION_SANDBOX_AUTORISE', false),

    // Plafond de rejeus par échange en échec (bornage anti-boucle du cahier).
    'plafond_rejeux' => (int) env('INTEGRATION_PLAFOND_REJEUX', 3),

    // Taille maximale des fichiers d'import (octets) — 10 Mo
    'upload_max' => (int) env('INTEGRATION_UPLOAD_MAX_OCTETS', env('INTEGRATION_UPLOAD_MAX', 10 * 1024 * 1024)),

    // Au-delà de ce nombre de lignes, l'import/export est dispatché en job (§52)
    'queue_threshold' => (int) env('INTEGRATION_QUEUE_THRESHOLD', 500),

    // Durée de conservation des fichiers d'export en heures (§82 — ex : 24 ou 168)
    'export_retention_hours' => (int) env('INTEGRATION_EXPORT_RETENTION_HOURS', 168),

    /*
     | Durée de conservation des fichiers d'IMPORT en heures (audit §P1-7).
     | Les fichiers importés contiennent des PII (CIN, emails, adresses) :
     | - rapport confirmé → fichier supprimé immédiatement après exécution ;
     | - rapport jamais confirmé → purge planifiée à l'expiration.
     */
    'import_retention_hours' => (int) env('INTEGRATION_IMPORT_RETENTION_HOURS', 168),

    /*
     | Nombre maximal de lignes de données acceptées dans un fichier
     | d'import (audit §P1-9) : borne le coût du parsing synchrone et
     | neutralise les bombes XLSX (décompression ZIP ~1000:1).
     */
    'import_max_rows' => (int) env('INTEGRATION_IMPORT_MAX_ROWS', 10000),

    // Pagination API : borne absolue de per_page (§58)
    'api_per_page_max' => 100,

    // Profils d'export (§43) — listes blanches STRICTES de colonnes.
    // Jamais de mots de passe, tokens, sessions, chemins internes.
    'profiles' => [
        'administratif' => [
            'etudiants' => ['cin', 'nom', 'prenom', 'email', 'telephone', 'faculte', 'niveau', 'statut'],
            'libelle' => 'Administratif (identité + contact)',
        ],
        'academique' => [
            'etudiants' => ['matricule', 'nom', 'prenom', 'faculte', 'annee', 'niveau', 'filiere', 'moyenne_annuelle', 'credits_obtenus', 'credits_total', 'decision'],
            'libelle' => 'Académique (parcours + résultats)',
        ],
        'statistique' => [
            'etudiants' => ['faculte', 'niveau', 'annee', 'effectif', 'moyenne_promotion', 'taux_reussite'],
            'libelle' => 'Statistique (agrégats anonymes)',
        ],
    ],

    // Filtres API autorisés par ressource (§59) — liste blanche
    'filters' => [
        'students' => ['search', 'faculty', 'status', 'year', 'niveau'],
        'records' => ['annee', 'niveau'],
    ],
];
