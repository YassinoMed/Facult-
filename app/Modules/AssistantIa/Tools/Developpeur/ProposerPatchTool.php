<?php

namespace App\Modules\AssistantIa\Tools\Developpeur;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use Illuminate\Support\Facades\File;

/**
 * Outil développeur : Préparation et affichage d'un patch / diff unifié proposé (sans application automatique).
 */
class ProposerPatchTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'proposer_patch';
    }

    public function description(): string
    {
        return 'Génère et présente une proposition de patch / diff unifié avec justification technique, risques et plan de tests (sans application sur le disque).';
    }

    public function mode(): string
    {
        return 'developpeur';
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
                'fichier' => [
                    'type' => 'string',
                    'description' => 'Chemin relatif du fichier concerné (ex: "app/Services/InscriptionEligibilityService.php")',
                ],
                'justification' => [
                    'type' => 'string',
                    'description' => 'Explication technique du besoin ou du bug à corriger',
                ],
                'diff_unifie' => [
                    'type' => 'string',
                    'description' => 'Contenu du diff au format unifié (---, +++, @@, +, -)',
                ],
                'analyse_risques' => [
                    'type' => 'string',
                    'description' => 'Impacts potentiels, effets de bord et régression possible',
                ],
                'tests_proposes' => [
                    'type' => 'string',
                    'description' => 'Cas de tests recommandés pour valider le patch',
                ],
            ],
            'required' => ['fichier', 'justification', 'diff_unifie'],
        ];
    }

    public function estSensible(): bool
    {
        return false; // C'est une proposition en affichage seul, aucune modification automatique
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $fichier = str_replace('\\', '/', trim((string) ($parametres['fichier'] ?? '')));
        $justification = (string) ($parametres['justification'] ?? '');
        $diff = (string) ($parametres['diff_unifie'] ?? '');
        $risques = (string) ($parametres['analyse_risques'] ?? 'Aucun risque majeur identifié.');
        $tests = (string) ($parametres['tests_proposes'] ?? 'Tests unitaires ciblés recommandés.');

        // Sécurité : interdire toute proposition sur .env ou répertoires cachés
        if (str_contains($fichier, '.env') || str_contains($fichier, '..') || str_starts_with($fichier, '/') || str_contains($fichier, './')) {
            throw new SecuriteIaException('Proposition de patch interdite sur ce chemin de fichier.');
        }

        $existe = File::exists(base_path($fichier));

        return ResultatOutil::succes([
            'fichier' => $fichier,
            'fichier_existant' => $existe,
            'justification' => $justification,
            'diff' => $diff,
            'analyse_risques' => $risques,
            'tests_proposes' => $tests,
            'statut' => 'proposition_affichee_sans_application',
        ], "Proposition de patch prête pour révision humaine sur '{$fichier}'.");
    }
}
