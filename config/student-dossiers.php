<?php

/*
|--------------------------------------------------------------------------
| Module StudentDossiers (§4-6) — configuration centralisée
|--------------------------------------------------------------------------
| Catégories et types extensibles : jamais codés en dur dans les
| contrôleurs — toujours lus depuis cette configuration.
*/
return [

    'categories' => [
        'identity' => 'Identité',
        'registration' => 'Inscription',
        'academic' => 'Scolarité',
        'administrative' => 'Administratif',
        'internship' => 'Stages',
        'medical' => 'Médical',
        'financial' => 'Financier',
        'other' => 'Autre',
    ],

    // Types configurables par catégorie (extensible sans toucher au code).
    'document_types' => [
        'identity' => ['cin', 'passeport', 'photo_identite', 'acte_naissance'],
        'registration' => ['recu_inscription', 'formulaire_inscription'],
        'academic' => ['releve_notes', 'diplome', 'attestation', 'convention_stage'],
        'administrative' => ['attestation', 'certificat', 'autre'],
        'internship' => ['convention_stage', 'rapport_stage', 'attestation_stage'],
        'medical' => ['certificat_medical', 'autre'],
        'financial' => ['recu_paiement', 'attestation_bourse', 'autre'],
        'other' => ['autre'],
    ],

    // Upload sécurisé : extensions + MIME réels (contenu, pas l'extension).
    'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],

    'mimes' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ],

    // Extensions toujours refusées (exécutables, scripts, markup actif).
    'extensions_interdites' => [
        'php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg', 'exe',
        'sh', 'bat', 'cmd', 'msi', 'jar', 'py', 'pl', 'cgi',
    ],

    'taille_max' => (int) env('STUDENT_DOSSIERS_TAILLE_MAX', 10 * 1024 * 1024),

    // Antivirus (§6) : point d'extension — implémentation branchée via
    // le contrat AntivirusScannerInterface (NullAntivirusScanner par défaut).
    'antivirus' => [
        'enabled' => (bool) env('STUDENT_DOSSIERS_ANTIVIRUS', false),
    ],

    // OCR futur (§11) : extraction désactivée par défaut — fail-soft,
    // jamais une preuve d'identité automatique.
    'ocr' => [
        'enabled' => (bool) env('STUDENT_DOSSIERS_OCR', false),
        'categories' => ['identity'],
    ],

    // PDF du dossier (§4) : A4, marges pro, identité non hardcodée.
    'pdf' => [
        'paper' => env('STUDENT_DOSSIERS_PDF_PAPER', 'A4'),
        'orientation' => env('STUDENT_DOSSIERS_PDF_ORIENTATION', 'portrait'),
        'margins' => [
            'top' => 18,
            'bottom' => 20,
            'left' => 15,
            'right' => 15,
        ],
        'logo' => env('STUDENT_DOSSIERS_PDF_LOGO'),
        'institution_name' => env('STUDENT_DOSSIERS_PDF_INSTITUTION', 'Université'),
        'footer' => env('STUDENT_DOSSIERS_PDF_FOOTER', 'Document généré par la scolarité — vérifiable auprès du service.'),
        'locale' => 'fr',
        // Au-delà de ce nombre de documents : génération via job (§14).
        'job_threshold' => (int) env('STUDENT_DOSSIERS_PDF_JOB_THRESHOLD', 200),
    ],
];
