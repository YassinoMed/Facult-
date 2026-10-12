<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fonction;
use App\Models\Personnel;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Support\Recherche;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Personnel administratif (§14) : profils de comptes User existants.
 * Réservé super_admin (comptes sensibles) — jamais de création User ici.
 */
class PersonnelController extends Controller
{
    public function index(Request $request): View
    {
        $query = Personnel::with(['user', 'fonction', 'departement']);

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w
                ->where('matricule', 'like', $motif)
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'like', $motif)->orWhere('email', 'like', $motif)));
        }

        if ($request->input('fonction_id') !== null && $request->input('fonction_id') !== '') {
            $query->where('fonction_id', (int) $request->input('fonction_id'));
        }

        return view('admin.personnel.index', [
            'personnels' => $query->orderBy('matricule')->paginate(12)->withQueryString(),
            'fonctions' => Fonction::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.personnel.form', [
            'personnel' => null,
            'comptes' => User::whereDoesntHave('personnelProfil')->orderBy('name')->get(),
            'fonctions' => Fonction::where('actif', true)->orderBy('nom')->get(),
            'departements' => Departement::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'user_id' => 'required|exists:users,id|unique:personnels,user_id',
            'matricule' => 'required|string|max:30|unique:personnels,matricule',
            'fonction_id' => 'nullable|exists:fonctions,id',
            'departement_id' => 'nullable|exists:departements,id',
            'bureau' => 'nullable|string|max:50',
            'date_entree' => 'nullable|date',
            'statut' => 'required|in:actif,inactif,suspendu',
        ]);

        Personnel::create($donnees);

        return redirect()->route('admin.personnel.index')
            ->with('success', 'Profil personnel créé.');
    }

    public function show(Personnel $personnel): View
    {
        $personnel->loadMissing(['user', 'fonction', 'departement.faculte']);

        return view('admin.personnel.show', compact('personnel'));
    }

    public function edit(Personnel $personnel): View
    {
        return view('admin.personnel.form', [
            'personnel' => $personnel,
            'comptes' => User::orderBy('name')->get(),
            'fonctions' => Fonction::where('actif', true)->orderBy('nom')->get(),
            'departements' => Departement::orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, Personnel $personnel): RedirectResponse
    {
        $donnees = $request->validate([
            'matricule' => 'required|string|max:30|unique:personnels,matricule,'.$personnel->id,
            'fonction_id' => 'nullable|exists:fonctions,id',
            'departement_id' => 'nullable|exists:departements,id',
            'bureau' => 'nullable|string|max:50',
            'date_entree' => 'nullable|date',
            'statut' => 'required|in:actif,inactif,suspendu',
        ]);

        $personnel->update($donnees);

        return redirect()->route('admin.personnel.show', $personnel)
            ->with('success', 'Profil personnel mis à jour.');
    }

    public function destroy(Personnel $personnel): RedirectResponse
    {
        // Profil uniquement — le compte User est conservé.
        $personnel->delete();

        return redirect()->route('admin.personnel.index')
            ->with('success', 'Profil personnel supprimé (compte conservé).');
    }
}
