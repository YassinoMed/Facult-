<?php

namespace App\Modules\Attendance\Services;

use App\Contracts\EnregistrementPresenceInterface;
use App\Models\Etudiant;
use App\Modules\Attendance\Events\PresenceEnregistree;
use App\Modules\Attendance\Models\Presence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Module Attendance — logique métier (§18 : le contrôleur reste fin).
 * Écriture atomique + event de domaine pour l'audit transverse (§23).
 */
class AttendanceService implements EnregistrementPresenceInterface
{
    public function enregistrer(
        Etudiant $etudiant,
        string $date,
        string $statut,
        ?string $seance = null,
        ?string $motif = null,
        ?int $enregistrePar = null,
    ): Presence {
        // Normalisation : la colonne est un DATE (sans heure) et la
        // recherche utilise whereDate — identique sur MySQL et SQLite.
        $jour = Carbon::parse($date)->format('Y-m-d');

        /** @var Presence $presence */
        $presence = DB::transaction(function () use ($etudiant, $jour, $statut, $seance, $motif, $enregistrePar) {
            // Verrou anti-course : deux POST simultanés (même
            // étudiant/date/séance) se sérialisent ici — le second
            // voit la ligne et met à jour au lieu de dupliquer
            // (l'unique DB ne couvre pas `seance` NULL sur MySQL).
            $existant = Presence::where('etudiant_id', $etudiant->id)
                ->whereDate('date', $jour)
                ->where('seance', $seance)
                ->lockForUpdate()
                ->first();

            if ($existant !== null) {
                $existant->update([
                    'statut' => $statut,
                    'motif' => $motif,
                    'enregistre_par' => $enregistrePar,
                ]);

                return $existant;
            }

            return Presence::create([
                'etudiant_id' => $etudiant->id,
                'date' => $jour,
                'seance' => $seance,
                'statut' => $statut,
                'motif' => $motif,
                'enregistre_par' => $enregistrePar,
            ]);
        });

        PresenceEnregistree::dispatch($presence->fresh('etudiant'));

        return $presence;
    }

    public function tauxPresence(Etudiant $etudiant, ?string $debut = null, ?string $fin = null): ?float
    {
        $total = $this->nombreSeances($etudiant, $debut, $fin);

        if ($total === 0) {
            return null;
        }

        $presents = $this->requete($etudiant, $debut, $fin)->whereIn('statut', ['present', 'retard'])->count();

        return round($presents / $total * 100, 1);
    }

    public function nombreSeances(Etudiant $etudiant, ?string $debut = null, ?string $fin = null): int
    {
        return $this->requete($etudiant, $debut, $fin)->count();
    }

    private function requete(Etudiant $etudiant, ?string $debut, ?string $fin): Builder
    {
        $query = Presence::where('etudiant_id', $etudiant->id);

        if ($debut !== null) {
            $query->whereDate('date', '>=', $debut);
        }
        if ($fin !== null) {
            $query->whereDate('date', '<=', $fin);
        }

        return $query;
    }
}
