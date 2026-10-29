<?php

/*
|--------------------------------------------------------------------------
| Catalogue des documents officiels (Phase 7 §44)
|--------------------------------------------------------------------------
| Chaque code = type GeneratedDocument + gabarit Blade + règles de
| génération. `decision_requise` = décision publiée exigée (null =
| aucun prérequis, ex. relevé). `prefixe` = numérotation officielle.
*/
return [
    'types' => [
        'releve_notes' => [
            'libelle' => 'Relevé de notes',
            'gabarit' => 'modules.student-dossiers.pdf.releve',
            'prefixe' => 'REL',
            'decision_requise' => null,
            'revocable' => true,
            'qr' => true,
        ],
        'releve_semestriel' => [
            'libelle' => 'Relevé de notes semestriel',
            'gabarit' => 'modules.student-dossiers.pdf.releve',
            'prefixe' => 'REL',
            'decision_requise' => null,
            'revocable' => true,
            'qr' => true,
            'semestre_requis' => true,
        ],
        'releve_cursus' => [
            'libelle' => 'Relevé de cursus',
            'gabarit' => 'modules.student-dossiers.pdf.releve-cursus',
            'prefixe' => 'REL',
            'decision_requise' => null,
            'revocable' => true,
            'qr' => true,
            'cursus' => true,
        ],
        'attestation_reussite' => [
            'libelle' => 'Attestation de réussite',
            'gabarit' => 'modules.student-dossiers.pdf.attestation-reussite',
            'prefixe' => 'ATT',
            'decision_requise' => ['admis', 'admis_compensation', 'admis_conditionnel'],
            'revocable' => true,
            'qr' => true,
        ],
        'attestation_inscription' => [
            'libelle' => 'Attestation d’inscription',
            'gabarit' => 'modules.student-dossiers.pdf.attestation-inscription',
            'prefixe' => 'ATI',
            'decision_requise' => null,
            'inscription_active_requise' => true,
            'revocable' => true,
            'qr' => true,
        ],
        'diplome' => [
            'libelle' => 'Diplôme',
            'gabarit' => 'modules.student-dossiers.pdf.diplome',
            'prefixe' => 'DIP',
            'decision_requise' => ['admis', 'admis_compensation'],
            'fin_cycle_requise' => true,
            'revocable' => true,
            'qr' => true,
        ],
        'attestation_scolarite' => [
            'libelle' => 'Attestation de scolarité',
            'gabarit' => 'modules.student-dossiers.pdf.attestation-scolarite',
            'prefixe' => 'ATS',
            'inscription_active_requise' => true,
            'dossier_valide_requis' => true,
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
        'certificat_scolarite' => [
            'libelle' => 'Certificat de scolarité',
            'gabarit' => 'modules.student-dossiers.pdf.certificat-scolarite',
            'prefixe' => 'CTS',
            'inscription_active_requise' => true,
            'dossier_valide_requis' => true,
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
        'attestation_presence' => [
            'libelle' => 'Attestation de présence',
            'gabarit' => 'modules.student-dossiers.pdf.attestation-presence',
            'prefixe' => 'ATP',
            'inscription_active_requise' => true,
            'presence_requise' => true,
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
        'attestation_assiduite' => [
            'libelle' => "Attestation d'assiduité",
            'gabarit' => 'modules.student-dossiers.pdf.attestation-assiduite',
            'prefixe' => 'AAS',
            'inscription_active_requise' => true,
            'presence_requise' => true,
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
        'attestation_admis' => [
            'libelle' => 'Attestation d’admission',
            'gabarit' => 'modules.student-dossiers.pdf.attestation-admis',
            'prefixe' => 'ADM',
            'decision_requise' => ['admis'],
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
        'attestation_abandon' => [
            'libelle' => 'Attestation d’abandon',
            'gabarit' => 'modules.student-dossiers.pdf.attestation-abandon',
            'prefixe' => 'ABA',
            'abandon_requis' => true,
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
        'attestation_transfert' => [
            'libelle' => 'Attestation de transfert',
            'gabarit' => 'modules.student-dossiers.pdf.attestation-transfert',
            'prefixe' => 'ATF',
            'transfert_requis' => true,
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
        'quittance' => [
            'libelle' => 'Quittance de paiement',
            'gabarit' => 'modules.student-dossiers.pdf.quittance',
            'prefixe' => 'QUI',
            'paiement_confirme_requis' => true,
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
        'certificat_reussite' => [
            'libelle' => 'Certificat de réussite',
            'gabarit' => 'modules.student-dossiers.pdf.certificat-reussite',
            'prefixe' => 'CTR',
            'decision_requise' => ['admis', 'admis_compensation'],
            'inscription_valide_requise' => true,
            'revocable' => true,
            'qr' => true,
            'donnees_etendues' => true,
        ],
    ],

    /*
    | QR Code (PHASE 14, LOT 10) : image QR en plus de l'URL + code
    | imprimés. Interrupteur responsable : `documents.qr_actif`
    | (désactivable sans déploiement). Clé `qr` par type réservée.
    */
    'qr' => [
        'actif' => (bool) env('DOCUMENTS_QR_ACTIF', true),
    ],

    /*
    | Signature d'intégrité (LOOP-07, R-07/6) : HMAC-SHA256 sur type,
    | référence, contenu_hash et code de vérification. Clé dédiée
    | recommandée en production ; à défaut, dérivation depuis APP_KEY.
    */
    'signature' => [
        'cle' => env('DOCUMENTS_SIGNATURE_KEY'),
    ],

    /*
    | Génération automatique à la publication d'une délibération (LOOP 4) :
    | relevé annuel de chaque décision publiée, via job en file.
    | Désactiver (false) pour garder uniquement la génération manuelle.
    */
    'generation_auto_publication' => (bool) env('DOCUMENTS_GENERATION_AUTO', true),
];
