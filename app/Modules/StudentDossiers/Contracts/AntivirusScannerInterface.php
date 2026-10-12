<?php

namespace App\Modules\StudentDossiers\Contracts;

/**
 * Point d'extension antivirus (§6) : le stockage appelle ce contrat
 * après écriture. `NullAntivirusScanner` (sain par défaut) est branché
 * tant qu'aucun moteur réel n'est configuré.
 */
interface AntivirusScannerInterface
{
    /**
     * @param  array{storage_path: string, sha256: string, size: int}  $contexte
     */
    public function analyser(array $contexte): AntivirusResult;
}
