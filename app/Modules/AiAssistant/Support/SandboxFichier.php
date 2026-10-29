<?php

namespace App\Modules\AiAssistant\Support;

use App\Modules\AiAssistant\Exceptions\OutilRefuseException;

/**
 * Sandbox de fichiers en LECTURE SEULE pour l'agent développeur (Lot 7).
 *
 * Racines autorisées : code, tests, docs — jamais .env ni rien de
 * sensible : le blocage est PRIORITAIRE sur tout autre critère.
 */
final class SandboxFichier
{
    /** @var list<string> Premiers segments de chemin autorisés. */
    public const RACINES = ['app', 'routes', 'database', 'tests', 'docs', 'resources'];

    /** @var list<string> Segments interdits où qu'ils soient. */
    public const SEGMENTS_INTERDITS = [
        '.git', 'storage', 'vendor', 'node_modules', 'private',
        'secrets', 'purge', 'cache', 'documents', 'uploads',
    ];

    /** @var list<string> Extensions interdites. */
    public const EXTENSIONS_INTERDITES = [
        'env', 'pem', 'key', 'p12', 'pfx', 'sqlite', 'sql',
        'lock', 'log', 'bak', 'zip', 'csv', 'pdf', 'jpg', 'png', 'webp',
    ];

    public const TAILLE_FICHIER_MAX = 512000; // 512 Ko — refus des dumps.

    public const LIGNES_MAX = 120; // borne de chaque extrait.

    public static function resoudre(string $cheminRelatif): ?string
    {
        $cheminRectifie = str_replace('\\', '/', trim($cheminRelatif));

        // Captures invalides : chemin absolu, traversée, racine inconnue.
        if ($cheminRectifie === ''
            || str_starts_with($cheminRectifie, '/')
            || preg_match('/^[a-zA-Z]:/', $cheminRectifie)
            || str_contains($cheminRectifie, '..')) {
            return null;
        }

        $racine = explode('/', $cheminRectifie)[0];
        if (! in_array($racine, self::RACINES, true)) {
            return null;
        }
        if (self::contientElementInterdit($cheminRectifie)) {
            return null;
        }

        $reel = realpath(base_path($cheminRectifie));
        $pivot = realpath(base_path($racine));

        if ($reel === false || $pivot === false
            || ! str_starts_with($reel, $pivot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (filesize($reel) > self::TAILLE_FICHIER_MAX) {
            return null;
        }

        return $reel;
    }

    /**
     * Extrait borné avec numéros de ligne (exposé échappé côté UI).
     *
     * @return array{chemin: string, depuis: int, total: int, lignes: array<int, int-string>}
     */
    public static function lireExtrait(string $cheminRelatif, int $depuis = 1, int $lignesMax = 60): array
    {
        $cheminRectifie = str_replace('\\', '/', trim($cheminRelatif));

        if ($depuis < 1 || $lignesMax < 1 || $lignesMax > self::LIGNES_MAX) {
            throw new OutilRefuseException('lire_fichier_extrait', 'bornes invalides');
        }

        $absolu = self::resoudre($cheminRectifie);
        if ($absolu === null) {
            throw new OutilRefuseException($cheminRectifie, 'chemin hors périmètre autorisé');
        }

        // Le fichier peut disparaître entre la résolution et la lecture.
        $fichier = file($absolu, FILE_IGNORE_NEW_LINES);
        if ($fichier === false) {
            throw new OutilRefuseException($cheminRectifie, 'fichier non lisible');
        }

        $plage = array_slice($fichier, $depuis - 1, $lignesMax);
        $numerotees = [];
        foreach ($plage as $index => $ligne) {
            $numerotees[(string) ($depuis + $index)] = $ligne;
        }

        return [
            'chemin' => $cheminRectifie,
            'depuis' => $depuis,
            'total' => count($fichier),
            'lignes' => $numerotees,
        ];
    }

    public static function contientElementInterdit(string $cheminRelatif): bool
    {
        $segments = explode('/', str_replace('\\', '/', $cheminRelatif));
        $vraiNom = end($segments);

        foreach ($segments as $segment) {
            if (in_array($segment, self::SEGMENTS_INTERDITS, true)) {
                return true;
            }
        }

        if (str_starts_with($vraiNom, '.env')) {
            return true;
        }

        $extension = strtolower(pathinfo($vraiNom ?? '', PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS_INTERDITES, true);
    }
}
