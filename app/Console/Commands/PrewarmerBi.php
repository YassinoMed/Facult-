<?php

namespace App\Console\Commands;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Services\BIService;
use Illuminate\Console\Command;

/**
 * Préchauffage du cache BI (§10.22) : calcule les jeux d'indicateurs
 * pour l'année active (ou ciblée) × périmètre global + chaque faculté,
 * afin que les dashboards restent rapides aux heures de pointe.
 * Idempotent : recalcul versionné, jamais d'écriture métier.
 */
class PrewarmerBi extends Command
{
    protected $signature = 'bi:prewarmer
        {--annee= : libellé de l’année universitaire (défaut : active)}
        {--faculte= : code faculté (défaut : toutes)}';

    protected $description = 'Précalcule le cache des indicateurs BI (année × facultés).';

    public function handle(BIService $bi): int
    {
        $annee = $this->option('annee') !== null
            ? AnneeUniversitaire::where('libelle', $this->option('annee'))->first()
            : AnneeUniversitaire::where('active', true)->first();

        if ($annee === null) {
            $this->error('Année universitaire introuvable.');

            return self::FAILURE;
        }

        $facultes = $this->option('faculte') !== null
            ? Faculte::where('code', mb_strtoupper((string) $this->option('faculte')))->get()
            : Faculte::orderBy('id')->get();

        $cibles = collect([null])->merge($facultes->pluck('id'))->all();
        $compteur = 0;

        foreach ($cibles as $faculteId) {
            $bi->indicateurs($annee->id, $faculteId);
            $compteur++;
        }

        $this->info("Cache BI préchauffé : {$compteur} périmètre(s) pour {$annee->libelle}.");

        return self::SUCCESS;
    }
}
