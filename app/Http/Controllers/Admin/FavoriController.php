<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FavoriUtilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Favoris épinglés (Phase 7 §7.4) : strictement personnels (propriétaire
 * seul, 404 sinon). Le lien favori pointe vers une page déjà gated —
 * l'autorisation est revérifiée à l'ouverture, jamais au favori.
 */
class FavoriController extends Controller
{
    public function basculer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'type' => ['required', 'string', 'max:30'],
            'reference' => ['required', 'string', 'max:120'],
            'titre' => ['required', 'string', 'max:150'],
            'lien' => ['required', 'string', 'max:255', 'starts_with:/'],
        ]);

        $existant = FavoriUtilisateur::where('user_id', $request->user()->id)
            ->where('type', $donnees['type'])
            ->where('reference', $donnees['reference'])
            ->first();

        if ($existant !== null) {
            $existant->delete();

            return redirect()->back()->with('success', 'Favori retiré.');
        }

        FavoriUtilisateur::create($donnees + ['user_id' => $request->user()->id]);

        return redirect()->back()->with('success', 'Ajouté aux favoris.');
    }

    public function supprimer(Request $request, FavoriUtilisateur $favori): RedirectResponse
    {
        abort_if($favori->user_id !== $request->user()->id, 404);

        $favori->delete();

        return redirect()->back()->with('success', 'Favori retiré.');
    }
}
