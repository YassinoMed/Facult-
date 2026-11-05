<?php

/*
|--------------------------------------------------------------------------
| Référentiel réglementaire — Gestion des acquis, compensation et redoublement
|--------------------------------------------------------------------------
|
| Base juridique nationale (Enseignement Supérieur Tunisie) :
| - Décret présidentiel n° 2022-631 du 14 juillet 2022 fixant le cadre général
|   du régime des études et les conditions d'obtention du diplôme national de Licence
|   dans le système « LMD » (JORT n° 80 du 19 juillet 2022, p. 2267-2274).
| - Arrêté ministériel du 7 juillet 2023 relatif au guide des procédures unifié du système
|   des crédits et aux règles générales d'évaluation et de passage pour la Licence LMD
|   (JORT n° 70 du 11 juillet 2023, p. 2167-2178).
| - Arrêté ministériel du 10 mai 2023 fixant la liste des diplômes nationaux de licence
|   du système « LMD » concernés par le seuil de compétence (حد الكفاءة - JORT n° 51 du 19 mai 2023).
| - Décret n° 95-2602 du 25 décembre 1995 fixant le cadre général du régime des études
|   et les conditions d'obtention du diplôme national d'ingénieur (JORT n° 03 du 09 janvier 1996, p. 43-48),
|   modifié par le décret n° 2009-643 du 2 mars 2009.
| - Décret n° 2008-3123 du 22 septembre 2008 et Décret n° 2012-1227 du 1er août 2012
|   fixant le cadre général des études de mastère dans le système « LMD » (JORT n° 78 du 26 septembre 2008 et JORT n° 64 du 14 août 2012).
|
| Principes directeurs :
| - Jamais de constante globale aveugle : les règles dépendent du régime du diplôme.
| - Si le régime applicable est inconnu : fail-closed (anomalie explicite, aucun calcul arbitraire).
| - Art. 8 Arrêté 2023 : UE capitalisée dès moyenne >= 10/20.
| - Art. 8 Arrêté 2023 : ECUE capitalisé séparément SI note >= 10/20 ET crédits propres dans la maquette.
| - Art. 9 Arrêté 2023 : UE validée par compensation reste liée au parcours, non transférable à 100% vers un autre.
| - Art. 10 Arrêté 2023 : Unités/éléments capitalisés valorisés et non repassés en redoublement.
| - Art. 11 Arrêté 2023 : UE à contrôle continu exclusif sans session de rattrapage automatique.
| - Art. 13 Arrêté 2023 : Seuil de compétence éliminatoire empêchant la compensation si note < seuil.
| - Art. 16 Arrêté 2023 : Rachat = compétence discrétionnaire exclusive du jury, aucun automatisme logiciel.
|
*/

return [
    'regimes' => [
        'LMD_LICENCE' => [
            'nom' => 'Licence Nationale LMD',
            'diplomes_concernes' => ['LIC', 'LF', 'LA', 'LMD'],
            'textes_officiels' => [
                'decret' => 'Décret présidentiel n° 2022-631 du 14 juillet 2022',
                'decret_detail' => 'Décret présidentiel n° 2022-631 du 14 juillet 2022 fixant le cadre général du régime des études et les conditions d\'obtention du diplôme national de Licence dans le système « LMD »',
                'jort_decret' => 'JORT n° 80 du 19 juillet 2022, pages 2267-2274',
                'arrete' => 'Arrêté ministériel du 7 juillet 2023 (système de crédits et capitalisation)',
                'arrete_detail' => 'Arrêté du ministre de l\'Enseignement supérieur et de la Recherche scientifique du 7 juillet 2023 fixant le guide des procédures unifié du système des crédits et les règles générales d\'évaluation et de passage applicables au diplôme national de licence',
                'jort_arrete' => 'JORT n° 70 du 11 juillet 2023, pages 2167-2178',
                'arrete_seuil' => 'Arrêté ministériel du 10 mai 2023 fixant la liste des diplômes nationaux de licence du système « LMD » concernés par le principe du seuil de compétence (حد الكفاءة)',
            ],
            'articles_cles' => [
                'capitalisation_ue' => 'Article 8 (UE capitalisée dès 10.0/20 à vie)',
                'capitalisation_ecue' => 'Article 8 (ECUE capitalisé si note >= 10.0 et crédits propres)',
                'compensation' => 'Article 9 (Compensation semestrielle et annuelle - non transférable automatiquement)',
                'redoublement' => 'Article 10 (Non-repassage des unités et éléments capitalisés)',
                'controle_continu' => 'Article 11 (CC exclusif sans session de rattrapage automatique)',
                'seuil_competence' => 'Article 13 (Seuil de compétence éliminatoire bloquant la compensation)',
                'rachat' => 'Article 16 (Rachat discrétionnaire exclusif du jury)',
            ],
            // Article 8 : Capitalisation UE dès 10.0/20
            'seuil_capitalisation_ue' => 10.0,
            // Article 8 : Capitalisation ECUE si note >= 10.0 ET crédits propres dans la maquette
            'seuil_capitalisation_ecue' => 10.0,
            'ecue_exige_credits_propres' => true,
            // Article 9 : Compensation semestre / année autorisée sous conditions
            'compensation_autorisee' => true,
            'seuil_compensation_generale' => 10.0,
            // Article 13 : Seuil de compétence éliminatoire (6.0/20 selon arrêté du 10 mai 2023 pour les filières ciblées)
            'seuil_competence_defaut' => 6.0,
            'reference_seuil' => 'Arrêté ministériel du 10 mai 2023 & Arrêté du 7 juillet 2023 (Art. 13)',
            'date_effet_seuil' => '2023-09-01',
            // Article 9 : UE compensée non transférable intégralement vers un autre parcours
            'transferabilite_ue_compensee' => false,
            // Article 11 : Contrôle continu exclusif n'ouvre pas de rattrapage automatique
            'exclure_rattrapage_cc_pur' => true,
            // Article 16 : Rachat = discrétionnaire jury, 0 automatisme logiciel
            'rachat_automatique' => false,
            // Article 10 : Éléments capitalisés conservés et non repassés en redoublement
            'dispense_repassage_capitalise' => true,
        ],

        'CYCLE_INGENIEUR' => [
            'nom' => 'Cycle Ingénieur',
            'diplomes_concernes' => ['ING', 'INGENIEUR'],
            'textes_officiels' => [
                'decret' => 'Décret n° 95-2602 du 25 décembre 1995 (modifié par Décret 2009-643)',
                'decret_detail' => 'Décret n° 95-2602 du 25 décembre 1995 fixant le cadre général du régime des études et les conditions d\'obtention du diplôme national d\'ingénieur (JORT n° 03 du 09 janvier 1996, p. 43-48, rectifiant la mention erronée Décret n° 95-2604 du 25 décembre 1995), complété par les règlements intérieurs d\'écoles (ENIT, ENSI, INSAT, EPT)',
                'jort_decret' => 'JORT n° 03 du 09 janvier 1996, pages 43-48',
            ],
            'articles_cles' => [
                'capitalisation_ue' => 'Décret n° 95-2602 - Art. 12 (Validation de module si moyenne >= 10.0)',
                'seuil_competence' => 'Décret n° 95-2602 - Art. 14 et règlements intérieurs d\'écoles (seuil éliminatoire de module 8.00/20, rectifiant l\'ancienne référence 95-2604)',
                'compensation' => 'Décret n° 95-2602 - Art. 15 (Moyenne générale annuelle >= 10.0/20 sans note de module inférieure au seuil éliminatoire)',
                'redoublement' => 'Décret n° 95-2602 - Art. 17 (Conservation des modules validés en redoublement)',
            ],
            'seuil_capitalisation_ue' => 10.0,
            'seuil_capitalisation_ecue' => 10.0,
            'ecue_exige_credits_propres' => true,
            'compensation_autorisee' => true,
            'seuil_compensation_generale' => 10.0,
            'seuil_competence_defaut' => 8.0, // seuil éliminatoire de module prévu par Art. 14 et scolarité
            'reference_seuil' => 'Décret n° 95-2602 du 25 décembre 1995 (rectifiant la mention Décret n° 95-2604) - Art. 14 (seuil éliminatoire de module 8.00/20)',
            'date_effet_seuil' => '1996-09-01',
            'transferabilite_ue_compensee' => false,
            'exclure_rattrapage_cc_pur' => true,
            'rachat_automatique' => false,
            'dispense_repassage_capitalise' => true,
        ],

        'MASTER_LMD' => [
            'nom' => 'Mastère LMD (Recherche et Professionnel)',
            'diplomes_concernes' => ['MAS', 'MR', 'MP'],
            'textes_officiels' => [
                'decret' => 'Décret fixant le cadre général des études de mastère LMD',
                'decret_detail' => 'Décret n° 2008-3123 du 22 septembre 2008 et Décret n° 2012-1227 du 1er août 2012 fixant le cadre général du régime des études et les conditions d\'obtention des diplômes nationaux de mastère dans le système « LMD »',
                'jort_decret' => 'JORT n° 78 du 26 septembre 2008 et JORT n° 64 du 14 août 2012',
            ],
            'articles_cles' => [
                'capitalisation_ue' => 'Décret n° 2008-3123 - Art. 8 (Capitalisation des UE >= 10.0)',
                'compensation' => 'Décret n° 2008-3123 - Art. 10 (Compensation au niveau semestriel)',
                'seuil_competence' => 'Décret n° 2008-3123 (Aucun seuil éliminatoire général par défaut ; soumis à l\'habilitation)',
            ],
            'seuil_capitalisation_ue' => 10.0,
            'seuil_capitalisation_ecue' => 10.0,
            'ecue_exige_credits_propres' => true,
            'compensation_autorisee' => true,
            'seuil_compensation_generale' => 10.0,
            'seuil_competence_defaut' => null,
            'reference_seuil' => 'Décret n° 2008-3123 & Décret n° 2012-1227 (aucun seuil éliminatoire par défaut)',
            'date_effet_seuil' => '2008-09-01',
            'transferabilite_ue_compensee' => false,
            'exclure_rattrapage_cc_pur' => true,
            'rachat_automatique' => false,
            'dispense_repassage_capitalise' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Matrice Réglementaire par Formation et Parcours (LOOP 38 & LOOP 39)
    |--------------------------------------------------------------------------
    |
    | Enregistre pour chaque diplôme et formation les 12 informations réglementaires :
    | - Diplôme, Établissement, Formation, Régime, Seuil, Compensation, Capitalisation,
    |   Redoublement, Passage conditionnel, Rachat, Date d'effet, Version, et Statut.
    |
    | Statuts certifiés (Taxonomie LOOP 39) :
    | - REGLE_CONFIRMEE (CONFIRME)
    | - ABSENCE_CONFIRMEE
    | - SOURCE_MANQUANTE
    | - REGLE_A_VALIDER (NON_CONFIGURE)
    | - REGLE_EXPIREE
    |
    */
    'matrice_formations' => [
        'LIC_SANTE' => [
            'diplome' => 'Licence Nationale en Sciences de la Santé et Technologies Médicales',
            'etablissement' => 'Faculté de Médecine / École Supérieure des Sciences et Techniques de la Santé',
            'formation' => 'Sciences Infirmières et Technologies Médicales',
            'regime' => 'LMD_LICENCE',
            'texte' => 'Arrêté ministériel du 10 mai 2023 (JORT n° 51 du 19 mai 2023)',
            'article' => 'Articles 1er et 2 (seuil éliminatoire حد الكفاءة)',
            'seuil' => 6.0,
            'compensation' => 'Compensation semestrielle conditionnée à l\'absence de toute note inférieure au seuil éliminatoire de 6.0/20',
            'capitalisation' => 'UE capitalisée dès moyenne >= 10.0 ; ECUE capitalisé si note >= 10.0 avec crédits propres',
            'redoublement' => 'Deux redoublements autorisés au maximum sur l\'ensemble du cycle de 3 ans (1 seul en 1ère année L1)',
            'passage_conditionnel' => 'Validation d\'au moins 45 crédits sur 60 du niveau précédent (Arrêté du 7 juillet 2023, Art. 12)',
            'rachat' => 'Prérogative discrétionnaire exclusive du jury de délibération (Art. 16, aucun automatisme logiciel)',
            'unite_concernee' => 'UE et éléments constitutifs fondamentaux',
            'date_effet' => '2023-09-01',
            'date_fin' => null,
            'version' => '2023.1',
            'statut' => 'CONFIRME',
            'statut_loop39' => 'REGLE_CONFIRMEE',
        ],
        'LIC_EDUCATION' => [
            'diplome' => 'Licence Nationale en Éducation et Enseignement',
            'etablissement' => 'Facultés des Lettres et Sciences Humaines',
            'formation' => 'Sciences de l\'Éducation et Didactique',
            'regime' => 'LMD_LICENCE',
            'texte' => 'Arrêté ministériel du 10 mai 2023 (JORT n° 51 du 19 mai 2023)',
            'article' => 'Articles 1er et 2 (seuil éliminatoire حد الكفاءة)',
            'seuil' => 6.0,
            'compensation' => 'Compensation semestrielle conditionnée à l\'absence de toute note < 6.0/20',
            'capitalisation' => 'UE capitalisée dès 10.0 ; ECUE capitalisé si >= 10.0 et crédits propres',
            'redoublement' => 'Deux redoublements max dans le cursus de licence de 3 ans (1 seul en L1)',
            'passage_conditionnel' => 'Au moins 45 crédits sur 60 validés avec dettes d\'unités non acquises',
            'rachat' => 'Prérogative discrétionnaire exclusive du jury souverain',
            'unite_concernee' => 'UE et matières disciplinaires fondamentales',
            'date_effet' => '2023-09-01',
            'date_fin' => null,
            'version' => '2023.1',
            'statut' => 'CONFIRME',
            'statut_loop39' => 'REGLE_CONFIRMEE',
        ],
        'LIC_INFO_STANDARD' => [
            'diplome' => 'Licence Nationale en Sciences de l\'Informatique',
            'etablissement' => 'Faculté des Sciences de Tunis (FST)',
            'formation' => 'Informatique Fondamentale et Développement Logiciel',
            'regime' => 'LMD_LICENCE',
            'texte' => 'Décret présidentiel n° 2022-631 du 14 juillet 2022 (JORT n° 80 du 19 juillet 2022)',
            'article' => 'Articles 13 et 14 (absence de seuil éliminatoire général par défaut)',
            'seuil' => null,
            'compensation' => 'Compensation semestrielle et annuelle de plein droit dès moyenne générale >= 10.0/20',
            'capitalisation' => 'UE capitalisée dès 10.0/20 ; ECUE capitalisé si >= 10.0/20 et crédits propres',
            'redoublement' => 'Deux redoublements max sur l\'ensemble du cursus de 3 ans (1 seul en L1)',
            'passage_conditionnel' => '45 crédits acquis sur 60 pour le passage de L1 à L2 ou de L2 à L3',
            'rachat' => 'Rachat souverain par le jury de délibération (Art. 16 Arrêté du 7 juillet 2023)',
            'unite_concernee' => 'Aucune (compensation globale autorisée dès moyenne >= 10/20)',
            'date_effet' => '2022-09-01',
            'date_fin' => null,
            'version' => '2022.1',
            'statut' => 'ABSENCE_CONFIRMEE',
            'statut_loop39' => 'ABSENCE_CONFIRMEE',
        ],
        'ING_INFO_ENIT' => [
            'diplome' => 'Diplôme National d\'Ingénieur en Génie Informatique',
            'etablissement' => 'École Nationale d\'Ingénieurs de Tunis (ENIT)',
            'formation' => 'Génie Informatique et Réseaux',
            'regime' => 'CYCLE_INGENIEUR',
            'texte' => 'Décret n° 95-2602 du 25 décembre 1995 & Règlement de scolarité ENIT',
            'article' => 'Article 10 (limitation redoublement), Art. 12 (validation modules), Art. 14 (seuil)',
            'seuil' => 8.0,
            'compensation' => 'Compensation annuelle entre modules si moyenne générale >= 10.0/20 ET aucune note de module < 8.0/20',
            'capitalisation' => 'Conservation des modules validés avec moyenne >= 10.0/20 (Art. 17 Décret 95-2602)',
            'redoublement' => 'Un seul redoublement autorisé au cours de l\'ensemble de la scolarité de 3 ans (Art. 10 Décret 95-2602)',
            'passage_conditionnel' => 'Selon délibération de passage avec dettes de modules non éliminatoires',
            'rachat' => 'Discrétionnaire par le conseil des enseignants / jury de l\'école',
            'unite_concernee' => 'Modules partiels et unités d\'ingénierie (seuil éliminatoire)',
            'date_effet' => '1996-09-01',
            'date_fin' => null,
            'version' => '1995.1',
            'statut' => 'CONFIRME',
            'statut_loop39' => 'REGLE_CONFIRMEE',
        ],
        'ING_NON_CONFIGURE' => [
            'diplome' => 'Diplôme National d\'Ingénieur Spécialité Émergente',
            'etablissement' => 'Établissement Non Configuré',
            'formation' => 'Génie Spécialité Non Déposée',
            'regime' => 'CYCLE_INGENIEUR',
            'texte' => 'Décret n° 95-2602 du 25 décembre 1995',
            'article' => 'Règlement interne non déposé',
            'seuil' => null,
            'compensation' => 'Non configurée',
            'capitalisation' => 'Modules validés >= 10.0/20',
            'redoublement' => 'Un seul redoublement en attente de validation du règlement intérieur',
            'passage_conditionnel' => 'Non spécifié',
            'rachat' => 'Non configuré',
            'unite_concernee' => 'Non spécifiées',
            'date_effet' => '1996-09-01',
            'date_fin' => null,
            'version' => '1995.1',
            'statut' => 'NON_CONFIGURE',
            'statut_loop39' => 'REGLE_A_VALIDER',
        ],
        'MASTER_RECHERCHE_FST' => [
            'diplome' => 'Mastère de Recherche en Informatique et Systèmes Intelligents',
            'etablissement' => 'Faculté des Sciences de Tunis (FST)',
            'formation' => 'Systèmes Intelligents et Décision',
            'regime' => 'MASTER_LMD',
            'texte' => 'Décret n° 2008-3123 du 22 septembre 2008 et Décret n° 2012-1227 du 1er août 2012',
            'article' => 'Articles 8 et 10 (soumis à l\'habilitation de la maquette)',
            'seuil' => null,
            'compensation' => 'Compensation semestrielle autorisée selon arrêté d\'habilitation du mastère',
            'capitalisation' => 'UE capitalisée dès 10.0/20 à vie',
            'redoublement' => 'Un seul redoublement autorisé sur l\'ensemble du cursus de mastère en 2 ans (M1 ou M2)',
            'passage_conditionnel' => 'Conditions fixées par la commission de mastère de l\'établissement',
            'rachat' => 'Commission de mastère souveraine',
            'unite_concernee' => 'Unités de mastère (absence de seuil éliminatoire général par défaut)',
            'date_effet' => '2008-09-01',
            'date_fin' => null,
            'version' => '2008.1',
            'statut' => 'ABSENCE_CONFIRMEE',
            'statut_loop39' => 'ABSENCE_CONFIRMEE',
        ],
        'FORMATION_A_VALIDER' => [
            'diplome' => 'Licence Co-construite en Intelligence Artificielle et Systèmes Embarqués',
            'etablissement' => 'Institut Supérieur des Technologies Avancées',
            'formation' => 'Intelligence Artificielle Embarquée',
            'regime' => 'LMD_LICENCE',
            'texte' => 'Projet de maquette en cours d\'habilitation ministérielle',
            'article' => 'Projet soumis au Conseil des Universités',
            'seuil' => null,
            'compensation' => 'À valider par arrêté ministériel',
            'capitalisation' => 'À déterminer',
            'redoublement' => 'Régime LMD standard sous réserve de validation',
            'passage_conditionnel' => 'À valider',
            'rachat' => 'À valider',
            'unite_concernee' => 'Unités spécifiques en attente',
            'date_effet' => '2027-09-01',
            'date_fin' => null,
            'version' => '0.9-draft',
            'statut' => 'NON_CONFIGURE',
            'statut_loop39' => 'REGLE_A_VALIDER',
        ],
        'FORMATION_EXPIREE' => [
            'diplome' => 'Licence Fondamentale Sciences Informatiques (Maquette 2018)',
            'etablissement' => 'Faculté des Sciences',
            'formation' => 'Sciences Informatiques Tronc Commun 2018',
            'regime' => 'LMD_LICENCE',
            'texte' => 'Arrêté ministériel du 28 juin 2018 (abrogé par Décret 2022-631)',
            'article' => 'Régime abrogé',
            'seuil' => null,
            'compensation' => 'Ancien système de compensation semestrielle',
            'capitalisation' => 'Unités validées conservées',
            'redoublement' => 'Ancien quota de redoublement',
            'passage_conditionnel' => 'Ancien seuil de 75% des crédits',
            'rachat' => 'Ancien jury de délibération',
            'unite_concernee' => 'Modules de la maquette 2018',
            'date_effet' => '2018-09-01',
            'date_fin' => '2022-08-31',
            'version' => '2018.1',
            'statut' => 'SOURCE_MANQUANTE',
            'statut_loop39' => 'REGLE_EXPIREE',
        ],
        'FORMATION_INCONNUE' => [
            'diplome' => 'Certificat Transversal Sans Régime',
            'etablissement' => 'Inconnu',
            'formation' => 'Non documentée',
            'regime' => 'INCONNU',
            'texte' => 'Aucun texte officiel recensé',
            'article' => 'N/A',
            'seuil' => null,
            'compensation' => 'Non documentée',
            'capitalisation' => 'Non documentée',
            'redoublement' => 'Non documenté',
            'passage_conditionnel' => 'Non documenté',
            'rachat' => 'Non documenté',
            'unite_concernee' => 'N/A',
            'date_effet' => 'N/A',
            'date_fin' => null,
            'version' => '0.0',
            'statut' => 'SOURCE_MANQUANTE',
            'statut_loop39' => 'SOURCE_MANQUANTE',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limites Réglementaires de Redoublement par Régime (LOOP 39 — PHASE 1 & 2)
    |--------------------------------------------------------------------------
    |
    | Détermine pour chaque cycle le nombre maximal d'inscriptions et de redoublements
    | autorisés de plein droit, ainsi que les autorités habilitées pour dérogation.
    |
    */
    'limites_redoublement' => [
        'CYCLE_INGENIEUR' => [
            'regime' => 'CYCLE_INGENIEUR',
            'nom' => 'Cycle de Formation d\'Ingénieurs (3 ans)',
            'max_redoublements_cycle' => 1,
            'max_redoublements_par_niveau' => 1,
            'duree_maximale_etudes_annees' => 4, // 3 ans d'études + 1 seul redoublement
            'texte' => 'Décret n° 95-2602 du 25 décembre 1995 (modifié par Décret n° 2009-643)',
            'article' => 'Article 10',
            'regle' => 'Le redoublement dans les établissements de formation d\'ingénieurs n\'est autorisé qu\'une seule fois au cours de la scolarité.',
            'derogation_possible' => true,
            'autorite_derogation' => 'Conseil scientifique / Direction de l\'école d\'ingénieurs',
        ],
        'LMD_LICENCE' => [
            'regime' => 'LMD_LICENCE',
            'nom' => 'Cycle de Licence LMD (3 ans)',
            'max_redoublements_cycle' => 2,
            'max_redoublements_par_niveau' => 1, // 1 seul redoublement autorisé en 1ère année (L1)
            'duree_maximale_etudes_annees' => 5, // 3 ans de licence + 2 redoublements max
            'texte' => 'Décret présidentiel n° 2022-631 du 14 juillet 2022 & Arrêté du 7 juillet 2023',
            'article' => 'Article 10 et réglementation générale universitaire',
            'regle' => 'Un seul redoublement autorisé en 1ère année (L1) et deux redoublements au maximum sur l\'ensemble du cycle de licence (durée totale maximale : 5 ans).',
            'derogation_possible' => true,
            'autorite_derogation' => 'Conseil de l\'Université / Président d\'Université',
        ],
        'MASTER_LMD' => [
            'regime' => 'MASTER_LMD',
            'nom' => 'Cycle de Mastère LMD (2 ans)',
            'max_redoublements_cycle' => 1,
            'max_redoublements_par_niveau' => 1,
            'duree_maximale_etudes_annees' => 3, // 2 ans + 1 seul redoublement
            'texte' => 'Décret n° 2008-3123 du 22 septembre 2008 & Décret n° 2012-1227 du 1er août 2012',
            'article' => 'Réglementation des études de mastère LMD',
            'regle' => 'Un seul redoublement autorisé au total au cours des deux années du cycle de mastère (M1 ou M2).',
            'derogation_possible' => true,
            'autorite_derogation' => 'Commission des mastères / Conseil scientifique de l\'établissement',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Matrice Récapitulative des Seuils de Compétence (LOOP 39 — PHASE 3)
    |--------------------------------------------------------------------------
    */
    'matrice_seuils' => [
        'LIC_SANTE' => [
            'valeur' => 6.0,
            'source' => 'Arrêté ministériel du 10 mai 2023 (JORT n° 51 du 19 mai 2023) - Art. 1er et 2',
            'date_effet' => '2023-09-01',
            'statut' => 'REGLE_CONFIRMEE',
        ],
        'LIC_EDUCATION' => [
            'valeur' => 6.0,
            'source' => 'Arrêté ministériel du 10 mai 2023 (JORT n° 51 du 19 mai 2023) - Art. 1er et 2',
            'date_effet' => '2023-09-01',
            'statut' => 'REGLE_CONFIRMEE',
        ],
        'LIC_INFO_STANDARD' => [
            'valeur' => null,
            'source' => 'Décret présidentiel n° 2022-631 du 14 juillet 2022 - Art. 13 et 14',
            'date_effet' => '2022-09-01',
            'statut' => 'ABSENCE_CONFIRMEE',
        ],
        'ING_INFO_ENIT' => [
            'valeur' => 8.0,
            'source' => 'Décret n° 95-2602 du 25 décembre 1995 (Art. 14) & Règlement intérieur ENIT',
            'date_effet' => '1996-09-01',
            'statut' => 'REGLE_CONFIRMEE',
        ],
        'ING_NON_CONFIGURE' => [
            'valeur' => null,
            'source' => 'Règlement interne non déposé',
            'date_effet' => '1996-09-01',
            'statut' => 'REGLE_A_VALIDER',
        ],
        'MASTER_RECHERCHE_FST' => [
            'valeur' => null,
            'source' => 'Décret n° 2008-3123 & Décret n° 2012-1227',
            'date_effet' => '2008-09-01',
            'statut' => 'ABSENCE_CONFIRMEE',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sécurité Cryptographique des Documents Officiels et Relevés A4
    |--------------------------------------------------------------------------
    */
    'crypto' => [
        'hmac_key' => env('ACQUIS_CRYPTO_HMAC_KEY', null),
        'version_active' => 'v1',
        'cles' => [
            'v1' => env('ACQUIS_CRYPTO_HMAC_KEY', null),
        ],
        'cles_anciennes' => [],
        'canonical_json' => true,
        'algorithme' => 'sha256',
        'limite_verifications_par_minute' => 30,
    ],
];
