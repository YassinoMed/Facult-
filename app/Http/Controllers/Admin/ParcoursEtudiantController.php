<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Services\ParcoursEtudiantService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Parcours académique en lecture seule (LOOP 13) : même service que le
 * portail étudiant, périmètre via `EtudiantPolicy::view` (403 + scope
 * faculté). Aucune écriture, aucune attribution devinée.
 */
class ParcoursEtudiantController extends Controller
{
    public function show(Etudiant $etudiant, ParcoursEtudiantService $parcours): View
    {
        Gate::authorize('view', $etudiant);

        return view('admin.etudiants.parcours', [
            'etudiant' => $etudiant,
            'parcours' => $parcours->consulter($etudiant),
        ]);
    }
}
