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
    // Les codes du catalogue `DocumentsRequisService` sont tous couverts :
    // une pièce exigée doit toujours pouvoir être téléversée.
    'document_types' => [
        'identity' => ['cin', 'passeport', 'photo_identite', 'acte_naissance'],
        'registration' => ['recu_inscription', 'formulaire_inscription', 'quitus_scolarite'],
        'academic' => ['releve_notes', 'diplome', 'attestation', 'convention_stage', 'attestation_reussite', 'releves_anterieurs'],
        'administrative' => ['attestation', 'certificat', 'autre', 'attestation_transfert'],
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

    // Antivirus (§6) : `null` = moteur explicitement désactivé ;
    // `clamav` = analyse obligatoire avant publication. Aucun binaire
    // n'est supposé installé : le branchement se fait uniquement après
    // validation opérationnelle (clamdscan/clamscan + signatures à jour).
    'antivirus' => [
        'driver' => env(
            'STUDENT_DOSSIERS_ANTIVIRUS_DRIVER',
            (bool) env('STUDENT_DOSSIERS_ANTIVIRUS', false) ? 'clamav' : 'null',
        ),
        'clamav' => [
            'binary' => env('STUDENT_DOSSIERS_CLAMAV_BINARY', 'clamdscan'),
            // Vide (défaut) = commande stdin documentée `clamdscan -`
            // ou `clamscan -`. `stream` force `--stream` (clamdscan
            // uniquement), utile pour le débogage.
            'mode' => env('STUDENT_DOSSIERS_CLAMAV_MODE'),
            'timeout' => (int) env('STUDENT_DOSSIERS_CLAMAV_TIMEOUT', 30),
        ],
        // Politique de disponibilité : toutes les catégories sont
        // fail-closed. Une panne moteur refuse le fichier et le stockage
        // le supprime ; jamais d'approbation implicite.
        'categories_fail_closed' => [
            'identity', 'registration', 'academic', 'administrative',
            'internship', 'medical', 'financial', 'other', 'justificatif',
        ],
        // Aucune catégorie fail-open : un fichier non analysé ne peut
        // jamais être publié. Le mode fail-open n'est pas exposé.
        'categories_fail_open' => [],
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
