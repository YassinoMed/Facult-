<?php

namespace App\Modules\AssistantIa\Tools\Developpeur;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use Illuminate\Support\Facades\File;

/**
 * Outil développeur : Recherche et liste des fichiers de code dans les répertoires autorisés (Lecture seule).
 */
class InspecterFichiersCodeTool implements OutilIaInterface
{
    protected const DOSSIERS_AUTORISES = [
        'app',
        'routes',
        'config',
        'tests',
        'resources/views',
        'docs',
        'database/migrations',
    ];

    public function nom(): string
    {
        return 'rechercher_fichiers_code';
    }

    public function description(): string
    {
        return 'Recherche des fichiers de code source ou de configuration dans les dossiers autorisés du projet (lecture seule).';
    }

    public function mode(): string
    {
        return 'developpeur';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_developpeur';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'motif' => [
                    'type' => 'string',
                    'description' => 'Motif de recherche ou nom de fichier partiel (ex: "Inscription", "Service", "Controller")',
                ],
                'dossier' => [
                    'type' => 'string',
                    'description' => 'Sous-dossier restreint (ex: "app/Services", "app/Modules", "routes")',
                ],
            ],
            'required' => ['motif'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $motif = (string) ($parametres['motif'] ?? '');
        $dossier = str_replace('\\', '/', trim((string) ($parametres['dossier'] ?? 'app')));

        // Sécurité : interdire tout chemin absolu ou traversal
        if (str_contains($dossier, '..') || str_starts_with($dossier, '/') || str_contains($dossier, './')) {
            throw new SecuriteIaException('Chemin de dossier non autorisé.');
        }

        $estAutorise = false;
        foreach (self::DOSSIERS_AUTORISES as $dAut) {
            if ($dossier === $dAut || str_starts_with($dossier, $dAut.'/')) {
                $estAutorise = true;
                break;
            }
        }

        if (! $estAutorise) {
            return ResultatOutil::echec("Le dossier '{$dossier}' n'est pas dans le périmètre autorisé.");
        }

        $cheminAbsolu = base_path($dossier);
        if (! File::isDirectory($cheminAbsolu)) {
            return ResultatOutil::echec("Le dossier '{$dossier}' n'existe pas.");
        }

        $fichiersTrouves = [];
        $tousFichiers = File::allFiles($cheminAbsolu);

        foreach ($tousFichiers as $f) {
            $nomFichier = $f->getFilename();
            $cheminRelatif = str_replace(base_path().'/', '', $f->getPathname());

            // Exclusion stricte des fichiers sensibles
            if (str_starts_with($nomFichier, '.env') || str_contains($cheminRelatif, 'private') || str_ends_with($nomFichier, '.key')) {
                continue;
            }

            if (stripos($nomFichier, $motif) !== false || stripos($cheminRelatif, $motif) !== false) {
                $fichiersTrouves[] = [
                    'chemin' => $cheminRelatif,
                    'taille_octets' => $f->getSize(),
                    'derniere_modification' => date('Y-m-d H:i:s', $f->getMTime()),
                ];
                if (count($fichiersTrouves) >= 20) {
                    break;
                }
            }
        }

        return ResultatOutil::succes([
            'total' => count($fichiersTrouves),
            'fichiers' => $fichiersTrouves,
        ], count($fichiersTrouves)." fichier(s) trouvé(s) pour le motif '{$motif}'.");
    }
}
