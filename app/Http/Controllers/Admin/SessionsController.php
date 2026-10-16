<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Sessions actives (Phase 27) : chaque administrateur voit SES
 * sessions (appareil, IP, activité) et peut révoquer les autres.
 * Jamais celles d'autrui (super_admin inclus : moindre privilège).
 */
class SessionsController extends Controller
{
    public function index(Request $request): View
    {
        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($session) => [
                'id' => $session->id,
                'courante' => $session->id === $request->session()->getId(),
                'ip' => $session->ip_address,
                'agent' => mb_substr((string) $session->user_agent, 0, 120),
                'activite' => date('d/m/Y H:i', (int) $session->last_activity),
            ]);

        return view('admin.sessions.index', ['sessions' => $sessions]);
    }

    /**
     * Révocation d'une autre session : opération privilégiée soumise à
     * réauthentification (mot de passe actuel), comme la désactivation
     * de la double facteur. La session courante reste révoquée par la
     * déconnexion (aucune auto-révocation ici).
     */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        abort_if($session === $request->session()->getId(), 422, 'Session courante : utilisez la déconnexion.');

        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            return back()->withErrors(['password' => 'Mot de passe incorrect.']);
        }

        DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', $session)
            ->delete();

        return back()->with('success', 'Session révoquée.');
    }
}
