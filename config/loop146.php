<?php

/*
|--------------------------------------------------------------------------
| LOOP 146 — règles institutionnelles non établies
|--------------------------------------------------------------------------
|
| Aucune autorité, voie de recours, voie de réexamen ou canal de relance
| n'est réputé compétent/existant par défaut. Ces listes restent vides tant
| qu'un texte officiel n'a pas été produit, vérifié et référencé. Les
| services concernés échouent alors de manière sûre (fail-closed) au lieu
| d'inventer une procédure ou une compétence.
|
| Pour activer une règle, renseigner la liste correspondante ET la référence
| du texte officiel qui la fonde.
|
*/

return [
    'derogations' => [
        // Q137 / Q138 : mécanisme officiel de dérogation identifié ?
        'mecanisme_identifie' => false,

        // Q137 / Q138 : autorités dont la compétence est officiellement établie.
        'autorites_competentes' => [],

        // Q139 / Q140 : canaux institutionnels de relance existants.
        'canaux_relance' => [],

        // Q143 : voies officielles de réexamen.
        'voies_reexamen' => [],

        // Q142 : voies officielles de recours contre une attente prolongée.
        'voies_recours' => [],

        // Références des textes officiels fondant les listes ci-dessus.
        'references_officielles' => [],
    ],
];
