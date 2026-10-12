<?php

/*
|--------------------------------------------------------------------------
| Inscriptions — réinscription simplifiée
|--------------------------------------------------------------------------
| Types de pièces considérés comme requis pour une (ré)inscription
| (codes `document_type` GED). Liste vide = aucun requis imposé :
| la checklist affiche alors uniquement les pièces réutilisables.
| Surcharge via .env : INS_DOCUMENTS_REQUIS=piece_identite,photo
|
| Matrice par type d'inscription (mission documents obligatoires) :
| chaque type peut exiger ses propres pièces (première inscription
| complète, réinscription allégée, transfert spécifique). Vide =
| aucun requis imposé pour ce type (la validation reste autorisée).
| Surcharge via .env : INS_DOCUMENTS_REQUIS_PREMIERE=cin,photo_identite
*/
$liste = fn (string $cle): array => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env($cle, ''))
)));

$requis = $liste('INS_DOCUMENTS_REQUIS');

return [
    'documents_requis' => $requis,
    'documents_requis_par_type' => [
        'premiere' => $liste('INS_DOCUMENTS_REQUIS_PREMIERE'),
        'reinscription' => $liste('INS_DOCUMENTS_REQUIS_REINSCRIPTION'),
        'transfert' => $liste('INS_DOCUMENTS_REQUIS_TRANSFERT'),
    ],
    /*
    |--------------------------------------------------------------------------
    | Fuseau des périodes d'inscription (Lot 3)
    |--------------------------------------------------------------------------
    | Les bornes sont STOCKÉES en UTC. Toute saisie est interprétée dans
    | ce fuseau : date simple → début 00:00:00 / fin 23:59:59 locales ;
    | datetime → heure locale ; affichage reconverti dans ce fuseau.
    | Surcharge via .env : INS_FUSEAU=Africa/Tunis
    */
    'fuseau' => (string) env('INS_FUSEAU', 'Africa/Tunis'),

    /*
    |--------------------------------------------------------------------------
    | Contact scolarité (LOOP 15/16) — source unique
    |--------------------------------------------------------------------------
    | Affiché partout où un humain doit prendre le relais : aide du
    | formulaire public, FAQ, orientation des questions non couvertes.
    | Jamais de numéro de dossier ou de donnée personnelle à côté.
    */
    'contact_scolarite' => [
        'email' => (string) env('INS_CONTACT_EMAIL', 'scolarite@universite.ac.ma'),
        'horaires' => (string) env('INS_CONTACT_HORAIRES', 'Lun–Ven, 08h30–16h30'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rendez-vous & permanence d'aide scolarité (LOOP 16)
    |--------------------------------------------------------------------------
    | Plages et horaires réellement configurés pour l'assistance humaine
    | et les entretiens de scolarité. Zéro calendrier inventé.
    */
    'rendez_vous' => [
        'actif' => (bool) env('INS_RDV_ACTIF', true),
        'duree_minutes' => 30,
        'capacite_par_creneau' => 1,
        'jours_ouvres' => [1, 2, 3, 4, 5], // Lundi (1) au Vendredi (5)
        'plages_horaires' => [
            '09:00', '09:30', '10:00', '10:30',
            '14:00', '14:30', '15:00', '15:30',
        ],
        'motifs' => [
            'inscription_bloquee' => 'Blocage technique ou administratif lors de l\'inscription',
            'piece_rejetee' => 'Explication ou contestation d\'une pièce justificative rejetée',
            'orientation_reclamation' => 'Orientation de parcours ou réclamation académique',
            'amenagement_particulier' => 'Aménagement d\'études ou situation personnelle',
            'autre' => 'Autre demande d\'assistance à la scolarité',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | FAQ officielle (LOOP 15) — contenus approuvés uniquement
    |--------------------------------------------------------------------------
    | Chaque entrée DOIT refléter un comportement réel du parcours
    | (aucune règle inventée) et citer sa source : page ou étape du
    | formulaire, espace étudiant. Les questions non couvertes sont
    | orientées vers la scolarité (contact ci-dessus) ou, pour les
    | étudiants authentifiés, vers une demande d'assistance (LOOP 16,
    | module OnlineRequests — type DEMANDE_LIBRE).
    | `source_route` = nom de route laravel vers la page de référence.
    */
    'faq' => [
        [
            'categorie' => 'Inscriptions',
            'question' => 'Quand puis-je m\'inscrire ?',
            'reponse' => 'Les inscriptions sont ouvertes uniquement pendant les périodes officielles définies par votre faculté (dates affichées sur la page d\'inscription). Hors période, le formulaire est désactivé et aucune soumission n\'est acceptée ; une ouverture exceptionnelle peut être accordée par la scolarité au cas par cas.',
            'source_libelle' => 'Page d\'inscription — bannière de période',
            'source_route' => 'inscription.create',
            'mots_cles' => ['periode', 'dates', 'calendrier', 'quand', 'ouverture', 'fermeture', 'delai', 'hors periode', 'debut', 'fin'],
        ],
        [
            'categorie' => 'Inscriptions',
            'question' => 'Quelles pièces dois-je préparer ?',
            'reponse' => 'Préparez votre CIN (ou passeport), votre année et mention du baccalauréat, et un e-mail actif. La liste précise des pièces exigées selon votre situation s\'affiche à l\'étape « Documents » du formulaire ; les pièces justificatives sont ensuite téléversées depuis votre espace étudiant.',
            'source_libelle' => 'Formulaire — étape Documents',
            'source_route' => 'inscription.create',
            'mots_cles' => ['pieces', 'documents', 'justificatifs', 'preparer', 'cin', 'passeport', 'baccalaureat', 'photo', 'televerser', 'fichiers', 'fournir'],
        ],
        [
            'categorie' => 'Inscriptions',
            'question' => 'Mes informations ne se sont pas pré-remplies, est-ce normal ?',
            'reponse' => 'Le pré-remplissage s\'appuie sur le référentiel national (MENS) à partir de votre CIN et de votre date de naissance. S\'il ne vous reconnaît pas, vous pouvez saisir vos informations manuellement : votre dossier sera examiné par la scolarité comme les autres.',
            'source_libelle' => 'Formulaire — étape Identification',
            'source_route' => 'inscription.create',
            'mots_cles' => ['pre-remplissage', 'preremplissage', 'mens', 'automatique', 'reconnaissance', 'saisie manuelle', 'national', 'identite'],
        ],
        [
            'categorie' => 'Dossier',
            'question' => 'J\'ai déjà un dossier, que faire ?',
            'reponse' => 'Si un dossier existe déjà avec vos informations, ne créez pas de nouveau dossier : connectez-vous à votre espace étudiant avec votre e-mail (ou CIN) et votre mot de passe pour continuer votre inscription.',
            'source_libelle' => 'Espace étudiant — connexion',
            'source_route' => 'etudiant.connexion.show',
            'mots_cles' => ['deja un dossier', 'dossier existant', 'doublon', 'reconnexion', 'continuer', 'espace etudiant', 'reprendre'],
        ],
        [
            'categorie' => 'Dossier',
            'question' => 'Comment suivre mon dossier ?',
            'reponse' => 'Après votre inscription, connectez-vous à votre espace étudiant : statut du dossier, pièces à compléter, affectation de groupe et notifications y sont centralisés. Un récépissé de dépôt (PDF) est disponible sur votre profil.',
            'source_libelle' => 'Espace étudiant — profil',
            'source_route' => 'etudiant.connexion.show',
            'mots_cles' => ['suivre', 'suivi', 'etat', 'avancement', 'statut', 'consulter', 'recepisse', 'notification', 'groupe'],
        ],
        [
            'categorie' => 'Dossier',
            'question' => 'Ma pièce a été rejetée, comment la corriger ?',
            'reponse' => 'Depuis « Mon dossier » dans votre espace étudiant, remplacez la pièce rejetée par une nouvelle version : l\'historique est conservé et la nouvelle pièce sera réexaminée par la scolarité.',
            'source_libelle' => 'Espace étudiant — Mon dossier',
            'source_route' => 'etudiant.connexion.show',
            'mots_cles' => ['rejetee', 'rejet', 'refus', 'corriger', 'remplacer', 'nouvelle version', 'non conforme', 'invalide', 'piece'],
        ],
        [
            'categorie' => 'Compte',
            'question' => 'J\'ai perdu mon mot de passe, que faire ?',
            'reponse' => 'Aucun mot de passe n\'est communiqué par téléphone ou e-mail. Présentez-vous à la scolarité de votre faculté (pièce d\'identité obligatoire) : un nouveau mot de passe vous sera remis en main propre.',
            'source_libelle' => 'Scolarité de votre faculté',
            'source_route' => null,
            'mots_cles' => ['mot de passe', 'perdu', 'oublie', 'reinitialisation', 'identifiants', 'main propre', 'recuperer', 'connexion impossible'],
        ],
        [
            'categorie' => 'Dossier',
            'question' => 'Le récépissé de dépôt prouve-il mon acceptation ?',
            'reponse' => 'Non. Le récépissé atteste uniquement que votre dossier a été déposé ; il ne constitue ni une décision d\'acceptation ni une affectation. Le statut officiel de votre dossier est visible dans votre espace étudiant.',
            'source_libelle' => 'Espace étudiant — profil',
            'source_route' => 'etudiant.connexion.show',
            'mots_cles' => ['recepisse', 'depot', 'acceptation', 'admission', 'preuve', 'attestation', 'affectation', 'valide'],
        ],
        [
            'categorie' => 'Inscriptions',
            'question' => 'Puis-je me réinscrire ou demander un transfert en ligne ?',
            'reponse' => 'Le formulaire public couvre la première inscription. Les réinscriptions et les transferts sont traités par la scolarité de votre faculté : contactez-la pour ouvrir votre droit.',
            'source_libelle' => 'Scolarité de votre faculté',
            'source_route' => null,
            'mots_cles' => ['reinscrire', 'reinscription', 'transfert', 'changement', 'mutation', 'droit', 're-inscription'],
        ],
    ],
];
