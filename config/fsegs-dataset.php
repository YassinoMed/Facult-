<?php

/*
|--------------------------------------------------------------------------
| Dataset FSEGS (docs/testing/fsegs-dataset.md)
|--------------------------------------------------------------------------
| Volumes du jeu de test réaliste. Le générateur calcule les effectifs
| finaux depuis groupes × capacités (jamais de dépassement) : les
| `etudiants` par cohorte sont plafonnés à la capacité totale.
| Mode normal ≈ 5 000 (4 800–5 200). Mode load : --load=N multiplie
| groupes et effectifs (mêmes règles métier).
*/
return [
    'seed' => 20261001,
    'faculte' => ['code' => 'FSEGS', 'nom' => 'Faculté des Sciences Économiques et de Gestion'],
    'annee' => '2026-2027',

    'formations' => [
        'GESTION' => ['nom' => 'Gestion', 'code' => 'GES'],
        'ECO' => ['nom' => 'Économie', 'code' => 'ECO'],
        'IG' => ['nom' => 'Informatique de gestion', 'code' => 'IG'],
    ],

    // Cohortes : groupes imposés + cibles étudiants + plages de capacité.
    // Capacités déterministes : min + ((index * 7 + decalage) % amplitude).
    'cohortes' => [
        ['formation' => 'GESTION', 'niveau' => 'L1', 'groupes' => 38, 'etudiants' => 1300, 'cap_min' => 32, 'cap_max' => 35, 'prefixe' => 'GESTION-L1-G'],
        ['formation' => 'GESTION', 'niveau' => 'L2', 'groupes' => 22, 'etudiants' => 650, 'cap_min' => 28, 'cap_max' => 32, 'prefixe' => 'GESTION-L2-G'],
        ['formation' => 'GESTION', 'niveau' => 'L3', 'groupes' => 18, 'etudiants' => 550, 'cap_min' => 28, 'cap_max' => 32, 'prefixe' => 'GESTION-L3-G'],
        ['formation' => 'ECO', 'niveau' => 'L1', 'groupes' => 30, 'etudiants' => 900, 'cap_min' => 28, 'cap_max' => 32, 'prefixe' => 'ECO-L1-G'],
        ['formation' => 'ECO', 'niveau' => 'L2', 'groupes' => 15, 'etudiants' => 450, 'cap_min' => 28, 'cap_max' => 32, 'prefixe' => 'ECO-L2-G'],
        ['formation' => 'ECO', 'niveau' => 'L3', 'groupes' => 12, 'etudiants' => 350, 'cap_min' => 27, 'cap_max' => 31, 'prefixe' => 'ECO-L3-G'],
        ['formation' => 'IG', 'niveau' => 'L1', 'groupes' => 12, 'etudiants' => 400, 'cap_min' => 31, 'cap_max' => 35, 'prefixe' => 'IG-L1-G'],
        ['formation' => 'IG', 'niveau' => 'L2', 'groupes' => 9, 'etudiants' => 250, 'cap_min' => 26, 'cap_max' => 30, 'prefixe' => 'IG-L2-G'],
        ['formation' => 'IG', 'niveau' => 'L3', 'groupes' => 6, 'etudiants' => 150, 'cap_min' => 24, 'cap_max' => 28, 'prefixe' => 'IG-L3-G'],
    ],

    // Parts des cas d'affectation (approximatives, rapportées au réel).
    'parts' => ['affectes' => 0.15, 'verrouilles' => 0.05, 'contraintes' => 0.05, 'speciaux' => 12],

    'email_domaine' => 'fsegs.example',
];
