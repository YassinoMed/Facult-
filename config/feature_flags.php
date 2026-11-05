<?php

/*
|--------------------------------------------------------------------------
| LOOP 43 — Déploiement progressif par indicateurs de fonctionnalité
|--------------------------------------------------------------------------
|
| Chaque indicateur est DÉSACTIVÉ par défaut. Un indicateur inconnu est
| toujours faux (fail-closed). Aucun indicateur ne remplace une validation
| métier ou institutionnelle : il ne fait que restreindre l'exposition d'une
| fonctionnalité déjà autorisée.
|
| Sémantique :
|   - `enabled`          : interrupteur global. false ⇒ toujours faux.
|   - `environnements`   : liste blanche d'environnements. Vide ⇒ hérite de
|                          `feature_flags.environnements`.
|   - `facultes`         : si non vide, le contexte doit appartenir à la liste.
|   - `programmes`       : idem pour le programme.
|   - `users`            : idem pour l'utilisateur.
|   - `pourcentage`     : 0-100. Nécessite un `user_id` ; sans sujet stable,
|                          l'indicateur est faux (pas de tirage aléatoire).
|
| Les dimensions renseignées se combinent en ET. Une dimension vide n'est pas
| un joker : elle n'ajoute simplement aucune restriction.
|
| Ces clés sont des interrupteurs techniques, pas des autorisations. Un
| indicateur activé ne vaut ni conformité réglementaire, ni feu vert métier.
|
*/

return [

    // Environnements où les indicateurs peuvent être évalués.
    // `local,testing` par défaut : la production n'est jamais incluse
    // implicitement.
    'environnements' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FEATURE_FLAGS_ENVIRONNEMENTS', 'local,testing'))
    ))),

    'flags' => [

        // LOOP 42 & 43 — pilote académique contrôlé (Licence Informatique FST).
        'pilote_academique' => [
            'enabled' => false,
            'description' => 'Exposition du pilote académique contrôlé (lecture seule FST Programme 21 L1 2025-2026).',
            'environnements' => ['testing', 'preproduction', 'local'],
            'facultes' => [1], // Faculté des Sciences de Tunis (FST)
            'programmes' => [21], // Licence Nationale en Informatique
            'annees' => [6], // Année Universitaire 2025-2026
            'modes' => ['AUDIT_ONLY', 'SIMULATION'],
            'users' => [],
            'pourcentage' => null,
        ],

        // LOOP 44 — gouvernance des règles académiques.
        'gouvernance_regles' => [
            'enabled' => false,
            'description' => 'Exposition de la gouvernance et de la version des règles.',
            'environnements' => [],
            'facultes' => [],
            'programmes' => [],
            'users' => [],
            'pourcentage' => null,
        ],

        // LOOP 45 — recours académiques.
        'recours_academique' => [
            'enabled' => false,
            'description' => 'Exposition du dépôt et du suivi des recours académiques.',
            'environnements' => [],
            'facultes' => [],
            'programmes' => [],
            'users' => [],
            'pourcentage' => null,
        ],

        // LOOP 47 — simulation de planning.
        'planification_simulation' => [
            'enabled' => false,
            'description' => 'Exposition de la simulation de planning (aucune publication).',
            'environnements' => [],
            'facultes' => [],
            'programmes' => [],
            'users' => [],
            'pourcentage' => null,
        ],

        // LOOP 48 — dictionnaire d'indicateurs.
        'kpi_academique' => [
            'enabled' => false,
            'description' => 'Exposition du dictionnaire et des tableaux d\'indicateurs.',
            'environnements' => [],
            'facultes' => [],
            'programmes' => [],
            'users' => [],
            'pourcentage' => null,
        ],
    ],
];
