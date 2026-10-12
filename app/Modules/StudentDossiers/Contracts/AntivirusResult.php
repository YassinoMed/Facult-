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
    ) {}
}
