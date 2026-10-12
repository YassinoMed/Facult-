<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CIN tunisienne — pipeline spécialisé (§2/§5)
    |--------------------------------------------------------------------------
    | Zones en COORDONNÉES NORMALISÉES (fractions 0-1 de la largeur et
    | hauteur du document) : indépendantes de la résolution du scan.
    | Ajustables ici sans toucher au code — chaque zone précise sa
    | langue moteur et son PSM Tesseract.
    |
    | Le recto CIN : en-tête République, photo à GAUCHE, n° CIN en haut
    | à droite, puis colonne اللقب / الاسم / filiation / تاريخ الولادة /
    | مكانها. Les valeurs sont SOUS les libellés (RTL).
    */

    // Indices de détection du document (§2) — le pipeline est robuste
    // si certains manquent : 1 indice suffit, 0 → repli sur le type
    // déclaré par l'utilisateur.
    'detection' => [
        'mots_cles_arabe' => [
            'الجمهورية التونسية',
            'بطاقة التعريف الوطنية',
            'الجمهورية',
            'التونسية',
        ],
        'mots_cles_latin' => [
            'REPUBLIQUE TUNISIENNE',
            'CARTE NATIONALE DIDENTITE',
            'TUNISIENNE',
        ],
        'indice_minimal' => 1,
    ],

    // Aspect ratio attendu du recto (ratio = largeur/hauteur ≈ 1.58)
    'aspect_ratio' => [
        'min' => 1.2,
        'max' => 2.2,
    ],

    /*
     | Zones OCR : [x1, y1, x2, y2] en fractions + langue + psm.
     | psm Tesseract : 6 = bloc uniforme, 7 = ligne unique,
     | 11 = texte dispersé (recommandé pour les zones arabe CIN).
     */
    'regions' => [
        'entete' => [
            'rect' => [0.0, 0.0, 1.0, 0.15],
            'langue' => 'ara',
            'psm' => 7,
        ],
        'document_number' => [
            'rect' => [0.26, 0.145, 0.99, 0.25],
            'langue' => 'ara+eng', // chiffres occidentaux ET arabo-indiques
            'psm' => 7,
        ],
        'last_name' => [
            'rect' => [0.30, 0.24, 1.0, 0.43],
            'langue' => 'ara',
            'psm' => 6,
        ],
        'first_name' => [
            'rect' => [0.30, 0.385, 1.0, 0.525],
            'langue' => 'ara',
            'psm' => 11,
        ],
        'filiation' => [
            'rect' => [0.30, 0.53, 1.0, 0.69],
            'langue' => 'ara',
            'psm' => 11,
        ],
        'birth_date' => [
            'rect' => [0.30, 0.63, 1.0, 0.79],
            'langue' => 'ara',
            'psm' => 11,
        ],
        'birth_place' => [
            'rect' => [0.30, 0.775, 1.0, 0.915],
            'langue' => 'ara',
            'psm' => 11,
        ],
    ],

    // Variantes de prétraitement testées par zone (§4) — vote majoritaire
    // ensuite. GD natif : aucune dépendance externe requise.
    'variantes' => [
        'original' => true,
        'gris_contraste' => true,   // grayscale + contraste renforcé
        'gris_net_agrandi' => true, // grayscale + netteté + upscale 150 %
        'binarisee' => true,        // seuillage fixe après grayscale
    ],
];
