<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Platform Sentinel — contrôle du stockage privé sécurisé.
 * Les documents étudiants et pièces justificatives résident dans `storage/app/private`.
 * Un stockage non inscriptible bloque le parcours d'inscription et de GED.
 */
class StockagePriveCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'INFRA_STOCKAGE_PRIVE';
    }

    public function categorie(): string
    {
        return 'infrastructure';
    }

    public function gravite(): string
    {
        return 'critique';
    }

    public function module(): ?string
    {
        return 'documents';
    }

    public function controler(): array
    {
        $cheminPrive = storage_path('app/private');

        try {
            $inscriptible = is_dir($cheminPrive) && is_writable($cheminPrive);
            $testFichier = 'sentinel_ping_'.time().'.tmp';
            $ecritureOk = false;

            if ($inscriptible) {
                $testPath = $cheminPrive.'/'.$testFichier;
                $ecritureOk = (bool) @file_put_contents($testPath, 'ping');
                if ($ecritureOk) {
                    @unlink($testPath);
                }
            }

            if (! $inscriptible || ! $ecritureOk) {
                return [[
                    'titre' => 'Stockage privé non inscriptible',
                    'diagnostic' => "Le répertoire `storage/app/private` n'est pas inscriptible par le processus web/PHP.",
                    'recommandation' => 'Vérifier les droits d’accès du dossier (ex. `chmod 775 storage/app/private` et propriété `www-data`).',
                ]];
            }
        } catch (\Throwable $e) {
            return [[
                'titre' => 'Erreur accès stockage privé',
                'diagnostic' => 'Exception lors du contrôle du disque privé : '.mb_substr($e->getMessage(), 0, 150),
                'recommandation' => 'Vérifier la configuration du disque `private` dans `config/filesystems.php`.',
            ]];
        }

        return [];
    }
}
