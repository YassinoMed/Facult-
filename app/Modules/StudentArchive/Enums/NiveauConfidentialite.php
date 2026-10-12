<?php

namespace App\Modules\StudentArchive\Enums;

/**
 * Classification documentaire (§11) : enum centralisé unique — jamais de
 * chaînes arbitraires dispersées. Pas de niveau médical séparé ici : le
 * médical/disciplinaire est porté par les permissions, pas par un 4e niveau.
 */
enum NiveauConfidentialite: string
{
    case Standard = 'standard';
    case Restreint = 'restreint';
    case Confidentiel = 'confidentiel';
}
