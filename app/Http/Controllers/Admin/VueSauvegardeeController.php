<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VueSauvegardee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vues sauvegardées (§7.8) : filtres nommés par tableau, propres à
 * l'utilisateur. Tableaux whitelistés — jamais de clé arbitraire
 * exécutée côté serveur.
 */
class VueSauvegardeeController extends Controller
{
    public const TABLEAUX = ['taches', 'etudiants', 'demandes', 'epreuves'];

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'tableau' => ['required', 'in:'.implode(',', self::TABLEAUX)],
            'nom' => ['required', 'string', 'max:80'],
            'filtres' => ['nullable', 'array', 'max:20'],
            'filtres.*' => ['nullable', 'string', 'max:120'],
            'par_defaut' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('par_defaut')) {
            VueSauvegardee::where('user_id', $request->user()->id)
                ->where('tableau', $request->input('tableau'))
                ->update(['par_defaut' => false]);
        }

        VueSauvegardee::create([
            'user_id' => $request->user()->id,
            'tableau' => $request->input('tableau'),
            'nom' => $request->input('nom'),
            'filtres' => $request->input('filtres', []),
            'par_defaut' => $request->boolean('par_defaut'),
        ]);

        return back()->with('success', 'Vue enregistrée.');
    }

    public function destroy(Request $request, VueSauvegardee $vue): RedirectResponse
    {
        abort_unless((int) $vue->user_id === (int) $request->user()->id, 404);

        $vue->delete();

        return back()->with('success', 'Vue supprimée.');
    }
}
