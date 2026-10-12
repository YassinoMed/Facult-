<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Centre de notifications (§4-6) : les trois espaces partagent les
 * vues, le destinataire est résolu par garde (back-office) ou session
 * (étudiant, enseignant) — jamais depuis un paramètre client (anti-IDOR).
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function indexAdmin(): View
    {
        $query = AppNotification::where('user_id', auth()->id());

        $this->appliquerFiltres($query, request());

        return view('notifications.index', [
            'titre' => 'Notifications',
            'notifications' => $query->latest()->paginate(15)->withQueryString(),
            'nonLues' => $this->notifications->nonLuesUser((int) auth()->id()),
            'actionToutLire' => route('admin.notifications.lire-tout'),
            'retour' => route('admin.dashboard'),
            'filtre' => request('filtre'),
            'type' => request('type'),
        ]);
    }

    public function lireAdmin(Request $request, AppNotification $notification): RedirectResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 404);

        $notification->marquerLue();

        return redirect($notification->lien ?? route('admin.notifications.index'));
    }

    public function toutLireAdmin(): RedirectResponse
    {
        $this->notifications->toutMarquerLuUser((int) auth()->id());

        return redirect()->back()->with('success', 'Toutes les notifications sont marquées comme lues.');
    }

    public function indexEtudiant(Request $request): View
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        $query = AppNotification::where('etudiant_id', $etudiantId);

        $this->appliquerFiltres($query, $request);

        return view('notifications.index', [
            'titre' => 'Notifications',
            'notifications' => $query->latest()->paginate(15)->withQueryString(),
            'nonLues' => $this->notifications->nonLuesEtudiant($etudiantId),
            'actionToutLire' => route('etudiant.notifications.lire-tout'),
            'retour' => route('etudiant.dashboard'),
            'filtre' => $request->input('filtre'),
            'type' => $request->input('type'),
        ]);
    }

    public function lireEtudiant(Request $request, AppNotification $notification): RedirectResponse
    {
        abort_if($notification->etudiant_id !== (int) $request->session()->get('etudiant_auth_id'), 404);

        $notification->marquerLue();

        return redirect($notification->lien ?? route('etudiant.notifications.index'));
    }

    public function toutLireEtudiant(Request $request): RedirectResponse
    {
        $this->notifications->toutMarquerLuEtudiant((int) $request->session()->get('etudiant_auth_id'));

        return redirect()->back()->with('success', 'Toutes les notifications sont marquées comme lues.');
    }

    public function indexEnseignant(Request $request): View
    {
        $enseignantId = (int) $request->session()->get('enseignant_auth_id');
        $query = AppNotification::where('teacher_id', $enseignantId);

        $this->appliquerFiltres($query, $request);

        return view('notifications.index', [
            'titre' => 'Notifications',
            'notifications' => $query->latest()->paginate(15)->withQueryString(),
            'nonLues' => $this->notifications->nonLuesEnseignant($enseignantId),
            'actionToutLire' => route('enseignant.notifications.lire-tout'),
            'retour' => route('enseignant.dashboard'),
            'filtre' => $request->input('filtre'),
            'type' => $request->input('type'),
        ]);
    }

    public function lireEnseignant(Request $request, AppNotification $notification): RedirectResponse
    {
        abort_if($notification->teacher_id !== (int) $request->session()->get('enseignant_auth_id'), 404);

        $notification->marquerLue();

        return redirect($notification->lien ?? route('enseignant.notifications.index'));
    }

    public function toutLireEnseignant(Request $request): RedirectResponse
    {
        $this->notifications->toutMarquerLuEnseignant((int) $request->session()->get('enseignant_auth_id'));

        return redirect()->back()->with('success', 'Toutes les notifications sont marquées comme lues.');
    }

    /**
     * Filtres du centre (§15.10) : non lues / importantes (priorité ≥ 2) / type.
     */
    private function appliquerFiltres(mixed $query, Request $request): void
    {
        match ($request->input('filtre')) {
            'non-lues' => $query->whereNull('read_at'),
            'importantes' => $query->where('priorite', '>=', 2),
            default => null,
        };

        if ($request->filled('type')) {
            $query->where('type', 'like', $request->input('type').'%');
        }
    }
}
