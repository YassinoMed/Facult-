<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Etudiant;
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

    /**
     * Q132 : contestation d'un refus de validation de preuve de remise.
     *
     * Le destinataire est résolu EXCLUSIVEMENT depuis la session serveur
     * (anti-IDOR) : une notification d'un autre étudiant renvoie 404. La
     * contestation est enregistrée sans modifier le statut de remise et
     * sans aucun effet métier automatique.
     */
    public function contesterEtudiant(Request $request, AppNotification $notification): RedirectResponse
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        abort_if($notification->etudiant_id !== $etudiantId, 404);

        $etudiant = Etudiant::find($etudiantId);
        abort_if($etudiant === null, 404);

        if (($notification->data['statut_remise'] ?? null) !== 'refusee') {
            return redirect()
                ->route('etudiant.notifications.index')
                ->withErrors(['motif_contestation' => 'Seul un refus de validation de preuve peut être contesté par cette voie.']);
        }

        $donnees = $request->validate([
            'motif_contestation' => ['required', 'string', 'min:10', 'max:2000'],
            'piece_contestation' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $pieceHash = null;
        $fichier = $request->file('piece_contestation');
        $chemin = $fichier?->getRealPath();
        if (is_string($chemin) && $chemin !== '') {
            $pieceHash = hash_file('sha256', $chemin) ?: null;
        }

        $resultat = $this->notifications->enregistrerContestationRemise(
            $notification,
            $donnees['motif_contestation'],
            $pieceHash,
            contestataire: $etudiant,
        );

        return redirect()
            ->route('etudiant.notifications.index')
            ->with('success', $resultat['deja_enregistree']
                ? 'Cette contestation est déjà enregistrée. Le statut de remise reste inchangé.'
                : 'Votre contestation a été enregistrée. La décision initiale est conservée et le statut de remise reste inchangé.');
    }

    /**
     * Q133, Q134 / STAT-004, BUS-004 :
     * Application administrative d'une décision de recours officiel relative à une preuve de remise.
     * Met à jour le statut selon les conclusions du recours, préserve la décision et liste les conséquences
     * potentielles sur les dossiers associés sans aucune mutation automatique.
     */
    public function appliquerRecoursAdmin(Request $request, AppNotification $notification): RedirectResponse
    {
        $donnees = $request->validate([
            'issue' => ['required', 'string', 'in:remise_confirmee,remise_non_confirmee'],
            'reference_recours' => ['required', 'string', 'min:3', 'max:255'],
            'piece_recours' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'dossiers_associes' => ['nullable', 'array'],
            'dossiers_associes.*' => ['string', 'max:100'],
        ]);

        $pieceHash = null;
        $fichier = $request->file('piece_recours');
        $chemin = $fichier?->getRealPath();
        if (is_string($chemin) && $chemin !== '') {
            $pieceHash = hash_file('sha256', $chemin) ?: null;
        }

        $resultat = $this->notifications->appliquerRecoursRemise(
            $notification,
            $donnees['issue'],
            $donnees['reference_recours'],
            autoriteId: (int) $request->user()->id
        );

        $message = $resultat['statut_remise'] === 'remise_confirmee'
            ? 'La décision de recours officiel a été enregistrée : remise confirmée.'
            : 'La décision de recours officiel a été enregistrée : maintien de la remise non confirmée.';

        if (! empty($resultat['dossiers_associes_identifies'])) {
            $message .= ' Les dossiers associés ont été recensés sans mutation automatique.';
        }

        return redirect()->back()->with('success', $message);
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
