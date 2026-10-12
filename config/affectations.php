<?php

/*
|--------------------------------------------------------------------------
| Affectations — pondérations du scoring et seuils de décision
|--------------------------------------------------------------------------
| Centralise les pondérations du moteur de sélection
| (`AffectationEngine::evaluer`, Phase 11) : contraintes dures
| (niveau, programme, capacité) inchangées, seuls les scores et
| seuils AUTO / SEMI-AUTO sont configurables ici. Valeurs par
| défaut = comportement historique (non-régression).
*/
return [
    'scoring' => [
        'niveau' => 30,
        'parcours_exact' => 25,
        'parcours_tronc_commun' => 15,
        'filiere_concordante' => 20,
        'filiere_defaut' => 15,
        'domaine' => 10,
        'capacite_confortable' => 5,
        'equilibrage_max' => 10,
    ],

    'seuils' => [
        // Score minimal + écart vs second + remplissage max → AUTO.
        'auto_score' => 85,
        'auto_ecart' => 8,
        'auto_remplissage_max' => 85,
        // En dessous → certitude faible (jamais d'automatisme pur).
        'faible_score' => 70,
        'faible_remplissage' => 90,
        // Bonus capacité : places libres confortables sous ce taux.
        'capacite_confortable_max' => 80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lots d'affectation de masse (LOOP P2)
    |--------------------------------------------------------------------------
    | seuil_sync : au-delà de ce nombre de sélections, la confirmation
    |   est exécutée en file via ExecuterAffectationMasseLot (mesuré
    |   LOOP 2 : synchrone viable jusqu'à ~1000).
    | taille_tranche : sélections par transaction dans le Job.
    | file : file d'attente dédiée (convention `documents`).
    */
    'lots' => [
        'seuil_sync' => (int) env('AFFECTATIONS_LOT_SEUIL_SYNC', 1000),
        'taille_tranche' => (int) env('AFFECTATIONS_LOT_TRANCHE', 500),
        'file' => (string) env('AFFECTATIONS_LOT_FILE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Traitements d'affectation des surveillances (Job asynchrone)
    |--------------------------------------------------------------------------
    | file : file d'attente du Job AffectationSurveillanceJob.
    */
    'surveillance_runs' => [
        'file' => (string) env('AFFECTATIONS_SURVEILLANCE_FILE', 'default'),
    ],
];
