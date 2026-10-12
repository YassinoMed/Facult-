<?php

namespace App\Services\Io;

use Illuminate\Http\UploadedFile;

/**
 * Contrat commun des parsers d'import (§36).
 * Toute donnée issue d'un fichier externe est considérée NON FIABLE (§38).
 */
interface ParsesFiles
{
    /** csv | json | xml | xlsx */
    public function format(): string;

    /**
     * @return array<int, array<string, mixed>> lignes normalisées (clés en snake_case)
     *
     * @throws IoException si le fichier est malformé
     */
    public function parse(UploadedFile $fichier): array;
}
