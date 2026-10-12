<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gabarits de notification (Phase 15 §15.1) : configuration globale,
 * version auto-incrémentée à chaque modification de texte (jamais de
 * réécriture silencieuse d'un texte déjà envoyé).
 */
class NotificationTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.notifications.templates', [
            'gabarits' => NotificationTemplate::orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, NotificationTemplate $gabarit): RedirectResponse
    {
        $donnees = $request->validate([
            'titre' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:500'],
            'actif' => ['sometimes', 'boolean'],
        ]);

        $texteChange = $donnees['titre'] !== $gabarit->titre || $donnees['message'] !== $gabarit->message;

        $gabarit->update([
            'titre' => $donnees['titre'],
            'message' => $donnees['message'],
            'actif' => (bool) ($donnees['actif'] ?? false),
            'version' => $texteChange ? $gabarit->version + 1 : $gabarit->version,
        ]);

        return redirect()->route('admin.notifications.templates')
            ->with('success', "Gabarit « {$gabarit->code} » mis à jour (v{$gabarit->fresh()->version}).");
    }
}
