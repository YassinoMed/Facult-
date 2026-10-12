<?php

/*
|--------------------------------------------------------------------------
| Module OnlineRequests — configuration centralisée
|--------------------------------------------------------------------------
*/
return [

    // Upload des pièces justificatives (même discipline que StudentDossiers).
    'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],

    'mimes' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ],

    'extensions_interdites' => [
        'php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg', 'exe',
        'sh', 'bat', 'cmd', 'msi', 'jar', 'py', 'pl', 'cgi',
    ],

    'taille_max' => (int) env('ONLINE_REQUESTS_TAILLE_MAX', 10 * 1024 * 1024),

    // Types pouvant générer un PDF (codes de request_types).
    'types_pdf' => ['ATTESTATION_SCOLARITE'],
];
