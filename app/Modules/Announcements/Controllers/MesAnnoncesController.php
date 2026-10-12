<?php

namespace App\Modules\Announcements\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Announcements\Models\AnnonceLecture;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MesAnnoncesController extends Controller
{
    public function index(Request $request): View
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        $annonces = Annonce::where('statut', 'published')
            ->where(fn ($q) => $q->whereNull('publiee_a')->orWhere('publiee_a', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expire_a')->orWhere('expire_a', '>', now()))
            ->where(fn ($q) => $q
                ->whereIn('audience', ['tous', 'etudiants'])
                ->orWhere(fn ($s) => $s->where('audience', 'programme')->where('programme_id', $etudiant->programme_id))
                ->orWhere(fn ($s) => $s->where('audience', 'groupe')->where('groupe_id', $etudiant->groupe_id)))
            ->when($etudiant->faculte_id !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $etudiant->faculte_id)))
            ->orderByDesc('epinglee')
            ->latest('publiee_a')
            ->paginate(15);

        return view('modules.announcements.mes-annonces', ['annonces' => $annonces]);
    }

    public function show(Request $request, Annonce $annonce): View
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        // Anti-IDOR + audience (audit sécurité 04/10/2026) : statut/dates
        // ET portée (audience, faculté) — comme l'index.
        abort_unless($annonce->visiblePourEtudiant($etudiant), 404);

        AnnonceLecture::firstOrCreate(
            ['annonce_id' => $annonce->id, 'etudiant_id' => $etudiant->id],
            ['lue_a' => now()],
        );

        return view('modules.announcements.show-etudiant', ['annonce' => $annonce]);
    }

    /**
     * Espace Enseignant : annonces `tous` + `enseignants` de sa faculté
     * (miroir étudiant, sans accusé de lecture — AnnonceLecture est lié
     * aux étudiants uniquement).
     */
    public function indexEnseignant(Request $request): View
    {
        $enseignant = Teacher::with('departement')->findOrFail($request->session()->get('enseignant_auth_id'));
        $faculteId = $enseignant->faculteId();

        $annonces = Annonce::where('statut', 'published')
            ->where(fn ($q) => $q->whereNull('publiee_a')->orWhere('publiee_a', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expire_a')->orWhere('expire_a', '>', now()))
            ->whereIn('audience', ['tous', 'enseignants'])
            ->when($faculteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)))
            ->orderByDesc('epinglee')
            ->latest('publiee_a')
            ->paginate(15);

        return view('modules.announcements.mes-annonces', ['annonces' => $annonces, 'routeLecture' => 'enseignant.annonces.show']);
    }

    public function showEnseignant(Request $request, Annonce $annonce): View
    {
        $enseignant = Teacher::with('departement')->findOrFail($request->session()->get('enseignant_auth_id'));

        abort_unless($annonce->visiblePourEnseignant($enseignant), 404);

        return view('modules.announcements.show-etudiant', ['annonce' => $annonce, 'retour' => route('enseignant.annonces.index')]);
    }
}
