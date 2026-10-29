<?php

namespace App\Modules\Schedule\Services;

use App\Models\IntegrationLog;
use App\Modules\Schedule\Models\ScheduleAvailability;
use Illuminate\Support\Str;

/**
 * PHASE A (Smart Timetables) — cycle de vie des disponibilités déclarées :
 * contrôle de chevauchement (même ressource, même type, même portée année)
 * puis persistance + trace d'intégration (sans aucune donnée personnelle).
 *
 * Les données attendues sont TOUJOURS complètes (issues des formulaires
 * validés) : la validation de forme est assurée par les FormRequests.
 */
class ScheduleAvailabilityService
{
    /**
     * @param  array<string, mixed>  $donnees
     * @return array{plage?: ScheduleAvailability, erreurs?: array<int, string>}
     */
    public function creer(array $donnees, ?int $auteurId = null): array
    {
        if ($chevauchante = $this->chevauchement($donnees)) {
            return ['erreurs' => [$this->messageChevauchement($chevauchante)]];
        }

        $donnees['cree_par'] = $auteurId;

        /** @var ScheduleAvailability $plage */
        $plage = ScheduleAvailability::create($donnees);

        $this->tracer('CREATED', $plage, 201);

        return ['plage' => $plage];
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array{plage?: ScheduleAvailability, erreurs?: array<int, string>}
     */
    public function modifier(ScheduleAvailability $plage, array $donnees): array
    {
        if ($chevauchante = $this->chevauchement($donnees, $plage->id)) {
            return ['erreurs' => [$this->messageChevauchement($chevauchante)]];
        }

        $plage->update($donnees);

        $this->tracer('UPDATED', $plage->refresh(), 200);

        return ['plage' => $plage];
    }

    public function supprimer(ScheduleAvailability $plage): void
    {
        $reference = $plage->id;
        $plage->delete();

        IntegrationLog::tracer(
            'schedule',
            'availability_manage',
            'SCHEDULE_AVAILABILITY_DELETED',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'DISPO-'.$reference,
        );
    }

    /**
     * Chevauchement strict avec une plage existante de MÊME ressource,
     * MÊME type et MÊME portée année (des plages d'années différentes
     * coexistent sans conflit).
     *
     * @param  array<string, mixed>  $donnees
     */
    private function chevauchement(array $donnees, ?int $ignorerId = null): ?ScheduleAvailability
    {
        $requete = ScheduleAvailability::query()
            ->where('type', $donnees['type'])
            ->where('day_of_week', $donnees['day_of_week'])
            ->where('annee_universitaire_id', $donnees['annee_universitaire_id'] ?? null)
            ->when($ignorerId !== null, fn ($q) => $q->where('id', '!=', $ignorerId));

        foreach (['teacher_id', 'room_id', 'group_id'] as $colonne) {
            $requete->where($colonne, $donnees[$colonne] ?? null);
        }

        return $requete->get()
            ->first(fn (ScheduleAvailability $p) => $p->chevauche($donnees['start_time'], $donnees['end_time']));
    }

    private function messageChevauchement(ScheduleAvailability $chevauchante): string
    {
        return 'Une plage du même type chevauche déjà cette période ('
            .$chevauchante->plageHumaine().').';
    }

    private function tracer(string $operation, ScheduleAvailability $plage, int $status): void
    {
        IntegrationLog::tracer(
            'schedule',
            'availability_manage',
            'SCHEDULE_AVAILABILITY_'.$operation,
            'completed',
            // Aucune donnée personnelle : identifiants techniques seuls.
            null,
            $status,
            null,
            null,
            (string) Str::uuid(),
            'DISPO-'.$plage->id,
        );
    }
}
