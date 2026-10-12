<?php

/*
|--------------------------------------------------------------------------
| Communication Center (Phase 15 §15.4) : mapping événement →
| gabarit + catégorie + activation. Désactiver une règle coupe
| l'envoi automatique sans toucher au code métier.
|--------------------------------------------------------------------------
| Catégories : resultats (+documents_officiels) obligatoires,
| inscriptions/demandes/annonces désactivables par l'utilisateur.
*/
return [
    'dedup_minutes' => 60,

    'evenements' => [
        'deliberation.publiee' => [
            'gabarit' => 'deliberation.publiee',
            'categorie' => 'resultats',
            'actif' => true,
        ],
        'inscription.valide' => [
            'gabarit' => 'inscription.valide',
            'categorie' => 'inscriptions',
            'actif' => true,
        ],
        'inscription.ajourne' => [
            'gabarit' => 'inscription.ajourne',
            'categorie' => 'inscriptions',
            'actif' => true,
        ],
        'inscription.abandon' => [
            'gabarit' => 'inscription.abandon',
            'categorie' => 'inscriptions',
            'actif' => true,
        ],
        'inscription.reinscrite' => [
            'gabarit' => 'inscription.reinscrite',
            'categorie' => 'inscriptions',
            'actif' => true,
        ],
        'inscription.progression' => [
            'gabarit' => 'inscription.progression',
            'categorie' => 'inscriptions',
            'actif' => true,
        ],
        // B1 (dette « notifications période ») : ouverture + clôture
        // proche, diffusées aux étudiants de la portée par
        // `periodes:avertir` (quotidien, dédupliqué).
        'periode.ouverte' => [
            'gabarit' => 'periode.ouverte',
            'categorie' => 'inscriptions',
            'actif' => true,
        ],
        'periode.bientot_fermee' => [
            'gabarit' => 'periode.bientot_fermee',
            'categorie' => 'inscriptions',
            'actif' => true,
        ],
        'inscription.transferee' => [
            'gabarit' => 'inscription.transferee',
            'categorie' => 'inscriptions',
            'actif' => true,
        ],
        // Parcours académique : décision d'aménagement (équivalence,
        // dispense, réorientation, passerelle). Gabarit à créer via
        // admin.notifications.templates ; sans gabarit = no-op tracé.
        'parcours.decision' => [
            'gabarit' => 'parcours.decision',
            'categorie' => 'parcours',
            'actif' => true,
        ],
    ],
];
