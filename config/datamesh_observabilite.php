<?php

return [
    /*
    | Seuils mesurables de l'Observability Center (tous modifiables sans
    | toucher au code). Aucun seuil n'invente une mesure : DEGRADE exige
    | des avertissements avérés par ControlesDonnees sur le produit.
    */
    'seuil_degrade_avertissements' => (int) (env('DATAMESH_SEUIL_DEGRADE', 1)),
];
