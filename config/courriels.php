<?php

use App\Mail\CourrielMessage;
use App\Notifications\AlerteChangementEmail;
use App\Notifications\CodeConfirmationChangementEmail;
use App\Notifications\CodeConfirmationNouvelleAdresse;
use App\Notifications\CodeVerificationDossierExistant;
use App\Notifications\ConfirmationChangementEmail;
use App\Notifications\ConfirmationMiseAJourDossierExistant;

/*
|--------------------------------------------------------------------------
| Catalogue des modèles de courriels (LOOP-MAIL-ENTERPRISE-01)
|--------------------------------------------------------------------------
| Identifiants stables, versions explicites, variables strictement
| autorisées. Les 6 modèles existants sont des classes Notification
| versionnées en code (aucune exécution de Blade/PHP administrable :
| l'édition arbitraire de modèle est volontairement non proposée).
| Seul `message_manuel` est émissible via le cockpit (double
| confirmation, destinataire validé, variables sûres uniquement).
*/
return [

    'version_catalogue' => '1',

    'modeles' => [

        'code_verification' => [
            'notification' => CodeVerificationDossierExistant::class,
            'version' => '1',
            'categorie' => 'securite',
            'sujet' => 'Votre code de vérification — mise à jour de dossier étudiant',
            'variables' => ['code', 'minutes_validite'],
            'duree_validite_minutes' => 10,
            'reemissible' => false,
            'regle' => 'Usage unique, envoi synchrone vers l’adresse enregistrée uniquement. Un renvoi exige la reprise du parcours (nouveau code).',
        ],

        'code_changement_email' => [
            'notification' => CodeConfirmationChangementEmail::class,
            'version' => '1',
            'categorie' => 'securite',
            'sujet' => 'Votre code de confirmation — changement d\'adresse email',
            'variables' => ['code', 'minutes_validite'],
            'duree_validite_minutes' => 30,
            'reemissible' => false,
            'regle' => 'Première preuve (adresse actuelle). Usage unique, 30 minutes.',
        ],

        'code_nouvelle_adresse' => [
            'notification' => CodeConfirmationNouvelleAdresse::class,
            'version' => '1',
            'categorie' => 'securite',
            'sujet' => 'Confirmez votre nouvelle adresse email — dossier étudiant',
            'variables' => ['code', 'minutes_validite'],
            'duree_validite_minutes' => 30,
            'reemissible' => false,
            'regle' => 'Seconde preuve (adresse candidate). L’ancienne adresse reste active jusqu’à confirmation.',
        ],

        'confirmation_mise_a_jour' => [
            'notification' => ConfirmationMiseAJourDossierExistant::class,
            'version' => '1',
            'categorie' => 'confirmation',
            'sujet' => 'Confirmation de mise à jour de votre dossier étudiant',
            'variables' => ['reference', 'nb_champs_modifies'],
            'lignes' => [
                'Votre demande de mise à jour a été enregistrée.',
                'Référence du dossier : {reference}',
                'Champs modifiés : {nb_champs_modifies}',
            ],
            'reemissible' => true,
            'emissible_service' => true,
            'regle' => 'Envoyée après commit uniquement. Réémission informative sans secret.',
        ],

        'alerte_changement_email' => [
            'notification' => AlerteChangementEmail::class,
            'version' => '1',
            'categorie' => 'securite',
            'sujet' => 'Sécurité : votre adresse email a été modifiée',
            'variables' => ['nouvel_email_masque'],
            'lignes' => [
                'Votre adresse email a été modifiée.',
                'Nouvelle adresse (masquée) : {nouvel_email_masque}',
                'Si vous n’êtes pas à l’origine de ce changement, contactez immédiatement la scolarité.',
            ],
            'reemissible' => true,
            'emissible_service' => true,
            'regle' => 'Adresse masquée uniquement. Envoyée après commit.',
        ],

        'confirmation_changement_email' => [
            'notification' => ConfirmationChangementEmail::class,
            'version' => '1',
            'categorie' => 'confirmation',
            'sujet' => 'Confirmation de changement de votre adresse email',
            'variables' => ['reference'],
            'lignes' => [
                'Votre adresse email a été mise à jour.',
                'Référence : {reference}',
            ],
            'reemissible' => true,
            'emissible_service' => true,
            'regle' => 'Envoyée après commit à la nouvelle adresse.',
        ],

        'message_manuel' => [
            'notification' => CourrielMessage::class,
            'version' => '1',
            'categorie' => 'administratif',
            'sujet' => 'Message du service de la scolarité',
            'variables' => ['message'],
            'reemissible' => false,
            'emissible_service' => true,
            'emissible_cockpit' => true,
            'regle' => 'Cockpit uniquement : destinataire validé, texte brut validé (500 caractères max, sans retour chariot anormal), aperçu + double confirmation, débit limité, acteur audité.',
        ],

    ],

    // Reprise administrative : plafond de tentatives manuelles par courriel.
    'max_reessais_manuels' => 3,

    // Délai minimal entre deux reprises manuelles du même courriel (secondes).
    'delai_reprise_secondes' => 60,

    // Débit maximal d'envois manuels par administrateur (par minute).
    'debit_manuel_par_minute' => 5,

    // Nouvel éditeur visuel du studio (STUDIO-04, déploiement progressif) :
    // `false` = éditeur v1 conservé, `true` = nouveau builder (mêmes routes,
    // mêmes contrats JSON, même moteur). Retour arrière instantané.
    'editeur_v2' => (bool) env('COURRIEL_EDITEUR_V2', false),

    /*
    | Registre central des variables de modèles (STUDIO-03 §6) : seules ces
    | variables peuvent apparaître comme `{{nom}}` dans un schéma. Aucune
    | expression PHP/Blade, aucun appel de fonction, aucun accès env.
    | Les variables sensibles (code, token, lien signé) N'Y FIGURENT PAS :
    | elles ne peuvent donc jamais être référencées par un modèle du studio.
    | `exemple` : donnée fictive pour les aperçus (jamais de dossier réel).
    */
    'variables_autorisees' => [
        'etablissement_nom' => [
            'type' => 'texte', 'exemple' => 'Université Exemple',
            'description' => 'Nom de l’établissement.',
            'contextes' => ['*'], 'si_absente' => 'chaîne vide',
        ],
        'destinataire_nom' => [
            'type' => 'texte', 'exemple' => 'Étudiant Exemple',
            'description' => 'Nom du destinataire, uniquement si nécessaire.',
            'contextes' => ['administratif', 'confirmation'], 'si_absente' => 'chaîne vide',
        ],
        'reference_dossier' => [
            'type' => 'texte', 'exemple' => 'INS-2026-000001',
            'description' => 'Référence de dossier non sensible.',
            'contextes' => ['confirmation', 'administratif'], 'si_absente' => 'chaîne vide',
        ],
        'echeance_intitule' => [
            'type' => 'texte', 'exemple' => 'Clôture des inscriptions',
            'description' => 'Intitulé d’une échéance.',
            'contextes' => ['rappel', 'administratif'], 'si_absente' => 'chaîne vide',
        ],
        'echeance_date' => [
            'type' => 'texte', 'exemple' => '30/09/2026',
            'description' => 'Date d’une échéance (texte libre validé).',
            'contextes' => ['rappel', 'administratif'], 'si_absente' => 'chaîne vide',
        ],
        'url_action' => [
            'type' => 'url', 'exemple' => 'https://exemple.univ.test/dossier',
            'description' => 'URL d’action générée par l’application (https uniquement).',
            'contextes' => ['rappel', 'confirmation', 'administratif'], 'si_absente' => 'bouton omis',
        ],
        'contact_email' => [
            'type' => 'texte', 'exemple' => 'scolarite@exemple.univ.test',
            'description' => 'Coordonnée institutionnelle.',
            'contextes' => ['*'], 'si_absente' => 'chaîne vide',
        ],
    ],

    // Envois de test du studio : liste d'adresses autorisées (vide = désactivé).
    'adresses_test_autorisees' => array_filter(array_map(
        'trim',
        explode(',', (string) env('COURRIEL_TEST_ALLOWLIST', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Configuration des campagnes de courriels (LOOP-MAIL-CAMPAIGNS-01)
    |--------------------------------------------------------------------------
    | Plafonds de volume, découpage en lots, seuil d'avertissement et timeouts.
    */
    'campagnes' => [
        'taille_lot_defaut' => (int) env('COURRIEL_CAMPAGNES_TAILLE_LOT', 50),
        'seuil_alerte_volume' => (int) env('COURRIEL_CAMPAGNES_SEUIL_ALERTE', 500),
        'max_destinataires' => (int) env('COURRIEL_CAMPAGNES_MAX_DESTINATAIRES', 10000),
        'max_tentatives_job' => (int) env('COURRIEL_CAMPAGNES_MAX_TENTATIVES', 3),
        'delai_reprise_echecs_secondes' => (int) env('COURRIEL_CAMPAGNES_DELAI_REPRISE', 60),
    ],
];
