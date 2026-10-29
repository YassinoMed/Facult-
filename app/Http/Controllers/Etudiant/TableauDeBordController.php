<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DecisionDeliberation;
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
        $etudiant = $request->attributes->get('etudiant')
            ?? Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));
        $etudiant->loadMissing('faculte');

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

        $planning = null;
        if ($etudiant->groupe_id !== null && app(ModuleRegistry::class)->estActif('schedule')) {
            $planning = Schedule::where('groupe_id', $etudiant->groupe_id)
                ->where('actif', true)
                ->whereIn('statut', ['publie', 'verrouille'])
                ->with('groupe')
                ->orderByDesc('id')
                ->first();
        }

        // Vie académique du jour (§6) : cours d'aujourd'hui dans son
        // groupe (exceptions appliquées), démarches récentes — requêtes
        // bornées, jamais le dossier complet.
        $coursAujourdhui = $this->coursAujourdhui($etudiant, $planning);
        $demarches = $this->demarchesRecentes($etudiant);
        $prochainsExamens = $this->prochainsExamens($etudiant);

        return view('etudiant.dashboard', [
            'etudiant' => $etudiant,
            'derniereInscription' => $derniereInscription,
            'resumeAcademique' => $this->resumeAcademiquePublie($etudiant, $derniereInscription),
            'documentsRecents' => $documentsRecents,
            'coursDuJour' => $coursAujourdhui,
            'planning' => $planning,
            'demarches' => $demarches,
            'prochainsExamens' => $prochainsExamens,
        ]);
    }

    /**
     * D-01 : le « dernier résultat » du dashboard est une décision de
     * délibération PUBLIÉE, jamais les colonnes de l'inscription
     * (recalculées à chaque note, donc antérieures à la publication).
     * Sans décision publiée, aucun résultat n'est rendu.
     *
     * @return array{publie: bool, moyenne: ?string, credits_obtenus: ?int, credits_total: ?int, decision: ?string}
     */
    private function resumeAcademiquePublie(Etudiant $etudiant, ?InscriptionAcademique $inscription): array
    {
        $decision = $inscription === null ? null : DecisionDeliberation::query()
            ->publiee()
            ->where('etudiant_id', $etudiant->id)
            ->where('inscription_academique_id', $inscription->id)
            ->latest('id')
            ->first();

        return [
            'publie' => $decision !== null,
            'moyenne' => $decision?->moyenne,
            'credits_obtenus' => $decision?->credits_obtenus,
            'credits_total' => $decision?->credits_total,
            'decision' => $decision?->decision,
        ];
    }

    /**
     * @return array<int, array{debut: string, fin: string, matiere: string, salle: ?string, statut: string}>
     */
    private function coursAujourdhui(Etudiant $etudiant, ?Schedule $planning = null): array
    {
        if ($etudiant->groupe_id === null
            || ! app(ModuleRegistry::class)->estActif('schedule')) {
            return [];
        }

        // SCHEDULE-09 (clôture) : mêmes règles de visibilité que l'EDT —
        // uniquement les plannings publiés (ou verrouillés après
        // publication, cf. `Schedule::TRANSITIONS`).
        if ($planning === null) {
            $planning = Schedule::where('groupe_id', $etudiant->groupe_id)
                ->where('actif', true)
                ->whereIn('statut', ['publie', 'verrouille'])
                ->with('groupe')
                ->orderByDesc('id')
                ->first();
        }

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
                    'matiere' => $slot->matiere?->nom ?? '',
                    'salle' => $exception?->nouvelleSalle?->code ?? $slot->room?->code,
                    'statut' => $exception?->status ?? $slot->status,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Prochaines épreuves publiées de l'étudiant (max. 3). Aucune
     * épreuve brouillon ou annulée n'est exposée ; le dossier complet
     * reste sur etudiant.examens.index.
     *
     * @return Collection<int, Candidat>
     */
    private function prochainsExamens(Etudiant $etudiant): Collection
    {
        if (! app(ModuleRegistry::class)->estActif('evaluation')) {
            return collect();
        }

        return Candidat::query()
            ->where('etudiant_id', $etudiant->id)
            ->whereHas('epreuve', fn ($q) => $q
                ->whereIn('statut', ['published', 'ongoing'])
                ->whereDate('date', '>=', Carbon::today()))
            ->with([
                'epreuve.matiere:id,code,nom',
                'epreuve.salle:id,code',
                'epreuve.session:id,nom',
                'salle:id,code',
            ])
            ->get()
            ->sortBy(fn (Candidat $c) => ($c->epreuve?->date?->format('Y-m-d') ?? '9999-12-31')
                .' '.($c->epreuve?->heure_debut ?? '23:59'))
            ->take(3)
            ->values();
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
