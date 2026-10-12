<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Support\Modules\ModuleRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Tableau de bord étudiant (§13) — volontairement LÉGER :
 * - l'étudiant est résolu depuis la session (anti-IDOR) ;
 * - la moyenne et les crédits affichés sont les colonnes PERSISTÉES
 *   de la dernière inscription (calculées par
 *   AcademicCalculationService à la saisie des notes), jamais
 *   recalculées ici ;
 * - AUCUN chargement des notes : le dossier complet (notes, matières,
 *   coefficients) reste réservé à archives.show / notes.index.
 */
class TableauDeBordController extends Controller
{
    public function index(Request $request): View
    {
        $etudiant = Etudiant::with('faculte')
            ->findOrFail($request->session()->get('etudiant_auth_id'));

        $derniereInscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with([
                'anneeUniversitaire',
                'programmeRef',
                'niveauRef',
                'semestres' => fn ($q) => $q->select(
                    'id', 'inscription_academique_id', 'numero',
                    'moyenne', 'credits_obtenus', 'credits_total', 'decision'
                )->orderBy('numero'),
            ])
            ->orderByDesc('annee_universitaire_id')
            ->first();

        $documentsRecents = $etudiant->documentsAcademiques()
            ->with('anneeUniversitaire')
            ->latest()
            ->limit(5)
            ->get();

        // Vie académique du jour (§6) : cours d'aujourd'hui dans son
        // groupe (exceptions appliquées), démarches récentes — requêtes
        // bornées, jamais le dossier complet.
        $coursAujourdhui = $this->coursAujourdhui($etudiant);
        $demarches = $this->demarchesRecentes($etudiant);

        return view('etudiant.dashboard', [
            'etudiant' => $etudiant,
            'derniereInscription' => $derniereInscription,
            'documentsRecents' => $documentsRecents,
            'coursDuJour' => $coursAujourdhui,
            'demarches' => $demarches,
        ]);
    }

    /**
     * @return array<int, array{debut: string, fin: string, matiere: string, salle: ?string, statut: string}>
     */
    private function coursAujourdhui(Etudiant $etudiant): array
    {
        if ($etudiant->groupe_id === null
            || ! app(ModuleRegistry::class)->estActif('schedule')) {
            return [];
        }

        // SCHEDULE-09 (clôture) : mêmes règles de visibilité que l'EDT —
        // uniquement les plannings publiés (ou verrouillés après
        // publication, cf. `Schedule::TRANSITIONS`).
        $planning = Schedule::where('groupe_id', $etudiant->groupe_id)
            ->where('actif', true)
            ->whereIn('statut', ['publie', 'verrouille'])
            ->orderByDesc('id')
            ->first();

        if ($planning === null) {
            return [];
        }

        $jour = strtolower(Carbon::now()->format('l'));
        $aujourdhui = Carbon::today()->toDateString();

        return ScheduleSlot::where('schedule_id', $planning->id)
            ->where('day_of_week', $jour)
            ->where('status', '!=', 'cancelled')
            ->with(['matiere', 'room', 'teacher', 'exceptions' => fn ($q) => $q->whereDate('exception_date', $aujourdhui)->with('nouvelleSalle')])
            ->orderBy('start_time')
            ->get()
            ->map(function ($slot) {
                $exception = $slot->exceptions->first();

                if ($exception !== null && $exception->status === 'cancelled') {
                    return null;
                }

                return [
                    'debut' => substr((string) ($exception?->new_start_time ?? $slot->start_time), 0, 5),
                    'fin' => substr((string) ($exception?->new_end_time ?? $slot->end_time), 0, 5),
                    'matiere' => $slot->matiere->nom,
                    'salle' => $exception?->nouvelleSalle?->code ?? $slot->room?->code,
                    'statut' => $exception?->status ?? $slot->status,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, OnlineRequest>
     */
    private function demarchesRecentes(Etudiant $etudiant)
    {
        if (! app(ModuleRegistry::class)->estActif('online-requests')) {
            return collect();
        }

        return OnlineRequest::with('type')
            ->where('etudiant_id', $etudiant->id)
            ->latest()
            ->limit(5)
            ->get();
    }
}
