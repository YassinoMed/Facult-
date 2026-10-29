<?php

namespace App\Modules\StudentDossiers\Contracts;

/**
 * Résultat d'analyse antivirus : sain + signature éventuelle.
 */
final readonly class AntivirusResult
{
    public function __construct(
        public bool $sain,
        public ?string $signature = null,
        /** Moteur indisponible / erreur technique (jamais un fichier sain). */
        public ?string $erreur = null,
    ) {}
}
