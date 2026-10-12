<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentAcademique;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleImport;
use App\Modules\Schedule\Models\Teacher;
use App\Services\NotificationService;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tableau de bord du back-office (§15) — statistiques en requêtes
 * AGRÉGÉES :
 * - les 4 cartes de statut partagent UNE seule requête
 *   (SUM conditionnelles), jamais une requête par carte ;
 * - le périmètre faculté (admin_faculte) est appliqué serveur sur
 *   chaque requête, jamais depuis un paramètre client.
 */
class TableauDeBordController extends Controller
{
    public function index(Request $request): View
    {
        $faculteRestreinteId = $request->user()->faculteRestreinteId();

        $ligne = Etudiant::query()
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(statut = 'en_attente') as en_attente")
            ->selectRaw("SUM(statut = 'valide') as valide")
            ->selectRaw("SUM(statut = 'rejete') as rejete")
            ->first();

        $stats = [
            'total' => (int) $ligne->total,
            'en_attente' => (int) $ligne->en_attente,
            'valide' => (int) $ligne->valide,
            'rejete' => (int) $ligne->rejete,
        ];

        $derniers = Etudiant::with('faculte')
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->latest()
            ->limit(8)
            ->get();

        $documentsTotal = DocumentAcademique::query()
            ->when(
                $faculteRestreinteId !== null,
                fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteRestreinteId))
            )
            ->count();

        $activite = HistoriqueAcademique::with(['etudiant', 'utilisateur'])
            ->when(
                $faculteRestreinteId !== null,
                fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteRestreinteId))
            )
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Pilotage transverse (§2) : référentiels + demandes + imports +
        // notifications — requêtes comptées, jamais de collections.
        $referentiels = [
            'enseignants' => Teacher::count(),
            'groupes' => Group::when(
                $faculteRestreinteId !== null,
                fn ($q) => $q->where('faculte_id', $faculteRestreinteId)
            )->count(),
            'salles' => Room::count(),
        ];

        $demandesAttente = app(ModuleRegistry::class)->estActif('online-requests')
            ? OnlineRequest::whereIn('status', ['submitted', 'received', 'in_review', 'waiting_student'])
                ->when(
                    $faculteRestreinteId !== null,
                    fn ($q) => $q->where('faculte_id', $faculteRestreinteId)
                )
                ->count()
            : 0;

        $importsRecents = ScheduleImport::with('schedule.groupe')
            ->when(
                $faculteRestreinteId !== null,
                fn ($q) => $q->whereHas('schedule.groupe', fn ($g) => $g->where('faculte_id', $faculteRestreinteId))
            )
            ->latest()->limit(5)->get();

        $notificationsNT = app(NotificationService::class)->nonLuesUser((int) $request->user()->id);

        // Centre de résolution : derniers runs non conformes (scopés).
        $runsQuery = DossierVerification::whereIn('id', function ($q) {
            $q->selectRaw('MAX(id)')->from('dossier_verifications')->groupBy('etudiant_id');
        })->whereHas('etudiant', function ($q) use ($faculteRestreinteId) {
            if ($faculteRestreinteId !== null) {
                $q->where('faculte_id', $faculteRestreinteId);
            }
        });

        $verifications = [
            'bloquants' => (clone $runsQuery)->where('verdict', 'BLOQUANT')->count(),
            'a_verifier' => (clone $runsQuery)->where('verdict', 'A_VERIFIER')->count(),
        ];

        return view('admin.dashboard', [
            'stats' => $stats,
            'derniers' => $derniers,
            'documentsTotal' => $documentsTotal,
            'activite' => $activite,
            'faculte' => $request->user()->faculte,
            'referentiels' => $referentiels,
            'demandesAttente' => $demandesAttente,
            'importsRecents' => $importsRecents,
            'notificationsNT' => $notificationsNT,
            'verifications' => $verifications,
            'estSuperAdmin' => $request->user()->estSuperAdmin(),
        ]);
    }
}
