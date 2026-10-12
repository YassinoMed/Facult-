<?php

namespace App\Services\Io;

use Exception;

/**
 * Exception métier des imports/exports — message FR sûr, jamais de stack trace.
 */
class IoException extends Exception
{
    public static function malforme(string $detail): self
    {
        return new self("Fichier illisible ou malformé : {$detail}");
    }

    public static function tropVolumineux(int $maxOctets): self
    {
        return new self('Fichier trop volumineux (maximum '.round($maxOctets / 1048576, 1).' Mo).');
    }

    public static function tropDeLignes(int $maxLignes): self
    {
        return new self("Fichier trop volumineux : maximum {$maxLignes} lignes de données (découper le fichier).");
    }

    public static function formatInvalide(string $format): self
    {
        return new self("Format « {$format} » non supporté. Formats acceptés : CSV, JSON, XML, XLSX, ODS.");
    }
}
