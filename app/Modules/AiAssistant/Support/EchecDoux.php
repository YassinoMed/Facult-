<?php

namespace App\Modules\AiAssistant\Support;

use App\Modules\AiAssistant\Exceptions\ActionRefuseeException;
use App\Modules\AiAssistant\Exceptions\AiAssistantException;
use App\Modules\AiAssistant\Exceptions\FournisseurIndisponibleException;
use App\Modules\AiAssistant\Exceptions\OutilRefuseException;
use App\Modules\AiAssistant\Exceptions\PlafondAtteintException;
use Throwable;

/**
 * Rendering FAIL-SOFT (Lot 3) : les erreurs IA deviennent des réponses
 * HTTP calibrées sans jamais exposer de détails internes, de chemin ou
 * de contenu de prompt. La sécurité prime sur l'explication.
 */
final class EchecDoux
{
    /**
     * @return array{0: int, 1: array{erreur: string, message: string}}
     */
    public static function versReponse(Throwable $erreur): array
    {
        $codePublic = method_exists($erreur, 'codePublic')
            ? (string) $erreur->codePublic()
            : 'ai.erreur';

        TraceurIa::evenement('erreur.typee', [
            'outil' => 'inconnu',
            'etat' => $codePublic,
        ]);

        return match (true) {
            $erreur instanceof OutilRefuseException, $erreur instanceof ActionRefuseeException => [403, [
                'erreur' => $erreur->codePublic(),
                'message' => 'Action refusée par la politique de sécurité IA.',
            ]],
            $erreur instanceof PlafondAtteintException => [429, [
                'erreur' => $erreur->codePublic(),
                'message' => 'Plafond d’utilisation IA atteint. Réessayez plus tard.',
            ]],
            $erreur instanceof FournisseurIndisponibleException => [502, [
                'erreur' => $erreur->codePublic(),
                'message' => 'Fournisseur IA momentanément indisponible.',
            ]],
            $erreur instanceof AiAssistantException => [400, [
                'erreur' => $erreur->codePublic(),
                'message' => 'Demande IA invalide ou non autorisée.',
            ]],
            default => [500, [
                'erreur' => 'ai.erreur',
                'message' => 'Une erreur interne est survenue.',
            ]],
        };
    }
}
