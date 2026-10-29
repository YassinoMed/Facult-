<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\Etudiant;
use App\Modules\AssistantIa\DTO\ActeurPortail;
use App\Modules\Schedule\Models\Teacher;

/**
 * Service de reconstruction et d'autorisation du contexte métier de page.
 * Reconstruit côté serveur les informations minimales autorisées (lecture seule).
 * Ne fait JAMAIS confiance au DOM ou aux données non vérifiées envoyées par le navigateur.
 */
class ContextePageService
{
    /**
     * Reconstruit et valide le contexte de la page pour l'acteur connecté.
     *
     * @param  array<string, mixed>  $routeParams
     * @return array{portail: string, ecran: string, route_valide: bool, description: string, donnees: array<string, mixed>, suggestions: array<int, string>, invite_systeme: string}
     */
    public function reconstruireContexte(
        ActeurPortail $acteur,
        ?string $routeName,
        array $routeParams = [],
        ?string $pathname = null
    ): array {
        $routeName = trim((string) $routeName);

        // 1. Validation de l'appartenance de la route au portail de l'acteur
        $routeValide = $this->verifierAppartenanceRoute($acteur, $routeName);

        if (! $routeValide || empty($routeName)) {
            return $this->contexteParDefaut($acteur);
        }

        // 2. Extraction du contexte selon le portail
        if ($acteur->estEtudiant()) {
            return $this->extraireContexteEtudiant($acteur, $routeName, $routeParams);
        }

        if ($acteur->estEnseignant()) {
            return $this->extraireContexteEnseignant($acteur, $routeName, $routeParams);
        }

        return $this->extraireContexteAdmin($acteur, $routeName, $routeParams);
    }

    /**
     * Contrôle d'accès strict : empêche un étudiant de revendiquer une route admin ou inversement.
     */
    protected function verifierAppartenanceRoute(ActeurPortail $acteur, string $routeName): bool
    {
        if (empty($routeName)) {
            return false;
        }

        if ($acteur->estEtudiant()) {
            return str_starts_with($routeName, 'etudiant.');
        }

        if ($acteur->estEnseignant()) {
            return str_starts_with($routeName, 'enseignant.');
        }

        if ($acteur->estAdmin()) {
            return str_starts_with($routeName, 'admin.');
        }

        return false;
    }

    /**
     * Contexte générique sécurisé en cas d'écran non contextualisé ou route inconnue.
     */
    protected function contexteParDefaut(ActeurPortail $acteur): array
    {
        $portail = $acteur->portail;
        $nomPortail = match ($portail) {
            'etudiant' => 'Portail Étudiant',
            'enseignant' => 'Portail Enseignant',
            default => 'Administration UniRegistre',
        };

        $suggestions = match ($portail) {
            'etudiant' => [
                'Quelles sont les démarches pour mon inscription ?',
                'Comment consulter mes résultats académiques ?',
                'Quelles sont les règles de compensation LMD ?',
            ],
            'enseignant' => [
                'Quels sont les délais de transmission des notes ?',
                'Comment fonctionne la règle de deuxième session ?',
                'Procédure d\'émargement et feuilles d\'appel',
            ],
            default => [
                'Expliquer le rôle de InscriptionEligibilityService',
                'Quelles sont les conditions de transition de statut d\'un dossier ?',
                'Comment fonctionne le workflow d\'approbation à 4 yeux ?',
            ],
        };

        return [
            'portail' => $portail,
            'ecran' => $nomPortail,
            'route_valide' => true,
            'description' => "Navigation générale dans l'espace {$nomPortail}.",
            'donnees' => [
                'nom_acteur' => $acteur->nom,
                'role' => $acteur->role,
            ],
            'suggestions' => $suggestions,
            'invite_systeme' => "[Contexte : {$nomPortail}. Utilisateur authentifié : {$acteur->nom} ({$acteur->role})]",
        ];
    }

    /**
     * Contexte autorisé pour l'espace Étudiant.
     */
    protected function extraireContexteEtudiant(ActeurPortail $acteur, string $routeName, array $routeParams): array
    {
        /** @var Etudiant|null $etudiant */
        $etudiant = Etudiant::find($acteur->id);
        if (! $etudiant) {
            return $this->contexteParDefaut($acteur);
        }

        $donnees = [
            'statut_dossier' => $etudiant->statut,
            'niveau' => $etudiant->niveau ?? 'Non spécifié',
            'annee_academique' => $etudiant->annee_academique ?? date('Y'),
        ];

        $ecran = 'Tableau de bord étudiant';
        $suggestions = [
            'Où en est le traitement de mon dossier d\'inscription ?',
            'Quelles sont les pièces justificatives requises ?',
            'Comment demander une attestation d\'inscription ?',
        ];

        if (str_contains($routeName, 'notes')) {
            $ecran = 'Mes notes et résultats';
            $suggestions = [
                'Quels sont les critères d\'admission et de compensation ?',
                'Comment sont calculées les moyennes d\'unités d\'enseignement (UE) ?',
                'Quand a lieu la session de rattrapage ?',
            ];
        } elseif (str_contains($routeName, 'inscriptions') || str_contains($routeName, 'suivi')) {
            $ecran = 'Suivi d\'inscription';
            $suggestions = [
                'Mon dossier est-il complet ?',
                'Que signifie le statut \'en_attente\' ?',
                'Comment téléverser une pièce complémentaire ?',
            ];
        } elseif (str_contains($routeName, 'profil')) {
            $ecran = 'Mon profil étudiant';
            $suggestions = [
                'Comment mettre à jour mes coordonnées ?',
                'Quelles informations sont modifiables par l\'étudiant ?',
            ];
        }

        $description = "Consultation de l'écran '{$ecran}' par l'étudiant #{$etudiant->id} (Statut: {$etudiant->statut}).";
        $invite = "\n--- CONTEXTE DE PAGE ÉTUDIANT (VÉRIFIÉ SERVEUR) ---\n"
            ."Écran : {$ecran}\n"
            ."Étudiant connecté : {$acteur->nom} (Statut dossier : {$etudiant->statut}, Niveau : {$etudiant->niveau})\n"
            ."Consigne : Réponds uniquement aux questions concernant ses droits, ses procédures et ses règles académiques personnelles sans jamais divulguer les dossiers d'autres étudiants.\n";

        return [
            'portail' => 'etudiant',
            'ecran' => $ecran,
            'route_valide' => true,
            'description' => $description,
            'donnees' => $donnees,
            'suggestions' => $suggestions,
            'invite_systeme' => $invite,
        ];
    }

    /**
     * Contexte autorisé pour l'espace Enseignant.
     */
    protected function extraireContexteEnseignant(ActeurPortail $acteur, string $routeName, array $routeParams): array
    {
        /** @var Teacher|null $teacher */
        $teacher = Teacher::find($acteur->id);
        if (! $teacher) {
            return $this->contexteParDefaut($acteur);
        }

        $donnees = [
            'enseignant_id' => $teacher->id,
            'matricule' => $teacher->matricule ?? 'N/A',
        ];

        $ecran = 'Tableau de bord enseignant';
        $suggestions = [
            'Quels sont les délais de transmission des feuilles d\'appel ?',
            'Comment préparer une proposition de rectification de note ?',
            'Quelles sont les règles régissant les épreuves d\'examen ?',
        ];

        if (str_contains($routeName, 'notes')) {
            $ecran = 'Saisie et validation des notes';
            $suggestions = [
                'Comment fonctionne la validation d\'un procès-verbal de notes ?',
                'Quelle est la procédure après publication des délibérations ?',
                'Comment corriger une coquille sur une note saisie ?',
            ];
        } elseif (str_contains($routeName, 'surveillances')) {
            $ecran = 'Mes surveillances d\'examens';
            $suggestions = [
                'Quelles sont les obligations en cas d\'empêchement de surveillance ?',
                'Comment déclarer un incident de salle lors d\'une épreuve ?',
            ];
        }

        $description = "Consultation de l'écran '{$ecran}' par l'enseignant {$acteur->nom}.";
        $invite = "\n--- CONTEXTE DE PAGE ENSEIGNANT (VÉRIFIÉ SERVEUR) ---\n"
            ."Écran : {$ecran}\n"
            ."Enseignant connecté : {$acteur->nom} (Matricule : {$teacher->matricule})\n"
            ."Consigne : Guide l'enseignant sur les procédures pédagogiques, la saisie des notes et ses obligations sans outrepasser les prérogatives des jurys.\n";

        return [
            'portail' => 'enseignant',
            'ecran' => $ecran,
            'route_valide' => true,
            'description' => $description,
            'donnees' => $donnees,
            'suggestions' => $suggestions,
            'invite_systeme' => $invite,
        ];
    }

    /**
     * Contexte autorisé pour l'espace Administration.
     */
    protected function extraireContexteAdmin(ActeurPortail $acteur, string $routeName, array $routeParams): array
    {
        $ecran = 'Cockpit d\'administration';
        $donnees = [
            'role' => $acteur->role,
            'faculte_id' => $acteur->faculteId,
        ];

        $suggestions = [
            'Expliquer le rôle et les vérifications de InscriptionEligibilityService',
            'Comment transiter un dossier d\'inscription en conformité avec InscriptionService ?',
            'Quelles sont les actions soumises à la règle des 4 yeux ?',
        ];

        if (str_contains($routeName, 'inscriptions')) {
            $ecran = 'Gestion des inscriptions';
            $suggestions = [
                'Quels sont les motifs de rejet conformes au règlement ?',
                'Comment préparer une demande de pièce complémentaire ?',
                'Vérifier les critères d\'éligibilité pour une réinscription',
            ];
        } elseif (str_contains($routeName, 'etudiants')) {
            $ecran = 'Registre des étudiants';
            $suggestions = [
                'Comment s\'effectue l\'affectation de groupe pédagogique ?',
                'Comment consulter l\'historique des modifications d\'un dossier ?',
            ];
        } elseif (str_contains($routeName, 'evaluation') || str_contains($routeName, 'deliberations')) {
            $ecran = 'Notes et délibérations';
            $suggestions = [
                'Quels sont les contrôles avant verrouillage d\'une délibération ?',
                'Comment sont traitées les anomalies de notes ?',
            ];
        }

        $description = "Consultation de l'écran d'administration '{$ecran}' (Rôle: {$acteur->role}).";
        $invite = "\n--- CONTEXTE DE PAGE ADMINISTRATION (VÉRIFIÉ SERVEUR) ---\n"
            ."Écran : {$ecran}\n"
            ."Administrateur : {$acteur->nom} (Rôle : {$acteur->role}, Faculté : ".($acteur->faculteId ?? 'Globale').")\n"
            ."Consigne : Assiste l'administrateur dans les contrôles réglementaires. Ne propose jamais d'exécution automatique directe : toute action sensible doit être préparée sous forme de proposition pour validation humaine.\n";

        return [
            'portail' => 'admin',
            'ecran' => $ecran,
            'route_valide' => true,
            'description' => $description,
            'donnees' => $donnees,
            'suggestions' => $suggestions,
            'invite_systeme' => $invite,
        ];
    }
}
