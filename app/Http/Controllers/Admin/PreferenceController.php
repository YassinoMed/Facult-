<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PreferencesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Préférences d'affichage (Phase 7 §7.4) : densité, lignes par page.
 * Strictement personnelles, appliquées aux registres.
 */
class PreferenceController extends Controller
{
    public function __construct(private readonly PreferencesService $preferences) {}

    public function afficher(Request $request): View
    {
        return view('admin.preferences', [
            'preferences' => $this->preferences->lire((int) $request->user()->id),
        ]);
    }

    public function mettreAJour(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'densite' => ['required', 'in:compact,confortable'],
            'lignes_par_page' => ['required', 'integer', 'min:5', 'max:100'],
        ]);

        $this->preferences->ecrire((int) $request->user()->id, $donnees);

        return redirect()->route('admin.preferences')->with('success', 'Préférences enregistrées.');
    }
}
