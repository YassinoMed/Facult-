<?php

use App\Modules\Sentinel\Services\Checks\AffectationNiveauCheck;
use App\Modules\Sentinel\Services\Checks\AffectationSansGroupeCheck;
use App\Modules\Sentinel\Services\Checks\BaseDonneesCheck;
use App\Modules\Sentinel\Services\Checks\CacheCheck;
use App\Modules\Sentinel\Services\Checks\ConfigurationPlateformeCheck;
use App\Modules\Sentinel\Services\Checks\ConflitExamensCheck;
use App\Modules\Sentinel\Services\Checks\DossierPiecesRequisesCheck;
use App\Modules\Sentinel\Services\Checks\FileAttenteCheck;
use App\Modules\Sentinel\Services\Checks\GroupeSurcapaciteCheck;
use App\Modules\Sentinel\Services\Checks\OperationsSensiblesCheck;
use App\Modules\Sentinel\Services\Checks\SauvegardeEchecsCheck;
use App\Modules\Sentinel\Services\Checks\SauvegardeFraicheurCheck;
use App\Modules\Sentinel\Services\Checks\StockagePriveCheck;

/*
|--------------------------------------------------------------------------
| Platform Sentinel — contrôles enregistrés et seuils
|--------------------------------------------------------------------------
| `checks` : classes implémentant `SentinelCheckInterface`, exécutées
| par `sentinel:controler`. Les défauts vivent dans chaque classe ; la
| table `sentinel_regles` ne porte que les surcharges (actif/gravité).
*/
return [
    'checks' => [
        BaseDonneesCheck::class,
        CacheCheck::class,
        FileAttenteCheck::class,
        StockagePriveCheck::class,
        ConfigurationPlateformeCheck::class,
        OperationsSensiblesCheck::class,
        AffectationSansGroupeCheck::class,
        AffectationNiveauCheck::class,
        GroupeSurcapaciteCheck::class,
        ConflitExamensCheck::class,
        DossierPiecesRequisesCheck::class,
        SauvegardeFraicheurCheck::class,
        SauvegardeEchecsCheck::class,
    ],
];
