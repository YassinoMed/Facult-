<?php

namespace App\Modules\Announcements\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Faculte;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Announcements\Requests\StoreAnnonceRequest;
use App\Modules\Announcements\Services\AnnonceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AnnonceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Annonce::class);

        $query = Annonce::with(['faculte', 'programme', 'groupe'])
            ->withCount('lectures')
            ->orderByDesc('epinglee')->latest('publiee_a')->latest('id');

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId));
        }

        return view('modules.announcements.index', ['annonces' => $query->paginate(15)->withQueryString()]);
    }

    public function create(): View
    {
        Gate::authorize('create', Annonce::class);

        return view('modules.announcements.form', [
            'facultes' => Faculte::where('active', true)->orderBy('nom')->get(),
        ]);
    }

    public function store(StoreAnnonceRequest $request): RedirectResponse
    {
        $annonce = Annonce::create([...$request->validated(), 'statut' => 'draft', 'created_by' => $request->user()->id]);

        return redirect()->route('admin.annonces.index')->with('success', 'Annonce créée en brouillon.');
    }

    public function publier(Request $request, Annonce $annonce, AnnonceService $service): RedirectResponse
    {
        Gate::authorize('publish', $annonce);

        try {
            $service->publier($annonce);
        } catch (DomainException $e) {
            return back()->withErrors(['annonce' => $e->getMessage()]);
        }

        return back()->with('success', 'Annonce publiée et diffusée.');
    }

    public function expirer(Request $request, Annonce $annonce, AnnonceService $service): RedirectResponse
    {
        Gate::authorize('publish', $annonce);

        $service->expirer($annonce);

        return back()->with('success', 'Annonce expirée.');
    }

    public function destroy(Request $request, Annonce $annonce): RedirectResponse
    {
        Gate::authorize('delete', $annonce);

        $annonce->delete();

        return redirect()->route('admin.annonces.index')->with('success', 'Annonce supprimée.');
    }
}
