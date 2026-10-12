<?php

namespace App\Console\Commands;

use App\Models\Etudiant;
use App\Models\RegistrationPeriod;
use App\Services\CommunicationDispatcher;
use Illuminate\Console\Command;

/**
 * B1 (dette « notifications période ») : notifie les étudiants des
 * périodes d'inscription via le centre existant (CommunicationDispatcher,
 * gabarits versionnés, préférences respectées).
 *
 *   · periode.ouverte        — période active DÈS MAINTENANT
 *   · periode.bientot_fermee — se clôture sous --jours (défaut 7)
 *
 * Idempotence par période (`avertie_*_at`, même pattern que `used_at`) :
 * une période est annoncée UNE fois par état — jamais de spam quotidien.
 * Audience = portée de la période (faculté/programme/niveau).
 */
class AvertirPeriodesInscription extends Command
{
    protected $signature = 'periodes:avertir
        {--jours=7 : Horizon de clôture imminente}';

    protected $description = 'Notifie les étudiants : période d’inscription ouverte / clôture proche (idempotent par période).';

    public function handle(CommunicationDispatcher $dispatcher): int
    {
        $horizon = max(1, (int) $this->option('jours'));
        $ouvertes = 0;
        $imminentes = 0;

        RegistrationPeriod::query()
            ->where('statut', 'active')
            ->where('opened_at', '<=', now())
            ->where('closed_at', '>', now())
            ->with('anneeUniversitaire')
            ->chunkById(50, function ($periodes) use ($dispatcher, $horizon, &$ouvertes, &$imminentes) {
                foreach ($periodes as $periode) {
                    $variables = $this->variables($periode);

                    if ($periode->avertie_ouverture_at === null) {
                        $ouvertes += $this->diffuserEtMarquer($dispatcher, $periode, 'periode.ouverte', 'avertie_ouverture_at', $variables);
                    }

                    if ($periode->avertie_fermeture_at === null
                        && $periode->closed_at->lessThan(now()->addDays($horizon))) {
                        $imminentes += $this->diffuserEtMarquer($dispatcher, $periode, 'periode.bientot_fermee', 'avertie_fermeture_at', $variables);
                    }
                }
            });

        $this->info("Périodes : {$ouvertes} notification(s) « ouverte », {$imminentes} « clôture proche ».");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function variables(RegistrationPeriod $periode): array
    {
        return [
            'nom' => $periode->nom ?? $periode->type_periode,
            'annee' => $periode->anneeUniversitaire?->libelle ?? '—',
            'date_fermeture' => $periode->closed_at->timezone(config('inscriptions.fuseau'))->format('d/m/Y H:i'),
        ];
    }

    /**
     * Audience de la portée, diffusion batch, marqueur idempotent posé
     * dans la même transaction que la diffusion (concurrence : rejeu
     * simultané = un seul gagnant, la marque fait foi).
     *
     * @param  array<string, string>  $variables
     */
    private function diffuserEtMarquer(
        CommunicationDispatcher $dispatcher,
        RegistrationPeriod $periode,
        string $evenement,
        string $colonne,
        array $variables,
    ): int {
        $ids = Etudiant::query()
            ->when($periode->faculte_id !== null, fn ($q) => $q->where('faculte_id', $periode->faculte_id))
            ->when($periode->programme_id !== null, fn ($q) => $q->where('programme_id', $periode->programme_id))
            ->when($periode->niveau !== null, fn ($q) => $q->where('niveau', $periode->niveau))
            ->pluck('id')
            ->all();

        $envoyees = $dispatcher->dispatcherPlusieurs(
            $evenement, $ids, $variables, route('etudiant.connexion.show'),
        );

        RegistrationPeriod::whereKey($periode->id)
            ->whereNull($colonne)
            ->update([$colonne => now()]);

        return $envoyees;
    }
}
