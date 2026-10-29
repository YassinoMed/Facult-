<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Services\BlocageInscriptionService;

/**
 * Outil métier : Diagnostic approfondi et explicable des blocages d'un dossier d'inscription.
 * Réutilise le service central BlocageInscriptionService et applique l'isolation facultaire.
 */
class DiagnostiquerBlocageDossierTool implements OutilIaInterface
{
    public function __construct(
        protected ?BlocageInscriptionService $blocageService = null
    ) {
        $this->blocageService = $blocageService ?? app(BlocageInscriptionService::class);
    }

    public function nom(): string
    {
        return 'diagnostiquer_blocage_dossier';
    }

    public function description(): string
    {
        return 'Diagnostique précisément pourquoi un dossier d\'inscription est bloqué ou en attente, en identifiant les anomalies de scolarité et les pièces manquantes.';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_metier';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'inscription_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant numérique de l\'inscription académique',
                ],
            ],
            'required' => ['inscription_id'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $inscriptionId = (int) ($parametres['inscription_id'] ?? 0);

        if ($inscriptionId <= 0) {
            return ResultatOutil::echec("L'identifiant d'inscription fourni est invalide.");
        }

        $inscription = InscriptionAcademique::with('etudiant.faculte')->find($inscriptionId);

        if (! $inscription) {
            return ResultatOutil::echec("Aucune inscription trouvée pour le numéro #{$inscriptionId}.");
        }

        // Contrôle strict de périmètre facultaire
        $faculteId = $inscription->faculte_id ?? $inscription->etudiant?->faculte_id;
        FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $faculteId);

        // Appel du service existant du projet UniRegistre
        $bilan = $this->blocageService->blocages($inscription);

        $donneesDiagnostic = [
            'inscription_id' => $inscription->id,
            'statut_actuel' => $inscription->statut,
            'verdict' => $bilan['verdict'] ?? 'INCONNU',
            'score_coherence' => $bilan['score'] ?? 0,
            'est_bloquant' => $bilan['bloquant'] ?? false,
            'anomalies' => $bilan['items'] ?? [],
        ];

        $nbAnomalies = count($donneesDiagnostic['anomalies']);
        $resume = "Dossier #{$inscriptionId} : Verdict {$donneesDiagnostic['verdict']} avec {$nbAnomalies} point(s) d'attention relevé(s).";

        return ResultatOutil::succes($donneesDiagnostic, $resume, [
            [
                'titre' => 'Moteur de Vérification des Inscriptions UniRegistre',
                'url_ou_chemin' => 'app/Services/BlocageInscriptionService.php',
            ],
        ]);
    }
}
