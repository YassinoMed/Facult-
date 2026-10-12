<?php

/*
|--------------------------------------------------------------------------
| Registre des modules (§10-11/§34) — plateforme CORE + MODULES
|--------------------------------------------------------------------------
| Chaque module déclare ici son activation (serveur uniquement, via
| variable d'environnement), ses entrées de navigation et ses widgets
| de dashboard. Le navigateur ne décide jamais : les routes d'un
| module désactivé répondent 404 (middleware `module:nom`) et ses
| liens ne sont pas rendus.
*/
return [

    'attendance' => [
        'active' => (bool) env('MODULE_ATTENDANCE', false),
    ],

    'student-dossiers' => [
        'active' => (bool) env('MODULE_STUDENT_DOSSIERS', false),
    ],

    'online-requests' => [
        'active' => (bool) env('MODULE_ONLINE_REQUESTS', false),
    ],

    'schedule' => [
        'active' => (bool) env('MODULE_SCHEDULE', false),
    ],

    // Enseignement : couche ECUE → enseignements — actif par défaut.
    'enseignement' => [
        'active' => (bool) env('MODULE_ENSEIGNEMENT', true),
    ],

    // Référentiel LMD : fondation des autres modules — actif par défaut.
    'referentiel' => [
        'active' => (bool) env('MODULE_REFERENTIEL', true),
    ],

    // Évaluation académique (Phase 4) : sessions → examens → épreuves →
    // notes → UE/semestre → délibérations. Désactivé par défaut.
    'evaluation' => [
        'active' => (bool) env('MODULE_EVALUATION', false),
    ],

    // Annonces ciblées (Phase 5) : publication + diffusion notifications.
    'announcements' => [
        'active' => (bool) env('MODULE_ANNOUNCEMENTS', false),
    ],

    // Calendrier académique (Phase 5) : événementiel transversal.
    'calendar' => [
        'active' => (bool) env('MODULE_CALENDAR', false),
    ],

    // Tâches & cockpit (Phases 7-9) : centre transverse idempotent.
    'tasks' => [
        'active' => (bool) env('MODULE_TASKS', false),
    ],

    // Finances universitaires (Phase 23) : tarifs, factures, paiements.
    'finances' => [
        'active' => (bool) env('MODULE_FINANCES', false),
    ],

    // Archive étudiant historique : dossier longitudinal 360 (vue consolidée).
    'student-archive' => [
        'active' => (bool) env('MODULE_STUDENT_ARCHIVE', false),
    ],

    // Parcours académique avancé LMD : planification, simulations,
    // crédits, dettes, équivalences, dispenses, réorientations,
    // passerelles, risques, historique, comparaison. Désactivé par défaut.
    'parcours-academique' => [
        'active' => (bool) env('MODULE_PARCOURS_ACADEMIQUE', false),
    ],

    // Versioning interne APP / DB / DEV / PROD & releases
    'versioning' => [
        'active' => (bool) env('MODULE_VERSIONING', true),
    ],

    // Platform Sentinel : surveillance transverse, contrôles
    // d'intégrité et incidents. Désactivé par défaut.
    'sentinel' => [
        'active' => (bool) env('MODULE_SENTINEL', false),
    ],

    /*
    | Navigation modulaire (§14) : filtrée par module actif côté
    | registre, puis par permission côté Blade (@can).
    */
    'nav' => [
        'admin' => [
            [
                'module' => 'attendance',
                'label' => 'Présences',
                'route' => 'admin.presences.index',
                'permission' => 'attendance.view',
            ],
            [
                'module' => 'attendance',
                'label' => 'Modèles de fiches',
                'route' => 'admin.presences.modeles.index',
                'permission' => 'attendance.templates.manage',
            ],
            [
                'module' => 'student-dossiers',
                'label' => 'Dossiers étudiants',
                'route' => 'admin.student-dossiers.index',
                'permission' => 'student_dossiers.view',
            ],
            [
                'module' => 'online-requests',
                'label' => 'Demandes',
                'route' => 'admin.demandes.index',
                'permission' => 'demandes.view',
            ],
            [
                'module' => 'schedule',
                'label' => 'Emplois du temps',
                'route' => 'admin.emplois-du-temps.index',
                'permission' => 'schedules.view',
            ],
            [
                'module' => 'referentiel',
                'label' => 'Référentiel LMD',
                'route' => 'admin.referentiel.tableau-bord',
                'permission' => 'referentiel.view',
            ],
            [
                'module' => 'enseignement',
                'label' => 'Enseignements',
                'route' => 'admin.enseignements.tableau-bord',
                'permission' => 'enseignements.view',
            ],
            [
                'module' => 'evaluation',
                'label' => 'Évaluations',
                'route' => 'admin.evaluations.index',
                'permission' => 'evaluations.view',
            ],
            [
                'module' => 'evaluation',
                'label' => 'Épreuves & Notes',
                'route' => 'admin.evaluations.epreuves.index',
                'permission' => 'notes_eval.view',
            ],
            [
                'module' => 'evaluation',
                'label' => 'Affectation enseignants',
                'route' => 'admin.evaluations.affectations.index',
                'permission' => 'epreuves.view',
                'icone' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
            ],
            [
                'module' => 'evaluation',
                'label' => 'Emploi des surveillances',
                'route' => 'admin.evaluations.surveillances.emploi',
                'permission' => 'epreuves.view',
                'icone' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
            ],
            [
                'module' => 'evaluation',
                'label' => 'Surveillance',
                'route' => 'admin.evaluations.surveillances.index',
                'permission' => 'epreuves.view',
                'icone' => 'M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z',
            ],
            [
                'module' => 'evaluation',
                'label' => 'Contraintes d’affectation',
                'route' => 'admin.evaluations.surveillances.contraintes.index',
                'permission' => 'surveillances.manage',
                'icone' => 'M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9 0h6.375',
            ],
            [
                'module' => 'parcours-academique',
                'label' => 'Parcours académique',
                'route' => 'admin.parcours.index',
                'permission' => 'parcours.view',
            ],
            [
                'module' => 'announcements',
                'label' => 'Annonces',
                'route' => 'admin.annonces.index',
                'permission' => 'annonces.view',
            ],
            [
                'module' => 'calendar',
                'label' => 'Calendrier',
                'route' => 'admin.calendrier.index',
                'permission' => 'calendriers.view',
            ],
            [
                'module' => 'tasks',
                'label' => 'Tâches',
                'route' => 'admin.taches.index',
                'permission' => 'tasks.view',
            ],
            [
                'module' => 'finances',
                'label' => 'Finances',
                'route' => 'admin.finances.index',
                'permission' => 'finances.view',
            ],
            [
                'module' => 'schedule',
                'label' => 'Explorer les plannings',
                'route' => 'admin.emplois-du-temps.explorer',
                'permission' => 'schedules.view',
            ],
            [
                'module' => 'versioning',
                'label' => 'Versions & Releases',
                'route' => 'admin.versioning.index',
                'permission' => 'versioning.view',
            ],
            [
                'module' => 'sentinel',
                'label' => 'Sentinelle plateforme',
                'route' => 'admin.sentinel.index',
                'permission' => 'sentinel.view',
            ],
        ],
        'etudiant' => [
            [
                'module' => 'attendance',
                'label' => 'Mes absences',
                'route' => 'etudiant.absences.index',
                'permission' => null, // espace personnel : session-scopé, sans permission back-office
            ],
            [
                'module' => 'attendance',
                'label' => 'Pointer ma présence',
                'route' => 'etudiant.appel.qr',
                'permission' => null,
            ],
            [
                'module' => 'student-dossiers',
                'label' => 'Mon dossier',
                'route' => 'etudiant.dossier.show',
                'permission' => null,
            ],
            [
                'module' => 'online-requests',
                'label' => 'Mes demandes',
                'route' => 'etudiant.demandes.index',
                'permission' => null,
            ],
            [
                'module' => 'schedule',
                'label' => 'Emploi du temps',
                'route' => 'etudiant.emploi-du-temps.semaine',
                'permission' => null,
            ],
            [
                'module' => 'announcements',
                'label' => 'Annonces',
                'route' => 'etudiant.annonces.index',
                'permission' => null,
            ],
            [
                'module' => 'calendar',
                'label' => 'Calendrier',
                'route' => 'etudiant.calendrier.agenda',
                'permission' => null,
            ],
            [
                'module' => 'enseignement',
                'label' => 'Supports de cours',
                'route' => 'etudiant.ressources.index',
                'permission' => null,
            ],
        ],
        'enseignant' => [
            // Rubriques modules (filtre d'activation natif : masquées si
            // module inactif, jamais de doublon avec les entrées en dur
            // ci-dessus qui couvrent demandes/réclamations/documents).
            [
                'module' => 'schedule',
                'label' => 'Mon emploi du temps',
                'route' => 'enseignant.emploi-du-temps.semaine',
                'permission' => null, // espace personnel : session-scopé, sans permission back-office
            ],
            [
                'module' => 'evaluation',
                'label' => 'Mes surveillances',
                'route' => 'enseignant.surveillances.index',
                'permission' => null,
            ],
            [
                'module' => 'evaluation',
                'label' => 'Mes notes à saisir',
                'route' => 'enseignant.notes.index',
                'permission' => null,
            ],
            [
                'module' => 'attendance',
                'label' => 'Mes présences',
                'route' => 'enseignant.presences.index',
                'permission' => null,
            ],
            [
                'module' => 'announcements',
                'label' => 'Annonces',
                'route' => 'enseignant.annonces.index',
                'permission' => null,
            ],
            [
                'module' => 'enseignement',
                'label' => 'Mes supports',
                'route' => 'enseignant.ressources.index',
                'permission' => null,
            ],
        ],
    ],

    /*
    | Widgets de dashboard (§15) : vues incluses par les dashboards
    | core uniquement si le module est actif. Chaque widget charge
    | ses propres données via un view composer du module.
    */
    'widgets' => [
        'admin' => [
            ['module' => 'attendance', 'view' => 'modules.attendance.widget-admin'],
            ['module' => 'student-dossiers', 'view' => 'modules.student-dossiers.widget-admin'],
            ['module' => 'online-requests', 'view' => 'modules.online-requests.widget-admin'],
            ['module' => 'schedule', 'view' => 'modules.schedule.widget-admin'],
            ['module' => 'evaluation', 'view' => 'modules.evaluation.widget-admin'],
            ['module' => 'announcements', 'view' => 'modules.announcements.widget-admin'],
            ['module' => 'tasks', 'view' => 'modules.tasks.widget-admin'],
        ],
        'etudiant' => [
            ['module' => 'attendance', 'view' => 'modules.attendance.widget-taux'],
            ['module' => 'student-dossiers', 'view' => 'modules.student-dossiers.widget-dossier'],
            ['module' => 'online-requests', 'view' => 'modules.online-requests.widget-etudiant'],
            ['module' => 'schedule', 'view' => 'modules.schedule.widget-etudiant'],
        ],
    ],
];
