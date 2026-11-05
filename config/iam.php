<?php

/*
|--------------------------------------------------------------------------
| Gouvernance IAM (LOOP 43-BIS)
|--------------------------------------------------------------------------
| Politique de la composante de gouvernance des identités, rôles,
| permissions et habilitations. Aucun secret ici.
|
| Les valeurs par défaut sont VOLONTAIREMENT conservatrices. Les durées
| et le nombre d'approbateurs sont des paramètres INSTITUTIONNELS : ils
| doivent être arrêtés par la politique de l'établissement, pas inventés
| par le code. Le service les lit à chaque opération.
*/

return [

    // Nombre d'approbations indépendantes exigées avant exécution.
    // 1 = quatre yeux (initiateur ≠ approbateur). Une institution peut
    // relever ce seuil ; le code refuse toujours l'auto-approbation.
    'approbateurs_requis' => max(1, (int) env('IAM_APPROBATEURS_REQUIS', 1)),

    // Durée maximale d'une affectation temporaire (jours).
    'duree_max_affectation_jours' => max(1, (int) env('IAM_DUREE_MAX_AFFECTATION_JOURS', 365)),

    // Durée de validité d'une demande à quatre yeux (heures). Une demande
    // non approuvée dans ce délai est scellée « expirée » et ne peut plus
    // produire d'effet métier.
    'demande_expiration_heures' => max(1, (int) env('IAM_DEMANDE_EXPIRATION_HEURES', 72)),

    // Durée maximale d'une délégation de permissions (jours).
    'duree_max_delegation_jours' => max(1, (int) env('IAM_DUREE_MAX_DELEGATION_JOURS', 90)),

    // Durée maximale d'un accès d'urgence (heures).
    'duree_max_urgence_heures' => max(1, (int) env('IAM_DUREE_MAX_URGENCE_HEURES', 4)),

    // Délai de revue indépendante après une urgence activée sans
    // validation préalable (heures).
    'revue_urgence_heures' => max(1, (int) env('IAM_REVUE_URGENCE_HEURES', 72)),

    // Rôles systèmes réservés : jamais attribuables par un profil
    // personnalisé, jamais auto-attribuables, jamais accordés en urgence.
    'roles_reserves' => ['super_admin'],

    // Préfixe des profils personnalisés (aucune collision avec les rôles
    // systèmes du catalogue canonique).
    'prefixe_profil' => 'perso_',

    // Les permissions IAM elles-mêmes ne sont pas administrables par un
    // profil personnalisé : l'autorité de gouvernance ne se délègue que
    // par rôle système et par décision institutionnelle explicite.
    'modules_reserves' => ['iam'],

    // Le module d'assistance IA possède son propre catalogue local
    // (PermissionsAi). Il est affiché en lecture seule dans le catalogue
    // fédéré : aucune attribution via la gouvernance IAM tant que le
    // module n'est pas raccordé au moteur central.
    'modules_federes_lecture_seule' => ['ai'],

    // Journalisation : taille maximale d'une valeur sérialisée dans
    // l'audit (protection contre l'explosion de volume et les fuites).
    'audit_taille_max_valeur' => 4000,

    // Pagination des grandes listes (utilisateurs, permissions, audit).
    'pagination' => [
        'utilisateurs' => 25,
        'affectations' => 25,
        'demandes' => 20,
        'audit' => 30,
        'recertification' => 25,
    ],
];
