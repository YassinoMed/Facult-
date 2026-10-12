<?php

namespace App\Modules\StudentDossiers\Services;

use App\Modules\StudentDossiers\Contracts\AntivirusResult;
use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;

/**
 * Implémentation par défaut (§6) : aucun moteur configuré = fichier
 * considéré sain, traçabilité explicite pour le jour du branchement.
 */
class NullAntivirusScanner implements AntivirusScannerInterface
{
    public function analyser(array $contexte): AntivirusResult
    {
        return new AntivirusResult(sain: true);
    }
}
