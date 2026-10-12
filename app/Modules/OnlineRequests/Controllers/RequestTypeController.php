<?php

namespace App\Modules\OnlineRequests\Controllers;

use App\Modules\OnlineRequests\Models\RequestType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catalogue des services (Phase 9 §41-42) : types configurables
 * (code, libellé, schéma, pièces, SLA, document final). Configuration
 * globale — gate `configuration.manage`, zéro permission créée.
 */
class RequestTypeController
{
    public function index(): View
    {
        return view('modules.online-requests.types-index', [
            'types' => RequestType::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_]+$/', 'unique:request_types,code'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'generates_document' => ['sometimes', 'boolean'],
            'processing_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        RequestType::create([
            'code' => $donnees['code'],
            'name' => $donnees['name'],
            'description' => $donnees['description'] ?? null,
            'requires_attachment' => (bool) ($donnees['requires_attachment'] ?? false),
            'generates_document' => (bool) ($donnees['generates_document'] ?? false),
            'processing_days' => $donnees['processing_days'],
            'active' => true,
        ]);

        return redirect()->route('admin.demandes.types.index')
            ->with('succes', 'Service créé.');
    }

    public function update(Request $request, RequestType $type): RedirectResponse
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'generates_document' => ['sometimes', 'boolean'],
            'processing_days' => ['required', 'integer', 'min:1', 'max:90'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $type->update([
            'name' => $donnees['name'],
            'description' => $donnees['description'] ?? null,
            'requires_attachment' => (bool) ($donnees['requires_attachment'] ?? false),
            'generates_document' => (bool) ($donnees['generates_document'] ?? false),
            'processing_days' => $donnees['processing_days'],
            'active' => (bool) ($donnees['active'] ?? false),
        ]);

        return redirect()->route('admin.demandes.types.index')
            ->with('succes', 'Service mis à jour.');
    }

    public function destroy(RequestType $type): RedirectResponse
    {
        if ($type->demandes()->exists()) {
            return redirect()->back()->withErrors([
                'type' => 'Service utilisé par des demandes : désactivez-le plutôt.',
            ]);
        }

        $type->delete();

        return redirect()->route('admin.demandes.types.index')
            ->with('succes', 'Service supprimé.');
    }
}
