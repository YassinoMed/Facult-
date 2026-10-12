<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Models\EvenementCalendrier;
use Carbon\Carbon;

/**
 * Jours ouvrés universitaires (§10.16) : week-ends + jours fériés +
 * vacances du calendrier exclus. Utilisé par les SLA.
 */
class JoursOuvrables
{
    /**
     * @return array<int, string> dates Y-m-d chômées sur la période.
     */
    public function joursChomes(string $debut, string $fin): array
    {
        if (! config('modules.calendar.active')) {
            return [];
        }

        $chomes = [];

        $evenements = EvenementCalendrier::where('statut', 'publie')
            ->whereIn('type', ['ferie', 'vacances'])
            ->whereDate('date_debut', '<=', $fin)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $debut))
            ->get();

        foreach ($evenements as $evenement) {
            $jour = $evenement->date_debut->copy();
            $limite = $evenement->date_fin ?? $evenement->date_debut;

            while ($jour->format('Y-m-d') <= $limite->format('Y-m-d')) {
                $chomes[] = $jour->format('Y-m-d');
                $jour->addDay();
            }
        }

        return array_values(array_unique($chomes));
    }

    public function estOuvre(string $date, ?array $chomes = null): bool
    {
        $jour = Carbon::parse($date);

        if ($jour->isWeekend()) {
            return false;
        }

        $chomes ??= $this->joursChomes($jour->format('Y-m-d'), $jour->format('Y-m-d'));

        return ! in_array($jour->format('Y-m-d'), $chomes, true);
    }

    /**
     * Jours ouvrés restants jusqu'à l'échéance (négatif si dépassée).
     */
    public function restants(string $echeance, ?string $depuis = null): int
    {
        $depuis ??= now()->format('Y-m-d');
        $chomes = $this->joursChomes(min($depuis, $echeance), max($depuis, $echeance));

        if ($depuis > $echeance) {
            return -$this->compterOuvres($echeance, $depuis, $chomes);
        }

        return $this->compterOuvres($depuis, $echeance, $chomes);
    }

    /** @param array<int, string> $chomes */
    private function compterOuvres(string $debut, string $fin, array $chomes): int
    {
        $total = 0;
        $jour = Carbon::parse($debut);

        while ($jour->format('Y-m-d') <= $fin) {
            if (! $jour->isWeekend() && ! in_array($jour->format('Y-m-d'), $chomes, true)) {
                $total++;
            }
            $jour->addDay();
        }

        return $total;
    }
}
