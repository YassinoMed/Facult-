<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OCR — langues centralisées (§3 du cahier des charges CIN)
    |--------------------------------------------------------------------------
    | AUCUNE langue OCR n'est codée en dur dans les services : tout est
    | résolu ici. Les valeurs sont des codes Tesseract (interchangeables
    | avec un autre moteur via OcrEngineInterface).
    */

    // Langue par défaut (documents non spécialisés)
    'default_language' => env('OCR_DEFAULT_LANGUAGE', 'ara'),

    // CIN tunisienne : principalement arabe + zone latine/chiffres
    // (le latin d'une CIN tunisienne est français — 'ara+fra' convient
    // aussi ; configurable par env)
    'cin_language' => env('OCR_CIN_LANGUAGE', 'ara+eng'),

    // Passeport : MRZ/latin
    'passeport_language' => env('OCR_PASSEPORT_LANGUAGE', 'eng+fra'),

    // Résolution des indices logiques → codes moteur
    'hint_map' => [
        'ara+lat' => null, // → cin_language (résolu par le service appelant)
        'ara' => 'ara',
        'ar' => 'ara',
        'lat' => 'eng',
        'latin' => 'eng',
        'auto' => null,
    ],
];
