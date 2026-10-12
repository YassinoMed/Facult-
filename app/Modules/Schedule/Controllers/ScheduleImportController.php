<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\ScheduleImport;
use App\Modules\Schedule\Requests\ConfirmScheduleImportRequest;
use App\Modules\Schedule\Requests\ImportScheduleRequest;
use App\Modules\Schedule\Services\ScheduleImportService;
use App\Services\Io\IoException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Import semestriel (§10-11) : formulaire → prévisualisation (session
 * serveur) → confirmation explicite → rapport. Périmètre faculté sur
 * les groupes proposés et vérifié à la confirmation.
 */
class ScheduleImportController extends Controller
{
    public function __construct(
        private readonly ScheduleImportService $imports,
    ) {}

    public function form(): View
    {
        abort_unless($this->peutImporter(), 403);

        return view('modules.schedule.import', [
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'groupes' => $this->groupesAccessibles(),
        ]);
    }

    public function preview(ImportScheduleRequest $request): View|RedirectResponse
    {
        abort_unless($this->peutImporter(), 403);

        try {
            $preparation = $this->imports->preparer($request->file('fichier'), [
                'annee_universitaire_id' => $request->input('annee_universitaire_id'),
                'semestre' => $request->input('semestre'),
                'groupe_id' => $request->input('groupe_id'),
            ], (int) $request->user()->id);
        } catch (IoException $e) {
            return redirect()->back()->withErrors(['fichier' => $e->getMessage()])->withInput();
        }

        return view('modules.schedule.preview', [
            'token' => $preparation['token'],
            'format' => $preparation['format'],
            'resultat' => $preparation['resultat'],
        ]);
    }

    public function confirmer(ConfirmScheduleImportRequest $request): View|RedirectResponse
    {
        abort_unless($this->peutImporter(), 403);

        try {
            $aboutissement = $this->imports->confirmer(
                $request->input('token'),
                $request->input('mode'),
                (int) $request->user()->id,
                $request->boolean('confirmation'),
            );
        } catch (IoException $e) {
            return redirect()->route('admin.emplois-du-temps.import.form')
                ->withErrors(['fichier' => $e->getMessage()]);
        }

        return view('modules.schedule.rapport', [
            'rapport' => $aboutissement['rapport'],
            'planning' => $aboutissement['planning'],
        ]);
    }

    public function annuler(Request $request): RedirectResponse
    {
        abort_unless($this->peutImporter(), 403);

        $this->imports->annuler((string) $request->input('token', ''));

        return redirect()->route('admin.emplois-du-temps.index')
            ->with('success', 'Import annulé — rien n’a été enregistré.');
    }

    public function rapport(Request $request, ScheduleImport $rapport): View
    {
        abort_unless($this->peutImporter(), 403);

        $rapport->loadMissing('schedule.groupe');

        if ($rapport->schedule !== null) {
            abort_unless(
                $request->user()->peutAccederFaculte((int) $rapport->schedule->groupe->faculte_id),
                404
            );
        }

        return view('modules.schedule.rapport', [
            'rapport' => $rapport,
            'planning' => $rapport->schedule,
        ]);
    }

    private function peutImporter(): bool
    {
        $utilisateur = auth()->user();

        return $utilisateur !== null && $utilisateur->hasPermission('schedules.manage');
    }

    /**
     * @return Collection<int, Group>
     */
    private function groupesAccessibles()
    {
        $query = Group::with('faculte')->where('actif', true)->orderBy('code');

        if (auth()->user()->faculteRestreinteId() !== null) {
            $query->where('faculte_id', auth()->user()->faculteRestreinteId());
        }

        return $query->get();
    }
}
