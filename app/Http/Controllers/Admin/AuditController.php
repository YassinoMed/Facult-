<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HistoriqueAcademique;
use App\Modules\Schedule\Models\Teacher;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Journal d'audit central (§31/§34) : lecture seule, filtres
 * (action, recherche, période), pagination. Permission `history.view`
 * — aucune écriture exposée ici.
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        // Isolation faculté : un admin_faculte ne voit que l'audit des
        // étudiants de son périmètre (anti-fuite PII cross-faculté) +
        // les événements enseignants de son périmètre (Phase E).
        $query = HistoriqueAcademique::with(['etudiant', 'utilisateur'])
            ->when(
                $request->user()->faculteRestreinteId(),
                fn ($q, $faculteId) => $q->where(
                    fn ($w) => $w->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId))
                        ->orWhere(
                            fn ($x) => $x->whereNull('etudiant_id')->where('entite', 'enseignant')
                                ->whereIn('entite_id', self::idsEnseignantsPerimetre($faculteId))
                        )
                )
            )
            ->latest();

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('etudiant', fn ($q) => $q
                ->where('nom', 'like', $motif)
                ->orWhere('prenom', 'like', $motif)
                ->orWhere('cin', 'like', $motif));
        }

        if ($debut = $request->input('depuis')) {
            $query->whereDate('created_at', '>=', $debut);
        }

        if ($fin = $request->input('jusquau')) {
            $query->whereDate('created_at', '<=', $fin);
        }

        return view('admin.audit.index', [
            'entrees' => $query->paginate(20)->withQueryString(),
            'actions' => HistoriqueAcademique::select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    /**
     * Export CSV du journal (mêmes filtres + même périmètre que
     * l'index — aucune fuite cross-faculté).
     */
    public function exporter(Request $request): StreamedResponse
    {
        $query = HistoriqueAcademique::with(['etudiant', 'utilisateur'])
            ->when(
                $request->user()->faculteRestreinteId(),
                fn ($q, $faculteId) => $q->where(
                    fn ($w) => $w->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId))
                        ->orWhere(
                            fn ($x) => $x->whereNull('etudiant_id')->where('entite', 'enseignant')
                                ->whereIn('entite_id', self::idsEnseignantsPerimetre($faculteId))
                        )
                )
            )
            ->latest()
            ->limit(5000);

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }

        if ($debut = $request->input('depuis')) {
            $query->whereDate('created_at', '>=', $debut);
        }

        if ($fin = $request->input('jusquau')) {
            $query->whereDate('created_at', '<=', $fin);
        }

        return response()->streamDownload(function () use ($query) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['date', 'action', 'entite', 'entite_id', 'etudiant', 'utilisateur'], ';');

            foreach ($query->cursor() as $entree) {
                fputcsv($sortie, [
                    $entree->created_at?->format('Y-m-d H:i:s'),
                    $entree->action,
                    $entree->entite,
                    $entree->entite_id,
                    $entree->etudiant?->nom_complet,
                    $entree->utilisateur?->name,
                ], ';');
            }

            fclose($sortie);
        }, 'audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Identifiants enseignants du périmètre (transversaux + faculté) —
     * même règle que la page Enseignants (Phase B, option 2).
     *
     * @return array<int>
     */
    private static function idsEnseignantsPerimetre(int $faculteId): array
    {
        return Teacher::where(
            fn ($w) => $w->whereNull('departement_id')
                ->orWhereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
        )->pluck('id')->all();
    }
}
