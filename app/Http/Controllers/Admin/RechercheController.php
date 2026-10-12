<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ElementRecent;
use App\Models\Etudiant;
use App\Models\FavoriUtilisateur;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Services\Recherche\SearchRegistry;
use App\Support\Modules\ModuleRegistry;
use App\Support\Recherche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Recherche globale back-office (§7) : un seul champ, résultats
 * groupés par entité, chaque section gated par permission ET scopée
 * faculté — aucune fuite inter-périmètre possible.
 */
class RechercheController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly SearchRegistry $recherche,
    ) {}

    public function global(Request $request): View|RedirectResponse
    {
        $q = trim($request->input('q', ''));
        $sections = [];

        if (mb_strlen($q) >= 2) {
            // Saut direct (Phase 7 §7.1) : CIN / n° inscription / référence
            // exact → fiche, si périmètre et permission (jamais de liste).
            // Le terme est conservé (?q=) pour le retour vers la recherche.
            if ($cible = $this->sautDirect($request, $q)) {
                return redirect($cible.'?q='.urlencode($q));
            }

            $sections = $this->recherche->sections(
                $request->user(), Recherche::contient($q), 6
            );
        }

        return view('admin.recherche', ['q' => $q, 'sections' => $sections]);
    }

    /**
     * Saut direct vers le dossier : correspondance EXACTE (pas LIKE)
     * sur CIN, n° inscription (étudiant) ou référence (demande),
     * scopée faculté + gated comme les sections.
     */
    private function sautDirect(Request $request, string $q): ?string
    {
        $utilisateur = $request->user();
        $faculteId = $utilisateur->faculteRestreinteId();

        if ($utilisateur->can('archives.view')) {
            $etudiant = Etudiant::where(fn ($w) => $w
                ->where('cin', $q)->orWhere('num_inscription', $q))
                ->when($faculteId !== null, fn ($w) => $w->where('faculte_id', $faculteId))
                ->first();

            if ($etudiant !== null) {
                return route('admin.etudiants.show', $etudiant);
            }
        }

        if ($utilisateur->can('demandes.view') && $this->modules->estActif('online-requests')) {
            $demande = OnlineRequest::where('reference', $q)
                ->when($faculteId !== null, fn ($w) => $w->where('faculte_id', $faculteId))
                ->first();

            if ($demande !== null) {
                return route('admin.demandes.show', $demande);
            }
        }

        return null;
    }

    /**
     * Contexte de la palette (Phase 7 §7.4) : récents + favoris de
     * l'utilisateur pour la requête vide. Strictement personnel.
     */
    public function contexte(Request $request): JsonResponse
    {
        $recents = ElementRecent::where('user_id', $request->user()->id)
            ->latest('updated_at')->limit(6)->get()
            ->map(fn ($e) => ['titre' => $e->titre, 'detail' => $e->type, 'lien' => $e->lien])
            ->values()->all();

        $favoris = FavoriUtilisateur::where('user_id', $request->user()->id)
            ->latest('updated_at')->limit(12)->get()
            ->map(fn ($f) => ['titre' => $f->titre, 'detail' => $f->type, 'lien' => $f->lien])
            ->values()->all();

        return response()->json(['recents' => $recents, 'favoris' => $favoris]);
    }

    /**
     * Recherche instantanée de la palette (JSON) : mêmes permissions
     * et mêmes périmètres que la page — aucun résultat exposé.
     */
    public function instantanee(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));
        $resultats = [];

        if (mb_strlen($q) >= 2) {
            // Parité page/palette (Phase 7 §7.2) : mêmes providers,
            // limite réduite adaptée au dropdown.
            $resultats = $this->recherche->instantanee(
                $request->user(), Recherche::contient($q), 5
            );
        }

        return response()->json($resultats);
    }
}
