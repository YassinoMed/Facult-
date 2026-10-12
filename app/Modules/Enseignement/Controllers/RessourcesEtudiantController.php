<?php

namespace App\Modules\Enseignement\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\Enseignement\Models\RessourcePedagogique;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace Étudiant — supports de cours publiés pour MES matières et
 * MON groupe (LOOP 14, volet supports).
 *
 * Visibilité : statut `publie` ET (ressource ciblée sur mon groupe OU
 * ressource tous-groupes d'une matière de l'un de mes programmes
 * inscrits). Contrôle rejoué au téléchargement, stockage privé.
 * Les brouillons et archives ne sont jamais visibles.
 */
class RessourcesEtudiantController extends Controller
{
    public function index(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $ressources = $this->visiblesPour($etudiant)
            ->with(['matiere', 'groupe', 'enseignant'])
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('modules.enseignement.supports-etudiant', [
            'etudiant' => $etudiant,
            'ressources' => $ressources,
        ]);
    }

    public function telecharger(Request $request, RessourcePedagogique $ressource): StreamedResponse
    {
        $etudiant = $this->etudiantConnecte($request);
        $this->assertVisible($etudiant, $ressource);

        abort_unless(Storage::disk('private')->exists($ressource->chemin), 404);

        return Storage::disk('private')->download($ressource->chemin, $ressource->nom_fichier, [
            'Content-Type' => $ressource->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function visiblesPour(Etudiant $etudiant)
    {
        return RessourcePedagogique::query()->visiblesPourEtudiant($etudiant);
    }

    private function assertVisible(Etudiant $etudiant, RessourcePedagogique $ressource): void
    {
        abort_unless($ressource->statut === 'publie', 404);
        abort_unless($this->visiblesPour($etudiant)->whereKey($ressource->id)->exists(), 404);
    }

    private function etudiantConnecte(Request $request): Etudiant
    {
        return Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));
    }
}
