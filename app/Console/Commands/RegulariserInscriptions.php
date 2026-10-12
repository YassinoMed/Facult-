<?php

namespace App\Console\Commands;

use App\Models\AnneeUniversitaire;
use App\Services\InscriptionService;
use Illuminate\Console\Command;

class RegulariserInscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scolarite:regulariser-inscriptions
                            {--annee= : ID ou libellé de l\'année universitaire (défaut : active ou 2026-2027)}
                            {--faculte= : ID de la faculté (optionnel)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Régularise en masse les inscriptions académiques des étudiants valides sans inscription pour l\'année universitaire.';

    /**
     * Execute the console command.
     */
    public function handle(InscriptionService $service): int
    {
        $anneeParam = $this->option('annee');
        $faculteParam = $this->option('faculte');

        if ($anneeParam !== null) {
            $annee = is_numeric($anneeParam)
                ? AnneeUniversitaire::find((int) $anneeParam)
                : AnneeUniversitaire::where('libelle', $anneeParam)->first();
        } else {
            $annee = AnneeUniversitaire::where('libelle', '2026-2027')->orWhere('active', true)->first();
        }

        if (! $annee) {
            $this->error('Année universitaire introuvable.');

            return self::FAILURE;
        }

        $faculteId = $faculteParam !== null ? (int) $faculteParam : null;

        $this->info("Régularisation des inscriptions académiques pour l'année {$annee->libelle}...");

        $resultat = $service->regulariserInscriptionsManquantes($annee->id, $faculteId);

        $this->info("Régularisation terminée : {$resultat['crees']} inscription(s) créée(s) sur {$resultat['total_candidats']} étudiant(s) éligible(s).");

        return self::SUCCESS;
    }
}
