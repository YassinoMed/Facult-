<?php

/*
|--------------------------------------------------------------------------
| Module Schedule — configuration centralisée
|--------------------------------------------------------------------------
*/
return [

    // Sondage Alpine de l'emploi du temps (secondes) : 30 à 60.
    'poll_interval' => (int) env('SCHEDULE_POLL_INTERVAL', 45),

    // Grille hebdomadaire affichée (heures pleines).
    'heure_debut' => 8,
    'heure_fin' => 18,

    // Cache de version du planning (secondes) + préfixe de clé.
    'version_ttl' => 3600,
    'version_prefixe' => 'schedule:version:',
];
