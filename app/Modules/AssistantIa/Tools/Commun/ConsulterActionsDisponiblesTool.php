<?php

namespace App\Modules\AssistantIa\Tools\Commun;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;

/**
 * Outil commun : Restitution des actions et capacités autorisées pour l'utilisateur connecté.
 * Déduit exclusivement les droits depuis le serveur (zéro confiance aux suggestions du modèle).
 */
class ConsulterActionsDisponiblesTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'consulter_actions_disponibles';
    }

    public function description(): string
    {
        return 'Indique les actions, consultations et simulations autorisées dans UniRegistre pour le compte utilisateur actuellement connecté.';
    }

    public function mode(): string
    {
        return 'commun';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.view';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $role = (string) ($utilisateur->role ?? 'consultation');
        $faculteId = $utilisateur->faculteRestreinteId();
        $estSuperAdmin = $utilisateur->estSuperAdmin();

        $actionsInfo = [
            'Recherche documentaire dans la base de connaissances (RAG)',
            'Explication des règles LMD, crédits ECTS et compensations semestrielles',
            'Explication du cycle d\'inscription en 5 étapes',
            'Orientation dans l\'arborescence des menus UniRegistre',
        ];

        $actionsConsultation = [];
        $actionsSimulation = [];
        $actionsSensibles = [];

        // Habilitations selon le rôle et les permissions effectives
        if ($estSuperAdmin || $utilisateur->hasPermission('assistant_ia.mode_metier') || in_array($role, ['admin_scolarite', 'admin_faculte', 'agent_scolarite'], true)) {
            $actionsConsultation[] = 'Diagnostiquer les blocages et anomalies de dossiers d\'inscription (diagnostiquer_blocage_dossier)';
            $actionsConsultation[] = 'Consulter les pièces justificatives requises et formats admis (verifier_pieces_manquantes)';
            $actionsConsultation[] = 'Consulter l\'éligibilité réglementaire d\'un étudiant à une épreuve (consulter_eligibilite_examen)';
            $actionsConsultation[] = 'Consulter les schémas et métadonnées du Data Mesh (consulter_produit_data_mesh)';
            $actionsConsultation[] = 'Consulter l\'état des tâches du cockpit scolarité (consulter_statut_tache)';

            $actionsSimulation[] = 'Simuler la planification d\'examens et détecter les conflits de salles/surveillants sans écriture (Niveau 2)';
            $actionsSimulation[] = 'Simuler des scénarios d\'inscription ou de parcours LMD';
        }

        if ($estSuperAdmin || $utilisateur->hasPermission('assistant_ia.action_proposer') || in_array($role, ['admin_scolarite', 'admin_faculte'], true)) {
            $actionsSensibles[] = 'Préparer une demande de pièces complémentaires ou relance soumise au contrôle 4 yeux (preparer_action_scolarite)';
        }

        if ($estSuperAdmin || $utilisateur->hasPermission('assistant_ia.action_approuver')) {
            $actionsSensibles[] = 'Valider ou rejeter une proposition d\'action sensible initiée par un tiers (règle des 4 yeux)';
        }

        if ($estSuperAdmin || $utilisateur->hasPermission('assistant_ia.mode_developpeur')) {
            $actionsConsultation[] = 'Inspecter les routes et contrôleurs de l\'application (consulter_routes)';
            $actionsConsultation[] = 'Explorer les fichiers sources autorisés (rechercher_fichiers_code)';
            $actionsSimulation[] = 'Générer une proposition de patch technique sous forme de diff en lecture seule (proposer_patch)';
        }

        // Cas profil Étudiant
        if ($role === 'etudiant') {
            $actionsConsultation[] = 'Consulter mon dossier personnel et mes pièces déposées';
            $actionsConsultation[] = 'Vérifier mon éligibilité personnelle à mes épreuves d\'examen';
            $actionsConsultation[] = 'Consulter mes notes et présences';
            $actionsSimulation[] = 'Simuler mon parcours académique et l\'obtention de crédits LMD';
        }

        // Cas profil Enseignant
        if ($role === 'enseignant') {
            $actionsConsultation[] = 'Consulter mon emploi du temps et mes surveillances affectées';
            $actionsConsultation[] = 'Vérifier la liste de mes notes à saisir';
            $actionsConsultation[] = 'Consulter les supports pédagogiques';
        }

        $perimetreTexte = $faculteId !== null ? "Faculté #{$faculteId}" : 'Transversal (toutes facultés)';

        $donnees = [
            'profil' => [
                'role' => $role,
                'est_super_admin' => $estSuperAdmin,
                'perimetre_faculte' => $perimetreTexte,
            ],
            'niveaux_actions' => [
                'niveau_0_information' => $actionsInfo,
                'niveau_1_consultation' => $actionsConsultation,
                'niveau_2_simulation' => $actionsSimulation,
                'niveau_3_actions_sensibles_4_yeux' => $actionsSensibles,
            ],
            'regles_gouvernance' => [
                'Les actions sensibles de Niveau 3 ne s\'exécutent jamais directement : elles nécessitent une validation explicite par un tiers habilité (règle des 4 yeux).',
                'Aucune consultation ne peut franchir le périmètre de la faculté assignée (isolation BOLA).',
            ],
        ];

        $resume = "Profil '{$role}' ({$perimetreTexte}) : ".count($actionsConsultation).' consultation(s), '.count($actionsSimulation).' simulation(s), '.count($actionsSensibles).' action(s) sensible(s) disponible(s).';

        return ResultatOutil::succes($donnees, $resume, [
            [
                'titre' => 'Matrice des Habilitations RBAC UniRegistre',
                'url_ou_chemin' => 'app/Support/Permissions.php',
                'source' => 'technical_documentation',
            ],
        ]);
    }
}
