<?php

/**
 * LOOP 45 — RECOURS ACADÉMIQUES.
 *
 * Ce fichier ne contient AUCUNE règle officielle : ni délai de dépôt, ni
 * liste de décisions contestables, ni référence réglementaire. Tant que ces
 * valeurs ne sont pas fournies par l'autorité compétente, le circuit reste
 * inactif et aucun recours réel ne peut être déposé.
 *
 * Le délai, lorsqu'il sera fourni, ne provoque jamais un rejet automatique :
 * il produit un indicateur de recevabilité soumis à décision humaine.
 */
return [

    // Interrupteur maître : false ⇒ tout dépôt ou acte est refusé.
    'actif' => false,

    // Référence du texte officiel qui fonde le recours (obligatoire pour agir).
    'reference_reglementaire' => null,

    // Code du type de demande servant de support au recours.
    // Le type doit exister et être actif ; il n'est jamais créé implicitement.
    'type_code' => 'RECOURS_ACADEMIQUE',

    // Délai de dépôt en jours, à compter de la publication de la décision.
    // null ⇒ non configuré : la recevabilité reste "à vérifier" (jamais un rejet).
    'delai_depot_jours' => null,

    // Types de décisions contestables (valeurs de evaluation_decisions).
    // [] ⇒ aucun type autorisé : le dépôt est refusé, sans exclusion.
    'types_decisions_contestables' => [],

    // Une décision de recours ne modifie JAMAIS automatiquement une note,
    // une moyenne ou une décision de délibération. Cette clé existe pour être
    // vérifiée explicitement ; la passer à true fait échouer le service.
    'application_automatique_autorisee' => false,

    // Domaine et version du workflow utilisés par le moteur.
    'workflow' => [
        'domaine' => 'recours',
        'version' => 1,
    ],
];
