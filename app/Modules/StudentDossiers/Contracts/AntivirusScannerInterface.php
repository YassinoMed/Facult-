<?php

namespace App\Modules\StudentDossiers\Contracts;

/**
 * Point d'extension antivirus (§6) : le stockage appelle ce contrat
 * après écriture. `NullAntivirusScanner` n'est branché que lorsque le
 * moteur est explicitement désactivé ; `ClamavAntivirusScanner` applique
 * une politique fail-closed par catégorie.
 */
interface AntivirusScannerInterface
{
    /**
     * @param  array{storage_path: string, sha256: string, size: int, disk?: string, categorie?: string}  $contexte
     */
    public function analyser(array $contexte): AntivirusResult;
}
