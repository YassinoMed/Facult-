<?php

namespace App\Services\Sauvegardes;

use App\Models\DatabaseBackup;

/**
 * Rétention contrôlée des lots (fichiers + lignes registre associées) :
 * conserve les N plus récents ET les plus jeunes que X jours. Ne touche
 * que les fichiers listés dans les manifests gérés (jamais de glob
 * aveugle) et ne supprime un lot qu'après validation du nouveau
 * (appelée en fin de sauvegarde réussie uniquement).
 */
class BackupRetentionService
{
    /**
     * @return array{supprimes: int, conserves: int}
     */
    public function purger(string $dossier, int $conserverNombre, ?int $conserverJours = null): array
    {
        $manifests = glob(rtrim($dossier, '/').'/manifest-*.json') ?: [];
        // Tri par date de modification réelle (les suffixes `-2` faussent
        // l'ordre lexical — même cause qu'en restauration).
        usort($manifests, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        $supprimes = 0;
        $conserves = 0;

        foreach ($manifests as $rang => $manifest) {
            $tropVieux = $conserverJours !== null
                && filemtime($manifest) < time() - $conserverJours * 86400;

            if ($rang < max(1, $conserverNombre) && ! $tropVieux) {
                $conserves++;

                continue;
            }

            $donnees = json_decode((string) file_get_contents($manifest), true);
            $fichiers = [];

            foreach ($donnees['artefacts'] ?? [] as $artefact) {
                $nom = basename((string) ($artefact['fichier'] ?? ''));

                if ($nom !== '') {
                    $fichiers[] = $nom;
                    @unlink($dossier.'/'.$nom);
                }
            }

            @unlink($manifest);
            $supprimes++;

            // Lignes registre associées aux fichiers supprimés (seulement).
            if ($fichiers !== []) {
                DatabaseBackup::whereIn('filename', $fichiers)->delete();
            }
        }

        return ['supprimes' => $supprimes, 'conserves' => $conserves];
    }
}
