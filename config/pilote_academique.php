<?php

/*
|--------------------------------------------------------------------------
| LOOP 42 — Pilote académique contrôlé
|--------------------------------------------------------------------------
|
| Ce fichier décrit le périmètre d'un pilote de recalcul académique sur une
| cohorte restreinte. Il est volontairement INACTIF et VIDE par défaut :
|
|   - `actif`             : false  → aucun dossier n'est traité ;
|   - `statut_validation` : A_VALIDER → aucune exécution métier autorisée ;
|   - `perimetre`         : vide   → aucun dossier ne peut être sélectionné ;
|   - `mode`              : SIMULATION → aucune écriture possible.
|
| Aucune valeur de seuil, de quota, de capacité ou de délai n'est inventée ici.
| Les seules valeurs configurables sont des paramètres techniques (tolérance
| de comparaison décimale, limite de volume) explicitement documentés.
|
| Pour activer un pilote, il faut :
|   1. renseigner un périmètre non vide (facultés / programmes / années /
|      niveaux / étudiants) ;
|   2. renseigner `reference_validation` avec la référence du texte ou de la
|      décision institutionnelle qui autorise le pilote ;
|   3. passer `statut_validation` à `VALIDE` et `actif` à `true`.
|
| Tant que ces conditions ne sont pas réunies, `PilotAcademiqueService`
| retourne un rapport BLOQUÉ sans traiter aucun dossier.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Activation
    |--------------------------------------------------------------------------
    */

    'actif' => env('PILOTE_ACADEMIQUE_ACTIF', false),

    // A_VALIDER | VALIDE | REFUSE
    'statut_validation' => env('PILOTE_ACADEMIQUE_STATUT', 'A_VALIDER'),

    // Référence du texte / de la décision institutionnelle autorisant le pilote.
    'reference_validation' => env('PILOTE_ACADEMIQUE_REFERENCE'),

    // SIMULATION uniquement en LOOP 42. `ECRITURE` n'est pas implémenté.
    'mode' => env('PILOTE_ACADEMIQUE_MODE', 'SIMULATION'),

    /*
    |--------------------------------------------------------------------------
    | Périmètre
    |--------------------------------------------------------------------------
    |
    | Tous les filtres renseignés sont appliqués en ET (intersection).
    | Un filtre vide n'est pas un joker : il est ignoré, mais le périmètre
    | global reste vide tant qu'aucun filtre n'est renseigné.
    |
    */

    'perimetre' => [
        // Identifiants de facultés. Isolation stricte : un dossier dont la
        // faculté n'est pas listée est exclu, jamais réaffecté.
        'facultes' => [],

        // Identifiants de programmes (formation).
        'programmes' => [],

        // Identifiants d'années universitaires.
        'annees' => [],

        // Codes de niveaux, ex. ['L1', 'L2']. Comparaison insensible à la casse.
        'niveaux' => [],

        // Identifiants d'étudiants (liste blanche explicite, optionnelle).
        'etudiants' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Paramètres techniques de comparaison
    |--------------------------------------------------------------------------
    |
    | `tolerance_moyenne` : écart maximal admis entre deux moyennes décimales
    | avant de conclure à une divergence. 0.01 correspond à la précision
    | `decimal:2` de la base ; ce n'est pas une règle pédagogique.
    |
    | `limite_dossiers` : plafond technique de volume pour une exécution.
    | `null` = pas de plafond artificiel. Ce n'est ni un quota ni une capacité
    | officielle.
    |
    */

    'tolerance_moyenne' => 0.01,

    'limite_dossiers' => env('PILOTE_ACADEMIQUE_LIMITE'),

];
