<?php

namespace App\Modules\Enseignement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sous-groupes TD/TP (§15) : rattachés à un groupe principal.
 */
class SousGroupeController extends Controller
{
    public function index(Request $request, Group $groupe): View
    {
        abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);

        return view('modules.enseignement.sous-groupes', [
            'groupe' => $groupe->loadMissing('schedules'),
            'sousGroupes' => SousGroupe::where('groupe_id', $groupe->id)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, Group $groupe): RedirectResponse
    {
        abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 403);

        $donnees = $request->validate([
            'code' => 'required|string|max:30',
            'nom' => 'required|string|max:100',
            'type' => 'required|in:td,tp,autre',
            'capacite' => 'nullable|integer|min:1|max:10000',
        ]);

        try {
            SousGroupe::create(['groupe_id' => $groupe->id, 'actif' => true] + $donnees);
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()->withErrors([
                'code' => 'Code déjà utilisé dans ce groupe.',
            ])->withInput();
        }

        return redirect()->route('admin.enseignements.sous-groupes.index', $groupe)
            ->with('success', 'Sous-groupe créé.');
    }

    public function destroy(Request $request, SousGroupe $sousGroupe): RedirectResponse
    {
        abort_unless($request->user()->peutAccederFaculte((int) $sousGroupe->groupe->faculte_id), 403);

        if ($sousGroupe->enseignements()->exists()) {
            return redirect()->back()->withErrors([
                'sous-groupe' => 'Sous-groupe utilisé par des enseignements.',
            ]);
        }

        $sousGroupe->delete();

        return redirect()->route('admin.enseignements.sous-groupes.index', $sousGroupe->groupe_id)
            ->with('success', 'Sous-groupe supprimé.');
    }
}
