<?php

/*
|--------------------------------------------------------------------------
| Paiements des inscriptions annuelles (LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01)
|--------------------------------------------------------------------------
| Ce fichier ne contient AUCUN montant : les montants attendus proviennent
| exclusivement des règles officielles existantes (facture liée à
| l'inscription, sinon tarifs actifs de l'année). Les statuts sont des
| constantes métier, pas des libellés libres.
|
| `types_frais` vide = tous les tarifs actifs de l'année sont pris en
| compte. Le définir (ex. "inscription,scolarite") restreint la somme aux
| types officiellement exigibles pour l'inscription annuelle.
*/
return [

    // Interrupteur serveur : désactive le parcours sans supprimer les
    // données déjà déposées (aucune donnée historique n'est effacée).
    'actif' => (bool) env('INS_PAIEMENTS_ACTIF', true),

    // Racine du disque privé pour les reçus. Les documents permanents
    // restent sous `student-dossiers/` : aucun mélange, aucune réutilisation.
    'racine_stockage' => 'paiements-inscriptions',

    // Types de frais à additionner quand aucun facture n'existe.
    // Vide = tous les tarifs actifs (comportement le plus fidèle aux
    // règles existantes, aucune valeur inventée).
    'types_frais' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INS_PAIEMENTS_TYPES_FRAIS', ''))
    ))),

    'statuts' => [
        'NON_PAYE' => 'Non payé',
        'RECU_DEPOSE' => 'Reçu déposé',
        'EN_VERIFICATION' => 'En vérification',
        'VALIDE' => 'Validé',
        'REFUSE' => 'Refusé',
    ],

    // Instructions affichées à l'étudiant. Aucune coordonnée bancaire
    // n'est codée en dur : l'établissement les publie via son canal officiel.
    'instructions' => env(
        'INS_PAIEMENTS_INSTRUCTIONS',
        'Déposez uniquement un reçu officiel (PDF ou image lisible) émis par la banque ou le guichet. '
        ."Le dépôt d'un reçu ne vaut pas paiement confirmé : la scolarité vérifie le montant, la référence et la date avant validation. "
        ."Conservez l'original : il peut être demandé lors du contrôle."
    ),

    'motif_refus_min' => 10,
    'motif_refus_max' => 1000,
    'commentaire_max' => 1000,
];
