<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\AssistantIa\Services\WorkflowApprobationService;

/**
 * Outil métier : Préparation d'une action administrative sensible (requiert validation 4-yeux).
 */
class PreparerActionScolariteTool implements OutilIaInterface
{
    public function __construct(
        protected ?WorkflowApprobationService $workflowService = null
    ) {
        $this->workflowService = $workflowService ?? app(WorkflowApprobationService::class);
    }

    public function nom(): string
    {
        return 'preparer_action_scolarite';
    }

    public function description(): string
    {
        return 'Prépare une action administrative sensible sur un dossier (demande de pièce, relance, déblocage conditionnel). Ne l\'exécute JAMAIS directement, déclenche un workflow d\'approbation humaine.';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.action_proposer';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type_action' => [
                    'type' => 'string',
                    'description' => 'Type d\'opération (ex: "demander_piece_complementaire", "relancer_etudiant", "debloquer_dossier")',
                ],
                'inscription_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant du dossier d\'inscription concerné',
                ],
                'motif' => [
                    'type' => 'string',
                    'description' => 'Justification administrative explicite',
                ],
                'details' => [
                    'type' => 'object',
                    'description' => 'Paramètres complémentaires de l\'action',
                ],
            ],
            'required' => ['type_action', 'inscription_id', 'motif'],
        ];
    }

    public function estSensible(): bool
    {
        return true;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $typeAction = (string) ($parametres['type_action'] ?? '');
        $inscriptionId = (int) ($parametres['inscription_id'] ?? 0);
        $motif = (string) ($parametres['motif'] ?? '');
        $details = (array) ($parametres['details'] ?? []);

        $inscription = InscriptionAcademique::with('etudiant')->find($inscriptionId);
        if (! $inscription) {
            return ResultatOutil::echec("Dossier d'inscription #{$inscriptionId} introuvable.");
        }

        // Vérification du périmètre facultaire
        $faculteId = $inscription->faculte_id ?? $inscription->etudiant?->faculte_id;
        FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $faculteId);

        $resumeImpact = "Action '{$typeAction}' sur le dossier #{$inscriptionId} (Motif: {$motif})";

        $proposition = $this->workflowService->proposerAction(
            demandeur: $utilisateur,
            typeAction: $typeAction,
            ressourceType: 'InscriptionAcademique',
            ressourceId: $inscriptionId,
            description: $motif,
            parametres: array_merge($details, ['inscription_id' => $inscriptionId, 'motif' => $motif]),
            resumeImpact: $resumeImpact,
            niveauRisque: 'moyen',
            faculteId: $faculteId
        );

        return ResultatOutil::requiertApprobation(
            typeAction: $typeAction,
            payloadAction: [
                'proposition_id' => $proposition->id,
                'proposition_uuid' => $proposition->uuid,
                'inscription_id' => $inscriptionId,
                'motif' => $motif,
                'statut' => $proposition->statut,
                'expire_a' => $proposition->expire_a->toIso8601String(),
            ],
            resumeImpact: $resumeImpact
        );
    }
}
